<?php

namespace Utopia\Balancer\Algorithm;

use Utopia\Balancer\Algorithm;
use Utopia\Balancer\Option;

class RoundRobin extends Algorithm
{
    public function getName(): string
    {
        return "RoundRobin";
    }

    private int $index;

    public function __construct(int $lastIndex)
    {
        $this->index = $lastIndex;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function setIndex(int $lastIndex): self
    {
        $this->index = $lastIndex;
        return $this;
    }

    /**
     * @param Option[] $options
     */
    public function run(array $options): ?Option
    {
        $this->index++;

        $option = null;

        if (\array_key_exists($this->index, $options)) {
            $option = $options[$this->index];
        } else {
            $option = $options[0] ?? null;
            $this->index = 0;
        }

        return $option;
    }
}
