<?php
require_once __DIR__ . '/Paths.php';

/**
 * Safe read/modify/write access to a container configuration file.
 *
 * LXC reads its configuration line by line, so the plugin owns clearly marked
 * blocks and never touches anything outside of them (except for the one time
 * migration of legacy `lxc.net.*` lines into the NETWORK block).
 *
 * All mutations happen in memory; `save()` writes the file once, atomically,
 * and keeps a `.bak` copy of the previous version.
 */
class ContainerConfig
{
    private string $name;
    private string $file;

    /** @var string[]|null */
    private ?array $buffer = null;

    /** Block tags the plugin manages. */
    public const TAG_NETWORK = 'NETWORK';
    public const TAG_DEVICES = 'DEVICES';
    public const TAG_SSH = 'SSH';

    public function __construct(string $name)
    {
        if (!LxcPaths::isValidContainerName($name)) {
            throw new InvalidArgumentException('Invalid container name: ' . $name);
        }

        $this->name = $name;
        $this->file = LxcPaths::containerConfig($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->file;
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    /**
     * @return string[]
     */
    public function lines(): array
    {
        if ($this->buffer === null) {
            $this->buffer = $this->exists() ? (file($this->file, FILE_IGNORE_NEW_LINES) ?: []) : [];
        }

        return $this->buffer;
    }

    public function read(): string
    {
        return implode("\n", $this->lines()) . (count($this->lines()) > 0 ? "\n" : '');
    }

    /**
     * @param string[] $lines
     */
    public function replaceLines(array $lines): void
    {
        $this->buffer = array_values($lines);
    }

    /**
     * Write the file atomically and keep a `.bak` copy of the previous version.
     */
    public function save(): bool
    {
        if (!is_dir(dirname($this->file))) {
            return false;
        }

        if ($this->exists()) {
            @copy($this->file, $this->file . '.bak');
        }

        $tmp = $this->file . '.tmp';
        $lines = $this->lines();
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        $payload = implode("\n", $lines) . "\n";

        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }

        @chmod($tmp, 0644);
        if (!rename($tmp, $this->file)) {
            return false;
        }

        $this->buffer = null;

        return true;
    }

    /**
     * Get the value of an exact `key = value` entry (last occurrence wins).
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*?)\s*$/';
        $value = $default;

        foreach ($this->lines() as $line) {
            if (preg_match($pattern, $line, $m) === 1) {
                $value = $m[1];
            }
        }

        return $value;
    }

    /**
     * Set an exact `key = value` entry, removing duplicates.
     * Passing null removes the entry entirely.
     */
    public function set(string $key, ?string $value): void
    {
        $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=/';
        $out = [];
        $written = false;

        foreach ($this->lines() as $line) {
            if (preg_match($pattern, $line) === 1) {
                if (!$written && $value !== null) {
                    $out[] = $key . ' = ' . $value;
                    $written = true;
                }
                continue;
            }
            $out[] = $line;
        }

        if (!$written && $value !== null) {
            $out[] = $key . ' = ' . $value;
        }

        $this->buffer = $out;
    }

    /**
     * Remove every line matching a regular expression.
     */
    public function removeMatching(string $regex): void
    {
        $out = [];
        foreach ($this->lines() as $line) {
            if (preg_match($regex, $line) !== 1) {
                $out[] = $line;
            }
        }

        $this->buffer = $out;
    }

    public function hasBlock(string $tag): bool
    {
        foreach ($this->lines() as $line) {
            if (trim($line) === self::startMarker($tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the body of a managed block without the markers.
     *
     * @return string[]
     */
    public function block(string $tag): array
    {
        $body = [];
        $inside = false;

        foreach ($this->lines() as $line) {
            $trimmed = trim($line);
            if ($trimmed === self::startMarker($tag)) {
                $inside = true;
                continue;
            }
            if ($trimmed === self::endMarker($tag)) {
                $inside = false;
                continue;
            }
            if ($inside) {
                $body[] = $line;
            }
        }

        return $body;
    }

    /**
     * Replace (or append) a managed block.
     *
     * @param string[] $body
     */
    public function setBlock(string $tag, array $body): void
    {
        $this->removeBlock($tag);

        $lines = $this->lines();
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        if ($lines !== []) {
            $lines[] = '';
        }

        $lines[] = self::startMarker($tag);
        foreach ($body as $entry) {
            $lines[] = $entry;
        }
        $lines[] = self::endMarker($tag);

        $this->buffer = $lines;
    }

    public function removeBlock(string $tag): void
    {
        $out = [];
        $inside = false;

        foreach ($this->lines() as $line) {
            $trimmed = trim($line);
            if ($trimmed === self::startMarker($tag)) {
                $inside = true;
                continue;
            }
            if ($trimmed === self::endMarker($tag)) {
                $inside = false;
                continue;
            }
            if (!$inside) {
                $out[] = $line;
            }
        }

        $this->buffer = $out;
    }

    public static function startMarker(string $tag): string
    {
        return '# >>> LXCv2:' . $tag . ' - managed by the LXC v2 plugin, do not edit >>>';
    }

    public static function endMarker(string $tag): string
    {
        return '# <<< LXCv2:' . $tag . ' <<<';
    }
}
