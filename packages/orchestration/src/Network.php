<?php

namespace Utopia\Orchestration;

class Network
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $driver;

    /**
     * @var string
     */
    protected $scope;

    public function __construct(string $name = '', string $id = '', string $driver = '', string $scope = '')
    {
        $this->name = $name;
        $this->id = $id;
        $this->driver = $driver;
        $this->scope = $scope;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setName(string $name): Network
    {
        $this->name = $name;

        return $this;
    }

    public function setId(string $id): Network
    {
        $this->id = $id;

        return $this;
    }

    public function setDriver(string $driver): Network
    {
        $this->driver = $driver;

        return $this;
    }

    public function setScope(string $scope): Network
    {
        $this->scope = $scope;

        return $this;
    }
}
