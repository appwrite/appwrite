<?php

namespace Appwrite\Transformation;

class Transformation
{
    public function __construct(protected Adapter $adapter)
    {
    }

    /**
     * @param array<mixed> $traits
     */
    public function isValid(array $traits): bool
    {
        return $this->adapter->isValid($traits);
    }

    public function transform(): bool
    {
        return $this->adapter->transform();
    }

    public function getOutput(): mixed
    {
        return $this->adapter->getOutput();
    }
}
