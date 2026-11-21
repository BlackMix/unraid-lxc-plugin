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
require_once $include . '/GuestNetwork.php';
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
check('macvlan vlan sub-interface', strpos($raw, 'lxc.net.0.link = eth0.200') !== false);
check('macvlan vlan metadata', strpos($raw, '#@macvlan_vlan 0 200') !== false);
check('macvlan has no veth vlan', strpos($raw, 'veth.vlan.id') === false);

$roundtrip = Network::read(new ContainerConfig('ct1'));
check('macvlan vlan read back', $roundtrip[0]->vlanId === 200);
check('macvlan base link restored', $roundtrip[0]->link === 'eth0', $roundtrip[0]->link);
check('macvlan effective link', $roundtrip[0]->effectiveLink() === 'eth0.200');

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

echo "\nDNS model and normalisation\n";
$dnsNic = NetworkInterface::fromRequest([
    'ipv4Mode' => 'static', 'ipv4Address' => '192.168.1.50', 'ipv4Gateway' => '192.168.1.1',
    'dns' => ['1.1.1.1', '8.8.8.8', '1.1.1.1', '9.9.9.9', '4.4.4.4'],
], 0);
check('ipv4 without prefix normalised to /24', $dnsNic->ipv4Address === '192.168.1.50/24', $dnsNic->ipv4Address);
check('dns deduped and capped at 3', $dnsNic->dns === ['1.1.1.1', '8.8.8.8', '9.9.9.9'], implode(',', $dnsNic->dns));
$config = new ContainerConfig('ct1');
check('network write returns bool', Network::write($config, [$dnsNic]) === true);
$raw = file_get_contents($config->path());
check('dns metadata written with index', strpos($raw, '#@dns 0 1.1.1.1') !== false);
$parsed = Network::read(new ContainerConfig('ct1'));
check('dns round-trip', $parsed[0]->dns === ['1.1.1.1', '8.8.8.8', '9.9.9.9'], implode(',', $parsed[0]->dns));
$rejected = false;
try {
    NetworkInterface::fromRequest(['ipv4Mode' => 'static', 'ipv4Address' => '1.2.3.4/24', 'dns' => ['not-an-ip']], 0);
} catch (InvalidArgumentException $e) {
    $rejected = true;
}
check('rejects invalid DNS in request', $rejected);

echo "\nGuest network: ifupdown + DNS\n";
$rootfs = $base . '/data/ct1/rootfs';
@mkdir($rootfs . '/etc/network', 0755, true);
file_put_contents($rootfs . '/etc/network/interfaces', "auto lo\niface lo inet loopback\nauto eth0\niface eth0 inet dhcp\n");
@unlink($rootfs . '/etc/resolv.conf');
$r = GuestNetwork::apply('ct1', [$dnsNic]);
check('guest apply ok', $r['ok'] === true, implode(' ', $r['messages']));
$ifaces = file_get_contents($rootfs . '/etc/network/interfaces');
check('ifupdown stanza is manual', strpos($ifaces, 'iface eth0 inet manual') !== false);
check('ifupdown dhcp removed', strpos($ifaces, 'iface eth0 inet dhcp') === false);
check('resolv.conf managed block written', strpos((string) @file_get_contents($rootfs . '/etc/resolv.conf'), 'nameserver 1.1.1.1') !== false);
check('networkd file absent when networkd disabled', !is_file($rootfs . '/etc/systemd/network/00-lxcv2-eth0.network'));

echo "\nGuest network: static -> DHCP\n";
$dhcpNic = NetworkInterface::fromRequest(['name' => 'eth0', 'ipv4Mode' => 'dhcp'], 0);
$r = GuestNetwork::apply('ct1', [$dhcpNic]);
check('guest dhcp apply ok', $r['ok'] === true, implode(' ', $r['messages']));
check('ifupdown stanza restored to dhcp', strpos(file_get_contents($rootfs . '/etc/network/interfaces'), 'iface eth0 inet dhcp') !== false);
$resolv = is_file($rootfs . '/etc/resolv.conf') ? (string) file_get_contents($rootfs . '/etc/resolv.conf') : '';
check('old dns block removed', strpos($resolv, 'nameserver 1.1.1.1') === false);

echo "\nGuest network: systemd-networkd\n";
@mkdir($rootfs . '/etc/systemd/system/multi-user.target.wants', 0755, true);
file_put_contents($rootfs . '/etc/systemd/system/multi-user.target.wants/systemd-networkd.service', '');
$r = GuestNetwork::apply('ct1', [$dnsNic]);
$netd = $rootfs . '/etc/systemd/network/00-lxcv2-eth0.network';
check('networkd file written', is_file($netd));
if (is_file($netd)) {
    $content = (string) file_get_contents($netd);
    check('networkd DHCP=no', strpos($content, 'DHCP=no') !== false);
    check('networkd static Address', strpos($content, 'Address=192.168.1.50/24') !== false);
}
@unlink($rootfs . '/etc/systemd/system/multi-user.target.wants/systemd-networkd.service');
@unlink($netd);

