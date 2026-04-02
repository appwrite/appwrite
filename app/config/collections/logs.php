<?php

use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Index;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\IndexType;

return [
    'stats' => [
        '$collection' => '_metadata',
        '$id' => 'stats',
        'name' => 'stats',
        'attributes' => [
            new Attribute(
                key: 'metric',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'region',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'value',
                type: ColumnType::Integer,
                size: 8,
                required: true,
            ),
            new Attribute(
                key: 'time',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'period',
                type: ColumnType::String,
                size: 4,
                required: true,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_time',
                type: IndexType::Key,
                attributes: ['time'],
                orders: ['DESC'],
            ),
            new Index(
                key: '_key_period_time',
                type: IndexType::Key,
                attributes: ['period', 'time'],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_metric_period_time',
                type: IndexType::Unique,
                attributes: ['metric', 'period', 'time'],
                orders: ['DESC'],
            ),
        ],
    ],
];
