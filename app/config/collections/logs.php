<?php

use Utopia\Database\Attribute;
use Utopia\Database\Index;

return [
    'stats' => [
        '$collection' => '_metadata',
        '$id' => 'stats',
        'name' => 'stats',
        'attributes' => [
            Attribute::string(key: 'metric', required: true),
            Attribute::string(key: 'region', required: true),
            Attribute::integer(key: 'value', size: 8, required: true),
            Attribute::datetime(key: 'time', signed: false, filters: ['datetime']),
            Attribute::string(key: 'period', size: 4, required: true),
        ],
        'indexes' => [
            Index::key(key: '_key_time', attributes: ['time'], orders: ['DESC']),
            Index::key(key: '_key_period_time', attributes: ['period', 'time'], orders: ['ASC']),
            Index::unique(key: '_key_metric_period_time', attributes: ['metric', 'period', 'time'], orders: ['DESC']),
        ],
    ],
];
