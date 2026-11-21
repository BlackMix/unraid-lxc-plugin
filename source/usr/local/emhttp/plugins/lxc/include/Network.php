<?php
require_once __DIR__ . '/ContainerConfig.php';

/**
 * A single network interface of an LXC container.
 */
class NetworkInterface
{
    public int $index = 0;
    public string $type = 'veth';
    public string $link = 'br0';
    public string $name = 'eth0';
    public string $hwaddr = '';
    public string $macvlanMode = 'bridge';
    public string $vethMode = 'bridge';
    public ?int $vlanId = null;

    /** dhcp | static | none */
    public string $ipv4Mode = 'dhcp';
    public string $ipv4Address = '';
    public string $ipv4Gateway = '';

    /** none | auto | static */
    public string $ipv6Mode = 'none';
    public string $ipv6Address = '';
    public string $ipv6Gateway = '';

    /** @var string[] DNS server IPs (max 3) */
    public array $dns = [];

    public bool $flagsUp = true;

    public function effectiveLink(): string
    {
        if ($this->type === 'macvlan' && $this->vlanId !== null) {
            $suffix = '.' . $this->vlanId;
            if (substr($this->link, -strlen($suffix)) !== $suffix) {
                return $this->link . $suffix;
            }
        }

        return $this->link;
    }

    /**
     * @return string[]
     */
    public function toLines(): array
    {
        $i = $this->index;
        $lines = [];

        // macvlan has no native vlan.id key: Unraid/ Linux need a VLAN
        // sub-interface on the host (eth0.100, bond0.100, ...).
        $link = $this->effectiveLink();

        $lines[] = 'lxc.net.' . $i . '.type = ' . $this->type;
        $lines[] = 'lxc.net.' . $i . '.flags = ' . ($this->flagsUp ? 'up' : 'down');
        $lines[] = 'lxc.net.' . $i . '.link = ' . $link;
        $lines[] = 'lxc.net.' . $i . '.name = ' . $this->name;

        if ($this->hwaddr !== '') {
            $lines[] = 'lxc.net.' . $i . '.hwaddr = ' . strtoupper($this->hwaddr);
        }

        if ($this->type === 'macvlan') {
            $lines[] = 'lxc.net.' . $i . '.macvlan.mode = ' . $this->macvlanMode;
        } elseif ($this->type === 'veth') {
            $lines[] = 'lxc.net.' . $i . '.veth.mode = ' . $this->vethMode;
            if ($this->vlanId !== null) {
                $lines[] = 'lxc.net.' . $i . '.veth.vlan.id = ' . $this->vlanId;
            }
        }

        switch ($this->ipv4Mode) {
            case 'static':
                $lines[] = 'lxc.net.' . $i . '.ipv4.address = ' . $this->ipv4Address;
                if ($this->ipv4Gateway !== '') {
                    $lines[] = 'lxc.net.' . $i . '.ipv4.gateway = ' . $this->ipv4Gateway;
                }
                break;
            case 'none':
                $lines[] = 'lxc.net.' . $i . '.ipv4.address = none';
                break;
            case 'dhcp':
            default:
                // Omitting the address makes LXC use DHCP inside the container.
                break;
        }

        switch ($this->ipv6Mode) {
            case 'auto':
                $lines[] = 'lxc.net.' . $i . '.ipv6.address = auto';
                break;
            case 'static':
                $lines[] = 'lxc.net.' . $i . '.ipv6.address = ' . $this->ipv6Address;
                if ($this->ipv6Gateway !== '') {
                    $lines[] = 'lxc.net.' . $i . '.ipv6.gateway = ' . $this->ipv6Gateway;
                }
                break;
            case 'none':
            default:
                break;
        }

        return $lines;
    }

