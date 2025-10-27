<?php

namespace Appwrite\Redaction;

use Appwrite\Redaction\Adapters\Adapter;

final readonly class RedactedValue
{
    public function __construct(
        private string  $original,
        private string  $value,
        private Adapter $adapter
    ) {}

    public function getOriginalValue(): string
    {
        return $this->original;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
