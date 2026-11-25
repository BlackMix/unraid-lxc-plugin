<?php
/**
 * Minimal ustar writer that streams directly to a gzip file via PHP.
 * No external tar/find/gzip/zstd binaries are needed.
 */
final class TarWriter
{
    /** @var resource */
    private $handle;
    private string $path;
    private int $written = 0;
    private int $files = 0;

    public function __construct(string $path)
    {
        $this->path = $path;
        $handle = @gzopen($path, 'wb9');
        if ($handle === false) {
            throw new RuntimeException('Could not open archive for writing: ' . $path);
        }
        $this->handle = $handle;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function written(): int
    {
        return $this->written;
    }

    public function files(): int
    {
        return $this->files;
    }

    public function addString(string $name, string $contents): void
    {
        $this->writeHeader($name, 0644, strlen($contents), time(), '0', '');
        if ($contents !== '') {
            $this->writeData($contents);
        }
    }

    public function addFile(string $name, string $source): void
    {
        $stat = @lstat($source);
        if ($stat === false) {
            return;
        }
        if (is_link($source)) {
            $target = (string) @readlink($source);
            $this->writeHeader($name, 0777, 0, $stat['mtime'], '2', $target);
            return;
        }
        if (is_dir($source)) {
            $this->writeHeader(rtrim($name, '/') . '/', 0755, 0, $stat['mtime'], '5', '');
            return;
        }
        if (!is_file($source)) {
            return; // skip devices, sockets, FIFOs
        }
        $size = (int) $stat['size'];
        $this->writeHeader($name, (int) $stat['mode'] & 0777, $size, $stat['mtime'], '0', '');
        $handle = @fopen($source, 'rb');
        if ($handle === false) {
            // Header was written with the real size; fill with zeros.
            $this->writeData(str_repeat("\0", $size));
            return;
        }
        $remaining = $size;
        while ($remaining > 0) {
            $chunk = fread($handle, min(1048576, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->writeData($chunk);
            $remaining -= strlen($chunk);
        }
        fclose($handle);
        if ($remaining > 0) {
            $this->writeData(str_repeat("\0", $remaining));
        }
    }

    public function finish(): void
    {
        // Two zero blocks mark the end of a tar archive.
        gzwrite($this->handle, str_repeat("\0", 1024));
        gzclose($this->handle);
    }

    private function writeData(string $data): void
    {
        gzwrite($this->handle, $data);
        $this->written += strlen($data);
        $pad = (512 - (strlen($data) % 512)) % 512;
        if ($pad > 0) {
            gzwrite($this->handle, str_repeat("\0", $pad));
            $this->written += $pad;
        }
        $this->files++;
    }

    private function writeHeader(string $name, int $mode, int $size, int $mtime, string $type, string $link): void
    {
        $name = ltrim($name, '/');
        $prefix = '';
        if (strlen($name) > 100) {
            $pos = strrpos(substr($name, 0, 100), '/');
            if ($pos !== false && $pos > 0) {
                $prefix = substr($name, 0, $pos);
                $name = substr($name, $pos + 1);
            }
        }
        $header = str_pad(substr($name, 0, 100), 100, "\0")
            . str_pad(sprintf('%07o', $mode), 8, "\0", STR_PAD_LEFT)
            . str_pad(sprintf('%07o', 0), 8, "\0", STR_PAD_LEFT)
            . str_pad(sprintf('%07o', 0), 8, "\0", STR_PAD_LEFT)
            . str_pad(sprintf('%011o', $size), 12, "\0", STR_PAD_LEFT)
            . str_pad(sprintf('%011o', $mtime), 12, "\0", STR_PAD_LEFT)
            . str_repeat(' ', 8)
            . $type
            . str_pad(substr($link, 0, 100), 100, "\0")
            . "ustar\0" . '00'
            . str_pad('root', 32, "\0")
            . str_pad('root', 32, "\0")
            . str_pad(sprintf('%07o', 0), 8, "\0", STR_PAD_LEFT)
            . str_pad(sprintf('%07o', 0), 8, "\0", STR_PAD_LEFT)
            . str_pad(substr($prefix, 0, 155), 155, "\0")
            . str_repeat("\0", 12);
        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }
        $header = substr_replace(
            $header,
            str_pad(sprintf('%06o', $checksum), 6, '0', STR_PAD_LEFT) . "\0 ",
            148,
            8
        );
        gzwrite($this->handle, $header);
        $this->written += 512;
        $this->files++;
    }

    /**
     * Total size of regular files under a root, without following symlinks
     * or leaving the filesystem.
     */
    public static function totalSize(string $root): int
    {
        $total = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $entry) {
                if ($entry->isFile()) {
                    $total += (int) $entry->getSize();
                }
            }
        } catch (Throwable $e) {
            return 0;
        }

        return $total;
    }
}
