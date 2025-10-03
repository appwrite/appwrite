<?php

use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;

return [
    'payments_plans' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('payments_plans'),
        'name' => 'Payments Plans',
        'attributes' => [
            [ '$id' => ID::custom('projectId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('projectInternalId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('planId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('name'), 'type' => Database::VAR_STRING, 'size' => 2048, 'signed' => true, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('description'), 'type' => Database::VAR_STRING, 'size' => 8192, 'signed' => true, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('pricing'), 'type' => Database::VAR_STRING, 'size' => 65536, 'signed' => true, 'required' => false, 'default' => [], 'array' => false, 'filters' => ['json'] ],
            [ '$id' => ID::custom('isDefault'), 'type' => Database::VAR_BOOLEAN, 'size' => 0, 'signed' => true, 'required' => false, 'default' => false, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('isFree'), 'type' => Database::VAR_BOOLEAN, 'size' => 0, 'signed' => true, 'required' => false, 'default' => false, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('status'), 'type' => Database::VAR_STRING, 'size' => 32, 'signed' => true, 'required' => false, 'default' => 'active', 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('providers'), 'type' => Database::VAR_STRING, 'size' => 65536, 'signed' => true, 'required' => false, 'default' => [], 'array' => false, 'filters' => ['json'] ],
            [ '$id' => ID::custom('features'), 'type' => Database::VAR_STRING, 'size' => 65536, 'signed' => true, 'required' => false, 'default' => [], 'array' => false, 'filters' => ['json'] ],
            [ '$id' => ID::custom('search'), 'type' => Database::VAR_STRING, 'size' => 16384, 'signed' => true, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
        ],
        'indexes' => [
            [ '$id' => ID::custom('_key_project_plan'), 'type' => Database::INDEX_UNIQUE, 'attributes' => ['projectId','planId'], 'lengths' => [Database::LENGTH_KEY, Database::LENGTH_KEY], 'orders' => [Database::ORDER_ASC, Database::ORDER_ASC] ],
            [ '$id' => ID::custom('_key_status'), 'type' => Database::INDEX_KEY, 'attributes' => ['status'], 'lengths' => [32], 'orders' => [Database::ORDER_ASC] ],
            [ '$id' => ID::custom('_fulltext_search'), 'type' => Database::INDEX_FULLTEXT, 'attributes' => ['search'], 'lengths' => [], 'orders' => [] ],
        ],
    ],

    'payments_features' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('payments_features'),
        'name' => 'Payments Features',
        'attributes' => [
            [ '$id' => ID::custom('projectId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('projectInternalId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('featureId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('name'), 'type' => Database::VAR_STRING, 'size' => 2048, 'signed' => true, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('type'), 'type' => Database::VAR_STRING, 'size' => 32, 'signed' => true, 'required' => true, 'default' => 'boolean', 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('description'), 'type' => Database::VAR_STRING, 'size' => 8192, 'signed' => true, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('providers'), 'type' => Database::VAR_STRING, 'size' => 65536, 'signed' => true, 'required' => false, 'default' => [], 'array' => false, 'filters' => ['json'] ],
        ],
        'indexes' => [
            [ '$id' => ID::custom('_key_project_feature'), 'type' => Database::INDEX_UNIQUE, 'attributes' => ['projectId','featureId'], 'lengths' => [Database::LENGTH_KEY, Database::LENGTH_KEY], 'orders' => [Database::ORDER_ASC, Database::ORDER_ASC] ],
            [ '$id' => ID::custom('_key_type'), 'type' => Database::INDEX_KEY, 'attributes' => ['type'], 'lengths' => [32], 'orders' => [Database::ORDER_ASC] ],
        ],
    ],

    'payments_plan_features' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('payments_plan_features'),
        'name' => 'Payments Plan Features',
        'attributes' => [
            [ '$id' => ID::custom('projectId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('projectInternalId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('planId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('featureId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('type'), 'type' => Database::VAR_STRING, 'size' => 32, 'signed' => true, 'required' => true, 'default' => 'boolean', 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('enabled'), 'type' => Database::VAR_BOOLEAN, 'size' => 0, 'signed' => true, 'required' => false, 'default' => true, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('currency'), 'type' => Database::VAR_STRING, 'size' => 8, 'signed' => true, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('interval'), 'type' => Database::VAR_STRING, 'size' => 16, 'signed' => true, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('includedUnits'), 'type' => Database::VAR_INTEGER, 'size' => 0, 'signed' => false, 'required' => false, 'default' => 0, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('tiersMode'), 'type' => Database::VAR_STRING, 'size' => 16, 'signed' => true, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('tiers'), 'type' => Database::VAR_STRING, 'size' => 65536, 'signed' => true, 'required' => false, 'default' => [], 'array' => false, 'filters' => ['json'] ],
            [ '$id' => ID::custom('usageCap'), 'type' => Database::VAR_INTEGER, 'size' => 0, 'signed' => false, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('overagePrice'), 'type' => Database::VAR_INTEGER, 'size' => 0, 'signed' => false, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('providers'), 'type' => Database::VAR_STRING, 'size' => 65536, 'signed' => true, 'required' => false, 'default' => [], 'array' => false, 'filters' => ['json'] ],
            [ '$id' => ID::custom('metadata'), 'type' => Database::VAR_STRING, 'size' => 65536, 'signed' => true, 'required' => false, 'default' => [], 'array' => false, 'filters' => ['json'] ],
        ],
        'indexes' => [
            [ '$id' => ID::custom('_key_unique'), 'type' => Database::INDEX_UNIQUE, 'attributes' => ['projectId','planId','featureId'], 'lengths' => [Database::LENGTH_KEY, Database::LENGTH_KEY, Database::LENGTH_KEY], 'orders' => [Database::ORDER_ASC, Database::ORDER_ASC, Database::ORDER_ASC] ],
            [ '$id' => ID::custom('_key_type'), 'type' => Database::INDEX_KEY, 'attributes' => ['type'], 'lengths' => [16], 'orders' => [Database::ORDER_ASC] ],
        ],
    ],

    'payments_subscriptions' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('payments_subscriptions'),
        'name' => 'Payments Subscriptions',
        'attributes' => [
            [ '$id' => ID::custom('projectId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('projectInternalId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('subscriptionId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('actorType'), 'type' => Database::VAR_STRING, 'size' => 16, 'signed' => true, 'required' => true, 'default' => 'user', 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('actorId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('actorInternalId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('planId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('status'), 'type' => Database::VAR_STRING, 'size' => 32, 'signed' => true, 'required' => false, 'default' => 'active', 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('trialEndsAt'), 'type' => Database::VAR_DATETIME, 'size' => 0, 'signed' => false, 'required' => false, 'default' => null, 'array' => false, 'filters' => ['datetime'] ],
            [ '$id' => ID::custom('currentPeriodStart'), 'type' => Database::VAR_DATETIME, 'size' => 0, 'signed' => false, 'required' => false, 'default' => null, 'array' => false, 'filters' => ['datetime'] ],
            [ '$id' => ID::custom('currentPeriodEnd'), 'type' => Database::VAR_DATETIME, 'size' => 0, 'signed' => false, 'required' => false, 'default' => null, 'array' => false, 'filters' => ['datetime'] ],
            [ '$id' => ID::custom('cancelAtPeriodEnd'), 'type' => Database::VAR_BOOLEAN, 'size' => 0, 'signed' => true, 'required' => false, 'default' => false, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('canceledAt'), 'type' => Database::VAR_DATETIME, 'size' => 0, 'signed' => false, 'required' => false, 'default' => null, 'array' => false, 'filters' => ['datetime'] ],
            [ '$id' => ID::custom('providers'), 'type' => Database::VAR_STRING, 'size' => 65536, 'signed' => true, 'required' => false, 'default' => [], 'array' => false, 'filters' => ['json'] ],
            [ '$id' => ID::custom('usageSummary'), 'type' => Database::VAR_STRING, 'size' => 65536, 'signed' => true, 'required' => false, 'default' => [], 'array' => false, 'filters' => ['json'] ],
            [ '$id' => ID::custom('tags'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => false, 'default' => [], 'array' => true, 'filters' => [] ],
            [ '$id' => ID::custom('search'), 'type' => Database::VAR_STRING, 'size' => 16384, 'signed' => true, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
        ],
        'indexes' => [
            [ '$id' => ID::custom('_key_actor'), 'type' => Database::INDEX_KEY, 'attributes' => ['projectId','actorType','actorId'], 'lengths' => [Database::LENGTH_KEY, 16, Database::LENGTH_KEY], 'orders' => [Database::ORDER_ASC, Database::ORDER_ASC, Database::ORDER_ASC] ],
            [ '$id' => ID::custom('_key_status'), 'type' => Database::INDEX_KEY, 'attributes' => ['status'], 'lengths' => [32], 'orders' => [Database::ORDER_ASC] ],
            [ '$id' => ID::custom('_key_plan'), 'type' => Database::INDEX_KEY, 'attributes' => ['planId'], 'lengths' => [Database::LENGTH_KEY], 'orders' => [Database::ORDER_ASC] ],
            [ '$id' => ID::custom('_fulltext_search'), 'type' => Database::INDEX_FULLTEXT, 'attributes' => ['search'], 'lengths' => [], 'orders' => [] ],
        ],
    ],

    'payments_usage_events' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('payments_usage_events'),
        'name' => 'Payments Usage Events',
        'attributes' => [
            [ '$id' => ID::custom('projectId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('subscriptionId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('actorType'), 'type' => Database::VAR_STRING, 'size' => 16, 'signed' => true, 'required' => true, 'default' => 'user', 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('actorId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('planId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('featureId'), 'type' => Database::VAR_STRING, 'size' => Database::LENGTH_KEY, 'signed' => true, 'required' => true, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('quantity'), 'type' => Database::VAR_INTEGER, 'size' => 0, 'signed' => false, 'required' => true, 'default' => 0, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('timestamp'), 'type' => Database::VAR_DATETIME, 'size' => 0, 'signed' => false, 'required' => true, 'default' => null, 'array' => false, 'filters' => ['datetime'] ],
            [ '$id' => ID::custom('providerSyncState'), 'type' => Database::VAR_STRING, 'size' => 32, 'signed' => true, 'required' => false, 'default' => 'pending', 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('providerEventId'), 'type' => Database::VAR_STRING, 'size' => 256, 'signed' => true, 'required' => false, 'default' => null, 'array' => false, 'filters' => [] ],
            [ '$id' => ID::custom('metadata'), 'type' => Database::VAR_STRING, 'size' => 65536, 'signed' => true, 'required' => false, 'default' => [], 'array' => false, 'filters' => ['json'] ],
        ],
        'indexes' => [
            [ '$id' => ID::custom('_key_subscription_feature_time'), 'type' => Database::INDEX_KEY, 'attributes' => ['projectId','subscriptionId','featureId','timestamp'], 'lengths' => [Database::LENGTH_KEY, Database::LENGTH_KEY, Database::LENGTH_KEY, 0], 'orders' => [Database::ORDER_ASC, Database::ORDER_ASC, Database::ORDER_ASC, Database::ORDER_ASC] ],
            [ '$id' => ID::custom('_key_sync_state'), 'type' => Database::INDEX_KEY, 'attributes' => ['providerSyncState'], 'lengths' => [32], 'orders' => [Database::ORDER_ASC] ],
        ],
    ],

];


