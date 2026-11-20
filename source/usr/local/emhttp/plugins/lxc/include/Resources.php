<?php
require_once __DIR__ . '/ContainerConfig.php';

/**
 * Resource limits (memory, swap, CPU pinning, CPU weight) stored as plain
 * `lxc.cgroup2.*` keys so they stay visible in the raw config editor.
 */
final class Resources
{
    /**
     * @return array{memoryMiB:string,swapMiB:string,cpuset:string,cpuWeight:string}
     */
    public static function read(ContainerConfig $config): array
    {
        $memory = (string) ($config->get('lxc.cgroup2.memory.max')
            ?? $config->get('lxc.cgroup.memory.limit_in_bytes')
            ?? '');
        $swap = (string) ($config->get('lxc.cgroup2.memory.swap.max')
            ?? $config->get('lxc.cgroup.memory.memsw.limit_in_bytes')
            ?? '');
        $cpuset = (string) ($config->get('lxc.cgroup2.cpuset.cpus')
            ?? $config->get('lxc.cgroup.cpuset.cpus')
            ?? '');
        $weight = (string) ($config->get('lxc.cgroup2.cpu.weight') ?? '');

        return [
            'memoryMiB' => self::bytesToMiBString($memory),
            'swapMiB' => self::bytesToMiBString($swap),
            'cpuset' => $cpuset,
            'cpuWeight' => $weight,
        ];
    }

    /**
     * @param array{memoryMiB?:string,swapMiB?:string,cpuset?:string,cpuWeight?:string} $values
     * @throws InvalidArgumentException
     */
    public static function write(ContainerConfig $config, array $values): void
    {
        $memory = trim((string) ($values['memoryMiB'] ?? ''));
        $swap = trim((string) ($values['swapMiB'] ?? ''));
        $cpuset = trim((string) ($values['cpuset'] ?? ''));
        $weight = trim((string) ($values['cpuWeight'] ?? ''));

        $config->set('lxc.cgroup2.memory.max', $memory === '' ? null : self::mibToBytes($memory) . '');
        $config->set('lxc.cgroup2.memory.swap.max', $swap === '' ? null : self::mibToBytes($swap) . '');

        if ($cpuset !== '') {
            if (preg_match('/^[0-9,-]+$/', $cpuset) !== 1) {
                throw new InvalidArgumentException('Invalid CPU pinning value, expected e.g. 0-3 or 0,2,4');
            }
            $config->set('lxc.cgroup2.cpuset.cpus', $cpuset);
        } else {
            $config->set('lxc.cgroup2.cpuset.cpus', null);
        }

        if ($weight !== '') {
            if (preg_match('/^\d{1,4}$/', $weight) !== 1 || (int) $weight < 1 || (int) $weight > 10000) {
                throw new InvalidArgumentException('CPU weight must be between 1 and 10000');
            }
            $config->set('lxc.cgroup2.cpu.weight', $weight);
        } else {
            $config->set('lxc.cgroup2.cpu.weight', null);
        }

        // Remove legacy cgroup v1 keys that would otherwise conflict.
        $config->set('lxc.cgroup.memory.limit_in_bytes', null);
        $config->set('lxc.cgroup.memory.memsw.limit_in_bytes', null);
        $config->set('lxc.cgroup.cpuset.cpus', null);

        $config->save();
    }

    public static function hostMemoryMiB(): int
    {
        $info = @file_get_contents('/proc/meminfo') ?: '';
        if (preg_match('/^MemTotal:\s+(\d+) kB/m', $info, $m) === 1) {
            return (int) floor(((int) $m[1]) / 1024);
        }

        return 0;
    }

    /**
     * Host CPU topology grouped into physical cores.
     *
     * @return array{online:string,cores:string[],threads:array<string,string>}
     */
    public static function hostCpus(): array
    {
        $online = trim((string) @file_get_contents('/sys/devices/system/cpu/online'));
        if ($online === '') {
            $online = '0-' . (max(0, (int) LxcPaths::capture(['nproc', '--all']) - 1));
        }

        $cores = [];
        $threads = [];
        foreach (glob('/sys/devices/system/cpu/cpu[0-9]*/topology/thread_siblings_list') ?: [] as $file) {
            $value = trim((string) @file_get_contents($file));
            if ($value === '') {
                continue;
            }
            $cores[] = $value;
            $cpu = preg_replace('#.*/cpu([0-9]+)/.*#', '$1', $file);
            $threads[$cpu] = $value;
        }

        $cores = array_values(array_unique($cores));
        sort($cores, SORT_NATURAL);

        return ['online' => $online, 'cores' => $cores, 'threads' => $threads];
    }

    private static function mibToBytes(string $value): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidArgumentException('Memory limit must be a whole number of MiB');
        }

        return (int) $value * 1024 * 1024;
    }

    private static function bytesToMiBString(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'max') {
            return '';
        }

        if (preg_match('/^(\d+)\s*([kmgti]?)b?$/i', $value, $m) !== 1) {
            return '';
        }

        $bytes = (int) $m[1];
        switch (strtolower($m[2])) {
            case 'k':
                $bytes *= 1024;
                break;
            case 'm':
                $bytes *= 1024 * 1024;
                break;
            case 'g':
                $bytes *= 1024 * 1024 * 1024;
                break;
            case 't':
                $bytes *= 1024 * 1024 * 1024 * 1024;
                break;
        }

        return (string) max(1, (int) round($bytes / 1024 / 1024));
    }
}
