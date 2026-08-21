<?php

use Utopia\Database\Attribute;
use Utopia\Database\Index;

return [
    'collections' => [
        '$collection' => 'databases',
        '$id' => 'collections',
        'name' => 'Collections',
        'attributes' => [
            Attribute::string(key: 'databaseInternalId', required: true),
            Attribute::string(key: 'databaseId', required: true),
            Attribute::string(key: 'name', size: 256, required: true),
            Attribute::boolean(key: 'enabled', required: true),
            Attribute::boolean(key: 'documentSecurity', required: true),
            Attribute::string(key: 'attributes', size: 1000000, filters: ['subQueryAttributes']),
            Attribute::string(key: 'indexes', size: 1000000, filters: ['subQueryIndexes']),
            Attribute::string(key: 'search', size: 16384),
        ],
        'indexes' => [
            Index::fullText(key: '_fulltext_search', attributes: ['search']),
            Index::key(key: '_key_name', attributes: ['name'], lengths: [256], orders: ['ASC']),
            Index::key(key: '_key_enabled', attributes: ['enabled'], orders: ['ASC']),
            Index::key(key: '_key_documentSecurity', attributes: ['documentSecurity'], orders: ['ASC']),
        ],
    ],
];
