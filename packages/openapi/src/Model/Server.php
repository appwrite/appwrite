<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class Server
{
    /**
     * @param  array<string, ServerVariable>  $variables
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public string $url,
        public string $description = '',
        public array $variables = [],
        public array $extensions = [],
    ) {}
}
