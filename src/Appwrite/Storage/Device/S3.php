<?php

namespace Appwrite\Storage\Device;

use Appwrite\Storage\Device;

class S3 extends Device
{
    /**
     * @return string
     */
    public function getName():string
    {
        return 'S3 Storage';
    }

    /**
     * @return string
     */
    public function getDescription():string
    {
        return 'S3 Bucket Storage drive for AWS or on premise solution';
    }

    /**
     * @return string
     */
    public function getRoot():string
    {
        return '';
    }

    /**
     * @param string $filename
     *
     * @return string
     */
    public function getPath($filename):string
    {
        return '';
    }


    /**
     * Upload.
     *
     * Upload a file to desired destination in the selected disk.
     *
     * @param string $target
     * @param string $filename
     *
     * @throws \Exception
     *
     * @return string|bool saved destination on success or false on failures
     */
    public function upload($source, $path):bool
    {
        return false;
    }

    /**
     * Read file by given path.
     *
     * @param string $path
     *
     * @return string
     */
    public function read(string $path):string
    {
        return '';
    }

    /**
     * Write file by given path.
     *
     * @param string $path
     * @param string $data
     *
     * @return bool
     */
    public function write(string $path, string $data):bool
    {
        return false;
    }

    /**
     * Move file from given source to given path, Return true on success and false on failure.
     *
     * @see http://php.net/manual/en/function.filesize.php
     *
     * @param string $source
     * @param string $target
     *
     * @return bool
     */
    public function move(string $source, string $target):bool
    {
        return false;
    }

    /**
     * Delete file in given path, Return true on success and false on failure.
     *
     * @see http://php.net/manual/en/function.filesize.php
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete(string $path, bool $recursive = false):bool
    {
        return false;
    }
    
    /**
     * Returns given file path its size.
     *
     * @see http://php.net/manual/en/function.filesize.php
     *
     * @param $path
     *
     * @return int
     */
    public function getFileSize(string $path):int
    {
        return 0;
    }

    /**
     * Returns given file path its mime type.
     *
     * @see http://php.net/manual/en/function.mime-content-type.php
     *
     * @param $path
     *
     * @return string
     */
    public function getFileMimeType(string $path):string
    {
        return '';
    }

    /**
     * Returns given file path its MD5 hash value.
     *
     * @see http://php.net/manual/en/function.md5-file.php
     *
     * @param $path
     *
     * @return string
     */
    public function getFileHash(string $path):string
    {
        return '';
    }

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
    public function getDirectorySize(string $path):int
    {
        return 0;
    }

    /**
     * Get Partition Free Space.
     *
     * disk_free_space — Returns available space on filesystem or disk partition
     *
     * @return float
     */
    public function getPartitionFreeSpace():float
    {
        return 0.0;
    }

    /**
     * Get Partition Total Space.
     *
     * disk_total_space — Returns the total size of a filesystem or disk partition
     *
     * @return float
     */
    public function getPartitionTotalSpace():float
    {
        return 0.0;
    }
}