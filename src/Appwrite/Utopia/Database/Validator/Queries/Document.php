<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries;
use Appwrite\Utopia\Database\Validator\Query\Cursor;
use Appwrite\Utopia\Database\Validator\Query\Filter;
use Appwrite\Utopia\Database\Validator\Query\Limit;
use Appwrite\Utopia\Database\Validator\Query\Offset;
use Appwrite\Utopia\Database\Validator\Query\Order;
use Appwrite\Utopia\Database\Validator\Query\Select;
use Utopia\Database\Database;

class Document extends Queries
{
    /**
     * Expression constructor
     *
     * @param array $attributes
     * @throws \Exception
     */
    public function __construct(array $attributes)
    {
        $attributes[] = new \Utopia\Database\Document([
            'key' => '$id',
            'type' => Database::VAR_STRING,
            'array' => false,
        ]);
        $attributes[] = new \Utopia\Database\Document([
            'key' => '$createdAt',
            'type' => Database::VAR_DATETIME,
            'array' => false,
        ]);
        $attributes[] = new \Utopia\Database\Document([
            'key' => '$updatedAt',
            'type' => Database::VAR_DATETIME,
            'array' => false,
        ]);

        $validators = [
            new Select($attributes),
        ];

        parent::__construct(...$validators);
    }
}
