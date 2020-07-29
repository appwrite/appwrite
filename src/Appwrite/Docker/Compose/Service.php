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
        $this->service['environment'] = isset($this->service['environment']) ? new Env(implode("\n", $this->service['environment'])) : null;
    }

    /**
     * @return array
     */
    public function getContainerName(): string
    {
        return (isset($this->service['container_name'])) ? $this->service['container_name'] : '';
    }

    /**
     * @return array
     */
    public function getImage(): string
    {
        return (isset($this->service['image'])) ? $this->service['image'] : '';
    }
}
