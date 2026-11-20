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

    public bool $flagsUp = true;

    /**
     * @return string[]
     */
    public function toLines(): array
    {
        $i = $this->index;
        $lines = [];
        $lines[] = 'lxc.net.' . $i . '.type = ' . $this->type;
        $lines[] = 'lxc.net.' . $i . '.flags = ' . ($this->flagsUp ? 'up' : 'down');
        $lines[] = 'lxc.net.' . $i . '.link = ' . $this->link;
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
        $nic->name = $values['name'] ?? ('eth' . $index);
        $nic->hwaddr = strtoupper($values['hwaddr'] ?? '');
        $nic->macvlanMode = $values['macvlan.mode'] ?? 'bridge';
        $nic->vethMode = $values['veth.mode'] ?? 'bridge';
        $nic->flagsUp = ($values['flags'] ?? 'up') !== 'down';

        if (isset($values['veth.vlan.id']) && preg_match('/^\d+$/', $values['veth.vlan.id']) === 1) {
            $vlan = (int) $values['veth.vlan.id'];
            $nic->vlanId = ($vlan >= 1 && $vlan <= 4094) ? $vlan : null;
        }

        $ipv4 = $values['ipv4.address'] ?? '';
        if ($ipv4 === '') {
            $nic->ipv4Mode = 'dhcp';
        } elseif (strtolower($ipv4) === 'none') {
            $nic->ipv4Mode = 'none';
        } else {
            $nic->ipv4Mode = 'static';
            $nic->ipv4Address = $ipv4;
            $nic->ipv4Gateway = $values['ipv4.gateway'] ?? '';
        }

        $ipv6 = $values['ipv6.address'] ?? '';
        if ($ipv6 === '') {
            $nic->ipv6Mode = 'none';
        } elseif (strtolower($ipv6) === 'auto') {
            $nic->ipv6Mode = 'auto';
        } elseif (strtolower($ipv6) === 'none') {
            $nic->ipv6Mode = 'none';
        } else {
            $nic->ipv6Mode = 'static';
            $nic->ipv6Address = $ipv6;
            $nic->ipv6Gateway = $values['ipv6.gateway'] ?? '';
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
        if (preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $name) !== 1) {
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
            $address = trim((string) ($data['ipv4Address'] ?? ''));
            if (!self::isValidIpv4Cidr($address)) {
                throw new InvalidArgumentException('Invalid IPv4 address (expected e.g. 192.168.1.50/24): ' . $address);
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
            $address = trim((string) ($data['ipv6Address'] ?? ''));
            if (!self::isValidIpv6Cidr($address)) {
                throw new InvalidArgumentException('Invalid IPv6 address (expected e.g. fd00::50/64): ' . $address);
            }
            $nic->ipv6Address = $address;

            $gateway = trim((string) ($data['ipv6Gateway'] ?? ''));
            if ($gateway !== '' && filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('Invalid IPv6 gateway: ' . $gateway);
            }
            $nic->ipv6Gateway = $gateway;
        }

        return $nic;
    }

    public static function isValidIpv4Cidr(string $value): bool
    {
        if (preg_match('#^([0-9]{1,3}(\.[0-9]{1,3}){3})(/([0-9]{1,2}))?$#', $value, $m) !== 1) {
            return false;
        }
        if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if (isset($m[3]) && $m[3] !== '') {
            $prefix = (int) $m[4];
            if ($prefix < 0 || $prefix > 32) {
                return false;
            }
        }

        return true;
    }

    public static function isValidIpv6Cidr(string $value): bool
    {
        $parts = explode('/', $value, 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }
        if (isset($parts[1])) {
            if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1) {
                return false;
            }
            $prefix = (int) $parts[1];
            if ($prefix < 0 || $prefix > 128) {
                return false;
            }
        }

        return true;
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

        if ($values === []) {
            return [self::defaultInterface()];
        }

        ksort($values);
        $interfaces = [];
        foreach ($values as $index => $entries) {
            $interfaces[] = NetworkInterface::fromValues((int) $index, $entries);
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
    public static function write(ContainerConfig $config, array $interfaces): void
    {
        if ($interfaces === []) {
            $interfaces = [self::defaultInterface()];
        }

        // One time migration: remove every legacy lxc.net.* line so the block
        // below is the single source of truth.
        $config->removeMatching('/^\s*lxc\.net\.\d+\./');

        $body = [];
        foreach (array_values($interfaces) as $index => $nic) {
            $nic->index = $index;
            if ($index > 0) {
                $body[] = '';
            }
            foreach ($nic->toLines() as $line) {
                $body[] = $line;
            }
        }

        $config->setBlock(ContainerConfig::TAG_NETWORK, $body);
        $config->save();
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
