<?php
require_once __DIR__ . '/ContainerConfig.php';

/**
 * Host device discovery (GPU / USB / misc) and container passthrough rules.
 *
 * Selection is stored as plugin owned metadata comments inside the DEVICES
 * block, the actual `lxc.mount.entry` / `lxc.cgroup2.devices.allow` lines are
 * generated from it. That makes USB passthrough survive a re-plug or reboot:
 * `sync()` re-resolves the current bus/device numbers before a container starts.
 */
final class Devices
{
    /** Metadata prefixes inside the managed block. */
    private const META_GPU = '#@gpu ';
    private const META_USB = '#@usb ';
    private const META_DEV = '#@dev ';

    /**
     * @return array<int, array{id:string,name:string,driver:string,pci:string,vendor:string,devices:string[],nvidia:bool}>
     */
    public static function listGpus(): array
    {
        $groups = [];

        foreach (glob('/sys/class/drm/*') ?: [] as $entry) {
            $base = basename($entry);
            if (preg_match('/^(card\d+|renderD\d+|controlD\d+)$/', $base) !== 1) {
                continue;
            }

            $deviceLink = $entry . '/device';
            if (!is_dir($deviceLink) && !is_link($deviceLink)) {
                continue;
            }

            $pci = basename((string) realpath($deviceLink));
            if ($pci === '' || $pci === '.') {
                continue;
            }

            if (!isset($groups[$pci])) {
                $driver = basename((string) realpath($deviceLink . '/driver'));
                $groups[$pci] = [
                    'id' => $pci,
                    'name' => self::pciName($pci, $deviceLink),
                    'driver' => $driver,
                    'pci' => $pci,
                    'vendor' => self::pciVendor($deviceLink),
                    'devices' => [],
                    'nvidia' => stripos($driver, 'nvidia') !== false,
                ];
            }

            $node = '/dev/dri/' . $base;
            if (!in_array($node, $groups[$pci]['devices'], true)) {
                $groups[$pci]['devices'][] = $node;
            }
        }

        // NVIDIA GPUs expose extra nodes outside of /dev/dri.
        foreach ($groups as $pci => $group) {
            if (!$group['nvidia']) {
                continue;
            }
            foreach (['/dev/nvidiactl', '/dev/nvidia-modeset', '/dev/nvidia-uvm', '/dev/nvidia-uvm-tools'] as $node) {
                if (file_exists($node)) {
                    $groups[$pci]['devices'][] = $node;
                }
            }
            foreach (glob('/dev/nvidia[0-9]*') ?: [] as $node) {
                if (file_exists($node)) {
                    $groups[$pci]['devices'][] = $node;
                }
            }
        }

        // Sort devices for a stable output.
        foreach ($groups as &$group) {
            sort($group['devices']);
        }
        unset($group);

        return array_values($groups);
    }

    private static function pciName(string $pci, string $deviceLink): string
    {
        $uevent = @file_get_contents($deviceLink . '/uevent') ?: '';
        $vendor = '';
        $device = '';
        foreach (explode("\n", $uevent) as $line) {
            if (strpos($line, 'PCI_ID=') === 0) {
                $parts = explode(':', substr($line, 7));
                $vendor = $parts[0] ?? '';
                $device = $parts[1] ?? '';
            }
        }

        $description = '';
        if (is_executable('/usr/sbin/lspci') || is_executable('/sbin/lspci') || is_executable('/usr/bin/lspci')) {
            $description = trim(LxcPaths::capture(['lspci', '-s', $pci]));
            if (strpos($description, ':') !== false) {
                $description = trim(substr($description, strpos($description, ':') + 1));
            }
        }

        if ($description !== '') {
            return $description;
        }

        $vendorName = self::pciVendorName($vendor);

        return trim($vendorName . ' ' . ($device !== '' ? '[' . $device . ']' : '')) ?: $pci;
    }

    private static function pciVendor(string $deviceLink): string
    {
        $uevent = @file_get_contents($deviceLink . '/uevent') ?: '';
        foreach (explode("\n", $uevent) as $line) {
            if (strpos($line, 'PCI_ID=') === 0) {
                return strtolower(explode(':', substr($line, 7))[0] ?? '');
            }
        }

        return '';
    }

