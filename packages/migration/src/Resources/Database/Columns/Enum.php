<?php

namespace Utopia\Migration\Resources\Database\Columns;

use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Table;

class Enum extends Column
{
    /**
     * @param array<string> $elements
     */
    public function __construct(
        string  $key,
        Table   $table,
        array   $elements,
        bool    $required = false,
        ?string $default = null,
        bool    $array = false,
        int     $size = Column::FORMAT_SIZES[Column::TYPE_ENUM],
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
            format: 'enum',
            formatOptions: [
                'elements' => $elements,
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
     *     size: int,
     *     required: bool,
     *     default: ?string,
     *     array: bool,
     *     formatOptions: array{
     *         elements: array<string>
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
            elements: $array['formatOptions']['elements'],
            required: $array['required'],
            default: $array['default'],
            array: $array['array'],
            size: $array['size'],
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }

    public function getType(): string
    {
        return Column::TYPE_ENUM;
    }

    /**
     * @return array<string>
     */
    public function getElements(): array
    {
        return (array)$this->formatOptions['elements'];
    }
}
