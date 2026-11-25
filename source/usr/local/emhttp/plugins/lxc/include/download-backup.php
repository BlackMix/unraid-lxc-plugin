<?php
require_once __DIR__ . '/ProxmoxBackup.php';

$name = (string) ($_GET['container'] ?? '');
$file = (string) ($_GET['file'] ?? '');
$token = (string) ($_GET['token'] ?? '');

if ($name === '' || $file === '' || !ProxmoxBackup::verifyToken($name, $file, $token)) {
    http_response_code(403);
    exit;
}

$path = ProxmoxBackup::resolveBackupFile($name, $file);
if ($path === null) {
    http_response_code(404);
    exit;
}

$type = str_ends_with($file, '.zst') ? 'application/zstd' : 'application/gzip';
header('Content-Type: ' . $type);
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Length: ' . (string) filesize($path));

$handle = @fopen($path, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit;
}
while (!feof($handle)) {
    echo fread($handle, 65536);
    flush();
}
fclose($handle);
exit;