    private static function pciVendorName(string $vendorId): string
    {
        $map = [
            '8086' => 'Intel',
            '1002' => 'AMD/ATI',
            '10de' => 'NVIDIA',
            '1a03' => 'ASPEED',
            '15ad' => 'VMware',
            '1234' => 'QEMU',
        ];

        return $map[strtolower($vendorId)] ?? ('[' . $vendorId . ']');
    }

    /**
     * @return array<int, array{id:string,vendor:string,product:string,name:string,bus:string,dev:string,serial:string}>
     */
    public static function listUsb(): array
    {
        $devices = [];
        $entries = glob('/sys/bus/usb/devices/*') ?: [];

        foreach ($entries as $entry) {
            $vendor = trim((string) @file_get_contents($entry . '/idVendor'));
            $product = trim((string) @file_get_contents($entry . '/idProduct'));
            if ($vendor === '' || $product === '') {
                continue;
            }

            $bus = trim((string) @file_get_contents($entry . '/busnum'));
            $dev = trim((string) @file_get_contents($entry . '/devnum'));
            if ($bus === '' || $dev === '') {
                continue;
            }

            $manufacturer = trim((string) @file_get_contents($entry . '/manufacturer'));
            $productName = trim((string) @file_get_contents($entry . '/product'));
            $serial = trim((string) @file_get_contents($entry . '/serial'));

            $name = trim($manufacturer . ' ' . $productName);
            if ($name === '') {
                $name = strtoupper($vendor) . ':' . strtoupper($product);
            }

            $devices[] = [
                'id' => strtolower($vendor) . ':' . strtolower($product),
                'vendor' => strtolower($vendor),
                'product' => strtolower($product),
                'name' => $name,
                'bus' => str_pad($bus, 3, '0', STR_PAD_LEFT),
                'dev' => str_pad($dev, 3, '0', STR_PAD_LEFT),
                'serial' => $serial,
            ];
        }

        usort($devices, static function (array $a, array $b): int {
            return strnatcmp($a['name'], $b['name']);
        });

        return $devices;
    }

    /**
     * Well known device nodes that are useful inside a container.
     *
     * @return array<int, array{path:string,label:string,present:bool}>
     */
    public static function listMisc(): array
    {
        $candidates = [
            '/dev/kvm' => 'KVM (virtualisation)',
            '/dev/fuse' => 'FUSE',
            '/dev/net/tun' => 'TUN/TAP',
            '/dev/vhost-net' => 'vhost-net',
            '/dev/snd' => 'Audio (ALSA)',
            '/dev/input' => 'Input devices',
            '/dev/video0' => 'Webcam / capture',
            '/dev/dvb' => 'DVB tuner',
            '/dev/ttyUSB0' => 'USB serial (ttyUSB0)',
            '/dev/ttyACM0' => 'USB CDC (ttyACM0)',
            '/dev/i2c-1' => 'I2C bus 1',
            '/dev/gpiochip0' => 'GPIO',
        ];

        $result = [];
        foreach ($candidates as $path => $label) {
            $result[] = [
                'path' => $path,
                'label' => $label,
                'present' => file_exists($path),
            ];
        }

        return $result;
    }

    /**
     * @return array{gpus:string[],usb:string[],misc:string[]}
     */
    public static function read(ContainerConfig $config): array
    {
        $selection = ['gpus' => [], 'usb' => [], 'misc' => []];

        foreach ($config->block(ContainerConfig::TAG_DEVICES) as $line) {
            $trimmed = trim($line);
            if (strpos($trimmed, self::META_GPU) === 0) {
                $selection['gpus'][] = trim(substr($trimmed, strlen(self::META_GPU)));
            } elseif (strpos($trimmed, self::META_USB) === 0) {
                $selection['usb'][] = trim(substr($trimmed, strlen(self::META_USB)));
            } elseif (strpos($trimmed, self::META_DEV) === 0) {
                $selection['misc'][] = trim(substr($trimmed, strlen(self::META_DEV)));
            }
        }

        $selection['gpus'] = array_values(array_unique(array_filter($selection['gpus'])));
        $selection['usb'] = array_values(array_unique(array_filter($selection['usb'])));
        $selection['misc'] = array_values(array_unique(array_filter($selection['misc'])));

        return $selection;
    }

