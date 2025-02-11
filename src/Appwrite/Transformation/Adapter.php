<?php

namespace Appwrite\Transformation;

abstract class Adapter
{
    protected mixed $output;

    public function __construct(protected mixed $input)
    {

    }

    /**
     * @param array<mixed> $traits
     */
    abstract public function isValid(array $traits): bool;

    abstract public function transform(): bool;

    public function getOutput(): mixed
    {
        return $this->output;
    }
}
