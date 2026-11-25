<?php
/**
 * Background worker for Proxmox backups.
 * Invoked by ajax.php with: name vmid jobid
 */
require_once __DIR__ . '/ProxmoxBackup.php';

$name = (string) ($argv[1] ?? '');
$vmid = (int) ($argv[2] ?? 0);
$jobId = (string) ($argv[3] ?? '');

error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    ProxmoxBackup::create($name, $vmid, $jobId);
} catch (Throwable $e) {
    ProxmoxBackup::writeStatus($jobId, 'error', 100, time(), 0, 0, [
        'error' => 'Worker failed: ' . $e->getMessage(),
    ]);
}
