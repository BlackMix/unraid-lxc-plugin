<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/ContainerConfig.php';
require_once __DIR__ . '/Network.php';
require_once __DIR__ . '/GuestNetwork.php';
require_once __DIR__ . '/ProxmoxBackup.php';
require_once __DIR__ . '/Devices.php';
require_once __DIR__ . '/Resources.php';
require_once __DIR__ . '/Ssh.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * @return never
 */
function lxcJson(bool $ok, string $message, array $extra = [])
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

/**
 * Defensive CSRF validation.
 *
 * The webGui already validates every same-origin POST in local_prepend.php and
 * injects the token into jQuery requests. This adds a second check for the
 * plugin endpoints. On releases without a token in var.ini (older Unraid) the
 * check is skipped so the plugin keeps working.
 */
function lxcValidateCsrf(): void
{
    $var = @parse_ini_file('/var/local/emhttp/var.ini');
    $expected = is_array($var) ? ($var['csrf_token'] ?? null) : null;
    if (!is_string($expected) || $expected === '') {
        return;
    }

    $provided = (string) ($_POST['csrf_token'] ?? '');
    if ($provided === '') {
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'x-csrf-token') {
                    $provided = (string) $value;
                    break;
                }
            }
        }
        if ($provided === '' && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $provided = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
    }

    if (!hash_equals($expected, $provided)) {
        lxcJson(false, 'Invalid CSRF token. Reload the page and try again.');
    }
}

lxcValidateCsrf();

/**
 * Reject anything that is not a real container name.
 */
function requireContainer(): string
{
    $name = (string) ($_POST['container'] ?? '');
    if (!LxcPaths::isValidContainerName($name) || !LxcPaths::containerExists($name)) {
        lxcJson(false, 'Unknown or invalid container.');
    }

    return $name;
}

