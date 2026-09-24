<?php

namespace Utopia\Balancer\Algorithm;

use Utopia\Balancer\Algorithm;
use Utopia\Balancer\Option;

class First extends Algorithm
{
    public function getName(): string
    {
        return "First";
    }

    /**
     * @param Option[] $options
     */
    public function run(array $options): ?Option
    {
        return $options[\array_key_first($options)] ?? null;
    }
}
