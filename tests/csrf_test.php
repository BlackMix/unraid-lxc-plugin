<?php
/**
 * CSRF regression test for ajax.php.
 * Run with: php tests/csrf_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = sys_get_temp_dir() . '/lxcv2-csrf';
@mkdir($base . '/data', 0755, true);
@mkdir('/boot/config/plugins/lxc', 0755, true);
@mkdir('/var/local/emhttp', 0755, true);
file_put_contents('/boot/config/plugins/lxc/lxc.conf', "lxc.lxcpath={$base}/data\n");
file_put_contents('/boot/config/plugins/lxc/plugin.cfg', "SERVICE=enabled\nBDEVTYPE=dir\nTIMEOUT=15\nAUTOSTART_DELAY=10\nDYNAMIC_STATS=enabled\nLXC_CONTAINER_URL=images.linuxcontainers.org\nLXC_BACKUP_SERVICE=disabled\nLXC_BACKUP_PATH=\n");

$varIni = '/var/local/emhttp/var.ini';
$hadVarIni = file_exists($varIni);
$oldVarIni = $hadVarIni ? file_get_contents($varIni) : null;

$runner = __DIR__ . '/ajax_runner.php';
$failures = 0;

function callAjax(string $runner, array $post, string $header = ''): array
{
    $args = [
        escapeshellarg(PHP_BINARY),
        escapeshellarg($runner),
        escapeshellarg(json_encode($post)),
    ];
    if ($header !== '') {
        $args[] = escapeshellarg($header);
    }
    $output = shell_exec(implode(' ', $args) . ' 2>&1');

    return [(string) $output, json_decode((string) $output, true)];
}

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

try {
    $token = 'test-csrf-token-123';

    echo "With a token in var.ini\n";
    file_put_contents($varIni, "csrf_token=\"{$token}\"\n");

    [$raw, $json] = callAjax($runner, ['lxc' => '', 'action' => 'getDevices']);
    check('rejects missing token', is_array($json) && ($json['ok'] ?? null) === false, $raw);
    check('error mentions CSRF', strpos($raw, 'CSRF') !== false);

    [$raw, $json] = callAjax($runner, ['lxc' => '', 'action' => 'getDevices', 'csrf_token' => 'wrong']);
    check('rejects wrong token', is_array($json) && ($json['ok'] ?? null) === false, $raw);

    [$raw, $json] = callAjax($runner, ['lxc' => '', 'action' => 'getDevices', 'csrf_token' => $token]);
    check('accepts POST token', is_array($json) && ($json['ok'] ?? true) === true, $raw);
    check('returns devices payload', is_array($json) && isset($json['gpus'], $json['usb'], $json['misc']), $raw);

    [$raw, $json] = callAjax($runner, ['lxc' => '', 'action' => 'getDevices'], $token);
    check('accepts X-CSRF-Token header', is_array($json) && ($json['ok'] ?? false) === true, $raw);

    echo "\nWithout a token in var.ini (older Unraid)\n";
    file_put_contents($varIni, "SOMETHING=else\n");
    [$raw, $json] = callAjax($runner, ['lxc' => '', 'action' => 'getDevices']);
    check('does not block when no token is published', is_array($json) && ($json['ok'] ?? false) === true, $raw);

    echo "\nWithout var.ini at all\n";
    @unlink($varIni);
    [$raw, $json] = callAjax($runner, ['lxc' => '', 'action' => 'getDevices']);
    check('does not block when var.ini is absent', is_array($json) && ($json['ok'] ?? false) === true, $raw);
} finally {
    if ($hadVarIni) {
        file_put_contents($varIni, (string) $oldVarIni);
    } else {
        @unlink($varIni);
    }
}

echo "\n" . ($failures === 0 ? 'ALL CSRF TESTS PASSED' : "$failures TEST(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