echo "\nGuest network: netplan\n";
@mkdir($rootfs . '/etc/netplan', 0755, true);
file_put_contents($rootfs . '/etc/netplan/01-original.yaml', "network:\n  version: 2\n");
$r = GuestNetwork::apply('ct1', [$dnsNic]);
$yaml = $rootfs . '/etc/netplan/99-lxcv2.yaml';
check('netplan drop-in written', is_file($yaml));
if (is_file($yaml)) {
    check('netplan dhcp4 false', strpos((string) file_get_contents($yaml), 'dhcp4: false') !== false);
}
@unlink($rootfs . '/etc/netplan/01-original.yaml');
@unlink($yaml);

echo "\nGuest network: NetworkManager multi-NIC\n";
@mkdir($rootfs . '/etc/NetworkManager', 0755, true);
$nic2 = NetworkInterface::fromRequest(['name' => 'eth1', 'ipv4Mode' => 'none'], 1);
$r = GuestNetwork::apply('ct1', [$dnsNic, $nic2]);
$nmConf = $rootfs . '/etc/NetworkManager/conf.d/99-lxcv2.conf';
check('NetworkManager conf written', is_file($nmConf));
if (is_file($nmConf)) {
    check('NM unmanaged aggregated for both NICs', strpos((string) file_get_contents($nmConf), 'interface-name:eth0;interface-name:eth1') !== false);
}
$r = GuestNetwork::apply('ct1', [$dhcpNic, $nic2]);
check('NM only eth1 stays unmanaged', is_file($nmConf) && strpos((string) file_get_contents($nmConf), 'interface-name:eth0') === false && strpos((string) file_get_contents($nmConf), 'interface-name:eth1') !== false);

echo "\nGuest network: symlink and containment\n";
@unlink($rootfs . '/etc/resolv.conf');
$outsideResolv = $base . '/outside-resolv.conf';
file_put_contents($outsideResolv, "nameserver 9.9.9.9\n");
@symlink($outsideResolv, $rootfs . '/etc/resolv.conf');
$r = GuestNetwork::apply('ct1', [$dnsNic]);
check('symlink refused', $r['ok'] === false && strpos(implode(' ', $r['messages']), 'symlink') !== false, implode(' ', $r['messages']));
check('symlink target untouched', file_get_contents($outsideResolv) === "nameserver 9.9.9.9\n");
@unlink($rootfs . '/etc/resolv.conf');
@mkdir($base . '/outside/rootfs', 0755, true);
$cfg = new ContainerConfig('ct1');
$cfg->set('lxc.rootfs.path', 'dir:' . $base . '/outside/rootfs');
$cfg->save();
$r = GuestNetwork::apply('ct1', [$dnsNic]);
check('rootfs outside container directory refused', $r['ok'] === false && strpos(implode(' ', $r['messages']), 'outside this container directory') !== false, implode(' ', $r['messages']));
$cfg = new ContainerConfig('ct1');
$cfg->set('lxc.rootfs.path', 'dir:' . $rootfs);
$cfg->save();

echo "\nGuest network: netplan YAML structure and IPv6\n";
@mkdir($rootfs . '/etc/netplan', 0755, true);
file_put_contents($rootfs . '/etc/netplan/01-original.yaml', "network:\n  version: 2\n");
$v6Nic = NetworkInterface::fromRequest([
    'name' => 'eth0', 'ipv4Mode' => 'static', 'ipv4Address' => '10.0.0.10', 'ipv4Gateway' => '10.0.0.1',
    'ipv6Mode' => 'static', 'ipv6Address' => 'fd00::50/64', 'ipv6Gateway' => 'fd00::1',
    'dns' => ['1.1.1.1'],
], 0);
$r = GuestNetwork::apply('ct1', [$v6Nic]);
$yaml = (string) @file_get_contents($rootfs . '/etc/netplan/99-lxcv2.yaml');
check('netplan route is one map with to+via', preg_match('/- to: default\s*\n\s*via: 10\.0\.0\.1/', $yaml) === 1, $yaml);
check('netplan has static IPv6 address', strpos($yaml, 'fd00::50/64') !== false, $yaml);
check('netplan has nameservers', strpos($yaml, 'nameservers:') !== false && strpos($yaml, '- 1.1.1.1') !== false, $yaml);
check('netplan accept-ra false with static v6', strpos($yaml, 'accept-ra: false') !== false, $yaml);
@unlink($rootfs . '/etc/netplan/01-original.yaml');
@unlink($rootfs . '/etc/netplan/99-lxcv2.yaml');

