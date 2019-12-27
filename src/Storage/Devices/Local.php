<?php

namespace Storage\Devices;

use Storage\Device;

class Local extends Device
{
    /**
     * @var string
     */
    protected $root = 'temp';

    /**
     * Local constructor.
     *
     * @param string $root
     */
    public function __construct($root = '')
    {
        $this->root = $root;
    }

    /**
     * @return string
     */
    public function getName():string
    {
        return 'Local Storage';
    }

    /**
     * @return string
     */
    public function getDescription():string
    {
        return 'Adapter for Local storage that is in the physical or virtual machine or mounted to it.';
    }

    /**
     * @return string
     */
    public function getRoot():string
    {
        return $this->root;
    }

    /**
     * @param string $filename
     *
     * @return string
     */
    public function getPath($filename):string
    {
        $path = '';

        for ($i = 0; $i < 4; ++$i) {
            $path = ($i < strlen($filename)) ? $path.DIRECTORY_SEPARATOR.$filename[$i] : $path.DIRECTORY_SEPARATOR.'x';
        }

        return $this->getRoot().$path.DIRECTORY_SEPARATOR.$filename;
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
    public function upload($target, $filename = '')
    {
        $filename = (empty($filename)) ? $target : $filename;
        $filename = uniqid().'.'.pathinfo($filename, PATHINFO_EXTENSION);

        $path = $this->getPath($filename);

        if (!is_uploaded_file($target)) {
            throw new Exception('File is not a valid uploaded file');
        }

        if (!file_exists(dirname($path))) { // Checks if directory path to file exists
            if (!@mkdir(dirname($path), 0755, true)) {
                throw new Exception('Can\'t create directory '.dirname($path));
            }
        }

        if (move_uploaded_file($target, $path)) {
            return $path;
        }

        throw new Exception('Upload failed');
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
        return file_get_contents($path);
    }

    /**
     * Write file by given path.
     *
     * @param string $path
     * @param string $data
     *
     * @return string
     */
    public function write(string $path, string $data):bool
    {
        return file_put_contents($path, $data);
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
    public function delete(string $path):bool
    {
        return unlink($path);
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
        return filesize($path);
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
        return mime_content_type($path);
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
        return md5_file($path);
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
        $size = 0;

        $directory = opendir($path);

        if (!$directory) {
            return -1;
        }

        while (($file = readdir($directory)) !== false) {
            // Skip file pointers
            if ($file[0] == '.') {
                continue;
            }

            // Go recursive down, or add the file size
            if (is_dir($path.$file)) {
                $size += $this->getDirectorySize($path.$file.DIRECTORY_SEPARATOR);
            } else {
                $size += filesize($path.$file);
            }
        }

        closedir($directory);

        return $size;
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
        return disk_free_space($this->getRoot());
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
        return disk_total_space($this->getRoot());
    }
}
