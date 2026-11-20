<?php
require_once __DIR__ . '/ContainerConfig.php';

/**
 * SSH provisioning for a container.
 *
 * Works on running containers through `lxc-attach` and on stopped containers by
 * writing into the rootfs directly (or chroot-ing into it). Passwords are always
 * passed on stdin, never on the command line and never logged.
 */
final class Ssh
{
    private const DROPIN = '/etc/ssh/sshd_config.d/99-lxcv2.conf';

    /**
     * @return array{permitRootLogin:string,passwordAuthentication:bool,rootPasswordSet:bool,user:string}
     */
    public static function read(ContainerConfig $config): array
    {
        $state = [
            'permitRootLogin' => 'prohibit-password',
            'passwordAuthentication' => false,
            'rootPasswordSet' => false,
            'user' => '',
        ];

        foreach ($config->block(ContainerConfig::TAG_SSH) as $line) {
            $line = trim($line);
            if (strpos($line, '#@permitrootlogin ') === 0) {
                $state['permitRootLogin'] = trim(substr($line, 17));
            } elseif (strpos($line, '#@passwordauthentication ') === 0) {
                $state['passwordAuthentication'] = trim(substr($line, 24)) === 'yes';
            } elseif (strpos($line, '#@rootpassword ') === 0) {
                $state['rootPasswordSet'] = trim(substr($line, 15)) === 'set';
            } elseif (strpos($line, '#@user ') === 0) {
                $state['user'] = trim(substr($line, 7));
            }
        }

        return $state;
    }

    public static function rootfs(string $name): string
    {
        $config = new ContainerConfig($name);
        $rootfs = $config->get('lxc.rootfs.path');
        if (is_string($rootfs) && $rootfs !== '') {
            if (preg_match('/^[a-z]+:(.*)$/i', $rootfs, $m) === 1) {
                return $m[1];
            }

            return $rootfs;
        }

        return LxcPaths::containerDir($name) . '/rootfs';
    }

    public static function isRunning(string $name): bool
    {
        [, $stdout] = LxcPaths::run(['lxc-info', '-n', $name, '-sH']);

        return trim($stdout) === 'RUNNING';
    }

