<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Reference;

final readonly class Reference
{
    public function __construct(public string $value)
    {
    }

    public function isLocal(): bool
    {
        return str_starts_with($this->value, '#');
    }
}
