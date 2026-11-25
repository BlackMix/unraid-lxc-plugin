<?php
/**
 * Proxmox-compatible backup tests.
 * Run with: php tests/proxmox_backup_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = sys_get_temp_dir() . '/lxv2-px-test';
@mkdir($base . '/data/px1/rootfs/etc', 0755, true);
@mkdir($base . '/backup', 0755, true);
@mkdir('/boot/config/plugins/lxc', 0755, true);
file_put_contents('/boot/config/plugins/lxc/lxc.conf', "lxc.lxcpath={$base}/data\n");
file_put_contents('/boot/config/plugins/lxc/plugin.cfg',
    "SERVICE=enabled\nBDEVTYPE=dir\nTIMEOUT=15\nLXC_BACKUP_SERVICE=enabled\n"
    . "LXC_BACKUP_PATH={$base}/backup\nLXC_BACKUP_KEEP=2\nLXC_BACKUP_THREADS=1\n"
    . "LXC_BACKUP_COMPRESSION=6\nLXC_BACKUP_USE_SNAPSHOT=disabled\nLXC_PROXMOX_VMID=100\n"
    . "LXC_PROXMOX_BACKUP_PATH={$base}/backup\n");
file_put_contents($base . '/data/px1/rootfs/etc/os-release', "ID=debian\nPRETTY_NAME=\"Debian\"\n");
file_put_contents($base . '/data/px1/config', implode("\n", [
    'lxc.rootfs.path = dir:' . $base . '/data/px1/rootfs',
    'lxc.uts.name = px1',
    'lxc.start.auto = 1',
    'lxc.cgroup2.cpuset.cpus = 0-3',
    'lxc.cgroup2.memory.max = 2147483648',
    'lxc.cgroup2.memory.swap.max = 512M',
    '#container_description=Proxmox Test',
    '# Parameters passed to the template: --dist debian --release bookworm',
    'lxc.net.0.type = veth',
    'lxc.net.0.link = br0',
    'lxc.net.0.name = eth0',
    'lxc.net.0.hwaddr = 52:54:00:AA:BB:CC',
    'lxc.net.0.veth.vlan.id = 100',
    'lxc.net.0.ipv4.address = 192.168.1.50/24',
    'lxc.net.0.ipv4.gateway = 192.168.1.1',
    'lxc.net.0.ipv6.address = fd00::50/64',
    'lxc.net.0.ipv6.gateway = fd00::1',
]) . "\n");

$fakeBin = sys_get_temp_dir() . '/lxv2-px-bin';
@mkdir($fakeBin, 0755, true);
file_put_contents($fakeBin . '/lxc-info', "#!/bin/sh\necho 'State: STOPPED'\n");
file_put_contents($fakeBin . '/lxc-stop', "#!/bin/sh\nexit 0\n");
file_put_contents($fakeBin . '/lxc-start', "#!/bin/sh\nexit 0\n");
foreach (glob($fakeBin . '/*') ?: [] as $bin) {
    @chmod($bin, 0755);
}
putenv('PATH=' . $fakeBin . ':' . getenv('PATH'));

$include = __DIR__ . '/../source/usr/local/emhttp/plugins/lxc/include';
require_once $include . '/ProxmoxBackup.php';

$failures = 0;
function check(string $label, bool $condition, string $extra = ''): void
{
    global $failures;
    if ($condition) {
        echo "  ok   $label\n";
    } else {
        $failures++;
        echo "  FAIL $label" . ($extra !== '' ? " -> $extra" : '') . "\n";
    }
}

echo "pct.conf generation\n";
$pct = ProxmoxBackup::toPctConfig('px1', 123, $base . '/data/px1/rootfs');
foreach ([
    'hostname: px1', 'cores: 4', 'memory: 2048', 'swap: 512', 'ostype: debian',
    'onboot: 1', 'unprivileged: 0',
    'net0: name=eth0,type=veth,bridge=br0,tag=100,hwaddr=52:54:00:AA:BB:CC,ip=192.168.1.50/24,gw=192.168.1.1,ip6=fd00::50/64,gw6=fd00::1',
    'rootfs: local-lvm:vm-123-disk-0,size=1G', 'description: Proxmox Test',
] as $needle) {
    check($needle, strpos($pct, $needle) !== false, $pct);
}
check('no lxc.* keys', strpos($pct, 'lxc.') === false, $pct);
check('description has no newline', substr_count($pct, "description:") === 1, $pct);

echo "\nValidation and file resolution\n";
check('vmid too low rejected', ProxmoxBackup::create('px1', 99)['ok'] === false);
check('invalid container rejected', ProxmoxBackup::create('../etc', 100)['ok'] === false);
$dir = ProxmoxBackup::dir('px1');
@mkdir($dir, 0755, true);
$good = 'vzdump-lxc-123-2026_11_25-12_00_00.tar.gz';
file_put_contents($dir . '/' . $good, str_repeat('x', 2048));
check('valid file resolves', ProxmoxBackup::resolveBackupFile('px1', $good) !== null);
check('traversal rejected', ProxmoxBackup::resolveBackupFile('px1', '../' . $good) === null);
check('unknown name rejected', ProxmoxBackup::resolveBackupFile('px1', 'backup.tar.gz') === null);
$link = $dir . '/vzdump-lxc-124-2026_11_25-12_00_01.tar.gz';
@symlink($good, $link);
check('symlink rejected', ProxmoxBackup::resolveBackupFile('px1', basename($link)) === null);
@unlink($link);
@unlink($dir . '/' . $good);

echo "\nDownload token\n";
$token = ProxmoxBackup::token('px1', $good);
check('token format', preg_match('/^\d+\.[0-9a-f]{64}$/', $token) === 1, $token);
check('valid token accepted', ProxmoxBackup::verifyToken('px1', $good, $token));
check('tampered token rejected', !ProxmoxBackup::verifyToken('px1', 'other.tar.gz', $token));
check('malformed token rejected', !ProxmoxBackup::verifyToken('px1', $good, 'abc'));
check('expired token rejected', !ProxmoxBackup::verifyToken('px1', $good, '1000000000.' . substr($token, strpos($token, '.') + 1)));

echo "\nArchive creation\n";
$result = ProxmoxBackup::create('px1', 123);
check('create succeeded', $result['ok'] === true, implode(' ', $result['messages']));
$files = ProxmoxBackup::list('px1');
check('backup listed', count($files) === 1, json_encode($files));
if ($files !== []) {
    $path = $dir . '/' . $files[0]['file'];
    check('compression fallback name', preg_match('/\.tar\.(zst|gz)$/', $files[0]['file']) === 1, $files[0]['file']);
    $listing = (string) shell_exec('tar -tf ' . escapeshellarg($path) . ' 2>/dev/null');
    if (str_ends_with($files[0]['file'], '.zst')) {
        $listing = (string) shell_exec('zstd -dc ' . escapeshellarg($path) . ' 2>/dev/null | tar -tf - 2>/dev/null');
    }
    check('archive has pct.conf', strpos($listing, './etc/vzdump/pct.conf') !== false, $listing);
    check('archive has rootfs file', strpos($listing, './etc/os-release') !== false, $listing);
}
check('staging removed', glob($base . '/backup/.proxmox-stage-*') === []);

echo "\nRetention\n";
for ($i = 0; $i < 3; $i++) {
    $name = 'vzdump-lxc-123-2026_11_2' . $i . '-12_00_00.tar.gz';
    file_put_contents($dir . '/' . $name, str_repeat('y', 2048));
}
ProxmoxBackup::retention('px1', 2);
check('retention keeps 2', count(ProxmoxBackup::list('px1')) === 2);

echo "\nEdge cases\n";
$cfg = new ContainerConfig('px1');
$cfg->set('lxc.net.0.ipv4.address', 'none');
$cfg->set('lxc.net.0.ipv6.address', 'auto');
$cfg->save();
$pctNone = ProxmoxBackup::toPctConfig('px1', 123, $base . '/data/px1/rootfs');
check('ipv4 none omits ip/gw', strpos($pctNone, 'ip=') === false && strpos($pctNone, 'gw=') === false, $pctNone);
check('ipv6 auto emits ip6=auto', strpos($pctNone, 'ip6=auto') !== false, $pctNone);

$cfg = new ContainerConfig('px1');
$cfg->set('lxc.net.0.type', 'macvlan');
$cfg->save();
$macResult = ProxmoxBackup::create('px1', 123);
check('macvlan backup refused', $macResult['ok'] === false && strpos(implode(' ', $macResult['messages']), 'macvlan') !== false, implode(' ', $macResult['messages']));
$cfg = new ContainerConfig('px1');
$cfg->set('lxc.net.0.type', 'veth');
$cfg->set('lxc.net.0.ipv4.address', '192.168.1.50/24');
$cfg->set('lxc.net.0.ipv6.address', 'fd00::50/64');
$cfg->save();

echo "\nStaging keeps guest rootfs intact\n";
$guestPct = $base . '/data/px1/rootfs/etc/vzdump/pct.conf';
@mkdir(dirname($guestPct), 0755, true);
file_put_contents($guestPct, "GUEST-CONFIG\n");
$result = ProxmoxBackup::create('px1', 123);
check('create with existing pct.conf succeeded', $result['ok'] === true, implode(' ', $result['messages']));
check('guest rootfs pct.conf untouched', file_get_contents($guestPct) === "GUEST-CONFIG\n");
@unlink($guestPct);

echo "\nProgress reporting\n";
$jobId = 'px' . bin2hex(random_bytes(4));
ProxmoxBackup::writeStatus($jobId, 'archiving', 42, time() - 10, 1000, 420);
$status = ProxmoxBackup::readStatus($jobId);
check('status readable', is_array($status) && $status['stage'] === 'archiving' && $status['percent'] === 42, json_encode($status));
check('invalid job rejected', ProxmoxBackup::readStatus('../etc/passwd') === null);
$result = ProxmoxBackup::create('px1', 123, $jobId);
$status = ProxmoxBackup::readStatus($jobId);
check('create with job succeeded', $result['ok'] === true, implode(' ', $result['messages']));
check('final status is done', is_array($status) && $status['stage'] === 'done' && $status['percent'] === 100, json_encode($status));
ProxmoxBackup::clearStatus($jobId);
check('status cleared', ProxmoxBackup::readStatus($jobId) === null);

echo "\nGlobal VMID setting\n";
require_once $include . '/Settings.php';
$settings = new Settings();
check('default proxmox vmid is 100', (string) $settings->proxmox_vmid === '100', (string) $settings->proxmox_vmid);
check('proxmox backup path configured', ProxmoxBackup::backupPath() === $base . '/backup', ProxmoxBackup::backupPath());

echo "\n" . ($failures === 0 ? 'ALL PROXMOX BACKUP TESTS PASSED' : "$failures TEST(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
