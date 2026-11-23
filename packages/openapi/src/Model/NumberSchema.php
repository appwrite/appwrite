<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class NumberSchema extends Schema
{
    public function __construct(
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public bool $exclusiveMinimum = false,
        public bool $exclusiveMaximum = false,
        public int|float|null $multipleOf = null,
        ?string $title = null,
        string $description = '',
        bool $nullable = false,
        mixed $default = null,
        array $enum = [],
        ?string $format = null,
        bool $readOnly = false,
        bool $writeOnly = false,
        bool $deprecated = false,
        mixed $example = null,
        array $extensions = [],
    ) {
        parent::__construct($title, $description, $nullable, $default, $enum, $format, $readOnly, $writeOnly, $deprecated, $example, $extensions);
    }
}
