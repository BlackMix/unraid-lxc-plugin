<?php
require_once __DIR__ . '/ContainerConfig.php';
require_once __DIR__ . '/Network.php';
require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/Settings.php';

/**
 * Proxmox-compatible LXC backups (vzdump format).
 *
 * Produces <backup_path>/proxmox/<container>/vzdump-lxc-<vmid>-<stamp>.tar.zst
 * (or .tar.gz when zstd is unavailable) containing ./etc/vzdump/pct.conf plus
 * the container rootfs at the top level. Restore on Proxmox with:
 *   pct restore <vmid> <file> --rootfs <storage>:<size>
 *
 * This is EXPERIMENTAL: the archive structure follows vzdump, but a real
 * `pct restore` validation on the target Proxmox version is still required.
 */
final class ProxmoxBackup
{
    private const KEY_FILE = '/boot/config/plugins/lxc/.download_key';
    private const TOKEN_TTL = 300;
    private const FILE_RE = '/^vzdump-lxc-(\d+)-(\d{4}_\d{2}_\d{2}-\d{2}_\d{2}_\d{2})\.tar\.(zst|gz)$/';

    public static function statusPath(string $jobId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9]/', '', $jobId) ?: 'unknown';

        // Under the web root so the browser can poll the JSON file directly,
        // with no PHP session lock and no second request to the worker.
        return dirname(__DIR__) . '/status/lxc-proxmox-' . $safe . '.json';
    }

    /**
     * @param array<string,mixed> $extra
     */
    public static function writeStatus(string $jobId, string $stage, int $percent, int $started, int $total = 0, int $done = 0, array $extra = []): void
    {
        if ($jobId === '') {
            return;
        }
        $path = self::statusPath($jobId);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $data = array_merge([
            'stage' => $stage,
            'percent' => max(0, min(100, $percent)),
            'started' => $started,
            'total' => $total,
            'done' => $done,
            'updated' => time(),
        ], $extra);
        @file_put_contents($path, json_encode($data));
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function readStatus(string $jobId): ?array
    {
        if ($jobId === '' || preg_match('/^[A-Za-z0-9]+$/', $jobId) !== 1) {
            return null;
        }
        $path = self::statusPath($jobId);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param string[] $messages
     * @return array{ok:bool,messages:string[]}
     */
    private static function failJob(string $jobId, int $started, array $messages): array
    {
        self::writeStatus($jobId, 'error', 100, $started, 0, 0, ['error' => implode(' ', $messages)]);

        return ['ok' => false, 'messages' => $messages];
    }

    public static function clearStatus(string $jobId): void
    {
        if ($jobId !== '' && preg_match('/^[A-Za-z0-9]+$/', $jobId) === 1) {
            @unlink(self::statusPath($jobId));
        }
    }

    /**
     * @return array{ok:bool,messages:string[],file?:string}
     */
    public static function create(string $name, int $vmid, string $jobId = ''): array
    {
        $started = time();
        self::writeStatus($jobId, 'preparing', 0, $started);
        $messages = [];
        if (!LxcPaths::isValidContainerName($name)) {
            return self::failJob($jobId, $started, ['Invalid container name.']);
        }
        if ($vmid < 100 || $vmid > 999999999) {
            return self::failJob($jobId, $started, ['VMID must be between 100 and 999999999.']);
        }
        $settings = new Settings();
        $backupPath = self::backupPath();

        $config = new ContainerConfig($name);
        if (!$config->exists()) {
            return self::failJob($jobId, $started, ['Container configuration not found.']);
        }
        $nics = Network::read($config);
        foreach ($nics as $nic) {
            if ($nic->type === 'macvlan' || $nic->vethMode === 'router') {
                return self::failJob($jobId, $started, [
                    'Proxmox backup is not supported for macvlan or veth router mode interfaces (interface ' . $nic->name . ').',
                ]);
            }
        }

        self::writeStatus($jobId, 'validating', 1, $started, 0, 0);
        $rootfs = self::resolveRootfs($name, $messages);
        if ($rootfs === null) {
            return self::failJob($jobId, $started, $messages);
        }
        self::writeStatus($jobId, 'staging', 3, $started, 0, 0);

        $targetDir = self::dir($name);
        if (!self::ensureDir($targetDir, $messages)) {
            return self::failJob($jobId, $started, $messages);
        }

        $stamp = gmdate('Y_m_d-H_i_s');
        $base = 'vzdump-lxc-' . $vmid . '-' . $stamp;
        // Zstd with all available threads when present; gzip fallback.
        $compression = self::hasZstd() ? 'zst' : 'gz';
        $final = $targetDir . '/' . $base . '.tar.' . $compression;
        $part = $targetDir . '/' . $base . '.tar.' . $compression . '.part';
        $staging = $backupPath . '/.proxmox-stage-' . $name . '-' . getmypid();

        $wasRunning = trim((string) LxcPaths::capture(['lxc-info', '-n', $name, '-sH'])) === 'RUNNING';
        $stopped = false;
        $ok = true;

        try {
            if ($wasRunning) {
                self::writeStatus($jobId, 'stopping', 2, $started, 0, 0);
                $timeout = (int) ($settings->timeout ?? 15);
                [, , $err] = LxcPaths::run(['lxc-stop', '--timeout=' . max(1, $timeout), '-n', $name]);
                $state = trim((string) LxcPaths::capture(['lxc-info', '-n', $name, '-sH']));
                if ($state !== 'STOPPED') {
                    $messages[] = 'Could not stop the container (state: ' . ($state !== '' ? $state : 'unknown')
                        . '); no backup was created.' . ($err !== '' ? ' ' . trim($err) : '');
                    return self::failJob($jobId, $started, $messages);
                }
                $stopped = true;
                $messages[] = 'Container stopped for a consistent backup; it will be restarted.';
            }

            if (is_dir($staging)) {
                self::removeTree($staging);
            }
            if (!@mkdir($staging, 0700, true)) {
                $messages[] = 'Could not create staging directory.';
                return self::failJob($jobId, $started, $messages);
            }

            // Only stage the generated pct.conf. The rootfs is archived
            // directly with tar --one-file-system: no cp -al/cp -a (which can
            // hang on bind mounts or device files) and no extra disk copy.
            $pctConf = $staging . '/etc/vzdump/pct.conf';
            if (!self::writeFile($pctConf, self::toPctConfig($name, $vmid, $rootfs), $messages, 'pct.conf')) {
                return self::failJob($jobId, $started, $messages);
            }
            self::writeStatus($jobId, 'staged', 4, $started, 0, 0);

            $threads = max(1, min(16, (int) ($settings->backup_threads ?? 1)));
            $exclude = "--exclude=./snaps --exclude=./tmp/?* --exclude=./var/run/?*pid --exclude=./dev/ --one-file-system";
            $owner = self::tarSupports('--numeric-owner') ? '--numeric-owner' : '';
            $xattrs = self::tarSupports('--xattrs') ? '--xattrs' : '';
            $acls = self::tarSupports('--acls') ? '--acls' : '';
            // du -sb prints "<bytes>\t<path>" — parse the leading number only.
            // Use the same simple external tar pattern as lxc-autobackup,
            // which is already proven on this Unraid. No staging copy, no PHP
            // recursion, no find: just one tar of the rootfs plus pct.conf.
            // Expected size of the rootfs (without crossing mounts). Used to
            // detect a truncated archive instead of reporting a false success.
            [$rcDu, $duOut] = LxcPaths::run(['du', '-sb', '-x', $rootfs], null, 60);
            $totalBytes = ($rcDu === 0 && preg_match('/^(\d+)/', trim($duOut), $duMatch) === 1)
                ? (int) $duMatch[1]
                : 0;
            $tarPart = $targetDir . '/' . $base . '.tar';
            self::writeStatus($jobId, 'copying', 5, $started, $totalBytes, 0);
            $tarLog = $targetDir . '/' . $base . '.tar.log';
            // pct.conf MUST be the first entry in the archive (vzdump format).
            $cmd = 'tar -C ' . escapeshellarg($staging) . ' ' . $owner . ' ' . $xattrs . ' ' . $acls
                . ' -cf ' . escapeshellarg($tarPart) . ' ./etc/vzdump/pct.conf'
                . ' 2> ' . escapeshellarg($tarLog)
                . ' && cd ' . escapeshellarg($rootfs)
                . ' && tar --one-file-system ' . $owner . ' ' . $xattrs . ' ' . $acls
                . ' --exclude=./snaps --exclude=./tmp/?* --exclude=./var/run/?*pid'
                . ' -rf ' . escapeshellarg($tarPart) . ' .'
                . ' 2>> ' . escapeshellarg($tarLog);
            [$rcTar, $tarStdout, $tarStderr] = LxcPaths::run(['bash', '-c', $cmd]);
            $tarError = trim((string) @file_get_contents($tarLog));
            if ($tarError === '') {
                $tarError = trim($tarStderr . ' ' . $tarStdout);
            }
            clearstatcache();
            if ($rcTar !== 0 || !is_file($tarPart)) {
                $messages[] = 'Backup archive could not be created (tar failed, exit ' . $rcTar . '). '
                    . ($tarError !== ''
                        ? 'tar said: ' . str_replace("\n", ' | ', $tarError)
                        : 'No detail captured. Full log kept at ' . $tarLog);
                @unlink($tarPart);
                return self::failJob($jobId, $started, $messages);
            }
            @unlink($tarLog);
            $tarSize = (int) filesize($tarPart);
            if ($totalBytes > 0 && $tarSize < ($totalBytes * 0.5)) {
                $messages[] = 'Backup is incomplete: rootfs has ' . round($totalBytes / 1048576)
                    . ' MiB but the archive has only ' . round($tarSize / 1048576)
                    . ' MiB. The container may have a bind mount or a file that tar could not read. No backup was kept.';
                @unlink($tarPart);
                return self::failJob($jobId, $started, $messages);
            }
            self::writeStatus($jobId, 'compressing', 90, $started, 0, 0);
            if ($compression === 'zst') {
                $compressCmd = 'zstd -q -T' . $threads . ' -o ' . escapeshellarg($part)
                    . ' ' . escapeshellarg($tarPart);
            } else {
                // gzip's -N is a compression LEVEL (1..9), not thread count.
                $level = max(1, min(9, (int) ($settings->backup_compression ?? 6)));
                $compressCmd = 'gzip -' . $level . ' -c ' . escapeshellarg($tarPart)
                    . ' > ' . escapeshellarg($part);
            }
            [$rcCompress, $compOut, $compErr] = LxcPaths::run(['bash', '-c', $compressCmd]);
            @unlink($tarPart);
            clearstatcache();
            if ($rcCompress !== 0 || !is_file($part) || filesize($part) < 64) {
                $detail = trim($compErr . ' ' . $compOut);
                $messages[] = 'Backup archive could not be created (compressor failed, exit ' . $rcCompress . '). '
                    . ($detail !== '' ? str_replace("\n", ' | ', $detail) : 'Check available disk space.');
                @unlink($part);
                return self::failJob($jobId, $started, $messages);
            }
            if (!@rename($part, $final)) {
                $messages[] = 'Could not install the final backup file.';
                @unlink($part);
                return self::failJob($jobId, $started, $messages);
            }

            self::retention($name, (int) ($settings->backup_keep ?? 0));
            $messages[] = 'Proxmox backup created: ' . basename($final) . '.';
            $messages[] = 'EXPERIMENTAL: validate with `pct restore <vmid> ' . basename($final) . ' --rootfs <storage>:<size>` on Proxmox.';
            self::writeStatus($jobId, 'done', 100, $started, $totalBytes, $totalBytes, ['file' => basename($final)]);
            $ok = true;
        } finally {
            if (!$ok) {
                self::writeStatus($jobId, 'error', 100, $started, $totalBytes ?? 0, 0, [
                    'error' => implode(' ', $messages),
                ]);
            }
            if (is_dir($staging)) {
                self::removeTree($staging);
            }
            if ($stopped) {
                LxcPaths::run(['lxc-start', '-n', $name]);
                $messages[] = 'Container restarted.';
            }
        }

        return ['ok' => $ok, 'messages' => $messages, 'file' => basename($final)];
    }

    /**
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
                    $messages[] = 'ZFS dataset is not mounted.';
                    return null;
                }
            } else {
                $messages[] = 'Rootfs backend is not supported for Proxmox backup (dir, btrfs, zfs only).';
                return null;
            }
        }
        if ($candidate === '') {
            $candidate = LxcPaths::containerDir($name) . '/rootfs';
        }
        $real = realpath($candidate);
        $containerDir = realpath(LxcPaths::containerDir($name));
        if ($real === false || !is_dir($real) || $containerDir === false
            || $real === $containerDir || strpos($real, $containerDir . '/') !== 0) {
            $messages[] = 'Container rootfs is missing or outside its directory.';
            return null;
        }

        return $real;
    }

    /**
     * @param string[] $messages
     */
    private static function copyRootfs(string $rootfs, string $staging, array &$messages): bool
    {
        [$rc] = LxcPaths::run(['cp', '-al', $rootfs . '/.', $staging . '/']);
        if ($rc === 0) {
            return true;
        }
        self::removeTree($staging);
        @mkdir($staging, 0700, true);
        $messages[] = 'Hardlink copy was not possible; using a full copy (extra disk space may be required).';

        [$rc] = LxcPaths::run(['cp', '-a', $rootfs . '/.', $staging . '/']);

        return $rc === 0;
    }

    private static function hasZstd(): bool
    {
        [$rc] = LxcPaths::run(['sh', '-c', 'command -v zstd']);

        return $rc === 0;
    }

    private static function tarSupports(string $flag): bool
    {
        [$rc] = LxcPaths::run(['tar', $flag, '--version']);

        return $rc === 0;
    }

    /**
     * @param string[] $messages
     */
    private static function ensureDir(string $dir, array &$messages): bool
    {
        if (is_link($dir)) {
            $messages[] = 'Backup directory is a symlink.';
            return false;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $messages[] = 'Could not create backup directory.';
            return false;
        }

        return true;
    }

    /**
     * @param string[] $messages
     */
    private static function writeFile(string $path, string $content, array &$messages, string $label): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $messages[] = $label . ': could not create directory.';
            return false;
        }
        $tmp = @tempnam($dir, '.lxv2');
        if ($tmp === false || @file_put_contents($tmp, $content) === false) {
            $messages[] = $label . ': could not write temporary file.';
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            $messages[] = $label . ': could not install file.';
            return false;
        }

        return true;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        LxcPaths::run(['rm', '-rf', '--', $dir]);
    }

    public static function backupPath(): string
    {
        $settings = new Settings();
        $path = trim((string) ($settings->proxmox_backup_path ?? ''));
        if ($path === '') {
            $path = trim((string) ($settings->backup_path ?? ''));
        }
        if ($path === '') {
            $path = '/mnt/user/backups/lxc-proxmox';
        }
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        return rtrim($path, '/');
    }

    public static function dir(string $container): string
    {
        return self::backupPath() . '/proxmox/' . $container;
    }

    /**
     * Generate the pct.conf payload from the LXC config.
     */
    public static function toPctConfig(string $name, int $vmid, string $rootfsPath = ''): string
    {
        $config = new ContainerConfig($name);
        $lines = ['# Proxmox-compatible config generated by the LXC v2 plugin (EXPERIMENTAL)', 'arch: amd64'];
        $lines[] = 'hostname: ' . self::sanitize($name, 63);
        $lines[] = 'cores: ' . self::cpuCount($config->get('lxc.cgroup2.cpuset.cpus')
            ?? $config->get('lxc.cgroup.cpuset.cpus') ?? '');
        $memory = self::toMiB($config->get('lxc.cgroup2.memory.max') ?? $config->get('lxc.cgroup.memory.limit_in_bytes') ?? '');
        if ($memory > 0) {
            $lines[] = 'memory: ' . $memory;
        }
        $swap = self::toMiB($config->get('lxc.cgroup2.memory.swap.max') ?? '');
        if ($swap > 0) {
            $lines[] = 'swap: ' . $swap;
        }
        $raw = $config->read();
        if (preg_match('/--dist\s+([A-Za-z0-9._-]+)/', $raw, $m) === 1) {
            $lines[] = 'ostype: ' . self::sanitize(strtolower($m[1]), 31);
        } else {
            $lines[] = 'ostype: debian';
        }
        $onboot = trim((string) ($config->get('lxc.start.auto') ?? '0')) === '1';
        $lines[] = 'onboot: ' . ($onboot ? '1' : '0');
        $lines[] = 'unprivileged: 0';

        foreach (Network::read($config) as $index => $nic) {
            $parts = ['name=' . $nic->name, 'type=veth'];
            if ($nic->type === 'veth') {
                $parts[] = 'bridge=' . $nic->link;
                if ($nic->vlanId !== null) {
                    $parts[] = 'tag=' . $nic->vlanId;
                }
            }
            if ($nic->hwaddr !== '') {
                $parts[] = 'hwaddr=' . strtoupper($nic->hwaddr);
            }
            if ($nic->ipv4Mode === 'static') {
                $parts[] = 'ip=' . $nic->ipv4Address;
                if ($nic->ipv4Gateway !== '') {
                    $parts[] = 'gw=' . $nic->ipv4Gateway;
                }
            } elseif ($nic->ipv4Mode === 'dhcp') {
                $parts[] = 'ip=dhcp';
            }
            if ($nic->ipv6Mode === 'static') {
                $parts[] = 'ip6=' . $nic->ipv6Address;
                if ($nic->ipv6Gateway !== '') {
                    $parts[] = 'gw6=' . $nic->ipv6Gateway;
                }
            } elseif ($nic->ipv6Mode === 'auto') {
                $parts[] = 'ip6=auto';
            }
            if (!$nic->flagsUp) {
                $parts[] = 'link_down=1';
            }
            $lines[] = 'net' . $index . ': ' . implode(',', $parts);
        }

        $size = 1;
        if ($rootfsPath !== '' && is_dir($rootfsPath)) {
            // du -sm prints "<MiB>\t<path>" — parse the leading number.
            [$rc, $out] = LxcPaths::run(['du', '-sm', $rootfsPath]);
            if ($rc === 0 && preg_match('/^(\d+)/', trim($out), $sizeMatch) === 1) {
                $size = max(1, (int) ceil(((int) $sizeMatch[1]) * 1.2 / 1024));
            }
        }
        $lines[] = 'rootfs: local-lvm:vm-' . $vmid . '-disk-0,size=' . $size . 'G';

        $description = trim((string) ($config->get('#container_description') ?? ''));
        if ($description !== '') {
            $lines[] = 'description: ' . self::sanitize($description, 255);
        }

        return implode("\n", $lines) . "\n";
    }

    private static function sanitize(string $value, int $max): string
    {
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return substr(trim($value), 0, $max);
    }

    private static function cpuCount(?string $cpuset): int
    {
        $cpuset = trim((string) $cpuset);
        if ($cpuset === '' || $cpuset === 'max') {
            return 1;
        }
        $count = 0;
        foreach (explode(',', $cpuset) as $part) {
            $part = trim($part);
            if (preg_match('/^(\d+)-(\d+)$/', $part, $m) === 1) {
                $count += max(0, (int) $m[2] - (int) $m[1] + 1);
            } elseif (ctype_digit($part)) {
                $count++;
            }
        }

        return max(1, $count);
    }

    private static function toMiB(?string $value): int
    {
        $value = strtoupper(trim((string) $value));
        if ($value === '' || $value === 'MAX') {
            return 0;
        }
        if (ctype_digit($value)) {
            return (int) floor((int) $value / 1048576);
        }
        if (preg_match('/^(\d+(?:\.\d+)?)([KMGT])$/', $value, $m) === 1) {
            $factors = ['K' => 1 / 1024, 'M' => 1, 'G' => 1024, 'T' => 1048576];

            return (int) floor((float) $m[1] * ($factors[$m[2]] ?? 1));
        }

        return 0;
    }

    /**
     * @return array<int,array{file:string,vmid:int,stamp:string,size:int,compression:string}>
     */
    public static function list(string $name): array
    {
        $dir = self::dir($name);
        $result = [];
        foreach (glob($dir . '/vzdump-lxc-*.tar.*') ?: [] as $file) {
            if (!is_file($file) || is_link($file)) {
                continue;
            }
            if (preg_match(self::FILE_RE, basename($file), $m) !== 1) {
                continue;
            }
            $result[] = [
                'file' => basename($file),
                'vmid' => (int) $m[1],
                'stamp' => $m[2],
                'size' => (int) filesize($file),
                'compression' => $m[3],
            ];
        }
        usort($result, static fn(array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));

        return $result;
    }

    public static function resolveBackupFile(string $name, string $file): ?string
    {
        if (!LxcPaths::isValidContainerName($name)
            || preg_match(self::FILE_RE, $file, $m) !== 1) {
            return null;
        }
        $path = self::dir($name) . '/' . $file;
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $real = realpath($path);
        $dir = realpath(self::dir($name));
        if ($real === false || $dir === false || dirname($real) !== $dir || basename($real) !== $file) {
            return null;
        }

        return $real;
    }

    public static function retention(string $name, int $keep): void
    {
        if ($keep < 1) {
            return;
        }
        $files = self::list($name);
        foreach (array_slice($files, $keep) as $entry) {
            $path = self::resolveBackupFile($name, $entry['file']);
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    public static function delete(string $name, string $file): bool
    {
        $path = self::resolveBackupFile($name, $file);

        return $path !== null && @unlink($path);
    }

    private static function downloadKey(): string
    {
        @mkdir(dirname(self::KEY_FILE), 0755, true);
        if (!is_file(self::KEY_FILE)) {
            $key = bin2hex(random_bytes(32));
            if (@file_put_contents(self::KEY_FILE, $key, LOCK_EX) !== false) {
                @chmod(self::KEY_FILE, 0600);
            }
        }
        $stored = trim((string) @file_get_contents(self::KEY_FILE));

        return $stored !== '' ? $stored : 'lxc-v2-fallback-key';
    }

    public static function token(string $name, string $file): string
    {
        $expiry = time() + self::TOKEN_TTL;
        $mac = bin2hex(hash_hmac('sha256', $name . '|' . $file . '|' . $expiry, self::downloadKey(), true));

        return $expiry . '.' . $mac;
    }

    public static function verifyToken(string $name, string $file, string $token): bool
    {
        if (preg_match('/^(\d+)\.([0-9a-f]{64})$/', $token, $m) !== 1) {
            return false;
        }
        if ((int) $m[1] < time()) {
            return false;
        }
        $expected = bin2hex(hash_hmac('sha256', $name . '|' . $file . '|' . $m[1], self::downloadKey(), true));

        return hash_equals($expected, $m[2]);
    }
}
