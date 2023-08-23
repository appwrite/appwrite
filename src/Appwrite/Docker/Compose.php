<?php

namespace Appwrite\Docker;

use Appwrite\Docker\Compose\Service;
use Exception;

class Compose
{
    /**
     * @var array
     */
    protected $compose = [];

    /**
     * @var string $data
     */
    public function __construct(string $data)
    {
        $this->compose = yaml_parse($data);

        $this->compose['services'] = (isset($this->compose['services']) && is_array($this->compose['services']))
            ? $this->compose['services'] : [];

        foreach ($this->compose['services'] as $key => &$service) {
            $service = new Service($service);
        }
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return (isset($this->compose['version'])) ? $this->compose['version'] : '';
    }

    /**
     * @return Service[]
     */
    public function getServices(): array
    {
        return $this->compose['services'];
    }

    /**
     * @return Service
     */
    public function getService(string $name): Service
    {
        if (!isset($this->compose['services'][$name])) {
            throw new Exception('Service not found');
        }

        return $this->compose['services'][$name];
    }

    /**
     * @return array
     */
    public function getNetworks(): array
    {
        return (isset($this->compose['networks'])) ? array_keys($this->compose['networks']) : [];
    }

    /**
     * @return array
     */
    public function getVolumes(): array
    {
        return (isset($this->compose['volumes'])) ? array_keys($this->compose['volumes']) : [];
    }
}
