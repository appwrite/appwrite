<?php

namespace Utopia\Migration\Resources\Database\Columns;

use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Table;

class DateTime extends Column
{
    public function __construct(
        string  $key,
        Table   $table,
        bool    $required = false,
        ?string $default = null,
        bool    $array = false,
        string  $createdAt = '',
        string  $updatedAt = ''
    ) {
        parent::__construct(
            $key,
            $table,
            required: $required,
            default: $default,
            array: $array,
            filters: ['datetime'],
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }

    public function getType(): string
    {
        return Column::TYPE_DATETIME;
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
     *     array: bool,
     *     default: ?string,
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
            default: $array['default'],
            array: $array['array'],
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }
}
