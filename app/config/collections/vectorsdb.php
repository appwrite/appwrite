<?php

use Utopia\Database\Attribute;
use Utopia\Database\Index;
use Utopia\Query\OrderDirection;

return [
    'collections' => [
        '$collection' => 'databases',
        '$id' => 'collections',
        'name' => 'Collections',
        'attributes' => [
            Attribute::string(key: 'databaseInternalId', required: true),
            Attribute::string(key: 'databaseId', required: true),
            Attribute::string(key: 'name', size: 256, required: true),
            Attribute::string(key: 'description', size: 1024, default: ''),
            Attribute::integer(key: 'dimension', required: true, signed: false),
            Attribute::boolean(key: 'enabled', required: true),
            Attribute::boolean(key: 'documentSecurity', required: true),
            // The table's permissions as the caller wrote them, kept only when one of them names
            // a column. $permissions holds the same grants with every column dropped, because that
            // is the form the read gate matches -- a column stored there would hide the table from
            // the role it was granted to. So this is the list to report and to replay onto the
            // collection; $permissions is a derived index of it.
            Attribute::string(key: 'columnPermissions', size: 1000000, default: [], filters: ['json']),
            Attribute::string(key: 'attributes', size: 1000000, filters: ['subQueryAttributes']),
            Attribute::string(key: 'indexes', size: 1000000, filters: ['subQueryIndexes']),
            Attribute::string(key: 'search', size: 16384),
        ],
        'defaultAttributes' => [
            Attribute::object(key: 'metadata', default: []),
        ],
        'indexes' => [
            Index::fulltext(key: '_fulltext_search', attributes: ['search']),
            Index::key(key: '_key_name', attributes: ['name'], lengths: [256], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_enabled', attributes: ['enabled'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_documentSecurity', attributes: ['documentSecurity'], orders: [OrderDirection::Asc]),
        ],
        'defaultIndexes' => [
            Index::object(key: '_key_metadata', attribute: 'metadata'),
        ],
    ],
];
