<?php

namespace Appwrite\Storage;

use Exception;

abstract class Device
{
    /**
     * Get Name.
     *
     * Get storage device name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get Description.
     *
     * Get storage device description and purpose.
     *
     * @return string
     */
    abstract public function getDescription(): string;

    /**
     * Get Root.
     *
     * Get storage device root path
     *
     * @return string
     */
    abstract public function getRoot(): string;

    /**
     * Get Path.
     *
     * Each device hold a complex directory structure that is being build in this method.
     *
     * @param $filename
     *
     * @return string
     */
    abstract public function getPath($filename): string;

    /**
     * Upload.
     *
     * Upload a file to desired destination in the selected disk, return true on success and false on failure.
     *
     * @param string $source
     * @param string $path
     *
     * @throws \Exception
     *
     * @return bool
     */
    abstract public function upload($source, $path): bool;
    
    /**
     * Read file by given path.
     *
     * @param string $path
     *
     * @return string
     */
    abstract public function read(string $path): string;

    /**
     * Write file by given path.
     *
     * @param string $path
     * @param string $data
     *
     * @return bool
     */
    abstract public function write(string $path, string $data): bool;

    /**
     * Move file from given source to given path, return true on success and false on failure.
     *
     * @see http://php.net/manual/en/function.filesize.php
     *
     * @param string $source
     * @param string $target
     *
     * @return bool
     */
    abstract public function move(string $source, string $target): bool;

    /**
     * Delete file in given path return true on success and false on failure.
     *
     * @see http://php.net/manual/en/function.filesize.php
     *
     * @param string $path
     * @param bool $recursive
     *
     * @return bool
     */
    abstract public function delete(string $path, bool $recursive = false): bool;

    /**
     * Returns given file path its size.
     *
     * @see http://php.net/manual/en/function.filesize.php
     *
     * @param $path
     *
     * @return int
     */
    abstract public function getFileSize(string $path): int;

    /**
     * Returns given file path its mime type.
     *
     * @see http://php.net/manual/en/function.mime-content-type.php
     *
     * @param $path
     *
     * @return string
     */
    abstract public function getFileMimeType(string $path): string;

    /**
     * Returns given file path its MD5 hash value.
     *
     * @see http://php.net/manual/en/function.md5-file.php
     *
     * @param $path
     *
     * @return string
     */
    abstract public function getFileHash(string $path): string;

    /**
     * Get directory size in bytes.
     *
     * Return -1 on error
     *
     * Based on http://www.jonasjohn.de/snippets/php/dir-size.htm
     *
     * @param $path
     *
     * @return int
     */
    abstract public function getDirectorySize(string $path): int;

    /**
     * Get Partition Free Space.
     *
     * disk_free_space — Returns available space on filesystem or disk partition
     *
     * @return float
     */
    abstract public function getPartitionFreeSpace(): float;

    /**
     * Get Partition Total Space.
     *
     * disk_total_space — Returns the total size of a filesystem or disk partition
     *
     * @return float
     */
    abstract public function getPartitionTotalSpace(): float;
}
