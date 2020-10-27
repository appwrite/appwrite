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
        
        $ports = (isset($this->service['ports']) && is_array($this->service['ports'])) ? $this->service['ports'] : [];
        $this->service['ports'] = [];

        array_walk($ports, function(&$value, $key) {
            $split = explode(':', $value);
            $this->service['ports'][
                (isset($split[0])) ? $split[0] : ''
            ] = (isset($split[1])) ? $split[1] : '';
        });

        $this->service['environment'] = (isset($this->service['environment']) && is_array($this->service['environment'])) ? $this->service['environment'] : [];
        $this->service['environment'] = new Env(implode("\n", $this->service['environment']));
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
        return substr($image, ((int)strpos($image, ':'))+1);
    }

    /**
     * @return Env
     */
    public function getEnvironment(): Env
    {
        return $this->service['environment'];
    }

    /**
     * @return array
     */
    public function getPorts(): array
    {
        return $this->service['ports'];
    }
}
