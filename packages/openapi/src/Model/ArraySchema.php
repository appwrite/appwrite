<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class ArraySchema extends Schema
{
    public function __construct(
        public Schema $items,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public bool $uniqueItems = false,
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
