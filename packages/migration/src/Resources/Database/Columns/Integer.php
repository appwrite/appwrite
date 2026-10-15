<?php

namespace Utopia\Migration\Resources\Database\Columns;

use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Table;

class Integer extends Column
{
    public function __construct(
        string $key,
        Table $table,
        bool   $required = false,
        ?int   $default = null,
        bool   $array = false,
        ?int   $min = null,
        ?int   $max = null,
        bool   $signed = true,
        string $createdAt = '',
        string $updatedAt = ''
    ) {
        $min ??= PHP_INT_MIN;
        $max ??= PHP_INT_MAX;
        $size = $max > 2147483647 ? 8 : 4;

        parent::__construct(
            $key,
            $table,
            size: $size,
            required: $required,
            default: $default,
            array: $array,
            signed: $signed,
            formatOptions: [
                'min' => $min,
                'max' => $max,
            ],
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
     *     array: bool,
     *     default: ?int,
     *     formatOptions: array{
     *         min: ?int,
     *         max: ?int
     *     },
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
            min: $array['formatOptions']['min'] ?? null,
            max: $array['formatOptions']['max'] ?? null,
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }

    public function getType(): string
    {
        return Column::TYPE_INTEGER;
    }

    public function getMin(): ?int
    {
        return (int)$this->formatOptions['min'];
    }

    public function getMax(): ?int
    {
        return (int)$this->formatOptions['max'];
    }
}
