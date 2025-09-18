<?php

namespace Appwrite\Transformation;

abstract class Adapter
{
    protected mixed $input;
    protected mixed $output;

    public function __construct()
    {

    }

    public function setInput(mixed $input): self
    {
        $this->input = $input;
        return $this;
    }

    public function getOutput(): mixed
    {
        return $this->output;
    }

    /**
     * @param array<mixed> $traits
     */
    abstract public function isValid(array $traits): bool;

    abstract public function transform(): void;
}
