<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Filter;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Query\Order;

class Executions extends Queries
{
    protected const ATTRIBUTE_TYPES = [
        'trigger' => Database::VAR_STRING,
        'status' => Database::VAR_STRING,
        'responseStatusCode' => Database::VAR_INTEGER,
        'duration' => Database::VAR_FLOAT,
        'requestMethod' => Database::VAR_STRING,
        'requestPath' => Database::VAR_STRING,
        'deploymentId' => Database::VAR_STRING,
    ];

    public const ALLOWED_ATTRIBUTES = [
        'trigger',
        'status',
        'responseStatusCode',
        'duration',
        'requestMethod',
        'requestPath',
        'deploymentId'
    ];

    /**
     * Expression constructor
     *
     */
    public function __construct(array $allowedAttributes = self::ALLOWED_ATTRIBUTES)
    {
        $attributes = [];
        foreach ($allowedAttributes as $attribute) {
            $attributes[] = new Document([
                'key' => $attribute,
                'type' => self::ATTRIBUTE_TYPES[$attribute],
                'array' => false,
            ]);
        }

        $attributes = [
            ...$attributes,
            new Document([
                'key' => '$id',
                'type' => Database::VAR_STRING,
                'array' => false,
            ]),
            new Document([
                'key' => '$createdAt',
                'type' => Database::VAR_DATETIME,
                'array' => false,
            ]),
            new Document([
                'key' => '$updatedAt',
                'type' => Database::VAR_DATETIME,
                'array' => false,
            ]),
            new Document([
                'key' => '$sequence',
                'type' => Database::VAR_INTEGER,
                'array' => false,
            ]),
        ];

        parent::__construct([
            new Limit(),
            new Offset(),
            new Cursor(),
            new Filter(
                attributes: $attributes,
                idAttributeType: Database::VAR_INTEGER,
                maxValuesCount: APP_DATABASE_QUERY_MAX_VALUES
            ),
            new Order($attributes),
        ]);
    }
}
