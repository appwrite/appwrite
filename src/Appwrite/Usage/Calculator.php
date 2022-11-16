<?php

namespace Appwrite\Usage;

abstract class Calculator
{
    protected string $region;

    public function __construct(string $region)
    {
        $this->region = $region;
    }

    abstract public function collect(): void;
}
