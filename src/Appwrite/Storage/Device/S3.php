<?php

namespace Appwrite\Storage\Device;

use Appwrite\Storage\Device;

class S3 extends Device
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'S3 Storage';
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return 'S3 Bucket Storage drive for AWS or on premise solution';
    }

    /**
     * @return string
     */
    public function getRoot()
    {
        return '';
    }

    /**
     * @param string $filename
     *
     * @return string
     */
    public function getPath($filename)
    {
        $path = '';

        for ($i = 0; $i < 4; ++$i) {
            $path = ($i < strlen($filename)) ? $path.DIRECTORY_SEPARATOR.$filename[$i] : $path.DIRECTORY_SEPARATOR.'x';
        }

        return $this->getRoot().$path.DIRECTORY_SEPARATOR.$filename;
    }
}
