<?php

namespace Appwrite\Storage\Validator;

use Exception;
use Utopia\Validator;

class FileType extends Validator
{
    /**
     * File Types Constants.
     */
    const FILE_TYPE_JPEG = 'jpeg';
    const FILE_TYPE_GIF = 'gif';
    const FILE_TYPE_PNG = 'png';
    const FILE_TYPE_GZIP = 'gz';

    /**
     * File Type Binaries.
     *
     * @var array
     */
    protected $types = array(
        self::FILE_TYPE_JPEG => "\xFF\xD8\xFF",
        self::FILE_TYPE_GIF => 'GIF',
        self::FILE_TYPE_PNG => "\x89\x50\x4e\x47\x0d\x0a",
        self::FILE_TYPE_GZIP => "application/x-gzip",
    );

    /**
     * @var array
     */
    protected $whiteList;

    /**
     * @param array $whiteList
     *
     * @throws Exception
     */
    public function __construct(array $whiteList)
    {
        foreach ($whiteList as $key) {
            if (!isset($this->types[$key])) {
                throw new Exception('Unknown file mime type');
            }
        }

        $this->whiteList = $whiteList;
    }

    public function getDescription()
    {
        return 'File mime-type is not allowed ';
    }

    /**
     * Is Valid.
     *
     * Binary check to finds whether a file is of valid type
     *
     * @see http://stackoverflow.com/a/3313196
     *
     * @param string $path
     *
     * @return bool
     */
    public function isValid($path)
    {
        if (!\is_readable($path)) {
            return false;
        }

        $handle = \fopen($path, 'r');

        if (!$handle) {
            return false;
        }

        $bytes = \fgets($handle, 8);

        var_dump($bytes);

        foreach ($this->whiteList as $key) {
            if (\strpos($bytes, $this->types[$key]) === 0) {
                \fclose($handle);

                return true;
            }
        }

        \fclose($handle);

        return false;
    }
}