$autoV6 = NetworkInterface::fromRequest(['name' => 'eth0', 'ipv4Mode' => 'dhcp', 'ipv6Mode' => 'auto'], 0);
file_put_contents($rootfs . '/etc/netplan/01-original.yaml', "network:\n  version: 2\n");
$r = GuestNetwork::apply('ct1', [$autoV6]);
$yaml = (string) @file_get_contents($rootfs . '/etc/netplan/99-lxcv2.yaml');
check('netplan accept-ra true with auto v6', strpos($yaml, 'accept-ra: true') !== false, $yaml);
check('netplan dhcp4 true with DHCP', strpos($yaml, 'dhcp4: true') !== false, $yaml);
@unlink($rootfs . '/etc/netplan/01-original.yaml');
@unlink($rootfs . '/etc/netplan/99-lxcv2.yaml');

echo "\nGuest network: networkd DHCP mode and IPv6\n";
@mkdir($rootfs . '/etc/systemd/system/multi-user.target.wants', 0755, true);
file_put_contents($rootfs . '/etc/systemd/system/multi-user.target.wants/systemd-networkd.service', '');
$r = GuestNetwork::apply('ct1', [$autoV6]);
$netd = $rootfs . '/etc/systemd/network/00-lxcv2-eth0.network';
$content = is_file($netd) ? (string) file_get_contents($netd) : '';
check('networkd DHCP=ipv4 (no unwanted DHCPv6)', strpos($content, 'DHCP=ipv4') !== false && strpos($content, 'DHCP=yes') === false, $content);
check('networkd IPv6AcceptRA=yes with auto', strpos($content, 'IPv6AcceptRA=yes') !== false, $content);
@unlink($rootfs . '/etc/systemd/system/multi-user.target.wants/systemd-networkd.service');
@unlink($netd);

echo "\nGuest network: DHCP + DNS clears old DNS\n";
@unlink($rootfs . '/etc/resolv.conf');
$dhcpDns = NetworkInterface::fromRequest(['name' => 'eth0', 'ipv4Mode' => 'dhcp', 'dns' => ['8.8.8.8']], 0);
$r = GuestNetwork::apply('ct1', [$dhcpDns]);
$resolv = is_file($rootfs . '/etc/resolv.conf') ? (string) file_get_contents($rootfs . '/etc/resolv.conf') : '';
check('dhcp+dns clears old managed block', strpos($resolv, 'nameserver 1.1.1.1') === false, $resolv);
check('dhcp+dns warns explicitly', strpos(implode(' ', $r['messages']), 'not applied') !== false, implode(' ', $r['messages']));

echo "\nGuest network: symlink without resolved and parent symlinks\n";
@unlink($rootfs . '/etc/resolv.conf');
file_put_contents($rootfs . '/etc/systemd/resolved.conf', "[Resolve]\nDNS=\n");
@symlink('/run/systemd/resolve/stub-resolv.conf', $rootfs . '/etc/resolv.conf');
$r = GuestNetwork::apply('ct1', [$dnsNic]);
check('symlink without resolved fails', $r['ok'] === false, implode(' ', $r['messages']));
check('symlink without resolved warns', strpos(implode(' ', $r['messages']), 'systemd-resolved is not enabled') !== false, implode(' ', $r['messages']));
@unlink($rootfs . '/etc/resolv.conf');
@unlink($rootfs . '/etc/systemd/resolved.conf');

$nmDir = $rootfs . '/etc/NetworkManager/conf.d';
if (is_dir($nmDir) && !is_link($nmDir)) {
    foreach (glob($nmDir . '/*') ?: [] as $stale) {
        @unlink($stale);
    }
    @rmdir($nmDir);
}
if (is_link($nmDir)) {
    @unlink($nmDir);
}
$outsideNm = $base . '/outside-nm';
@mkdir($outsideNm, 0755, true);
file_put_contents($outsideNm . '/99-lxcv2.conf', 'outside');
@symlink($outsideNm, $nmDir);
$r = GuestNetwork::apply('ct1', [$dhcpNic]);
check('parent symlink blocks NM write', strpos(implode(' ', $r['messages']), 'refusing to traverse symlink') !== false, implode(' ', $r['messages']));
check('symlink target untouched', file_get_contents($outsideNm . '/99-lxcv2.conf') === 'outside');
@unlink($nmDir);

