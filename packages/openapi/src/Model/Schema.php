<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

abstract readonly class Schema
{
    /**
     * @param  list<mixed>  $enum
     * @param  array<string, mixed>  $extensions
     */
    public function __construct(
        public ?string $title = null,
        public string $description = '',
        public bool $nullable = false,
        public mixed $default = null,
        public array $enum = [],
        public ?string $format = null,
        public bool $readOnly = false,
        public bool $writeOnly = false,
        public bool $deprecated = false,
        public mixed $example = null,
        public array $extensions = [],
    ) {}
}
