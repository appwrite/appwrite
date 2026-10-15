<?php

namespace Utopia\Query\Hook\Attribute;

use Utopia\Query\Hook\Attribute;

readonly class Map implements Attribute
{
    /** @param array<string, string> $map */
    public function __construct(private array $map)
    {
    }

    public function resolve(string $attribute): string
    {
        return $this->map[$attribute] ?? $attribute;
    }
}
