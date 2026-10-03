<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Document;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Filter;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Query\Order;
use Utopia\Query\Schema\ColumnType;

class Executions extends Queries
{
    protected const ATTRIBUTE_TYPES = [
        'trigger' => ColumnType::String->value,
        'status' => ColumnType::String->value,
        'responseStatusCode' => ColumnType::Integer->value,
        'duration' => ColumnType::Float->value,
        'requestMethod' => ColumnType::String->value,
        'requestPath' => ColumnType::String->value,
        'deploymentId' => ColumnType::String->value,
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
                'type' => ColumnType::String->value,
                'array' => false,
            ]),
            new Document([
                'key' => '$createdAt',
                'type' => ColumnType::Datetime->value,
                'array' => false,
            ]),
            new Document([
                'key' => '$updatedAt',
                'type' => ColumnType::Datetime->value,
                'array' => false,
            ]),
            new Document([
                'key' => '$sequence',
                'type' => ColumnType::Integer->value,
                'array' => false,
            ]),
        ];

        parent::__construct([
            new Limit(),
            new Offset(),
            new Cursor(),
            new Filter(
                attributes: $attributes,
                idAttributeType: ColumnType::Integer->value,
                maxValuesCount: APP_DATABASE_QUERY_MAX_VALUES
            ),
            new Order($attributes),
        ]);
    }
}
