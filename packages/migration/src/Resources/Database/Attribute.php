<?php

namespace Utopia\Migration\Resources\Database;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Attribute extends Resource
{
    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_FLOAT = 'double';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_EMAIL = 'email';
    public const TYPE_ENUM = 'enum';
    public const TYPE_IP = 'ip';
    public const TYPE_URL = 'url';
    public const TYPE_RELATIONSHIP = 'relationship';

    public const TYPE_POINT = 'point';
    public const TYPE_LINE = 'linestring';
    public const TYPE_POLYGON = 'polygon';

    public const TYPE_OBJECT = 'object';
    public const TYPE_VECTOR = 'vector';

    /**
     * @param string $key
     * @param Table $table
     * @param string $fieldType The actual field type (e.g., 'string', 'integer', 'email')
     * @param int $size
     * @param bool $required
     * @param mixed|null $default
     * @param bool $array
     * @param bool $signed
     * @param string $format
     * @param array<string, mixed> $formatOptions
     * @param array<string> $filters
     * @param array<string, mixed> $options
     * @param string $createdAt
     * @param string $updatedAt
     */
    public function __construct(
        protected readonly string $key,
        protected readonly Table  $table,
        protected readonly string $fieldType = '',
        protected readonly int    $size = 0,
        protected readonly bool   $required = false,
        protected readonly mixed  $default = null,
        protected readonly bool   $array = false,
        protected readonly bool   $signed = false,
        protected readonly string $format = '',
        protected readonly array  $formatOptions = [],
        protected readonly array  $filters = [],
        protected array           $options = [],
        protected string          $createdAt = '',
        protected string $updatedAt = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'table' => $this->table,
            'type' => $this->getType(),
            'size' => $this->size,
            'required' => $this->required,
            'default' => $this->default,
            'array' => $this->array,
            'signed' => $this->signed,
            'format' => $this->format,
            'formatOptions' => $this->formatOptions,
            'filters' => $this->filters,
            'options' => $this->options,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_ATTRIBUTE;
    }

    public function getType(): string
    {
        return $this->fieldType;
    }

    /**
     * Convert a Column resource to an Attribute resource.
     *
     * @param Column $column
     * @return self
     */
    public static function fromColumn(Column $column): self
    {
        return new self(
            $column->getKey(),
            $column->getTable(),
            $column->getType(),
            $column->getSize(),
            $column->isRequired(),
            $column->getDefault(),
            $column->isArray(),
            $column->isSigned(),
            $column->getFormat(),
            $column->getFormatOptions(),
            $column->getFilters(),
            $column->getOptions(),
            $column->getCreatedAt(),
            $column->getUpdatedAt()
        );
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_DATABASES;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTable(): Table
    {
        return $this->table;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function isArray(): bool
    {
        return $this->array;
    }

    public function isSigned(): bool
    {
        return $this->signed;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFormatOptions(): array
    {
        return $this->formatOptions;
    }

    /**
     * @return array<string>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @return array<string, mixed>
     */
    public function &getOptions(): array
    {
        return $this->options;
    }
}
