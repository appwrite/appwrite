<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class StringSchema extends Schema
{
    /** @param list<string> $enumKeys */
    public function __construct(
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
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
        public ?string $enumName = null,
        public array $enumKeys = [],
        public bool $open = false,
    ) {
        parent::__construct($title, $description, $nullable, $default, $enum, $format, $readOnly, $writeOnly, $deprecated, $example, $extensions);
    }
}