    /**
     * @param array<string, string> $values keyed by the part after `lxc.net.N.`
     */
    public static function fromValues(int $index, array $values): self
    {
        $nic = new self();
        $nic->index = $index;
        $nic->type = $values['type'] ?? 'veth';
        $nic->link = $values['link'] ?? 'br0';
        $rawName = $values['name'] ?? ('eth' . $index);
        $nic->name = ctype_digit($rawName) ? ('eth' . $index) : $rawName;
        $nic->hwaddr = strtoupper($values['hwaddr'] ?? '');
        $nic->macvlanMode = $values['macvlan.mode'] ?? 'bridge';
        $nic->vethMode = $values['veth.mode'] ?? 'bridge';
        $nic->flagsUp = ($values['flags'] ?? 'up') !== 'down';

        if (isset($values['veth.vlan.id']) && preg_match('/^\d+$/', $values['veth.vlan.id']) === 1) {
            $vlan = (int) $values['veth.vlan.id'];
            $nic->vlanId = ($vlan >= 1 && $vlan <= 4094) ? $vlan : null;
        }

        $ipv4 = $values['ipv4.address'] ?? '';
        $v4Lower = strtolower($ipv4);
        if ($v4Lower === 'none') {
            $nic->ipv4Mode = 'none';
        } else {
            $normalised = self::normalizeIpv4Cidr($ipv4);
            if ($ipv4 === '' || $normalised === null) {
                // Missing or corrupt raw config: fall back to DHCP, never emit garbage.
                $nic->ipv4Mode = 'dhcp';
            } else {
                $nic->ipv4Mode = 'static';
                $nic->ipv4Address = $normalised;
                $gateway = trim($values['ipv4.gateway'] ?? '');
                $nic->ipv4Gateway = self::isValidIp($gateway, true) ? $gateway : '';
            }
        }

        $ipv6 = $values['ipv6.address'] ?? '';
        $v6Lower = strtolower($ipv6);
        if ($ipv6 === '' || $v6Lower === 'none') {
            $nic->ipv6Mode = 'none';
        } elseif ($v6Lower === 'auto') {
            $nic->ipv6Mode = 'auto';
        } elseif (!self::isValidIpv6Cidr($ipv6)) {
            $nic->ipv6Mode = 'none';
        } else {
            $nic->ipv6Mode = 'static';
            $nic->ipv6Address = self::normalizeIpv6Cidr($ipv6) ?? $ipv6;
            $gateway = trim($values['ipv6.gateway'] ?? '');
            $nic->ipv6Gateway = self::isValidIp($gateway, false) ? $gateway : '';
        }

        return $nic;
    }

    /**
     * Validate and normalise a user supplied payload.
     *
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException
     */
    public static function fromRequest(array $data, int $index): self
    {
        $nic = new self();
        $nic->index = $index;

        $type = strtolower((string) ($data['type'] ?? 'veth'));
        $nic->type = in_array($type, ['veth', 'macvlan'], true) ? $type : 'veth';

        $link = (string) ($data['link'] ?? 'br0');
        if (preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $link) !== 1) {
            throw new InvalidArgumentException('Invalid network link/interface: ' . $link);
        }
        $nic->link = $link;

        $name = (string) ($data['name'] ?? ('eth' . $index));
        if (preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $name) !== 1 || ctype_digit($name)) {
            throw new InvalidArgumentException('Invalid in-container interface name: ' . $name);
        }
        $nic->name = $name;

        $mac = trim((string) ($data['hwaddr'] ?? ''));
        if ($mac !== '' && preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac) !== 1) {
            throw new InvalidArgumentException('Invalid MAC address: ' . $mac);
        }
        $nic->hwaddr = strtoupper($mac);

        $vlan = trim((string) ($data['vlanId'] ?? ''));
        if ($vlan !== '') {
            if (preg_match('/^\d{1,4}$/', $vlan) !== 1) {
                throw new InvalidArgumentException('Invalid VLAN id: ' . $vlan);
            }
            $id = (int) $vlan;
            if ($id < 1 || $id > 4094) {
                throw new InvalidArgumentException('VLAN id must be between 1 and 4094.');
            }
            $nic->vlanId = $id;
        }

        $macvlanMode = (string) ($data['macvlanMode'] ?? 'bridge');
        $nic->macvlanMode = in_array($macvlanMode, ['bridge', 'private', 'vepa', 'passthru'], true) ? $macvlanMode : 'bridge';

        $vethMode = (string) ($data['vethMode'] ?? 'bridge');
        $nic->vethMode = in_array($vethMode, ['bridge', 'router'], true) ? $vethMode : 'bridge';

        $nic->flagsUp = empty($data['flagsDown']);

        $ipv4Mode = (string) ($data['ipv4Mode'] ?? 'dhcp');
        $nic->ipv4Mode = in_array($ipv4Mode, ['dhcp', 'static', 'none'], true) ? $ipv4Mode : 'dhcp';

        if ($nic->ipv4Mode === 'static') {
            $address = self::normalizeIpv4Cidr((string) ($data['ipv4Address'] ?? ''));
            if ($address === null) {
                throw new InvalidArgumentException('Invalid IPv4 address (expected e.g. 192.168.1.50/24): ' . (string) ($data['ipv4Address'] ?? ''));
            }
            $nic->ipv4Address = $address;

            $gateway = trim((string) ($data['ipv4Gateway'] ?? ''));
            if ($gateway !== '' && filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException('Invalid IPv4 gateway: ' . $gateway);
            }
            $nic->ipv4Gateway = $gateway;
        }

        $ipv6Mode = (string) ($data['ipv6Mode'] ?? 'none');
        $nic->ipv6Mode = in_array($ipv6Mode, ['none', 'auto', 'static'], true) ? $ipv6Mode : 'none';

        if ($nic->ipv6Mode === 'static') {
            $address = self::normalizeIpv6Cidr((string) ($data['ipv6Address'] ?? ''));
            if ($address === null) {
                throw new InvalidArgumentException('Invalid IPv6 address (expected e.g. fd00::50/64): ' . (string) ($data['ipv6Address'] ?? ''));
            }
            $nic->ipv6Address = $address;

            $gateway = trim((string) ($data['ipv6Gateway'] ?? ''));
            if ($gateway !== '' && filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('Invalid IPv6 gateway: ' . $gateway);
            }
            $nic->ipv6Gateway = $gateway;
        }

        $nic->dns = self::normalizeDns($data['dns'] ?? []);

        return $nic;
    }

    /**
     * Normalise a user supplied DNS list (array or separated string).
     * Deduplicates, preserves order and caps at three servers.
     *
     * @param mixed $value
     * @return string[]
     */
    public static function normalizeDns($value, bool $strict = true): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                if ($strict) {
                    throw new InvalidArgumentException('Invalid DNS server: ' . $ip);
                }
                continue;
            }
            if (!in_array($ip, $out, true)) {
                $out[] = $ip;
            }
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    /**
     * Normalise an IPv4 address to CIDR notation. A missing prefix becomes /24.
     * Returns null when the value is not a valid IPv4 address or prefix.
     */
    public static function normalizeIpv4Cidr(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('#^([0-9]{1,3}(\.[0-9]{1,3}){3})(?:/([0-9]{1,2}))?$#', $value, $m) !== 1) {
            return null;
        }
        if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $prefix = 24;
        if (isset($m[3]) && $m[3] !== '') {
            $prefix = (int) $m[3];
            if ($prefix < 0 || $prefix > 32) {
                return null;
            }
        }

        return $m[1] . '/' . $prefix;
    }

    public static function isValidIpv4Cidr(string $value): bool
    {
        return self::normalizeIpv4Cidr($value) !== null;
    }

    public static function isValidIp(string $value, bool $ipv4): bool
    {
        return filter_var(trim($value), FILTER_VALIDATE_IP, $ipv4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Normalise an IPv6 address to CIDR. A missing prefix becomes /64.
     * Returns null for invalid addresses or prefixes.
     */
    public static function normalizeIpv6Cidr(string $value): ?string
    {
        $value = trim($value);
        $parts = explode('/', $value, 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }
        $prefix = 64;
        if (isset($parts[1])) {
            if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1) {
                return null;
            }
            $prefix = (int) $parts[1];
            if ($prefix < 0 || $prefix > 128) {
                return null;
            }
        }

        return $parts[0] . '/' . $prefix;
    }

    public static function isValidIpv6Cidr(string $value): bool
    {
        return self::normalizeIpv6Cidr($value) !== null;
    }
}

