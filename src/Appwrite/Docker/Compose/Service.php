<?php

namespace Appwrite\Docker\Compose;

use Appwrite\Docker\Env;

class Service
{
    /**
     * @var array
     */
    protected $service = [];

    /**
     * @var string $path
     */
    public function __construct(array $service)
    {
        $this->service = $service;
        $this->service['environment'] = isset($this->service['environment']) ? new Env(implode("\n", $this->service['environment'])) : new Env('');
    }

    /**
     * @return string
     */
    public function getContainerName(): string
    {
        return (isset($this->service['container_name'])) ? $this->service['container_name'] : '';
    }

    /**
     * @return string
     */
    public function getImage(): string
    {
        return (isset($this->service['image'])) ? $this->service['image'] : '';
    }

    /**
     * @return string
     */
    public function getImageVersion(): string
    {
        $image = $this->getImage();
        return substr($image, strpos($image, ':')+1);
    }

    /**
     * @return string
     */
    public function getEnvironment(): Env
    {
        return $this->service['environment'];
    }
}