    /**
     * Persist the selection and regenerate the passthrough rules.
     *
     * @param array{gpus?:string[],usb?:string[],misc?:string[]} $selection
     */
    public static function write(ContainerConfig $config, array $selection): void
    {
        $gpus = self::sanitizeGpuIds($selection['gpus'] ?? []);
        $usb = self::sanitizeUsbIds($selection['usb'] ?? []);
        $misc = self::sanitizePaths($selection['misc'] ?? []);

        $config->setBlock(ContainerConfig::TAG_DEVICES, self::buildBlock($gpus, $usb, $misc));
        $config->save();
    }

    /**
     * Re-resolve USB bus/device numbers and rewrite the DEVICES block.
     * Returns true when the container configuration changed.
     */
    public static function sync(string $name): bool
    {
        if (!LxcPaths::containerExists($name)) {
            return false;
        }

        $config = new ContainerConfig($name);
        $selection = self::read($config);
        if ($selection['usb'] === [] && $selection['gpus'] === [] && $selection['misc'] === []) {
            return false;
        }

        $before = $config->block(ContainerConfig::TAG_DEVICES);
        $after = self::buildBlock($selection['gpus'], $selection['usb'], $selection['misc']);

        if ($before === $after) {
            return false;
        }

        $config->setBlock(ContainerConfig::TAG_DEVICES, $after);

        return $config->save();
    }

    /**
     * @param string[] $gpus
     * @param string[] $usb
     * @param string[] $misc
     * @return string[]
     */
    private static function buildBlock(array $gpus, array $usb, array $misc): array
    {
        $availableGpus = [];
        foreach (self::listGpus() as $gpu) {
            $availableGpus[$gpu['id']] = $gpu;
        }

        $allow = [];
        $mounts = [];
        $body = [];

        foreach ($gpus as $gpuId) {
            $body[] = self::META_GPU . $gpuId;
            if (!isset($availableGpus[$gpuId])) {
                continue;
            }
            foreach ($availableGpus[$gpuId]['devices'] as $node) {
                self::appendDeviceRules($node, $allow, $mounts);
            }
        }

        $availableUsb = [];
        foreach (self::listUsb() as $device) {
            $availableUsb[$device['id']] = $device;
        }

        foreach ($usb as $usbId) {
            $body[] = self::META_USB . $usbId;
            if (!isset($availableUsb[$usbId])) {
                continue;
            }
            $device = $availableUsb[$usbId];
            $source = '/dev/bus/usb/' . $device['bus'] . '/' . $device['dev'];
            $target = 'dev/bus/usb/' . $device['bus'] . '/' . $device['dev'];
            $mounts[] = 'lxc.mount.entry = ' . $source . ' ' . $target . ' none bind,optional,create=file';
            // The minor number changes when the device is re-plugged, so allow
            // the whole USB char major. Only the mounted node is visible inside
            // the container, other devices stay unreachable.
            $allow['c 189'] = 'lxc.cgroup2.devices.allow = c 189:* rwm';
        }

        foreach ($misc as $path) {
            $body[] = self::META_DEV . $path;
            if (!file_exists($path)) {
                continue;
            }
            self::appendDeviceRules($path, $allow, $mounts);
        }

        if ($allow !== []) {
            ksort($allow);
            $body = array_merge($body, array_values($allow));
        }

        if ($mounts !== []) {
            $body = array_merge($body, array_values(array_unique($mounts)));
        }

        return $body;
    }