    /**
     * @param array{rootPassword?:string,permitRootLogin?:string,passwordAuthentication?:bool,user?:string,userPassword?:string,userSudo?:bool,installOpenssh?:bool} $options
     * @return array{ok:bool,messages:string[]}
     */
    public static function apply(string $name, array $options): array
    {
        $messages = [];
        $ok = true;

        $permitRootLogin = in_array(($options['permitRootLogin'] ?? 'prohibit-password'), ['yes', 'no', 'prohibit-password', 'forced-commands-only'], true)
            ? (string) $options['permitRootLogin']
            : 'prohibit-password';
        $passwordAuthentication = !empty($options['passwordAuthentication']);

        $rootfs = self::rootfs($name);
        if (!is_dir($rootfs)) {
            return ['ok' => false, 'messages' => ['Root filesystem not found: ' . $rootfs]];
        }

        $running = self::isRunning($name);

        if (!empty($options['installOpenssh'])) {
            [$installed, $output] = self::installOpenssh($name, $rootfs, $running);
            $messages[] = $installed ? 'OpenSSH server installed.' : 'Could not install OpenSSH server: ' . $output;
            $ok = $ok && $installed;
        }

        // Always write the drop-in: it is harmless when sshd is not installed.
        $dropinDir = $rootfs . '/etc/ssh/sshd_config.d';
        LxcPaths::ensureDir($dropinDir);
        $dropin = "# Managed by the LXC v2 plugin\n"
            . 'PermitRootLogin ' . $permitRootLogin . "\n"
            . 'PasswordAuthentication ' . ($passwordAuthentication ? 'yes' : 'no') . "\n"
            . "PubkeyAuthentication yes\n";
        if (@file_put_contents($dropinDir . '/99-lxcv2.conf', $dropin) === false) {
            $messages[] = 'Failed to write the sshd drop-in configuration.';
            $ok = false;
        } else {
            $messages[] = 'sshd drop-in written (' . self::DROPIN . ').';
        }

        self::ensureDropinIncluded($rootfs);

        $rootPassword = (string) ($options['rootPassword'] ?? '');
        if ($rootPassword !== '') {
            [$changed, $error] = self::setPassword($name, $rootfs, $running, 'root', $rootPassword);
            $messages[] = $changed ? 'Root password updated.' : 'Failed to set the root password: ' . $error;
            $ok = $ok && $changed;
        }

        $user = trim((string) ($options['user'] ?? ''));
        $userPassword = (string) ($options['userPassword'] ?? '');
        if ($user !== '') {
            if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) !== 1) {
                $messages[] = 'Invalid user name, expected [a-z_][a-z0-9_-]{0,31}.';
                $ok = false;
            } else {
                [$created, $createError] = self::ensureUser($name, $rootfs, $running, $user, !empty($options['userSudo']));
                if (!$created) {
                    $messages[] = 'Failed to create user ' . $user . ': ' . $createError;
                    $ok = false;
                } else {
                    $messages[] = 'User ' . $user . ' is present.';
                }

                if ($userPassword !== '') {
                    [$changed, $error] = self::setPassword($name, $rootfs, $running, $user, $userPassword);
                    $messages[] = $changed ? 'Password for ' . $user . ' updated.' : 'Failed to set the password for ' . $user . ': ' . $error;
                    $ok = $ok && $changed;
                }
            }
        }

        if ($running) {
            [$reloaded, $reloadError] = self::restartSshd($name);
            $messages[] = $reloaded ? 'sshd restarted.' : 'Could not restart sshd: ' . $reloadError;
        } else {
            self::enableSshdOffline($rootfs);
            $messages[] = 'Container is stopped: sshd will be enabled on next boot.';
        }

        $config = new ContainerConfig($name);
        $body = [
            '#@permitrootlogin ' . $permitRootLogin,
            '#@passwordauthentication ' . ($passwordAuthentication ? 'yes' : 'no'),
            '#@rootpassword ' . ($rootPassword !== '' ? 'set' : (self::read($config)['rootPasswordSet'] ? 'set' : 'unset')),
            '#@user ' . $user,
        ];
        $config->setBlock(ContainerConfig::TAG_SSH, $body);
        $config->save();

        return ['ok' => $ok, 'messages' => $messages];
    }

    /**
     * @return array{0:bool,1:string}
     */
    private static function setPassword(string $name, string $rootfs, bool $running, string $user, string $password): array
    {
        if (strpos($password, "\n") !== false || strpos($password, "\r") !== false) {
            return [false, 'password must not contain line breaks'];
        }

        $payload = $user . ':' . $password . "\n";

        if ($running) {
            [$code, , $stderr] = LxcPaths::run(['lxc-attach', '-n', $name, '--', 'chpasswd'], $payload);

            return [$code === 0, trim($stderr)];
        }

        [$code, , $stderr] = LxcPaths::run(['chroot', $rootfs, 'chpasswd'], $payload);

        return [$code === 0, trim($stderr)];
    }

    /**
     * @return array{0:bool,1:string}
     */
    private static function ensureUser(string $name, string $rootfs, bool $running, string $user, bool $sudo): array
    {
        $script = 'id -u ' . escapeshellarg($user) . ' >/dev/null 2>&1 || '
            . 'useradd -m -s /bin/bash ' . escapeshellarg($user);
        if ($sudo) {
            $script .= ' ; (usermod -aG sudo ' . escapeshellarg($user) . ' 2>/dev/null'
                . ' || usermod -aG wheel ' . escapeshellarg($user) . ' 2>/dev/null'
                . ' || true)';
        }

        if ($running) {
            [$code, , $stderr] = LxcPaths::run(['lxc-attach', '-n', $name, '--', '/bin/sh', '-c', $script]);

            return [$code === 0, trim($stderr)];
        }

        [$code, , $stderr] = LxcPaths::run(['chroot', $rootfs, '/bin/sh', '-c', $script]);

        return [$code === 0, trim($stderr)];
    }

    /**
     * @return array{0:bool,1:string}
     */
    private static function restartSshd(string $name): array
    {
        $script = 'systemctl restart ssh 2>/dev/null'
            . ' || systemctl restart sshd 2>/dev/null'
            . ' || rc-service sshd restart 2>/dev/null'
            . ' || service ssh restart 2>/dev/null';

        [$code, , $stderr] = LxcPaths::run(['lxc-attach', '-n', $name, '--', '/bin/sh', '-c', $script]);

        return [$code === 0, trim($stderr)];
    }

    private static function enableSshdOffline(string $rootfs): void
    {
        $link = $rootfs . '/etc/systemd/system/multi-user.target.wants/ssh.service';
        if (is_dir($rootfs . '/etc/systemd/system') && !file_exists($link)) {
            $candidates = ['/lib/systemd/system/ssh.service', '/usr/lib/systemd/system/ssh.service', '/lib/systemd/system/sshd.service', '/usr/lib/systemd/system/sshd.service'];
            foreach ($candidates as $candidate) {
                if (file_exists($rootfs . $candidate)) {
                    LxcPaths::ensureDir(dirname($link));
                    @symlink($candidate, $link);
                    break;
                }
            }
        }

        // Alpine / OpenRC
        $runlevel = $rootfs . '/etc/runlevels/default/sshd';
        if (is_dir($rootfs . '/etc/runlevels/default') && !file_exists($runlevel)) {
            $initd = $rootfs . '/etc/init.d/sshd';
            if (file_exists($initd)) {
                @symlink('/etc/init.d/sshd', $runlevel);
            }
        }
    }

    private static function ensureDropinIncluded(string $rootfs): void
    {
        $main = $rootfs . '/etc/ssh/sshd_config';
        if (!is_file($main)) {
            return;
        }

        $contents = (string) file_get_contents($main);
        if (stripos($contents, 'sshd_config.d') !== false) {
            return;
        }

        @file_put_contents($main, "Include /etc/ssh/sshd_config.d/*.conf\n" . $contents);
    }

    /**
     * @return array{0:bool,1:string}
     */
    private static function installOpenssh(string $name, string $rootfs, bool $running): array
    {
        $script = 'if command -v apt-get >/dev/null 2>&1; then '
            . 'DEBIAN_FRONTEND=noninteractive apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq openssh-server; '
            . 'elif command -v dnf >/dev/null 2>&1; then dnf install -y -q openssh-server; '
            . 'elif command -v yum >/dev/null 2>&1; then yum install -y -q openssh-server; '
            . 'elif command -v pacman >/dev/null 2>&1; then pacman -Sy --noconfirm openssh; '
            . 'elif command -v apk >/dev/null 2>&1; then apk add --no-cache openssh; '
            . 'elif command -v zypper >/dev/null 2>&1; then zypper --non-interactive install openssh; '
            . 'else echo "no supported package manager"; exit 1; fi';

        if ($running) {
            [$code, , $stderr] = LxcPaths::run(['lxc-attach', '-n', $name, '--', '/bin/sh', '-c', $script], null, 900);

            return [$code === 0, trim($stderr)];
        }

        [$code, , $stderr] = LxcPaths::run(['chroot', $rootfs, '/bin/sh', '-c', $script], null, 900);

        return [$code === 0, trim($stderr)];
    }
}
