<?php

namespace Utopia\Balancer\Algorithm;

use Utopia\Balancer\Algorithm;
use Utopia\Balancer\Option;

class Last extends Algorithm
{
    public function getName(): string
    {
        return "Last";
    }

    /**
     * @param Option[] $options
     */
    public function run(array $options): ?Option
    {
        $key = \array_key_last($options);

        return $key === null ? null : $options[$key];
    }
}
