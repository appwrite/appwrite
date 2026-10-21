<?php

namespace Utopia\Migration\Resources\Database\Columns;

use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Table;

class Decimal extends Column
{
    public function __construct(
        string $key,
        Table $table,
        bool   $required = false,
        ?float $default = null,
        bool   $array = false,
        ?float $min = null,
        ?float $max = null,
        bool   $signed = true,
        string $createdAt = '',
        string $updatedAt = ''
    ) {
        $min ??= PHP_FLOAT_MIN;
        $max ??= PHP_FLOAT_MAX;

        parent::__construct(
            $key,
            $table,
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
     *     default: ?float,
     *     formatOptions: array{
     *         min: ?float,
     *         max: ?float
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
            min: $array['formatOptions']['min'],
            max: $array['formatOptions']['max'],
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }

    public function getType(): string
    {
        return Column::TYPE_FLOAT;
    }

    public function getMin(): ?float
    {
        return (float)$this->formatOptions['min'];
    }

    public function getMax(): ?float
    {
        return (float)$this->formatOptions['max'];
    }
}
