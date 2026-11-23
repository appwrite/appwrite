<?php

namespace Appwrite\Docker\Compose;

use Appwrite\Docker\Env;

class Service
{
    /**
     * @var array
     */
    protected $service = [];

    public function __construct(array $service)
    {
        $this->service = $service;

        $ports = (isset($this->service['ports']) && is_array($this->service['ports'])) ? $this->service['ports'] : [];
        $this->service['ports'] = [];

        foreach ($ports as $port) {
            $port = (string) $port;
            $separator = strrpos($port, ':');
            if ($separator === false) {
                $this->service['ports'][$port] = '';
                continue;
            }

            $this->service['ports'][substr($port, 0, $separator)] = substr($port, $separator + 1);
        }

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
        return substr($image, ((int)strpos($image, ':')) + 1);
    }

    public function getEntrypoint(): string
    {
        return (isset($this->service['entrypoint'])) ? $this->service['entrypoint'] : '';
    }

    /**
     * @return Env
     */
    public function getEnvironment(): Env
    {
        return $this->service['environment'];
    }

    /**
     * @return array<string, string> published port (with any host IP) => container port
     */
    public function getPorts(): array
    {
        return $this->service['ports'];
    }
}
