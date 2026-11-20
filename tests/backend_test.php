<?php
/**
 * Local sanity test for the LXC v2 backend helpers.
 * Run with: php tests/backend_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = sys_get_temp_dir() . '/lxcv2-test';
@mkdir($base . '/data/ct1/rootfs', 0755, true);
@mkdir('/boot/config/plugins/lxc', 0755, true);
file_put_contents('/boot/config/plugins/lxc/lxc.conf', "lxc.lxcpath={$base}/data\n");
file_put_contents('/boot/config/plugins/lxc/plugin.cfg', "SERVICE=enabled\nBDEVTYPE=dir\n");
file_put_contents($base . '/data/ct1/config', "lxc.rootfs.path = dir:{$base}/data/ct1/rootfs\nlxc.uts.name = ct1\n#container_description=Test\n");

$include = __DIR__ . '/../source/usr/local/emhttp/plugins/lxc/include';
require_once $include . '/Paths.php';
require_once $include . '/ContainerConfig.php';
require_once $include . '/Network.php';
require_once $include . '/Devices.php';
require_once $include . '/Resources.php';

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

echo "Paths\n";
check('dataPath', LxcPaths::dataPath() === $base . '/data', LxcPaths::dataPath());
check('valid name', LxcPaths::isValidContainerName('ct-1_a.b'));
check('rejects injection', !LxcPaths::isValidContainerName('ct;rm -rf /'));
check('container exists', LxcPaths::containerExists('ct1'));

echo "\nNetwork: DHCP default\n";
$config = new ContainerConfig('ct1');
$nics = Network::read($config);
check('one default nic', count($nics) === 1);
$nics[0]->hwaddr = '52:54:00:AA:BB:CC';
Network::write($config, $nics);
$raw = file_get_contents($config->path());
check('network block written', strpos($raw, 'LXCv2:NETWORK') !== false);
check('dhcp omits ipv4.address', strpos($raw, 'ipv4.address') === false);
check('hwaddr uppercase', strpos($raw, '52:54:00:AA:BB:CC') !== false);
check('legacy description kept', strpos($raw, '#container_description=Test') !== false);

echo "\nNetwork: static IPv4 + IPv6 + VLAN\n";
$nic = NetworkInterface::fromRequest([
    'type' => 'veth',
    'link' => 'br0',
    'name' => 'eth0',
    'hwaddr' => '52:54:00:11:22:33',
    'vlanId' => '100',
    'ipv4Mode' => 'static',
    'ipv4Address' => '192.168.1.50/24',
    'ipv4Gateway' => '192.168.1.1',
    'ipv6Mode' => 'static',
    'ipv6Address' => 'fd00::50/64',
    'ipv6Gateway' => 'fd00::1',
], 0);
$config = new ContainerConfig('ct1');
Network::write($config, [$nic]);
$raw = file_get_contents($config->path());
foreach ([
    'lxc.net.0.veth.vlan.id = 100',
    'lxc.net.0.ipv4.address = 192.168.1.50/24',
    'lxc.net.0.ipv4.gateway = 192.168.1.1',
    'lxc.net.0.ipv6.address = fd00::50/64',
    'lxc.net.0.ipv6.gateway = fd00::1',
] as $needle) {
    check($needle, strpos($raw, $needle) !== false);
}
check('no duplicated net lines', substr_count($raw, 'lxc.net.0.type') === 1);

echo "\nNetwork: read back round-trip\n";
$config = new ContainerConfig('ct1');
$parsed = Network::read($config);
check('vlan parsed', $parsed[0]->vlanId === 100);
check('ipv4 mode parsed', $parsed[0]->ipv4Mode === 'static');
check('ipv4 address parsed', $parsed[0]->ipv4Address === '192.168.1.50/24');
check('ipv6 mode parsed', $parsed[0]->ipv6Mode === 'static');

echo "\nNetwork: validation\n";
$rejected = false;
try {
    NetworkInterface::fromRequest(['ipv4Mode' => 'static', 'ipv4Address' => '999.1.1.1/24'], 0);
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
check('rejects bad ipv4', $rejected);

$rejected = false;
try {
    NetworkInterface::fromRequest(['link' => 'br0; reboot'], 0);
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
check('rejects bad link', $rejected);

echo "\nNetwork: macvlan + vlan\n";
$nic = NetworkInterface::fromRequest([
    'type' => 'macvlan',
    'link' => 'eth0',
    'vlanId' => '200',
    'ipv4Mode' => 'dhcp',
], 0);
$config = new ContainerConfig('ct1');
Network::write($config, [$nic]);
$raw = file_get_contents($config->path());
check('macvlan type', strpos($raw, 'lxc.net.0.type = macvlan') !== false);
check('macvlan mode', strpos($raw, 'lxc.net.0.macvlan.mode = bridge') !== false);
check('macvlan has no veth vlan', strpos($raw, 'veth.vlan.id') === false);

echo "\nNetwork: multiple interfaces\n";
$a = NetworkInterface::fromRequest(['link' => 'br0', 'name' => 'eth0'], 0);
$b = NetworkInterface::fromRequest(['link' => 'br1', 'name' => 'eth1', 'ipv4Mode' => 'none'], 1);
$config = new ContainerConfig('ct1');
Network::write($config, [$a, $b]);
$raw = file_get_contents($config->path());
check('second nic written', strpos($raw, 'lxc.net.1.link = br1') !== false);
check('ipv4 none honoured', strpos($raw, 'lxc.net.1.ipv4.address = none') !== false);

echo "\nDevices\n";
$config = new ContainerConfig('ct1');
$gpus = Devices::listGpus();
echo '  (detected ' . count($gpus) . " gpu(s), " . count(Devices::listUsb()) . " usb device(s))\n";
Devices::write($config, ['gpus' => [], 'usb' => [], 'misc' => ['/dev/null']]);
$raw = file_get_contents($config->path());
check('misc metadata', strpos($raw, '#@dev /dev/null') !== false);
check('misc mount', strpos($raw, 'lxc.mount.entry = /dev/null dev/null') !== false);
check('misc cgroup allow', strpos($raw, 'lxc.cgroup2.devices.allow = c 1:3 rwm') !== false);

$selection = Devices::read(new ContainerConfig('ct1'));
check('misc read back', $selection['misc'] === ['/dev/null']);

$config = new ContainerConfig('ct1');
Devices::write($config, ['misc' => ['/dev/../etc/passwd']]);
check('rejects traversal', Devices::read(new ContainerConfig('ct1'))['misc'] === []);

echo "\nResources\n";
$config = new ContainerConfig('ct1');
Resources::write($config, ['memoryMiB' => '2048', 'swapMiB' => '512', 'cpuset' => '0-3', 'cpuWeight' => '200']);
$raw = file_get_contents($config->path());
check('memory bytes', strpos($raw, 'lxc.cgroup2.memory.max = ' . (2048 * 1024 * 1024)) !== false);
check('swap bytes', strpos($raw, 'lxc.cgroup2.memory.swap.max = ' . (512 * 1024 * 1024)) !== false);
check('cpuset', strpos($raw, 'lxc.cgroup2.cpuset.cpus = 0-3') !== false);
check('cpu weight', strpos($raw, 'lxc.cgroup2.cpu.weight = 200') !== false);

$read = Resources::read(new ContainerConfig('ct1'));
check('memory read back', $read['memoryMiB'] === '2048', $read['memoryMiB']);
check('cpuset read back', $read['cpuset'] === '0-3');

Resources::write(new ContainerConfig('ct1'), ['memoryMiB' => '', 'swapMiB' => '', 'cpuset' => '', 'cpuWeight' => '']);
$raw = file_get_contents('/tmp/lxcv2-test/data/ct1/config');
check('limits removed', strpos($raw, 'memory.max') === false && strpos($raw, 'cpuset.cpus') === false);

echo "\nBackup file\n";
check('.bak created', is_file('/tmp/lxcv2-test/data/ct1/config.bak'));

echo "\n" . ($failures === 0 ? 'ALL TESTS PASSED' : "$failures TEST(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
