<?php

namespace Utopia\Balancer\Algorithm;

use Utopia\Balancer\Algorithm;
use Utopia\Balancer\Option;

class Random extends Algorithm
{
    public function getName(): string
    {
        return "Random";
    }

    /**
     * @param Option[] $options
     */
    public function run(array $options): ?Option
    {
        return $options[\array_rand($options)] ?? null;
    }
}
