<?php

namespace Appwrite\Filter;

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

    abstract public function isValid(mixed $input): bool;

    abstract public function filter(): self;
}
