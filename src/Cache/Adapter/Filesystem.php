<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter;

use Exception;
use Utopia\Cache\Adapter;

class Filesystem implements Adapter
{
    /**
     * Filesystem constructor.
     */
    public function __construct(protected string $path, protected bool $streaming = false) {}

    /**
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $file = $this->getPath($key);

        if (file_exists($file) && (filemtime($file) + $ttl > time())) { // Cache is valid
            if ($this->streaming) {
                return fopen($file, 'rb');
            }

            return file_get_contents($file);
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @param  int  $ttl time in seconds
     * @return bool|string|array<int|string, mixed>
     * @throws Exception
     */
    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        if (empty($data)) {
            return false;
        }

        $file = $this->getPath($key);
        $dir = \dirname($file);
        try {
            if (!file_exists($dir) && (!mkdir($dir, 0755, true) && ! file_exists($dir))) {
                throw new Exception("Can't create directory {$dir}");
            }

            return (file_put_contents($file, $data, LOCK_EX)) ? $data : false;
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool
    {
        $file = $this->getPath($key);

        if (! file_exists($file) || ! touch($file)) {
            return false;
        }

        clearstatcache(true, $file);

        return true;
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        return [];
    }

    /**
     * @param  string  $hash optional
     */
    public function purge(string $key, string $hash = ''): bool
    {
        $file = $this->getPath($key);

        if (file_exists($file)) {
            return unlink($file);
        }

        return false;
    }

    public function flush(): bool
    {
        return $this->deleteDirectory($this->path);
    }

    public function ping(): bool
    {
        return file_exists($this->path) && is_writable($this->path) && is_readable($this->path);
    }

    /**
     * Returning root directory size in bytes
     */
    public function getSize(): int
    {
        try {
            return $this->getDirectorySize($this->path);
        } catch (Exception) {
            return 0;
        }
    }

    private function getDirectorySize(string $dir): int
    {
        $size = 0;
        $normalizedPath = rtrim($dir, '/') . '/*';

        $paths = glob($normalizedPath, GLOB_NOSORT);
        if ($paths === false) {
            return $size;
        }

        foreach ($paths as $path) {
            if (is_file($path)) {
                $fileSize = filesize($path);
                $size += $fileSize !== false ? $fileSize : 0;
            } elseif (is_dir($path)) {
                $size += $this->getDirectorySize($path);
            }
        }

        return $size;
    }

    public function getPath(string $filename): string
    {
        return $this->path . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     *
     * @throws Exception
     */
    protected function deleteDirectory(string $path): bool
    {
        if (! is_dir($path)) {
            throw new Exception("$path must be a directory");
        }

        if (substr($path, \strlen($path) - 1, 1) !== '/') {
            $path .= '/';
        }

        $files = glob($path . '*', GLOB_MARK);

        if (! $files) {
            throw new Exception('Error happened during glob');
        }

        foreach ($files as $file) {
            if (is_dir($file)) {
                self::deleteDirectory($file);
            } else {
                unlink($file);
            }
        }

        return rmdir($path);
    }

    public function getName(?string $key = null): string
    {
        return 'filesystem';
    }
}
