<?php

use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;

$logsCollection = [];

$logsCollection['stats'] = [
    '$collection' => ID::custom(Database::METADATA),
    '$id' => ID::custom('stats'),
    'name' => 'stats',
    'attributes' => [
        [
            '$id' => ID::custom('metric'),
            'type' => Database::VAR_STRING,
            'format' => '',
            'size' => 255,
            'signed' => true,
            'required' => true,
            'default' => null,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => ID::custom('region'),
            'type' => Database::VAR_STRING,
            'format' => '',
            'size' => 255,
            'signed' => true,
            'required' => true,
            'default' => null,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => ID::custom('value'),
            'type' => Database::VAR_INTEGER,
            'format' => '',
            'size' => 8,
            'signed' => true,
            'required' => true,
            'default' => null,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => ID::custom('time'),
            'type' => Database::VAR_DATETIME,
            'format' => '',
            'size' => 0,
            'signed' => false,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => ['datetime'],
        ],
        [
            '$id' => ID::custom('period'),
            'type' => Database::VAR_STRING,
            'format' => '',
            'size' => 4,
            'signed' => true,
            'required' => true,
            'default' => null,
            'array' => false,
            'filters' => [],
        ],
    ],
    'indexes' => [
        [
            '$id' => ID::custom('_key_time'),
            'type' => Database::INDEX_KEY,
            'attributes' => ['time'],
            'lengths' => [],
            'orders' => [Database::ORDER_DESC],
        ],
        [
            '$id' => ID::custom('_key_period_time'),
            'type' => Database::INDEX_KEY,
            'attributes' => ['period', 'time'],
            'lengths' => [],
            'orders' => [Database::ORDER_ASC],
        ],
        [
            '$id' => ID::custom('_key_metric_period_time'),
            'type' => Database::INDEX_UNIQUE,
            'attributes' => ['metric', 'period', 'time'],
            'lengths' => [],
            'orders' => [Database::ORDER_DESC],
        ],
    ],
];

return $logsCollection;
