<?php

namespace Utopia\Balancer;

class Option
{
    /**
     * @var array<string, mixed>
     */
    private array $state;

    /**
     * @param array<string, mixed> $state
     */
    public function __construct(array $state)
    {
        $this->state = $state;
    }

    public function setState(string $key, mixed $value): self
    {
        $this->state[$key] = $value;
        return $this;
    }

    public function getState(string $key, mixed $default = null): mixed
    {
        return $this->state[$key] ?? $default;
    }

    public function deleteState(string $key): self
    {
        unset($this->state[$key]);
        return $this;
    }

    /**
     * @return array<string, mixed> $state
     */
    public function getStates(): array
    {
        return $this->state;
    }
}
