<?php
/** Runner used by csrf_test.php to call ajax.php in a clean process. */
$_POST = json_decode((string) ($argv[1] ?? '{}'), true) ?: [];
if (isset($argv[2]) && $argv[2] !== '') {
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $argv[2];
}
require __DIR__ . '/../source/usr/local/emhttp/plugins/lxc/include/ajax.php';
