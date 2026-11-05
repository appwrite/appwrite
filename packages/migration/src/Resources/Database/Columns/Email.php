<?php

namespace Utopia\Migration\Resources\Database\Columns;

use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Table;

class Email extends Text
{
    public function __construct(
        string  $key,
        Table   $table,
        bool    $required = false,
        ?string $default = null,
        bool    $array = false,
        int     $size = Column::FORMAT_SIZES[Column::TYPE_EMAIL],
        string  $createdAt = '',
        string  $updatedAt = ''
    ) {
        parent::__construct(
            $key,
            $table,
            required: $required,
            default: $default,
            array: $array,
            size: $size,
            format: 'email',
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }

    public function getType(): string
    {
        return Column::TYPE_EMAIL;
    }
}
