<?php
/**
 * Render the plugin pages with a minimal Unraid stub to catch runtime errors.
 * Run with: php -d short_open_tag=1 tests/page_render_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

// The webGui provides this translation helper; stub it for standalone runs.
if (!function_exists('_')) {
    function _(string $text): string
    {
        return $text;
    }
}

if (!ini_get('short_open_tag')) {
    fwrite(STDERR, "short_open_tag must be enabled: php -d short_open_tag=1 tests/page_render_test.php\n");
    exit(1);
}

$base = sys_get_temp_dir() . '/lxcv2-render';
@mkdir($base . '/data/ct1/rootfs/etc/ssh', 0755, true);
@mkdir('/boot/config/plugins/lxc', 0755, true);
file_put_contents('/boot/config/plugins/lxc/lxc.conf', "lxc.lxcpath={$base}/data\n");
file_put_contents('/boot/config/plugins/lxc/plugin.cfg', "SERVICE=enabled\nBDEVTYPE=dir\nTIMEOUT=15\nAUTOSTART_DELAY=10\nDYNAMIC_STATS=enabled\nLXC_CONTAINER_URL=images.linuxcontainers.org\nLXC_BACKUP_SERVICE=disabled\n");
file_put_contents('/boot/config/plugins/lxc/default.conf', "lxc.net.0.type = veth\nlxc.net.0.link = br0\n");
file_put_contents($base . '/data/ct1/config', implode("\n", [
    'lxc.rootfs.path = dir:' . $base . '/data/ct1/rootfs',
    'lxc.uts.name = ct1',
    '# Parameters passed to the template: --dist debian --release bookworm',
    'lxc.start.auto = 1',
    'lxc.net.0.type = veth',
    'lxc.net.0.link = br0',
    'lxc.net.0.hwaddr = 52:54:00:AA:BB:CC',
    'lxc.net.0.ipv4.address = 192.168.1.50/24',
    '#container_description=Demo container',
]) . "\n");

$var = ['fsState' => 'Started', 'csrf_token' => 'test-page-csrf-token'];
$display = ['theme' => 'white'];

// csrf.php reads var.ini first (same source ajax.php validates against).
$varIni = '/var/local/emhttp/var.ini';
@mkdir('/var/local/emhttp', 0755, true);
$hadVarIni = file_exists($varIni);
$oldVarIni = $hadVarIni ? file_get_contents($varIni) : null;
file_put_contents($varIni, "csrf_token=\"test-page-csrf-token\"\n");

// Fake LXC binaries so the list pages have something to render.
$fakeBin = sys_get_temp_dir() . '/lxcv2-bin';
@mkdir($fakeBin, 0755, true);
file_put_contents($fakeBin . '/lxc-ls', "#!/bin/sh\nif [ \"\$1\" = \"--active\" ]; then echo ct1; else echo ct1; fi\n");
file_put_contents($fakeBin . '/lxc-info', "#!/bin/sh\ncase \"\$*\" in\n  *-sH*) echo RUNNING ;;\n  *-iH*) echo 192.168.1.50 ;;\n  *) echo \"State: RUNNING\" ;;\nesac\n");
file_put_contents($fakeBin . '/lxc-snapshot', "#!/bin/sh\necho 'No snapshots'\n");
foreach (glob($fakeBin . '/*') ?: [] as $bin) {
    @chmod($bin, 0755);
}
putenv('PATH=' . $fakeBin . ':' . getenv('PATH'));

// In the webGui, autov() prints the cache-busted path directly.
function autov(string $path): void
{
    echo $path;
}

function mk_option(string $selected, string $value, string $label): string
{
    return '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . $label . '</option>';
}

$pluginDir = __DIR__ . '/../source/usr/local/emhttp/plugins/lxc';

$css = file_get_contents($pluginDir . '/css/lxc.css');
check('CSS uses the Unraid theme variables', strpos($css, '--mild-background-color') !== false && strpos($css, '--border-color') !== false);

// The pages require files by their absolute Unraid path.
$emhttpPlugin = '/usr/local/emhttp/plugins/lxc';
if (!is_dir('/usr/local/emhttp/plugins')) {
    @mkdir('/usr/local/emhttp/plugins', 0755, true);
}
if (is_link($emhttpPlugin) || !file_exists($emhttpPlugin)) {
    @unlink($emhttpPlugin);
    @symlink(realpath($pluginDir), $emhttpPlugin);
}
if (!file_exists($emhttpPlugin . '/include/Paths.php')) {
    fwrite(STDERR, "Could not link $emhttpPlugin to the source tree.\n");
    exit(1);
}

/**
 * Strip the Unraid page header (everything up to the first `---`) and render.
 */