if (isset($_POST['lxc'])) {
    $settings = new Settings();

    switch ($_POST['action']) {
        // ---------------------------------------------------- lifecycle actions
        case 'startCONT':
            $name = requireContainer();
            $container = new Container($name);
            $container->startContainer();
            lxcJson(true, 'Container started.');

        case 'stopCONT':
            $name = requireContainer();
            $container = new Container($name);
            $container->stopContainer();
            lxcJson(true, 'Container stopped.');

        case 'startALLCONT':
            foreach (getAllContainers() as $cont) {
                if ($cont->state === 'STOPPED' || $cont->state === 'FROZEN') {
                    (new Container($cont->name))->startContainer();
                }
            }
            lxcJson(true, 'All containers started.');

        case 'stopALLCONT':
            foreach (getAllContainers() as $cont) {
                if ($cont->state === 'RUNNING' || $cont->state === 'FROZEN') {
                    (new Container($cont->name))->stopContainer();
                }
            }
            lxcJson(true, 'All containers stopped.');

        case 'restartCONT':
            $name = requireContainer();
            (new Container($name))->restartContainer();
            lxcJson(true, 'Container restarted.');

        case 'freezeCONT':
            $name = requireContainer();
            (new Container($name))->freezeContainer();
            lxcJson(true, 'Container frozen.');

        case 'freezeALLCONT':
            foreach (getAllContainers() as $cont) {
                if ($cont->state === 'RUNNING') {
                    (new Container($cont->name))->freezeContainer();
                }
            }
            lxcJson(true, 'All containers frozen.');

        case 'unfreezeCONT':
            $name = requireContainer();
            (new Container($name))->unfreezeContainer();
            lxcJson(true, 'Container unfrozen.');

        case 'unfreezeALLCONT':
            foreach (getAllContainers() as $cont) {
                if ($cont->state === 'FROZEN') {
                    (new Container($cont->name))->unfreezeContainer();
                }
            }
            lxcJson(true, 'All containers unfrozen.');

        case 'killCONT':
            $name = requireContainer();
            (new Container($name))->killContainer();
            lxcJson(true, 'Container killed.');

        case 'autostart':
            $name = requireContainer();
            (new Container($name))->setAutostart(($_POST['autostart'] ?? '') === 'true' ? 1 : 0);
            lxcJson(true, 'Autostart updated.');

        case 'destroyCONT':
            $name = requireContainer();
            ob_start();
            (new Container($name))->destroyContainer();
            $output = ob_get_clean();
            lxcJson(strpos((string) $output, 'ERROR') === false, trim((string) $output) ?: 'Container destroyed.');

        case 'snapshotCONT':
            $name = requireContainer();
            ob_start();
            (new Container($name))->createSnapshot();
            $output = ob_get_clean();
            lxcJson(strpos((string) $output, 'ERROR') === false, trim((string) $output) ?: 'Snapshot created.');

        case 'deleteSNAP':
            $name = requireContainer();
            (new Container($name))->deleteSnapshot((string) ($_POST['snapshot'] ?? ''));
            lxcJson(true, 'Snapshot deleted.');

        case 'backupCONT':
            $name = requireContainer();
            ob_start();
            (new Container($name))->createBackup();
            $output = ob_get_clean();
            lxcJson(strpos((string) $output, 'ERROR') === false, trim((string) $output) ?: 'Backup created.');

        case 'deleteBACKUP':
            $name = requireContainer();
            (new Container($name))->deleteBackup((string) ($_POST['backup'] ?? ''));
            lxcJson(true, 'Backup deleted.');

        // ------------------------------------------------------- create / copy
        case 'createCONT':
            $name = (string) ($_POST['name'] ?? '');
            if (!LxcPaths::isValidContainerName($name)) {
                lxcJson(false, 'Invalid container name.');
            }

            $startAfterCreate = (string) ($_POST['startcont'] ?? '') === 'true';
            $interfaces = null;
            $networkRaw = (string) ($_POST['network'] ?? '');
            if ($networkRaw !== '') {
                $payload = json_decode($networkRaw, true);
                if (is_array($payload)) {
                    try {
                        $interfaces = [NetworkInterface::fromRequest($payload, 0)];
                    } catch (InvalidArgumentException $e) {
                        lxcJson(false, $e->getMessage());
                    }
                }
            }

            $warnings = [];
            ob_start();
            createContainer(
                $name,
                (string) ($_POST['description'] ?? ''),
                (string) ($_POST['distribution'] ?? ''),
                (string) ($_POST['release'] ?? ''),
                $interfaces !== null ? 'false' : (string) ($_POST['startcont'] ?? ''),
                (string) ($_POST['autostart'] ?? ''),
                (string) ($_POST['mac'] ?? '')
            );
            $output = ob_get_clean();

            // Apply the network before the first boot so the container never
            // starts with the template default interface.
            if ($interfaces !== null && LxcPaths::containerExists($name)) {
                if (!Network::write(new ContainerConfig($name), $interfaces)) {
                    lxcJson(false, 'Container created but NOT started: its network configuration could not be saved. Fix it and start the container manually.');
                }
                $guest = GuestNetwork::apply($name, $interfaces);
                if (!$guest['ok']) {
                    $soft = false;
                    foreach ($guest['messages'] as $gm) {
                        if (stripos($gm, 'not supported') !== false
                            || stripos($gm, 'not mounted') !== false
                            || stripos($gm, 'outside this container') !== false) {
                            $soft = true;
                        }
                    }
                    if (!$soft) {
                        lxcJson(false, 'Container created but NOT started: guest network configuration failed. ' . implode(' ', $guest['messages']) . ' Fix it and start the container manually.');
                    }
                    $warnings = $guest['messages'];
                }
                if ($startAfterCreate) {
                    (new Container($name))->startContainer();
                }
            }

            $message = trim((string) $output) ?: 'Container created.';
            if ($warnings !== []) {
                $message .= ' ' . implode(' ', $warnings);
            }
            lxcJson(strpos((string) $output, 'ERROR') === false, $message, ['warnings' => $warnings]);

        case 'copyCONT':
            $name = (string) ($_POST['name'] ?? '');
            $source = (string) ($_POST['container'] ?? '');
            if (!LxcPaths::isValidContainerName($name) || !LxcPaths::containerExists($source)) {
                lxcJson(false, 'Invalid container name.');
            }
            ob_start();
            copyContainer($name, (string) ($_POST['description'] ?? ''), $source, (string) ($_POST['autostart'] ?? ''), (string) ($_POST['mac'] ?? ''));
            $output = ob_get_clean();
            lxcJson(true, (trim((string) $output) ?: 'Container copied.') . ' Note: the static IP and DNS from the source container were inherited; change them before starting both containers on the same network.');

        case 'fromSnapshot':
            $name = (string) ($_POST['name'] ?? '');
            if (!LxcPaths::isValidContainerName($name)) {
                lxcJson(false, 'Invalid container name.');
            }
            ob_start();
            createFromSnapshot($name, (string) ($_POST['description'] ?? ''), (string) ($_POST['container'] ?? ''), (string) ($_POST['snapshot'] ?? ''), (string) ($_POST['autostart'] ?? ''), (string) ($_POST['mac'] ?? ''));
            lxcJson(true, (trim((string) ob_get_clean()) ?: 'Container restored.') . ' Note: the stored static IP and DNS were restored; check for IP conflicts before starting it.');

        case 'fromBackup':
            $name = (string) ($_POST['name'] ?? '');
            if (!LxcPaths::isValidContainerName($name)) {
                lxcJson(false, 'Invalid container name.');
            }
            ob_start();
            createFromBackup($name, (string) ($_POST['description'] ?? ''), (string) ($_POST['container'] ?? ''), (string) ($_POST['backup'] ?? ''), (string) ($_POST['autostart'] ?? ''), (string) ($_POST['mac'] ?? ''));
            lxcJson(true, (trim((string) ob_get_clean()) ?: 'Container restored.') . ' Note: the stored static IP and DNS were restored; check for IP conflicts before starting it.');

        case 'createTEMPLATE':
            $name = (string) ($_POST['name'] ?? '');
            if (!LxcPaths::isValidContainerName($name)) {
                lxcJson(false, 'Invalid container name.');
            }
            ob_start();
            createfromTemplate(
                $name,
                (string) ($_POST['description'] ?? ''),
                (string) ($_POST['repository'] ?? ''),
                (string) ($_POST['webui'] ?? ''),
                (string) ($_POST['icon'] ?? ''),
                (string) ($_POST['startcont'] ?? ''),
                (string) ($_POST['autostart'] ?? ''),
                (string) ($_POST['convertbdev'] ?? ''),
                (string) ($_POST['mac'] ?? ''),
                (string) ($_POST['supportlink'] ?? ''),
                (string) ($_POST['donatelink'] ?? '')
            );
            $output = ob_get_clean();
            lxcJson(strpos((string) $output, 'ERROR') === false, trim((string) $output) ?: 'Container created.');

        // ----------------------------------------------------- metadata actions
        case 'setDescription':
            $name = requireContainer();
            (new Container($name))->setDescription((string) ($_POST['description'] ?? ''));
            lxcJson(true, 'Description saved.');

        case 'delDescription':
            $name = requireContainer();
            (new Container($name))->delDescription();
            lxcJson(true, 'Description removed.');

        case 'setWebUIURL':
            $name = requireContainer();
            (new Container($name))->setWebuiurl((string) ($_POST['webuiurl'] ?? ''));
            lxcJson(true, 'WebUI URL saved.');

        case 'delWebUIURL':
            $name = requireContainer();
            (new Container($name))->delWebuiurl();
            lxcJson(true, 'WebUI URL removed.');

        // ------------------------------------------------------------ raw config
        case 'showConfig':
            $name = requireContainer();
            header('Content-Type: text/plain; charset=UTF-8');
            echo (new ContainerConfig($name))->read();
            exit;

        case 'saveConfig':
            $name = requireContainer();
            $updated = (string) ($_POST['updatedConfig'] ?? '');
            $path = LxcPaths::containerConfig($name);
            if (is_file($path)) {
                @copy($path, $path . '.bak');
            }
            file_put_contents($path, $updated);
            $container = new Container($name);
            if ($container->state === 'RUNNING') {
                $container->restartContainer();
            }
            lxcJson(true, 'Configuration saved.');

        // ------------------------------------------------------ LXC v2 additions
        case 'saveGeneral':
            $name = requireContainer();
            $config = new ContainerConfig($name);
            $config->set('#container_description', trim((string) ($_POST['description'] ?? '')) ?: null);
            $config->set('#container_webui', trim((string) ($_POST['webui'] ?? '')) ?: null);
            $config->set('#container_supportlink', trim((string) ($_POST['supportlink'] ?? '')) ?: null);
            $config->set('#container_donatelink', trim((string) ($_POST['donatelink'] ?? '')) ?: null);

            $order = trim((string) ($_POST['order'] ?? ''));
            $config->set('#container_order', ($order !== '' && ctype_digit($order)) ? $order : null);
            $config->set('lxc.start.auto', ($_POST['autostart'] ?? '0') === '1' ? '1' : '0');
            $config->save();

            try {
                Resources::write(new ContainerConfig($name), [
                    'memoryMiB' => (string) ($_POST['memoryMiB'] ?? ''),
                    'swapMiB' => (string) ($_POST['swapMiB'] ?? ''),
                    'cpuset' => (string) ($_POST['cpuset'] ?? ''),
                    'cpuWeight' => (string) ($_POST['cpuWeight'] ?? ''),
                ]);
            } catch (InvalidArgumentException $e) {
                lxcJson(false, $e->getMessage());
            }

            lxcJson(true, 'General settings saved.');

        case 'saveNetwork':
            $name = requireContainer();
            $payload = json_decode((string) ($_POST['nics'] ?? '[]'), true);
            if (!is_array($payload) || $payload === []) {
                lxcJson(false, 'No interface provided.');
            }

            try {
                $interfaces = [];
                foreach (array_values($payload) as $index => $nicData) {
                    if (!is_array($nicData)) {
                        continue;
                    }
                    $interfaces[] = NetworkInterface::fromRequest($nicData, $index);
                }
            } catch (InvalidArgumentException $e) {
                lxcJson(false, $e->getMessage());
            }

            if ($interfaces === []) {
                lxcJson(false, 'No valid interface provided.');
            }

            $config = new ContainerConfig($name);
            if (!Network::write($config, $interfaces)) {
                lxcJson(false, 'Could not save the container configuration.');
            }

            // macvlan VLANs need a matching sub-interface on the host.
            $warnings = [];
            foreach ($interfaces as $nic) {
                if ($nic->type !== 'macvlan' || $nic->vlanId === null) {
                    continue;
                }
                $link = $nic->effectiveLink();
                if (!is_dir('/sys/class/net/' . $link)) {
                    $warnings[] = 'VLAN ' . $nic->vlanId . ': crie a sub-interface ' . $link . ' no host antes de iniciar o container.';
                }
            }

            $guest = GuestNetwork::apply($name, $interfaces);
            $warnings = array_merge($warnings, $guest['messages']);
            if (!$guest['ok']) {
                lxcJson(false, 'Network saved, but the guest configuration failed: ' . implode(' ', $guest['messages']), ['warnings' => $warnings]);
            }

            $running = trim((string) LxcPaths::capture(['lxc-info', '-n', $name, '-sH'])) === 'RUNNING';
            $message = $running ? 'Network saved. Restart the container to apply it.' : 'Network saved.';
            lxcJson(true, $message . ($warnings !== [] ? ' ' . implode(' ', $warnings) : ''), ['warnings' => $warnings]);

        case 'saveDevices':
            $name = requireContainer();
            $gpus = json_decode((string) ($_POST['gpus'] ?? '[]'), true);
            $usb = json_decode((string) ($_POST['usb'] ?? '[]'), true);
            $misc = json_decode((string) ($_POST['misc'] ?? '[]'), true);

            $config = new ContainerConfig($name);
            Devices::write($config, [
                'gpus' => is_array($gpus) ? $gpus : [],
                'usb' => is_array($usb) ? $usb : [],
                'misc' => is_array($misc) ? $misc : [],
            ]);
            Devices::writeUdevRules();

            $running = trim((string) LxcPaths::capture(['lxc-info', '-n', $name, '-sH'])) === 'RUNNING';
            lxcJson(true, $running ? 'Devices saved. Restart the container to apply them.' : 'Devices saved.');

        case 'saveSsh':
            $name = requireContainer();
            $result = Ssh::apply($name, [
                'rootPassword' => (string) ($_POST['rootPassword'] ?? ''),
                'permitRootLogin' => (string) ($_POST['permitRootLogin'] ?? 'prohibit-password'),
                'passwordAuthentication' => ($_POST['passwordAuthentication'] ?? '0') === '1',
                'user' => (string) ($_POST['user'] ?? ''),
                'userPassword' => (string) ($_POST['userPassword'] ?? ''),
                'userSudo' => ($_POST['userSudo'] ?? '0') === '1',
                'installOpenssh' => ($_POST['installOpenssh'] ?? '0') === '1',
            ]);
            lxcJson($result['ok'], implode(' ', $result['messages']), ['messages' => $result['messages']]);

        case 'saveRawConfig':
            $name = requireContainer();
            $raw = (string) ($_POST['rawConfig'] ?? '');
            if (trim($raw) === '') {
                lxcJson(false, 'Refusing to write an empty configuration.');
            }
            $path = LxcPaths::containerConfig($name);
            if (is_file($path)) {
                @copy($path, $path . '.bak');
            }
            if (file_put_contents($path, $raw) === false) {
                lxcJson(false, 'Could not write the configuration file.');
            }
            $state = trim((string) LxcPaths::capture(['lxc-info', '-n', $name, '-sH']));
            if ($state === 'RUNNING') {
                (new Container($name))->restartContainer();
            }
            lxcJson(true, 'Configuration saved.');

        case 'getDevices':
            lxcJson(true, 'ok', [
                'gpus' => Devices::listGpus(),
                'usb' => Devices::listUsb(),
                'misc' => Devices::listMisc(),
            ]);

        case 'createProxmoxBackup':
            // Let the polling requests read progress while this job runs.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $name = requireContainer();
            $vmid = (int) ($_POST['vmid'] ?? 0);
            $jobId = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['job'] ?? '')) ?: bin2hex(random_bytes(8));
            // Create the status synchronously so the first poll always finds
            // the job even if the background worker has not started yet.
            ProxmoxBackup::writeStatus($jobId, 'preparing', 0, time());
            $worker = escapeshellarg(__DIR__ . '/proxmox-backup-worker.php');
            $phpBin = escapeshellarg(PHP_BINARY === '' ? 'php' : PHP_BINARY);
            $log = escapeshellarg(sys_get_temp_dir() . '/lxc-proxmox-' . $jobId . '.log');
            $cmd = 'nohup ' . $phpBin . ' ' . $worker . ' ' . escapeshellarg($name) . ' '
                . escapeshellarg((string) $vmid) . ' ' . escapeshellarg($jobId)
                . ' > ' . $log . ' 2>&1 &';
            exec($cmd, $out, $rc);
            lxcJson(true, 'Proxmox backup started.', ['job' => $jobId, 'started' => true]);

        case 'getProxmoxBackupProgress':
            // Release the session lock so polling is not blocked by the
            // long-running backup request that holds the same session.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $jobId = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['job'] ?? ''));
            $status = ProxmoxBackup::readStatus($jobId);
            if ($status === null) {
                lxcJson(false, 'Unknown backup job.');
            }
            lxcJson(true, 'ok', ['status' => $status]);

        case 'deleteProxmoxBackup':
            $name = requireContainer();
            $file = (string) ($_POST['file'] ?? '');
            lxcJson(ProxmoxBackup::delete($name, $file), 'Proxmox backup deleted.');

        case 'getDownloadLink':
            $name = requireContainer();
            $file = (string) ($_POST['file'] ?? '');
            if (ProxmoxBackup::resolveBackupFile($name, $file) === null) {
                lxcJson(false, 'Proxmox backup not found.');
            }
            $token = ProxmoxBackup::token($name, $file);
            lxcJson(true, '/plugins/lxc/include/download-backup.php?container=' . urlencode($name)
                . '&file=' . urlencode($file) . '&token=' . urlencode($token));

        default:
            lxcJson(false, 'Unknown action.');
    }
}

// ---------------------------------------------------------------- stats polling
if (isset($_POST['action']) && $_POST['action'] === 'updateValues') {
    file_put_contents('/tmp/lxc/containers/active', time());
    $containerNames = json_decode((string) ($_POST['containerNames'] ?? '[]'), true);
    $data = [];
    foreach (is_array($containerNames) ? $containerNames : [] as $name) {
        if (!LxcPaths::isValidContainerName((string) $name) || !LxcPaths::containerExists((string) $name)) {
            continue;
        }
        $container = new Container((string) $name);
        $data[] = [
            'name' => (string) $name,
            'cpu_usage' => $container->cpu_usage,
            'memoryUse' => $container->memoryUse,
            'ips' => $container->ips,
            'totalBytes' => $container->totalBytes,
        ];
    }
    echo json_encode($data);
    exit;
}

lxcJson(false, 'Nothing to do.');
