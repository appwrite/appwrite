<?php

namespace Utopia\Balancer;

abstract class Algorithm
{
    /**
     * @param Option[] $options
     */
    abstract public function run(array $options): ?Option;

    abstract public function getName(): string;
}