echo "\nGuest network: cross-container rootfs and raw config validation\n";
@mkdir($base . '/data/ct2/rootfs', 0755, true);
$cfg = new ContainerConfig('ct1');
$cfg->set('lxc.rootfs.path', 'dir:' . $base . '/data/ct2/rootfs');
$cfg->save();
$r = GuestNetwork::apply('ct1', [$dnsNic]);
check('cross-container rootfs refused', $r['ok'] === false && strpos(implode(' ', $r['messages']), 'outside this container directory') !== false, implode(' ', $r['messages']));
$cfg = new ContainerConfig('ct1');
$cfg->set('lxc.rootfs.path', 'dir:' . $rootfs);
$cfg->save();

$cfg = new ContainerConfig('ct1');
$cfg->set('lxc.net.0.type', 'veth');
$cfg->set('lxc.net.0.name', 'eth0');
$cfg->set('lxc.net.0.ipv4.address', 'garbage');
$cfg->set('lxc.net.0.ipv4.gateway', 'also-garbage');
$cfg->save();
$bad = Network::read(new ContainerConfig('ct1'));
check('invalid raw ipv4 falls back to dhcp', $bad[0]->ipv4Mode === 'dhcp', $bad[0]->ipv4Mode);
check('invalid raw gateway discarded', $bad[0]->ipv4Gateway === '', $bad[0]->ipv4Gateway);
Network::write(new ContainerConfig('ct1'), [$dnsNic]);

echo "\nGuest network: ifupdown auto and DNS ordering\n";
file_put_contents($rootfs . '/etc/network/interfaces', "iface eth0 inet dhcp\n");
$r = GuestNetwork::apply('ct1', [$dhcpNic]);
check('auto added for existing stanza', strpos((string) file_get_contents($rootfs . '/etc/network/interfaces'), 'auto eth0') !== false);

@unlink($rootfs . '/etc/resolv.conf');
file_put_contents($rootfs . '/etc/resolv.conf', "nameserver 203.0.113.1\nsearch example.com\noptions timeout:1\n");
$r = GuestNetwork::apply('ct1', [$dnsNic]);
$resolv = (string) @file_get_contents($rootfs . '/etc/resolv.conf');
check('custom DNS block comes first', strpos($resolv, 'nameserver 1.1.1.1') < strpos($resolv, 'search example.com'), $resolv);
check('old nameserver removed', strpos($resolv, '203.0.113.1') === false, $resolv);
check('search and options preserved', strpos($resolv, 'search example.com') !== false && strpos($resolv, 'options timeout:1') !== false, $resolv);

echo "\nIPv4 none round-trip\n";
$noneNic = NetworkInterface::fromRequest(['name' => 'eth0', 'ipv4Mode' => 'none'], 0);
Network::write(new ContainerConfig('ct1'), [$noneNic]);
$back = Network::read(new ContainerConfig('ct1'));
check('ipv4 none round-trip', $back[0]->ipv4Mode === 'none', $back[0]->ipv4Mode);

echo "\nGuest network: ifupdown split files and unsourced .d\n";
@mkdir($rootfs . '/etc/network', 0755, true);
@mkdir($rootfs . '/etc/network/interfaces.d', 0755, true);
file_put_contents($rootfs . '/etc/network/interfaces', "auto lo\niface lo inet loopback\nsource /etc/network/interfaces.d/*\n");
file_put_contents($rootfs . '/etc/network/interfaces.d/eth0', "auto eth0\niface eth0 inet dhcp\niface eth0 inet6 auto\n");
$r = GuestNetwork::apply('ct1', [$v6Nic]);
$extra = (string) file_get_contents($rootfs . '/etc/network/interfaces.d/eth0');
check('v4 stanza stays in sourced file', strpos($extra, 'iface eth0 inet manual') !== false, $extra);
check('v6 stanza stays in sourced file', strpos($extra, 'iface eth0 inet6 manual') !== false, $extra);
check('main file has no eth0 stanza', strpos((string) file_get_contents($rootfs . '/etc/network/interfaces'), 'iface eth0') === false);

file_put_contents($rootfs . '/etc/network/interfaces', "auto lo\niface lo inet loopback\n");
file_put_contents($rootfs . '/etc/network/interfaces.d/eth0', "auto eth0\niface eth0 inet dhcp\n");
$r = GuestNetwork::apply('ct1', [$dhcpNic]);
$mainIfaces = (string) file_get_contents($rootfs . '/etc/network/interfaces');
check('stanza created in main when .d is not sourced', strpos($mainIfaces, 'iface eth0 inet dhcp') !== false, $mainIfaces);

