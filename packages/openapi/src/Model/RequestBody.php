<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class RequestBody
{
    /**
     * @param  array<string, MediaType>  $content
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public string $description = '',
        public bool $required = false,
        public array $content = [],
        public array $extensions = [],
    ) {}
}
