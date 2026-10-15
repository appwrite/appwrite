<?php

namespace Utopia\Migration\Resources\Database;

use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

abstract class Column extends Resource
{
    public const TYPE_STRING = 'string';
    public const TYPE_TEXT = 'text';
    public const TYPE_VARCHAR = 'varchar';
    public const TYPE_MEDIUMTEXT = 'mediumtext';
    public const TYPE_LONGTEXT = 'longtext';

    public const TYPE_INTEGER = 'integer';
    public const TYPE_BIG_INT = 'bigint';
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
     * Types whose size is fixed by the type itself. Appwrite leaves the size
     * off the API response for these, so it has to be derived from the type.
     *
     * Mirrors Appwrite\Utopia\Database\Attribute::SIZES.
     *
     * @var array<string, int>
     */
    public const SIZES = [
        self::TYPE_TEXT => 65535,
        self::TYPE_MEDIUMTEXT => 16777215,
        self::TYPE_LONGTEXT => 2147483647,
    ];

    /**
     * String formats, mapped to the size Appwrite creates them with. Each
     * format is also accepted as a shorthand type on an inline column
     * definition, where it means a string of that format.
     *
     * Mirrors Appwrite\Utopia\Database\Attribute::FORMAT_SIZES.
     *
     * @var array<string, int>
     */
    public const FORMAT_SIZES = [
        self::TYPE_EMAIL => 254,
        self::TYPE_ENUM => UtopiaDatabase::LENGTH_KEY,
        self::TYPE_IP => 39,
        self::TYPE_URL => 2000,
    ];

    /**
     * Size to fall back on for a varchar that arrives without one. Appwrite
     * requires an explicit size for varchar, so this only guards a source
     * that omits it.
     */
    public const DEFAULT_VARCHAR_SIZE = 255;

    /**
     * Resolve a raw column definition into the type, format and size Appwrite
     * stores for it. A format shorthand (`email`, `url`, `ip`, `enum`) becomes
     * a string of that format, and an omitted size is filled in from the type
     * or the format.
     *
     * Mirrors Appwrite\Utopia\Database\Attribute::resolve() so a column read
     * from a source ends up with the size the destination would have stored.
     *
     * @param array<string, mixed> $column
     * @return array{type: string, format: string, size: int}
     */
    public static function resolve(array $column): array
    {
        $type = \is_string($column['type'] ?? null) ? $column['type'] : '';
        $format = \is_string($column['format'] ?? null) ? $column['format'] : '';

        if ($type === 'biginteger') {
            $type = self::TYPE_BIG_INT;
        }

        if (isset(self::FORMAT_SIZES[$type])) {
            $format = $type;
            $type = self::TYPE_STRING;
        }

        $size = $column['size'] ?? null;
        $size = \is_numeric($size) ? (int) $size : 0;

        if (isset(self::SIZES[$type])) {
            // Fixed width types ignore any size the source reported.
            $size = self::SIZES[$type];
        } elseif ($size < 1) {
            $size = self::FORMAT_SIZES[$format] ?? $size;
        }

        return [
            'type' => $type,
            'format' => $format,
            'size' => $size,
        ];
    }

    /**
     * @param string $key
     * @param Table $table
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
        return Resource::TYPE_COLUMN;
    }

    abstract public function getType(): string;

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

    /**
     * Convert this Column resource to an Attribute resource.
     * This provides a deterministic way to derive attributes from columns,
     * eliminating the need to maintain duplicate per-type Attribute implementations.
     *
     * @return Attribute
     */
    public function getAttribute(): Attribute
    {
        return Attribute::fromColumn($this);
    }
}
