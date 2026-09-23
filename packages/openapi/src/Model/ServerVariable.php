<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class ServerVariable
{
    /**
     * @param  list<string>  $enum
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public string $default,
        public array $enum = [],
        public string $description = '',
        public array $extensions = [],
    ) {}
}
