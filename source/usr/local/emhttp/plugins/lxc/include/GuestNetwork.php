<?php
require_once __DIR__ . '/ContainerConfig.php';
require_once __DIR__ . '/Network.php';
require_once __DIR__ . '/Paths.php';

/**
 * Applies the plugin's network model inside the container rootfs.
 *
 * LXC pre-configures the interface before the guest init starts, but the
 * guest's own network stack (ifupdown, netplan, networkd, NetworkManager) can
 * reset it on boot. This module writes the matching guest configuration so the
 * static address and DNS survive a restart.
 *
 * Only dir:/btrfs:/zfs: rootfs paths contained in this container's directory
 * are touched. Symlinks are never followed and writes are atomic (temp+rename).
 */
final class GuestNetwork
{
    private const DNS_START = '# >>> LXCv2:DNS >>>';
    private const DNS_END = '# <<< LXCv2:DNS <<<';

    /**
     * @param NetworkInterface[] $interfaces
     * @return array{ok:bool,messages:string[]}
     */
    public static function apply(string $name, array $interfaces): array
    {
        $messages = [];
        $rootfs = self::resolveRootfs($name, $messages);
        if ($rootfs === null) {
            return ['ok' => false, 'messages' => $messages];
        }

        $stacks = self::detectStacks($rootfs);
        $dns = self::aggregateDns($interfaces);
        $dnsApplicable = $dns !== [] && self::dnsCanApply($interfaces);
        $ok = true;

        // Always clean orphaned managed files, even when netplan is the
        // primary stack (a previously enabled networkd file must not return).
        $ok = self::removeOrphanNetworkdFiles($rootfs, $interfaces, $messages) && $ok;

        $nmUnmanaged = [];
        foreach (array_values($interfaces) as $nic) {
            $mode = $nic->ipv4Mode; // static | dhcp | none
            if ($stacks['ifupdown']) {
                $ok = self::applyIfupdown($rootfs, $nic, $mode, $messages) && $ok;
            }
            if ($stacks['networkd']) {
                $ok = self::applyNetworkd($rootfs, $nic, $mode, $dnsApplicable ? $dns : [], $messages) && $ok;
            }
            if ($stacks['networkmanager'] && $mode !== 'dhcp') {
                $nmUnmanaged[] = $nic->name;
            }
        }

        if ($stacks['netplan'] && $interfaces !== []) {
            $ok = self::applyNetplan($rootfs, $interfaces, $dnsApplicable ? $dns : [], $messages) && $ok;
        }
        if ($stacks['networkmanager']) {
            $dhcpNames = [];
            foreach ($interfaces as $nic) {
                if ($nic->ipv4Mode === 'dhcp') {
                    $dhcpNames[] = $nic->name;
                }
            }
            $ok = self::applyNetworkManager($rootfs, $nmUnmanaged, $dhcpNames, $messages) && $ok;
        }

        if ($dns === [] || !$dnsApplicable) {
            $ok = self::clearDns($rootfs, $messages) && $ok;
            if ($dns !== [] && !$dnsApplicable) {
                $messages[] = 'Custom DNS was requested with DHCP; it was not applied. The container will use the DNS advertised by the DHCP server.';
            }
        } else {
            $ok = self::applyDns($rootfs, $dns, $stacks['resolved'], $messages) && $ok;
        }

        $messages[] = 'Guest network configuration written. Restart the container to apply it.';

        return ['ok' => $ok, 'messages' => $messages];
    }

    /**
     * Resolve and contain the rootfs. Returns null on any unsafe backend/path.
     *
     * @param string[] $messages
     */
    private static function resolveRootfs(string $name, array &$messages): ?string
    {
        $config = new ContainerConfig($name);
        $raw = $config->get('lxc.rootfs.path', '');
        $candidate = '';

        if (is_string($raw) && $raw !== '') {
            if (preg_match('/^(?:dir|btrfs):(.*)$/i', $raw, $m) === 1) {
                $candidate = trim($m[1]);
            } elseif (preg_match('/^zfs:(.*)$/i', $raw, $m) === 1) {
                [$rc, $out] = LxcPaths::run(['zfs', 'get', '-H', '-o', 'value', 'mountpoint', trim($m[1])]);
                $candidate = $rc === 0 ? trim($out) : '';
                if ($candidate === '' || $candidate === '-') {
                    $messages[] = 'ZFS dataset is not mounted; guest network configuration was skipped.';
                    return null;
                }
            } else {
                $messages[] = 'Rootfs backend is not supported for guest network configuration (dir, btrfs and zfs only).';
                return null;
            }
        }

        if ($candidate === '') {
            $candidate = LxcPaths::containerDir($name) . '/rootfs';
        }

        $real = realpath($candidate);
        $containerDir = realpath(LxcPaths::containerDir($name));
        if ($real === false || !is_dir($real) || $real === '/' || $containerDir === false) {
            $messages[] = 'Container rootfs not found or invalid.';
            return null;
        }
        if ($real === $containerDir || strpos($real, $containerDir . '/') !== 0) {
            $messages[] = 'Container rootfs is outside this container directory (or is the container directory itself); refusing to write guest network configuration.';
            return null;
        }

        return $real;
    }

