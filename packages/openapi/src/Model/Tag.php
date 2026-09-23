<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Tag
{
    /** @param array<string, mixed> $extensions */
    public function __construct(
        public string $name,
        public string $description = '',
        public ?ExternalDocumentation $externalDocumentation = null,
        public array $extensions = [],
    ) {}
}
