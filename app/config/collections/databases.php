<?php

use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Index;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\IndexType;

return [
    'collections' => [
        '$collection' => 'databases',
        '$id' => 'collections',
        'name' => 'Collections',
        'attributes' => [
            new Attribute(
                key: 'databaseInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'databaseId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'name',
                type: ColumnType::String,
                size: 256,
                required: true,
            ),
            new Attribute(
                key: 'enabled',
                type: ColumnType::Boolean,
                required: true,
            ),
            new Attribute(
                key: 'documentSecurity',
                type: ColumnType::Boolean,
                required: true,
            ),
            new Attribute(
                key: 'attributes',
                type: ColumnType::String,
                size: 1000000,
                filters: ['subQueryAttributes'],
            ),
            new Attribute(
                key: 'indexes',
                type: ColumnType::String,
                size: 1000000,
                filters: ['subQueryIndexes'],
            ),
            new Attribute(
                key: 'search',
                type: ColumnType::String,
                size: 16384,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_fulltext_search',
                type: IndexType::Fulltext,
                attributes: ['search'],
            ),
            new Index(
                key: '_key_name',
                type: IndexType::Key,
                attributes: ['name'],
                lengths: [256],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_enabled',
                type: IndexType::Key,
                attributes: ['enabled'],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_documentSecurity',
                type: IndexType::Key,
                attributes: ['documentSecurity'],
                orders: ['ASC'],
            ),
        ],
    ],
];