function renderPage(string $file, array $query = []): string
{
    $contents = file_get_contents($file);
    $parts = preg_split('/^---\s*$/m', $contents, 2);
    $body = $parts[1] ?? $contents;

    $_GET = $query;
    $tmp = tempnam(sys_get_temp_dir(), 'lxcv2page');
    file_put_contents($tmp, $body);

    // Unraid renders tab pages in the parent scope; emulate the shared state.
    global $settings, $var, $display;
    if (!isset($settings)) {
        require_once '/usr/local/emhttp/plugins/lxc/include/Settings.php';
        $settings = new Settings();
    }

    ob_start();
    try {
        include $tmp;
    } catch (Throwable $e) {
        ob_end_clean();
        unlink($tmp);
        throw $e;
    }
    $output = ob_get_clean();
    unlink($tmp);

    return $output;
}

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

/**
 * Structurally locate the <script> element containing the CSRF token and report
 * whether it is nested inside another <script> element. Tracks open/close depth
 * instead of relying on raw substring positions around the token.
 */
function csrfScriptIsNested(string $html): ?bool
{
    $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    $tokenPos = strpos($html, 'LXCV2_CSRF');
    if ($tokenPos === false) {
        return null;
    }
    $before = substr($html, 0, $tokenPos);
    $lastOpen = strrpos($before, '<script');
    if ($lastOpen === false) {
        return null;
    }
    $beforeLastOpen = substr($before, 0, $lastOpen);
    return substr_count($beforeLastOpen, '<script') > substr_count($beforeLastOpen, '</script>');
}

// Every page that loads lxc.js must publish the CSRF token before it, otherwise
// the ajaxPrefilter bails out and ajax.php rejects every POST (e.g. container
// creation) with "Invalid CSRF token".
$csrfPages = [];
foreach (glob($pluginDir . '/*.page') ?: [] as $pageFile) {
    if (strpos((string) file_get_contents($pageFile), 'lxc.js') === false) {
        continue;
    }
    $csrfPages[] = basename($pageFile);
}
$expectedCsrfPages = [
    'LXC.page', 'LXCAddContainer.page', 'LXCAddTemplate.page', 'LXCBackups.page',
    'LXCCopyContainer.page', 'LXCDashboard.page', 'LXCRestoreBackup.page',
    'LXCRestoreSnapshot.page', 'LXCSettingsMain.page', 'LXCSnapshots.page',
];
sort($csrfPages);
sort($expectedCsrfPages);
check('all lxc.js pages are known', $csrfPages === $expectedCsrfPages, implode(', ', $csrfPages));
foreach ($expectedCsrfPages as $page) {
    $file = $pluginDir . '/' . $page;
    check("$page exists and loads lxc.js", is_file($file) && strpos((string) file_get_contents($file), 'lxc.js') !== false);
}

// Scanner fixtures: a flat script must pass, a nested one must fail.
check('scanner accepts flat CSRF script', csrfScriptIsNested('<script>window.LXCV2_CSRF = "x";</script>') === false);
check('scanner rejects nested CSRF script', csrfScriptIsNested('<script><script>window.LXCV2_CSRF = "x";</script></script>') === true);
check('scanner ignores script tags in HTML comments', csrfScriptIsNested('<!-- <script> --><script>window.LXCV2_CSRF = "x";</script>') === false);

foreach ($csrfPages as $page) {
    $source = (string) file_get_contents($pluginDir . '/' . $page);
    $includePos = strpos($source, 'include/csrf.php');
    $scriptPos = strpos($source, 'lxc.js');
    check("$page includes csrf.php before lxc.js", $includePos !== false && $scriptPos !== false && $includePos < $scriptPos);
}

$pages = [
    'LXCEditContainer.page' => ['name' => 'ct1'],
    'LXCAddContainer.page' => [],
    'LXCSettingsMain.page' => [],
    'LXCDashboard.page' => [],
    'LXC.page' => [],
    'LXCSettingsMisc.page' => [],
];

