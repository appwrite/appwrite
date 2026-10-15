<?php

namespace Utopia\Orchestration;

class Container
{
    /**
     * @param  array<string, string>  $labels
     */
    public function __construct(string $name = '', string $id = '', string $status = '', array $labels = [])
    {
        $this->name = $name;
        $this->id = $id;
        $this->status = $status;
        $this->labels = $labels;
    }

    /**
     * @var string
     */
    protected $name = '';

    /**
     * @var string
     */
    protected $id = '';

    /**
     * @var string
     */
    protected $status = '';

    /**
     * @var array<string, string>
     */
    protected $labels = [];

    /**
     * Get the container's name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the container's ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the container's status
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get the container's labels
     *
     * @return array<string, string>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /**
     * Set the container's name
     *
     * @return $this
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Set the container's id
     *
     * @return $this
     */
    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Set the container's status
     *
     * @return $this
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Set the container's labels
     *
     * @param  array<string, string>  $labels
     * @return $this
     */
    public function setLabels(array $labels): self
    {
        $this->labels = $labels;

        return $this;
    }
}
