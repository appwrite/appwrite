<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Example
{
    /** @param array<string, mixed> $extensions */
    public function __construct(
        public string $summary = '',
        public string $description = '',
        public mixed $value = null,
        public ?string $externalValue = null,
        public array $extensions = [],
    ) {
    }
}