foreach ($pages as $page => $query) {
    echo "\n$page\n";
    try {
        $output = renderPage($pluginDir . '/' . $page, $query);
        $errors = [];
        if (preg_match('/(Warning|Fatal error|Notice|Deprecated):/', $output, $m)) {
            $errors[] = $m[0];
        }
        check('renders without PHP errors', $errors === [], implode(', ', $errors));
        check('produces output', strlen(trim($output)) > 0);

        if (in_array($page, $csrfPages, true)) {
            check('publishes CSRF token', strpos($output, 'window.LXCV2_CSRF = "test-page-csrf-token"') !== false);
            check('exactly one CSRF token emission', substr_count($output, 'window.LXCV2_CSRF') === 1);
            check('CSRF script is not nested in another script', csrfScriptIsNested($output) === false);
            preg_match_all('/<script[^>]*src=["\'][^"\']*lxc\.js[^"\']*["\'][^>]*>/i', $output, $scriptTags);
            check('loads lxc.js exactly once', count($scriptTags[0]) === 1);
        }

        if ($page === 'LXCEditContainer.page') {
            check('shows container name', strpos($output, 'ct1') !== false);
            check('has network tab', strpos($output, 'id="tab-network"') !== false);
            check('has devices tab', strpos($output, 'id="tab-devices"') !== false);
            check('has ssh tab', strpos($output, 'id="tab-ssh"') !== false);
            check('preloads nic json', strpos($output, 'window.LXCV2_EDIT') !== false);
            check('nic json has static ipv4', strpos($output, '192.168.1.50/24') !== false);
            check('nic json exposes dns', strpos($output, '"dns"') !== false);
            check('detects distribution', strpos($output, 'debian.png') !== false);
            check('description rendered', strpos($output, 'Demo container') !== false);
            $scriptPos = strpos($output, 'lxc-edit.js');
            $tabsPos = strpos($output, 'lxcv2-tab');
            check('editor script loads after the tabs', $scriptPos !== false && $tabsPos !== false && $scriptPos > $tabsPos);
        }

        if ($page === 'LXC.page') {
            check('has actions column', strpos($output, 'Actions') !== false);
            check('has inline terminal icon', strpos($output, 'fa-terminal') !== false);
            check('links to editor', strpos($output, '/LXCEditContainer?name=ct1') !== false);
            check('actions are plain icons', strpos($output, 'lxcv2-row-icon') !== false);
            check('actions have no button frame', strpos($output, 'lxcv2-icon-btn') === false);
            check('actions are anchors, not buttons', strpos($output, '<button type="button" class="lxcv2-row-icon') === false);
            check('stats tolerate parsed JSON', strpos($output, 'lxcAsArray') !== false);
            check('stats skip unchanged values', strpos($output, 'function lxcSet') !== false);
            check('stats render sparklines', strpos($output, 'lxcv2-spark') !== false);
            check('stats keep update guard', strpos($output, 'function lxcAccept') !== false);
            check('stats do not shadow window.history', strpos($output, 'var history =') === false && strpos($output, 'lxcHistory') !== false);
            check('stats history capped', strpos($output, 'series.length > 24') !== false);
            check('sparkline columns sized', strpos($output, "width: 210px; min-width: 210px;") !== false);
            check('no direct JSON.parse on response', strpos($output, 'JSON.parse(response)') === false);
            check('stat label is width constrained', strpos($css, '.lxcv2-stat-value') !== false && strpos($css, 'max-width: 120px') !== false);
        }

        if ($page === 'LXCAddContainer.page') {
            check('loads lxc.js even without an image list', strpos($output, '/plugins/lxc/js/lxc.js') !== false);
            check('has network type field', strpos($output, 'contNetType') !== false);
            check('has vlan field', strpos($output, 'contNetVlan') !== false);
            check('has ipv6 field', strpos($output, 'contIpv6Mode') !== false);
            check('has DNS field', strpos($output, 'contDns') !== false);
        }
    } catch (Throwable $e) {
        $failures++;
        echo '  FAIL exception -> ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

if ($hadVarIni) {
    file_put_contents($varIni, (string) $oldVarIni);
} else {
    @unlink($varIni);
}

echo "\n" . ($failures === 0 ? 'ALL PAGES RENDER' : "$failures CHECK(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
