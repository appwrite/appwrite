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
    public function getName()
    {
        return 'Local Storage';
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return 'Adapter for Local storage that is in the physical or virtual machine or mounted to it.';
    }

    /**
     * @return string
     */
    public function getRoot()
    {
        return '/storage/uploads/'.$this->root;
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