    /**
     * @param array<string,string> $allow
     * @param string[] $mounts
     */
    private static function appendDeviceRules(string $path, array &$allow, array &$mounts): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_dir($path)) {
            $mounts[] = 'lxc.mount.entry = ' . $path . ' ' . ltrim($path, '/') . ' none bind,optional,create=dir';
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $child = rtrim($path, '/') . '/' . $entry;
                if (is_dir($child)) {
                    self::appendDeviceRules($child, $allow, $mounts);
                    continue;
                }
                $rule = self::charDeviceRule($child);
                if ($rule !== null) {
                    $allow[$rule[0]] = $rule[1];
                }
            }

            return;
        }

        $mounts[] = 'lxc.mount.entry = ' . $path . ' ' . ltrim($path, '/') . ' none bind,optional,create=file';
        $rule = self::charDeviceRule($path);
        if ($rule !== null) {
            $allow[$rule[0]] = $rule[1];
        }
    }

    /**
     * @return array{0:string,1:string}|null [key, config line]
     */
    private static function charDeviceRule(string $path): ?array
    {
        $stat = @stat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0020000) {
            return null;
        }

        $major = ($stat['rdev'] >> 8) & 0xfff;
        $minor = ($stat['rdev'] & 0xff) | (($stat['rdev'] >> 12) & 0xfffff00);
        $key = 'c ' . $major . ':' . $minor;

        return [$key, 'lxc.cgroup2.devices.allow = c ' . $major . ':' . $minor . ' rwm'];
    }

    /**
     * @param mixed[] $ids
     * @return string[]
     */
    private static function sanitizeGpuIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if (preg_match('/^[0-9a-fA-F]{4}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}\.[0-9a-fA-F]$/', $id) === 1) {
                $clean[] = strtolower($id);
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param mixed[] $ids
     * @return string[]
     */
    private static function sanitizeUsbIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = strtolower(trim((string) $id));
            if (preg_match('/^[0-9a-f]{4}:[0-9a-f]{4}$/', $id) === 1) {
                $clean[] = $id;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param mixed[] $paths
     * @return string[]
     */
    private static function sanitizePaths(array $paths): array
    {
        $clean = [];
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '' || $path[0] !== '/') {
                continue;
            }
            if (strpos($path, '..') !== false) {
                continue;
            }
            if (preg_match('#^/dev/[A-Za-z0-9_./-]+$#', $path) !== 1) {
                continue;
            }
            $clean[] = $path;
        }

        return array_values(array_unique($clean));
    }

    /**
     * udev rules giving every USB device selected by at least one container a
     * stable symlink so that containers keep working after a re-plug.
     */
    public static function writeUdevRules(): void
    {
        $selected = self::selectedUsbIds();

        $rules = [
            '# Generated by the LXC v2 plugin - do not edit',
            'SUBSYSTEM=="usb", ACTION=="add", RUN+="/usr/local/emhttp/plugins/lxc/lxc_scripts/lxc-device-sync"',
        ];

        foreach (self::listUsb() as $device) {
            if (!in_array($device['id'], $selected, true)) {
                continue;
            }
            $rules[] = sprintf(
                'SUBSYSTEM=="usb", ATTR{idVendor}=="%s", ATTR{idProduct}=="%s", SYMLINK+="lxcv2-usb/%s_%s"',
                $device['vendor'],
                $device['product'],
                $device['vendor'],
                $device['product']
            );
        }

        $file = '/etc/udev/rules.d/99-lxcv2-usb.rules';
        if (@file_put_contents($file, implode("\n", $rules) . "\n") === false) {
            return;
        }
        @chmod($file, 0644);
        LxcPaths::run(['udevadm', 'control', '--reload-rules']);
        LxcPaths::run(['udevadm', 'trigger', '--subsystem-match=usb']);
    }

    /**
     * USB ids referenced by any container.
     *
     * @return string[]
     */
    public static function selectedUsbIds(): array
    {
        $ids = [];
        foreach (glob(LxcPaths::dataPath() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (!LxcPaths::isValidContainerName($name) || $name === 'cache' || $name === 'custom-icons') {
                continue;
            }
            if (!is_file($dir . '/config')) {
                continue;
            }
            try {
                $selection = self::read(new ContainerConfig($name));
            } catch (Throwable $e) {
                continue;
            }
            foreach ($selection['usb'] as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }
}
