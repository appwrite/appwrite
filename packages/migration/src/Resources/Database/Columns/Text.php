<?php

namespace Utopia\Migration\Resources\Database\Columns;

use Utopia\Database\Database;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Table;

class Text extends Column
{
    public function __construct(
        string  $key,
        Table   $table,
        bool    $required = false,
        ?string $default = null,
        bool    $array = false,
        int     $size = Database::LENGTH_KEY,
        string  $format = '',
        string  $createdAt = '',
        string  $updatedAt = ''
    ) {
        parent::__construct(
            $key,
            $table,
            size: $size,
            required: $required,
            default: $default,
            array: $array,
            format: $format,
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }

    /**
     * @param array{
     *     key: string,
     *     collection?: array{
     *         database: array{
     *             id: string,
     *             name: string,
     *         },
     *         name: string,
     *         id: string,
     *         documentSecurity: bool,
     *         permissions: ?array<string>
     *     },
     *     table?: array{
     *         database: array{
     *             id: string,
     *             name: string,
     *         },
     *         name: string,
     *         id: string,
     *         rowSecurity: bool,
     *         permissions: ?array<string>
     *     },
     *     required: bool,
     *     default: ?string,
     *     array: bool,
     *     size: int,
     *     format: string,
     *     createdAt: string,
     *     updatedAt: string,
     * } $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['key'],
            Table::fromArray($array['table'] ?? $array['collection']),
            required: $array['required'],
            default: $array['default'] ?? null,
            array: $array['array'],
            size: $array['size'],
            format: $array['format'],
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }

    public function getType(): string
    {
        return Column::TYPE_STRING;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getFormat(): string
    {
        return $this->format;
    }
}
