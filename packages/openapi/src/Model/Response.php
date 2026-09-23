<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Response
{
    /**
     * @param  array<string, Header>  $headers
     * @param  array<string, MediaType>  $content
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public string $description,
        public array $headers = [],
        public array $content = [],
        public array $extensions = [],
    ) {}
}