/**
 * Host interface discovery and container network block (de)serialisation.
 */
final class Network
{
    /**
     * Interfaces that are safe to attach a container to.
     *
     * @return array<int, array{name:string,kind:string,state:string,mac:string,addresses:string[]}>
     */
    public static function availableLinks(): array
    {
        $result = [];
        $entries = @scandir('/sys/class/net') ?: [];

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (preg_match('/^(lo|docker|veth|virbr|vnet|tap|tun|wg|zt|tailscale|dummy|kube|br-)/i', $name) === 1) {
                continue;
            }

            // Only ethernet-like interfaces can be used as a container link.
            $type = trim((string) @file_get_contents('/sys/class/net/' . $name . '/type'));
            if ($type !== '1') {
                continue;
            }

            $kind = 'ethernet';
            if (is_dir('/sys/class/net/' . $name . '/bridge')) {
                $kind = 'bridge';
            } elseif (is_dir('/sys/class/net/' . $name . '/bonding')) {
                $kind = 'bond';
            } elseif (strpos($name, '.') !== false) {
                $kind = 'vlan';
            } elseif (is_dir('/sys/class/net/' . $name . '/wireless') || is_dir('/sys/class/net/' . $name . '/phy80211')) {
                $kind = 'wireless';
            } elseif (strpos($name, 'vhost') === 0) {
                $kind = 'macvlan';
            }

            $result[] = [
                'name' => $name,
                'kind' => $kind,
                'state' => trim((string) @file_get_contents('/sys/class/net/' . $name . '/operstate')),
                'mac' => trim((string) @file_get_contents('/sys/class/net/' . $name . '/address')),
                'addresses' => self::interfaceAddresses($name),
            ];
        }

        $order = ['bridge' => 0, 'bond' => 1, 'vlan' => 2, 'ethernet' => 3, 'macvlan' => 4, 'wireless' => 5];
        usort($result, static function (array $a, array $b) use ($order): int {
            $cmp = ($order[$a['kind']] ?? 9) <=> ($order[$b['kind']] ?? 9);

            return $cmp !== 0 ? $cmp : strnatcmp($a['name'], $b['name']);
        });