echo "\nGuest network: rootfs equal to containerDir\n";
$cfg = new ContainerConfig('ct1');
$cfg->set('lxc.rootfs.path', 'dir:' . $base . '/data/ct1');
$cfg->save();
$r = GuestNetwork::apply('ct1', [$dnsNic]);
check('rootfs equal to containerDir refused', $r['ok'] === false && strpos(implode(' ', $r['messages']), 'container directory itself') !== false, implode(' ', $r['messages']));
$cfg = new ContainerConfig('ct1');
$cfg->set('lxc.rootfs.path', 'dir:' . $rootfs);
$cfg->save();

echo "\nGuest network: resolved drop-in idempotence and NM DHCP warning\n";
@mkdir($rootfs . '/etc/systemd/resolved.conf.d', 0755, true);
file_put_contents($rootfs . '/etc/systemd/resolved.conf.d/99-lxcv2.conf', 'STALE');
file_put_contents($rootfs . '/etc/systemd/resolved.conf', "[Resolve]\n");
@unlink($rootfs . '/etc/resolv.conf');
$r = GuestNetwork::apply('ct1', [$dnsNic]);
check('stale resolved drop-in removed when disabled', !is_file($rootfs . '/etc/systemd/resolved.conf.d/99-lxcv2.conf'));
check('dns applied to resolv.conf when resolved disabled', strpos((string) @file_get_contents($rootfs . '/etc/resolv.conf'), 'nameserver 1.1.1.1') !== false);

@mkdir($rootfs . '/etc/NetworkManager', 0755, true);
foreach ([
    $rootfs . '/run/NetworkManager/system-connections/*.nmconnection',
    $rootfs . '/etc/NetworkManager/system-connections/*.nmconnection',
] as $stalePattern) {
    foreach (glob($stalePattern) ?: [] as $stale) {
        @unlink($stale);
    }
}
$r = GuestNetwork::apply('ct1', [$dhcpNic]);
check('NM DHCP without profile warns', strpos(implode(' ', $r['messages']), 'no connection profile') !== false, implode(' ', $r['messages']));

file_put_contents($rootfs . '/etc/systemd/system/multi-user.target.wants/systemd-resolved.service', '');
$r = GuestNetwork::apply('ct1', [$dnsNic]);
$drop = (string) @file_get_contents($rootfs . '/etc/systemd/resolved.conf.d/99-lxcv2.conf');
check('resolved drop-in uses one DNS= line', preg_match('/^DNS=1\.1\.1\.1 8\.8\.8\.8 9\.9\.9\.9$/m', $drop) === 1, $drop);
@unlink($rootfs . '/etc/systemd/system/multi-user.target.wants/systemd-resolved.service');

echo "\nIPv6 auto in ifupdown and prefix normalisation\n";
$noPrefix = NetworkInterface::fromRequest([
    'name' => 'eth0', 'ipv4Mode' => 'dhcp', 'ipv6Mode' => 'static', 'ipv6Address' => 'fd00::50',
], 0);
check('ipv6 without prefix normalised to /64', $noPrefix->ipv6Address === 'fd00::50/64', $noPrefix->ipv6Address);
file_put_contents($rootfs . '/etc/network/interfaces', "auto lo\niface lo inet loopback\niface eth0 inet6 manual\n");
$r = GuestNetwork::apply('ct1', [$autoV6]);
$ifaces = (string) file_get_contents($rootfs . '/etc/network/interfaces');
check('ipv6 auto uses inet6 auto', strpos($ifaces, 'iface eth0 inet6 auto') !== false, $ifaces);
check('auto stanza present for ipv6', preg_match('/^\s*auto\s+eth0\s*$/m', $ifaces) === 1, $ifaces);

echo "\nNetworkManager profile under /run and numeric interface names\n";
@mkdir($rootfs . '/run/NetworkManager/system-connections', 0755, true);
file_put_contents($rootfs . '/run/NetworkManager/system-connections/eth0.nmconnection', "[connection]\ninterface-name=eth0\n");
$r = GuestNetwork::apply('ct1', [$dhcpNic]);
check('NM /run profile suppresses false warning', strpos(implode(' ', $r['messages']), 'no connection profile') === false, implode(' ', $r['messages']));
$rejectedNumeric = false;
try {
    NetworkInterface::fromRequest(['name' => '0', 'ipv4Mode' => 'dhcp'], 0);
} catch (InvalidArgumentException $e) {
    $rejectedNumeric = true;
}
check('numeric interface name rejected', $rejectedNumeric);

echo "\n" . ($failures === 0 ? 'ALL TESTS PASSED' : "$failures TEST(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
