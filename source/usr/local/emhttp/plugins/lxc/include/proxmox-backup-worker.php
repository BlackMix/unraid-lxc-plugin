<?php
/**
 * Background worker for Proxmox backups.
 * Invoked by ajax.php with: name vmid jobid
 */
require_once __DIR__ . '/ProxmoxBackup.php';

$name = (string) ($argv[1] ?? '');
$vmid = (int) ($argv[2] ?? 0);
$jobId = (string) ($argv[3] ?? '');

ProxmoxBackup::create($name, $vmid, $jobId);