        return $result;
    }

    /**
     * @return string[]
     */
    private static function interfaceAddresses(string $name): array
    {
        $addresses = [];
        $output = LxcPaths::capture(['ip', '-o', 'addr', 'show', 'dev', $name]);
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/\binet6?\s+([0-9a-fA-F:.\/]+)/', $line, $m) === 1) {
                $addresses[] = $m[1];
            }
        }

        return $addresses;
    }

    /**
     * Build the interface list from the container configuration, migrating the
     * legacy (unmarked) `lxc.net.*` lines when needed.
     *
     * @return NetworkInterface[]
     */
    public static function read(ContainerConfig $config): array
    {
        $values = self::collectNetValues($config->lines());

        // Remember macvlan VLAN tags: they are encoded in the link name and
        // would otherwise be lost when the config is read back.
        $macvlanVlans = [];
        $dnsByIndex = [];
        foreach ($config->lines() as $line) {
            $trimmed = trim($line);
            if (preg_match('/^#@macvlan_vlan\s+(\d+)\s+(\d{1,4})$/', $trimmed, $m) === 1) {
                $macvlanVlans[(int) $m[1]] = (int) $m[2];
            }
            if (preg_match('/^#@dns\s+(\d+)\s+(\S+)$/', $trimmed, $m) === 1) {
                $ip = trim($m[2]);
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $dnsByIndex[(int) $m[1]][] = $ip;
                }
            }
        }

        if ($values === []) {
            return [self::defaultInterface()];
        }

        ksort($values);
        $interfaces = [];
        foreach ($values as $index => $entries) {
            $nic = NetworkInterface::fromValues((int) $index, $entries);
            if (isset($macvlanVlans[(int) $index])) {
                $nic->vlanId = $macvlanVlans[(int) $index];
                $suffix = '.' . $nic->vlanId;
                if (substr($nic->link, -strlen($suffix)) === $suffix) {
                    $nic->link = substr($nic->link, 0, -strlen($suffix));
                }
            }
            if (isset($dnsByIndex[(int) $index])) {
                $nic->dns = NetworkInterface::normalizeDns($dnsByIndex[(int) $index], false);
            }
            $interfaces[] = $nic;
        }

        return $interfaces;
    }

    /**
     * @param string[] $lines
     * @return array<int, array<string, string>>
     */
    private static function collectNetValues(array $lines): array
    {
        $values = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*lxc\.net\.(\d+)\.([A-Za-z0-9_.]+)\s*=\s*(.*?)\s*$/', $line, $m) !== 1) {
                continue;
            }
            $values[(int) $m[1]][$m[2]] = $m[3];
        }

        return $values;
    }

    public static function defaultInterface(): NetworkInterface
    {
        $nic = new NetworkInterface();
        $defaultLink = LxcPaths::readKeyValue(LxcPaths::PLUGIN_DIR . '/default.conf', 'lxc.net.0.link', 'br0');
        if (is_string($defaultLink) && preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $defaultLink) === 1) {
            $nic->link = $defaultLink;
        }

        return $nic;
    }

    /**
     * Persist the interface list and drop the legacy unmarked net lines.
     *
     * @param NetworkInterface[] $interfaces
     */
    public static function write(ContainerConfig $config, array $interfaces): bool
    {
        if ($interfaces === []) {
            $interfaces = [self::defaultInterface()];
        }

        // One time migration: remove every legacy lxc.net.* line so the block
        // below is the single source of truth. DNS metadata is rewritten below.
        $config->removeMatching('/^\s*lxc\.net\.\d+\./');
        $config->removeMatching('/^\s*#@dns\s/');

        $body = [];
        foreach (array_values($interfaces) as $index => $nic) {
            $nic->index = $index;
            if ($index > 0) {
                $body[] = '';
            }
            if ($nic->type === 'macvlan' && $nic->vlanId !== null) {
                $body[] = '#@macvlan_vlan ' . $index . ' ' . $nic->vlanId;
            }
            foreach ($nic->dns as $ip) {
                $body[] = '#@dns ' . $index . ' ' . $ip;
            }
            foreach ($nic->toLines() as $line) {
                $body[] = $line;
            }
        }

        $config->setBlock(ContainerConfig::TAG_NETWORK, $body);

        return $config->save();
    }

    /**
     * Human readable summary used by the container list.
     */
    public static function summarize(NetworkInterface $nic): string
    {
        $parts = [$nic->type, $nic->link];
        if ($nic->vlanId !== null) {
            $parts[] = 'vlan ' . $nic->vlanId;
        }
        if ($nic->ipv4Mode === 'static') {
            $parts[] = $nic->ipv4Address;
        } elseif ($nic->ipv4Mode === 'dhcp') {
            $parts[] = 'dhcp';
        }

        return implode(' · ', $parts);
    }
}
