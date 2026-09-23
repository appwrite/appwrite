<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class License
{
    /** @param array<string, mixed> $extensions */
    public function __construct(
        public string $name,
        public ?string $url = null,
        public ?string $identifier = null,
        public array $extensions = [],
    ) {}
}
