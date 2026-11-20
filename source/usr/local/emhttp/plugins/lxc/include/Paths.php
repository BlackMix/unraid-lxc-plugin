<?php
/**
 * LXC v2 - shared paths and small filesystem helpers.
 *
 * Kept dependency free on purpose: Settings.php <-> functions.php <-> Container.php
 * already require each other, so new code must not add another require cycle.
 */
final class LxcPaths
{
    /** Plugin configuration directory on the flash drive. */
    public const PLUGIN_DIR = '/boot/config/plugins/lxc';

    /** Directory the webGui serves this plugin from. */
    public const EMHTTP_DIR = '/usr/local/emhttp/plugins/lxc';

    /** Directory holding the helper scripts (lxc-autobackup, lxc-startup.sh, ...). */
    public const SCRIPTS_DIR = self::EMHTTP_DIR . '/lxc_scripts';

    /** LXC container name rules (mirrors lxc-ls output plus a safety margin). */
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';

    public static function isValidContainerName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Absolute path configured through lxc.lxcpath.
     */
    public static function dataPath(): string
    {
        $conf = self::PLUGIN_DIR . '/lxc.conf';
        if (is_file($conf)) {
            $lines = file($conf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                if (preg_match('/^\s*lxc\.lxcpath\s*=\s*(.+?)\s*$/', $line, $m) === 1) {
                    return rtrim(trim($m[1], "\"'"), '/');
                }
            }
        }

        return '/mnt/user/lxc';
    }

    public static function containerDir(string $name): string
    {
        return self::dataPath() . '/' . $name;
    }

    public static function containerConfig(string $name): string
    {
        return self::containerDir($name) . '/config';
    }

    public static function containerExists(string $name): bool
    {
        return self::isValidContainerName($name) && is_dir(self::containerDir($name));
    }

    /**
     * Read a `KEY=value` entry from /boot/config/plugins/lxc/plugin.cfg.
     */
    public static function pluginVar(string $key, ?string $default = null): ?string
    {
        return self::readKeyValue(self::PLUGIN_DIR . '/plugin.cfg', $key, $default);
    }

    /**
     * Write a `KEY=value` entry into /boot/config/plugins/lxc/plugin.cfg,
     * appending it when the key does not exist yet.
     */
    public static function setPluginVar(string $key, string $value): void
    {
        self::writeKeyValue(self::PLUGIN_DIR . '/plugin.cfg', $key, $value);
    }

    public static function readKeyValue(string $file, string $key, ?string $default = null): ?string
    {
        if (!is_file($file)) {
            return $default;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*?)\s*$/', $line, $m) === 1) {
                return trim($m[1], "\"'");
            }
        }

        return $default;
    }

    public static function writeKeyValue(string $file, string $key, string $value): void
    {
        $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=/';
        $found = false;
        $out = [];

        foreach ($lines as $line) {
            if (preg_match($pattern, $line) === 1) {
                if (!$found) {
                    $out[] = $key . '=' . $value;
                    $found = true;
                }
                continue;
            }
            $out[] = $line;
        }

        if (!$found) {
            $out[] = $key . '=' . $value;
        }

        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, implode("\n", $out) . "\n", LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0644);
        rename($tmp, $file);
    }

    /**
     * Ensure a directory exists (recursive, 0755) and return its path.
     */
    public static function ensureDir(string $path): string
    {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        return $path;
    }

    /**
     * Run a command with an argument list (no shell interpolation) and capture
     * the output. Avoids the string concatenation pattern used by the old code.
     *
     * @param string[] $args
     * @return array{0:int,1:string,2:string} [exitCode, stdout, stderr]
     */
    public static function run(array $args, ?string $stdin = null, int $timeout = 0): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = implode(' ', array_map('escapeshellarg', $args));
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [-1, '', 'failed to spawn process'];
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ($deadline !== null && microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $stderr .= "\ntimeout";
                break;
            }
            usleep(20000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, $stdout, $stderr];
    }

    /**
     * Convenience wrapper returning trimmed stdout, or '' on failure.
     *
     * @param string[] $args
     */
    public static function capture(array $args): string
    {
        [, $stdout] = self::run($args);

        return trim($stdout);
    }
}