    /**
     * @return array{netplan:bool,ifupdown:bool,networkd:bool,networkmanager:bool,resolved:bool}
     */
    private static function detectStacks(string $rootfs): array
    {
        $netplan = glob($rootfs . '/etc/netplan/*.yaml') ?: [];
        $networkd = self::serviceEnabled($rootfs, 'systemd-networkd');
        if (!$networkd && is_dir($rootfs . '/etc/systemd/network')) {
            foreach (glob($rootfs . '/etc/systemd/network/*.network') ?: [] as $file) {
                if (strpos(basename($file), '00-lxcv2-') !== 0) {
                    $networkd = true;
                    break;
                }
            }
        }

        return [
            'netplan' => $netplan !== [],
            'ifupdown' => is_file($rootfs . '/etc/network/interfaces'),
            'networkd' => $networkd,
            'networkmanager' => is_dir($rootfs . '/etc/NetworkManager')
                || is_dir($rootfs . '/etc/NetworkManager/system-connections'),
            'resolved' => self::serviceEnabled($rootfs, 'systemd-resolved'),
        ];
    }

    /**
     * A service is considered enabled only when its enable symlink/unit exists
     * under /etc/systemd/system. A config file alone is not evidence.
     */
    private static function serviceEnabled(string $rootfs, string $service): bool
    {
        foreach (['multi-user.target.wants', 'default.target.wants', 'sockets.target.wants'] as $target) {
            $base = $rootfs . '/etc/systemd/system/' . $target . '/' . $service;
            if (is_file($base . '.service') || is_link($base . '.service')
                || is_file($base . '.socket') || is_link($base . '.socket')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param NetworkInterface[] $interfaces
     * @return string[]
     */
    private static function aggregateDns(array $interfaces): array
    {
        $all = [];
        foreach ($interfaces as $nic) {
            foreach ($nic->dns as $ip) {
                if (!in_array($ip, $all, true)) {
                    $all[] = $ip;
                }
            }
        }

        return array_slice($all, 0, 3);
    }

    /**
     * @param NetworkInterface[] $interfaces
     */
    private static function dnsCanApply(array $interfaces): bool
    {
        foreach ($interfaces as $nic) {
            if ($nic->dns !== [] && $nic->ipv4Mode === 'dhcp') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $dns
     * @param string[] $messages
     */
    private static function applyNetworkd(string $rootfs, NetworkInterface $nic, string $mode, array $dns, array &$messages): bool
    {
        $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '', $nic->name) ?: 'eth0';
        $lines = ['[Match]', 'Name=' . $nic->name, '', '[Network]'];

        if ($mode === 'dhcp') {
            $lines[] = 'DHCP=ipv4';
        } else {
            $lines[] = 'DHCP=no';
            if ($mode === 'static' && self::validAddress($nic->ipv4Address, true)) {
                $lines[] = 'Address=' . $nic->ipv4Address;
                if (self::validIp($nic->ipv4Gateway, true)) {
                    $lines[] = 'Gateway=' . $nic->ipv4Gateway;
                }
            }
        }

        if ($nic->ipv6Mode === 'static' && self::validAddress($nic->ipv6Address, false)) {
            $lines[] = 'Address=' . $nic->ipv6Address;
            if (self::validIp($nic->ipv6Gateway, false)) {
                $lines[] = 'Gateway=' . $nic->ipv6Gateway;
            }
        }
        $lines[] = 'IPv6AcceptRA=' . ($nic->ipv6Mode === 'auto' ? 'yes' : 'no');
        foreach ($dns as $ip) {
            $lines[] = 'DNS=' . $ip;
        }

        return self::write($rootfs, 'etc/systemd/network/00-lxcv2-' . $safeName . '.network', implode("\n", $lines) . "\n", $messages, 'systemd-networkd');
    }

    private static function validIp(string $value, bool $ipv4): bool
    {
        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_IP, $ipv4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) !== false;
    }

    private static function validAddress(string $value, bool $ipv4): bool
    {
        $parts = explode('/', trim($value), 2);
        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return false;
        }
        $prefix = (int) $parts[1];

        return $ipv4
            ? self::validIp($parts[0], true) && $prefix >= 0 && $prefix <= 32
            : self::validIp($parts[0], false) && $prefix >= 0 && $prefix <= 128;
    }

    /**
     * @param NetworkInterface[] $interfaces
     * @param string[] $messages
     */
    private static function removeOrphanNetworkdFiles(string $rootfs, array $interfaces, array &$messages): bool
    {
        $keep = [];
        foreach ($interfaces as $nic) {
            $keep[] = '00-lxcv2-' . (preg_replace('/[^A-Za-z0-9_.-]/', '', $nic->name) ?: 'eth0') . '.network';
        }
        $ok = true;
        foreach (self::globSafe($rootfs, 'etc/systemd/network/00-lxcv2-*.network', $messages) as $file) {
            if (!in_array(basename($file), $keep, true)) {
                $ok = self::unlinkSafe($rootfs, 'etc/systemd/network/' . basename($file), $messages) && $ok;
            }
        }

        return $ok;
    }

    /**
     * @param NetworkInterface[] $interfaces
     * @param string[] $dns
     * @param string[] $messages
     */
    private static function applyNetplan(string $rootfs, array $interfaces, array $dns, array &$messages): bool
    {
        $ethernets = [];
        foreach ($interfaces as $nic) {
            $entry = [
                'dhcp4' => $nic->ipv4Mode === 'dhcp',
                'dhcp6' => false,
                'accept-ra' => $nic->ipv6Mode === 'auto',
            ];
            if ($nic->ipv4Mode === 'static' && self::validAddress($nic->ipv4Address, true)) {
                $entry['addresses'][] = $nic->ipv4Address;
                if (self::validIp($nic->ipv4Gateway, true)) {
                    $entry['routes'][] = ['to' => 'default', 'via' => $nic->ipv4Gateway];
                }
            }
            if ($nic->ipv6Mode === 'static' && self::validAddress($nic->ipv6Address, false)) {
                $entry['addresses'][] = $nic->ipv6Address;
                if (self::validIp($nic->ipv6Gateway, false)) {
                    $entry['routes'][] = ['to' => 'default', 'via' => $nic->ipv6Gateway];
                }
            }
            if ($dns !== []) {
                $entry['nameservers']['addresses'] = $dns;
            }
            $ethernets[$nic->name] = $entry;
        }

        $yaml = self::renderYaml(['network' => ['version' => 2, 'ethernets' => $ethernets]], 0);

        return self::write($rootfs, 'etc/netplan/99-lxcv2.yaml', $yaml, $messages, 'netplan');
    }

    /**
     * Minimal YAML emitter for maps, lists and list-of-maps (netplan subset).
     *
     * @param array<mixed> $data
     */
    private static function renderYaml(array $data, int $indent): string
    {
        $pad = str_repeat('  ', $indent);
        $out = '';
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $isList = array_keys($value) === range(0, count($value) - 1);
                if ($isList) {
                    $out .= $pad . $key . ":\n";
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $first = true;
                            foreach ($item as $k => $v) {
                                $prefix = $pad . '  ' . ($first ? '- ' : '  ');
                                $out .= $prefix . $k . ': ' . self::yamlScalar($v) . "\n";
                                $first = false;
                            }
                        } else {
                            $out .= $pad . '  - ' . self::yamlScalar($item) . "\n";
                        }
                    }
                } else {
                    $out .= $pad . $key . ":\n" . self::renderYaml($value, $indent + 1);
                }
            } else {
                $out .= $pad . $key . ': ' . self::yamlScalar($value) . "\n";
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function yamlScalar($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param string[] $messages
     */
    private static function applyIfupdown(string $rootfs, NetworkInterface $nic, string $mode, array &$messages): bool
    {
        $v4Method = $mode === 'dhcp' ? 'dhcp' : 'manual';
        $ok = self::applyIfupdownStanza($rootfs, $nic->name, $v4Method, false, $messages);

        // static/none: LXC pre-configures the address, so keep ifupdown out of
        // the way with `manual`. auto: SLAAC needs ifupdown's `inet6 auto`.
        $v6Method = $nic->ipv6Mode === 'auto' ? 'auto' : 'manual';
        $ok = self::applyIfupdownStanza($rootfs, $nic->name, $v6Method, true, $messages) && $ok;

        return $ok;
    }

    /**
     * @param string[] $messages
     */
    private static function applyIfupdownStanza(string $rootfs, string $nic, string $method, bool $ipv6, array &$messages): bool
    {
        $stanza = self::findIfupdownStanza($rootfs, $nic, $messages, $ipv6);
        $target = $stanza['file'] ?? ($rootfs . '/etc/network/interfaces');
        $lines = $stanza['lines'] ?? (is_file($target) ? (file($target, FILE_IGNORE_NEW_LINES) ?: []) : []);
        $index = $stanza['index'] ?? null;
        $keyword = $ipv6 ? 'inet6' : 'inet';

        if ($index === null) {
            if (!self::hasAutoStanza($rootfs, $nic, $messages)) {
                $lines[] = 'auto ' . $nic;
            }
            $lines[] = 'iface ' . $nic . ' ' . $keyword . ' ' . $method;
        } else {
            $lines[$index] = 'iface ' . $nic . ' ' . $keyword . ' ' . $method;
            $end = self::stanzaEnd($lines, $index);
            for ($i = $index + 1; $i < $end; $i++) {
                if (preg_match('/^\s*(address|netmask|network|broadcast|gateway|dns-nameservers|dns-search)\s+/i', $lines[$i]) === 1) {
                    $lines[$i] = '';
                }
            }
            if (!self::hasAutoStanza($rootfs, $nic, $messages)) {
                $lines[] = 'auto ' . $nic;
            }
        }

        return self::write(
            $rootfs,
            ltrim(str_replace($rootfs . '/', '', $target), '/'),
            implode("\n", $lines) . "\n",
            $messages,
            'ifupdown'
        );
    }

    /**
     * @param string[] $lines
     */
    private static function stanzaEnd(array $lines, int $start): int
    {
        $end = count($lines);
        for ($i = $start + 1; $i < count($lines); $i++) {
            if (preg_match('/^\s*(iface|auto|allow-hotplug|source|source-directory|mapping)\b/', $lines[$i]) === 1) {
                return $i;
            }
        }

        return $end;
    }

    /**
     * @param string[] $messages
     */
    private static function hasAutoStanza(string $rootfs, string $nic, array &$messages): bool
    {
        $pattern = '/^\s*(auto|allow-hotplug)\s+.*\b' . preg_quote($nic, '/') . '\b/';
        foreach (self::ifupdownFiles($rootfs, $messages) as $file) {
            foreach ((file($file, FILE_IGNORE_NEW_LINES) ?: []) as $line) {
                if (preg_match($pattern, $line) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param string[] $messages
     * @return array{file:string,index:int,method:string,lines:string[]}|null
     */
    private static function findIfupdownStanza(string $rootfs, string $nic, array &$messages, bool $ipv6 = false): ?array
    {
        $pattern = '/^\s*iface\s+' . preg_quote($nic, '/') . '\s+inet' . ($ipv6 ? '6' : '') . '\s+(\S+)/';
        foreach (self::ifupdownFiles($rootfs, $messages) as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                if (preg_match($pattern, $line, $m) === 1) {
                    return ['file' => $file, 'index' => $i, 'method' => $m[1], 'lines' => $lines];
                }
            }
        }

        return null;
    }

    /**
     * @param string[] $messages
     * @return string[]
     */
    private static function ifupdownFiles(string $rootfs, array &$messages): array
    {
        $files = [];
        $main = $rootfs . '/etc/network/interfaces';
        if (!is_file($main) || is_link($main)) {
            return $files;
        }
        $files[] = $main;
        $content = (string) file_get_contents($main);

        // Only follow the includes the guest actually declares. A file sitting
        // in interfaces.d but not sourced is not part of the ifupdown config.
        if (preg_match_all('/^\s*source\s+(\S+)/m', $content, $m) > 0) {
            foreach ($m[1] as $pattern) {
                $full = $rootfs . '/' . ltrim(str_replace('"', '', $pattern), '/');
                $relative = ltrim(str_replace($rootfs . '/', '', $full), '/');
                foreach (self::globSafe($rootfs, $relative, $messages) as $file) {
                    if (is_file($file) && !is_link($file)) {
                        $files[] = $file;
                    }
                }
            }
        }
        if (preg_match_all('/^\s*source-directory\s+(\S+)/m', $content, $m) > 0) {
            foreach ($m[1] as $dir) {
                $full = $rootfs . '/' . ltrim(str_replace('"', '', $dir), '/');
                $relative = ltrim(str_replace($rootfs . '/', '', $full), '/');
                if (!self::pathIsSafe($rootfs, $relative, $messages) || !is_dir($full) || is_link($full)) {
                    continue;
                }
                foreach (scandir($full) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $file = $full . '/' . $entry;
                    if (is_file($file) && !is_link($file)) {
                        $files[] = $file;
                    }
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param string[] $unmanaged
     * @param string[] $dhcpNames
     * @param string[] $messages
     */
    private static function applyNetworkManager(string $rootfs, array $unmanaged, array $dhcpNames, array &$messages): bool
    {
        foreach ($dhcpNames as $nic) {
            $hasProfile = false;
            // netplan with the NetworkManager renderer writes generated
            // profiles under /run, so check both locations.
            $patterns = [
                $rootfs . '/etc/NetworkManager/system-connections/*.nmconnection',
                $rootfs . '/run/NetworkManager/system-connections/*.nmconnection',
            ];
            foreach ($patterns as $pattern) {
                foreach (glob($pattern) ?: [] as $file) {
                    if (is_file($file) && !is_link($file)
                        && strpos((string) file_get_contents($file), 'interface-name=' . $nic) !== false) {
                        $hasProfile = true;
                        break 2;
                    }
                }
            }
            if (!$hasProfile) {
                $messages[] = 'NetworkManager is active but has no connection profile for DHCP interface ' . $nic . '. DHCP may not start until a profile exists inside the container.';
            }
        }

        $path = 'etc/NetworkManager/conf.d/99-lxcv2.conf';
        if ($unmanaged === []) {
            return self::remove($rootfs, $path, $messages);
        }
        $devices = [];
        foreach ($unmanaged as $nic) {
            $devices[] = 'interface-name:' . $nic;
        }
        $content = "# Managed by the LXC v2 plugin\n[keyfile]\nunmanaged-devices=" . implode(';', $devices) . "\n";

        return self::write($rootfs, $path, $content, $messages, 'NetworkManager');
    }

    /**
     * @param string[] $dns
     * @param string[] $messages
     */
    private static function applyDns(string $rootfs, array $dns, bool $resolved, array &$messages): bool
    {
        $ok = true;
        if ($resolved) {
            $content = "# Managed by the LXC v2 plugin\n[Resolve]\nDNS=" . implode(' ', $dns) . "\n";
            $ok = self::write($rootfs, 'etc/systemd/resolved.conf.d/99-lxcv2.conf', $content, $messages, 'systemd-resolved');
        } else {
            // A stale drop-in would resurrect the old DNS if resolved is
            // re-enabled later. Keep the managed state idempotent.
            $ok = self::remove($rootfs, 'etc/systemd/resolved.conf.d/99-lxcv2.conf', $messages);
        }

        $target = $rootfs . '/etc/resolv.conf';
        if (is_link($target)) {
            if (!$resolved) {
                $messages[] = '/etc/resolv.conf is a symlink and systemd-resolved is not enabled; DNS could not be applied.';
                $ok = false;
            }

            return $ok;
        }
        $existing = is_file($target) ? (string) file_get_contents($target) : '';
        $existing = preg_replace(
            '/' . preg_quote(self::DNS_START, '/') . '.*?' . preg_quote(self::DNS_END, '/') . '/s',
            '',
            $existing
        ) ?? $existing;
        // Custom DNS must win: drop pre-existing nameserver lines, keep search/options.
        $existing = preg_replace('/^\s*nameserver\s+.*$/mi', '', $existing) ?? $existing;
        $body = [];
        foreach ($dns as $ip) {
            $body[] = 'nameserver ' . $ip;
        }
        $block = self::DNS_START . "\n" . implode("\n", $body) . "\n" . self::DNS_END;
        $cleaned = trim($existing);
        $content = $block . "\n" . ($cleaned !== '' ? $cleaned . "\n" : '');

        return self::write($rootfs, 'etc/resolv.conf', $content, $messages, 'resolv.conf') && $ok;
    }

    /**
     * @param string[] $messages
     */
    private static function clearDns(string $rootfs, array &$messages): bool
    {
        $ok = self::remove($rootfs, 'etc/systemd/resolved.conf.d/99-lxcv2.conf', $messages);
        $target = $rootfs . '/etc/resolv.conf';
        if (!is_file($target) || is_link($target)) {
            return $ok;
        }
        $existing = (string) file_get_contents($target);
        $cleaned = preg_replace(
            '/' . preg_quote(self::DNS_START, '/') . '.*?' . preg_quote(self::DNS_END, '/') . '/s',
            '',
            $existing
        ) ?? $existing;

        return self::write($rootfs, 'etc/resolv.conf', trim($cleaned) . "\n", $messages, 'resolv.conf') && $ok;
    }

    /**
     * @return string[]
     */
    private static function globSafe(string $rootfs, string $relative, array &$messages): array
    {
        if (!self::pathIsSafe($rootfs, $relative, $messages)) {
            return [];
        }
        $pattern = $rootfs . '/' . $relative;
        $matches = glob($pattern) ?: [];
        $base = realpath(dirname($pattern));
        $result = [];
        foreach ($matches as $file) {
            if ($base !== false && is_file($file) && !is_link($file)
                && realpath(dirname($file)) === $base) {
                $result[] = $file;
            }
        }

        return $result;
    }

    /**
     * @param string[] $messages
     */
    private static function remove(string $rootfs, string $relative, array &$messages): bool
    {
        if (!self::pathIsSafe($rootfs, $relative, $messages)) {
            return false;
        }
        $path = $rootfs . '/' . $relative;
        if (!is_file($path) || is_link($path)) {
            return true;
        }

        return @unlink($path);
    }

    /**
     * @param string[] $messages
     */
    private static function unlinkSafe(string $rootfs, string $relative, array &$messages): bool
    {
        return self::remove($rootfs, $relative, $messages);
    }

    /**
     * @param string[] $messages
     */
    private static function pathIsSafe(string $rootfs, string $relative, array &$messages, string $label = 'path'): bool
    {
        $parts = array_values(array_filter(explode('/', trim($relative, '/')), 'strlen'));
        $current = $rootfs;
        foreach ($parts as $part) {
            $current .= '/' . $part;
            if (is_link($current)) {
                $messages[] = $label . ': refusing to traverse symlink ' . $current;
                return false;
            }
        }
        $realParent = realpath(dirname($rootfs . '/' . $relative));
        $rootReal = realpath($rootfs);
        if ($rootReal === false || ($realParent !== false
            && $realParent !== $rootReal && strpos($realParent, $rootReal . '/') !== 0)) {
            $messages[] = $label . ': path escapes the container rootfs.';
            return false;
        }

        return true;
    }

    /**
     * @param string[] $messages
     */
    private static function write(string $rootfs, string $relative, string $content, array &$messages, string $label): bool
    {
        if (!self::pathIsSafe($rootfs, $relative, $messages, $label)) {
            return false;
        }
        $parts = array_values(array_filter(explode('/', trim($relative, '/')), 'strlen'));
        $current = $rootfs;
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            $current .= '/' . $part;
            if (is_link($current)) {
                $messages[] = $label . ': refusing to write through symlink ' . $current;
                return false;
            }
            if ($i < $last && !is_dir($current)) {
                if (!@mkdir($current, 0755, true) && !is_dir($current)) {
                    $messages[] = $label . ': could not create directory ' . $current;
                    return false;
                }
            }
        }

        $path = $rootfs . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $messages[] = $label . ': could not create directory ' . $dir;
            return false;
        }
        $tmp = @tempnam($dir, '.lxv2');
        if ($tmp === false || @file_put_contents($tmp, $content) === false) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
            $messages[] = $label . ': could not write temporary file.';
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            $messages[] = $label . ': could not install configuration file.';
            return false;
        }

        return true;
    }
}
