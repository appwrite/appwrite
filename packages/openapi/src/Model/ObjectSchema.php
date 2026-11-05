<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

final readonly class ObjectSchema extends Schema
{
    /**
     * @param  array<string, Schema>  $properties
     * @param  list<string>  $required
     */
    public function __construct(
        public array $properties = [],
        public array $required = [],
        public bool|Schema|null $additionalProperties = null,
        public ?int $minProperties = null,
        public ?int $maxProperties = null,
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
