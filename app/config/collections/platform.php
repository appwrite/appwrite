<?php

use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Index;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\IndexType;
use Utopia\Config\Config;

$providers = Config::getParam('oAuthProviders', []);

$platformCollections = [
    'projects' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('projects'),
        'name' => 'Projects',
        'attributes' => [
            new Attribute(
                key: 'teamInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'teamId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'name',
                type: ColumnType::String,
                size: 128,
            ),
            new Attribute(
                key: 'region',
                type: ColumnType::String,
                size: 128,
            ),
            new Attribute(
                key: 'description',
                type: ColumnType::String,
                size: 256,
            ),
            new Attribute(
                key: 'database',
                type: ColumnType::String,
                size: 256,
                required: true,
            ),
            new Attribute(
                key: 'logo',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'url',
                type: ColumnType::String,
                size: 16384,
            ),
            new Attribute(
                key: 'version',
                type: ColumnType::String,
                size: 16,
            ),
            new Attribute(
                key: 'legalName',
                type: ColumnType::String,
                size: 256,
            ),
            new Attribute(
                key: 'legalCountry',
                type: ColumnType::String,
                size: 256,
            ),
            new Attribute(
                key: 'legalState',
                type: ColumnType::String,
                size: 256,
            ),
            new Attribute(
                key: 'legalCity',
                type: ColumnType::String,
                size: 256,
            ),
            new Attribute(
                key: 'legalAddress',
                type: ColumnType::String,
                size: 256,
            ),
            new Attribute(
                key: 'legalTaxId',
                type: ColumnType::String,
                size: 256,
            ),
            new Attribute(
                key: 'accessedAt',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'services',
                type: ColumnType::String,
                size: 16384,
                default: [],
                filters: ['json'],
            ),
            new Attribute(
                key: 'apis',
                type: ColumnType::String,
                size: 16384,
                default: [],
                filters: ['json'],
            ),
            new Attribute(
                key: 'smtp',
                type: ColumnType::String,
                size: 16384,
                default: [],
                filters: ['json', 'encrypt'],
            ),
            // TODO make sure size fits
            new Attribute(
                key: 'templates',
                type: ColumnType::String,
                size: 1_000_000,
                default: [],
                filters: ['json'],
            ),
            new Attribute(
                key: 'auths',
                type: ColumnType::String,
                size: 16384,
                default: [],
                filters: ['json'],
            ),
            new Attribute(
                key: 'oAuthProviders',
                type: ColumnType::String,
                size: 16384,
                default: [],
                filters: ['json', 'encrypt'],
            ),
            new Attribute(
                key: 'platforms',
                type: ColumnType::String,
                size: 16384,
                filters: ['subQueryPlatforms'],
            ),
            new Attribute(
                key: 'webhooks',
                type: ColumnType::String,
                size: 16384,
                filters: ['subQueryWebhooks'],
            ),
            new Attribute(
                key: 'keys',
                type: ColumnType::String,
                size: 16384,
                filters: ['subQueryKeys'],
            ),
            new Attribute(
                key: 'devKeys',
                type: ColumnType::String,
                size: 16384,
                filters: ['subQueryDevKeys'],
            ),
            new Attribute(
                key: 'search',
                type: ColumnType::String,
                size: 16384,
            ),
            new Attribute(
                key: 'pingCount',
                type: ColumnType::Integer,
                default: 0,
                signed: false,
            ),
            new Attribute(
                key: 'pingedAt',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'labels',
                type: ColumnType::String,
                size: 128,
                default: [],
                array: true,
            ),
            new Attribute(
                key: 'onboarding',
                type: ColumnType::String,
                size: 65536,
                default: [],
                filters: ['json'],
            ),
            new Attribute(
                key: 'status',
                type: ColumnType::String,
                size: 100,
                signed: false,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_search',
                type: IndexType::Fulltext,
                attributes: ['search'],
            ),
            new Index(
                key: '_key_name',
                type: IndexType::Key,
                attributes: ['name'],
                lengths: [128],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_team',
                type: IndexType::Key,
                attributes: ['teamId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_pingCount',
                type: IndexType::Key,
                attributes: ['pingCount'],
            ),
            new Index(
                key: '_key_pingedAt',
                type: IndexType::Key,
                attributes: ['pingedAt'],
            ),
            new Index(
                key: '_key_database',
                type: IndexType::Key,
                attributes: ['database'],
            ),
            new Index(
                key: '_key_region_accessed_at',
                type: IndexType::Key,
                attributes: ['region', 'accessedAt'],
            ),
            new Index(
                key: '_key_accessedAt',
                type: IndexType::Key,
                attributes: ['accessedAt'],
            ),
            new Index(
                key: '_key_teamInternalId',
                type: IndexType::Key,
                attributes: ['teamInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
        ],
    ],

    'schedules' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('schedules'),
        'name' => 'schedules',
        'attributes' => [
            new Attribute(
                key: 'resourceType',
                type: ColumnType::String,
                size: 100,
            ),
            new Attribute(
                key: 'resourceInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'resourceId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'resourceUpdatedAt',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::Id,
            ),
            new Attribute(
                key: 'schedule',
                type: ColumnType::String,
                size: 100,
            ),
            new Attribute(
                key: 'data',
                type: ColumnType::String,
                size: 65535,
                default: new \stdClass(),
                filters: ['json', 'encrypt'],
            ),
            new Attribute(
                key: 'active',
                type: ColumnType::Boolean,
            ),
            new Attribute(
                key: 'region',
                type: ColumnType::String,
                size: 10,
                required: true,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_region_resourceType_resourceUpdatedAt',
                type: IndexType::Key,
                attributes: ['region', 'resourceType', 'resourceUpdatedAt'],
            ),
            new Index(
                key: '_key_region_resourceType_projectId_resourceId',
                type: IndexType::Key,
                attributes: ['region', 'resourceType', 'projectId', 'resourceId'],
            ),
            new Index(
                key: '_key_region_resourceType_projectInternalId_resourceId',
                type: IndexType::Key,
                attributes: ['region', 'resourceType', 'projectInternalId', 'resourceId'],
            ),
            new Index(
                key: '_key_project_id_region',
                type: IndexType::Key,
                attributes: ['projectId', 'region'],
            ),
            new Index(
                key: '_key_project_internal_id_region',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'region'],
            ),
            new Index(
                key: '_key_region_rt_active',
                type: IndexType::Key,
                attributes: ['region', 'resourceType', 'active'],
            ),
        ],
    ],

    'platforms' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('platforms'),
        'name' => 'platforms',
        'attributes' => [
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'type',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'name',
                type: ColumnType::String,
                size: 256,
                required: true,
            ),
            // For app platforms
            new Attribute(
                key: 'key',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            // Unused at the moment
            new Attribute(
                key: 'store',
                type: ColumnType::String,
                size: 256,
            ),
            // For web platforms
            new Attribute(
                key: 'hostname',
                type: ColumnType::String,
                size: 256,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_project',
                type: IndexType::Key,
                attributes: ['projectInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_project_id',
                type: IndexType::Key,
                attributes: ['projectId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
        ],
    ],

    'keys' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('keys'),
        'name' => 'keys',
        'attributes' => [
            new Attribute(
                key: 'resourceType',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'resourceId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'resourceInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'name',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'scopes',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
                array: true,
            ),
            // var_dump of \bin2hex(\random_bytes(128)) => string(256) doubling for encryption
            new Attribute(
                key: 'secret',
                type: ColumnType::String,
                size: 512,
                required: true,
                filters: ['encrypt'],
            ),
            new Attribute(
                key: 'expire',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'accessedAt',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'sdks',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
                array: true,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_resource',
                type: IndexType::Key,
                attributes: ['resourceType', 'resourceInternalId'],
            ),
            new Index(
                key: '_key_accessedAt',
                type: IndexType::Key,
                attributes: ['accessedAt'],
            ),
        ],
    ],

    'devKeys' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('devKeys'),
        'name' => 'Dev keys',
        'attributes' => [
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
                default: 0,
            ),
            new Attribute(
                key: 'name',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            // var_dump of \bin2hex(\random_bytes(128)) => string(256) doubling for encryption
            new Attribute(
                key: 'secret',
                type: ColumnType::String,
                size: 512,
                required: true,
                filters: ['encrypt'],
            ),
            new Attribute(
                key: 'expire',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'accessedAt',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'sdks',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
                array: true,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_project',
                type: IndexType::Key,
                attributes: ['projectInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_accessedAt',
                type: IndexType::Key,
                attributes: ['accessedAt'],
            ),
        ],
    ],

    'webhooks' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('webhooks'),
        'name' => 'webhooks',
        'attributes' => [
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'name',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'url',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'httpUser',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            // TODO will the length suffice after encryption?
            new Attribute(
                key: 'httpPass',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                filters: ['encrypt'],
            ),
            new Attribute(
                key: 'security',
                type: ColumnType::Boolean,
                required: true,
            ),
            new Attribute(
                key: 'events',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
                array: true,
            ),
            new Attribute(
                key: 'signatureKey',
                type: ColumnType::String,
                size: 2048,
            ),
            new Attribute(
                key: 'enabled',
                type: ColumnType::Boolean,
                default: true,
            ),
            new Attribute(
                key: 'logs',
                type: ColumnType::String,
                size: 1000000,
                default: '',
            ),
            new Attribute(
                key: 'attempts',
                type: ColumnType::Integer,
                default: 0,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_project',
                type: IndexType::Key,
                attributes: ['projectInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_project_id',
                type: IndexType::Key,
                attributes: ['projectId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
        ],
    ],

    'notifications' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('notifications'),
        'name' => 'Notifications',
        'attributes' => [
            new Attribute(
                key: 'messageId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'recipientHash',
                type: ColumnType::String,
                size: 64,
                required: true,
            ),
            new Attribute(
                key: 'type',
                type: ColumnType::String,
                size: 100,
                default: 'info',
            ),
            new Attribute(
                key: 'channel',
                type: ColumnType::String,
                size: 64,
                required: true,
            ),
            new Attribute(
                key: 'resourceType',
                type: ColumnType::String,
                size: 64,
                required: true,
            ),
            new Attribute(
                key: 'resourceId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::Id,
                required: true,
            ),
            new Attribute(
                key: 'resourceInternalId',
                type: ColumnType::Id,
                required: true,
            ),
            new Attribute(
                key: 'parentResourceType',
                type: ColumnType::String,
                size: 64,
                required: true,
            ),
            new Attribute(
                key: 'parentResourceId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'parentResourceInternalId',
                type: ColumnType::Id,
                required: true,
            ),
            new Attribute(
                key: 'title',
                type: ColumnType::String,
                size: 256,
                required: true,
            ),
            new Attribute(
                key: 'body',
                type: ColumnType::String,
                size: 65535,
                required: true,
            ),
            new Attribute(
                key: 'read',
                type: ColumnType::Boolean,
                default: false,
            ),
            new Attribute(
                key: 'firstSeen',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'lastSeen',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_messageId',
                type: IndexType::Key,
                attributes: ['messageId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_recipient',
                type: IndexType::Unique,
                attributes: ['messageId', 'channel', 'recipientHash'],
                lengths: [Database::LENGTH_KEY, 64, 64],
                orders: ['ASC', 'ASC', 'ASC'],
            ),
            new Index(
                key: '_key_project',
                type: IndexType::Key,
                attributes: ['projectId', 'projectInternalId'],
                lengths: [Database::LENGTH_KEY, 0],
                orders: ['ASC', 'ASC'],
            ),
            new Index(
                key: '_key_project_resource',
                type: IndexType::Key,
                attributes: ['projectId', 'projectInternalId', 'resourceType', 'resourceId', 'resourceInternalId'],
                lengths: [Database::LENGTH_KEY, 0, 64, Database::LENGTH_KEY, 0],
                orders: ['ASC', 'ASC', 'ASC', 'ASC', 'ASC'],
            ),
            new Index(
                key: '_key_project_parent_resource',
                type: IndexType::Key,
                attributes: ['projectId', 'projectInternalId', 'parentResourceType', 'parentResourceId', 'parentResourceInternalId'],
                lengths: [Database::LENGTH_KEY, 0, 64, Database::LENGTH_KEY, 0],
                orders: ['ASC', 'ASC', 'ASC', 'ASC', 'ASC'],
            ),
        ],
    ],

    'certificates' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('certificates'),
        'name' => 'Certificates',
        'attributes' => [
            // The maximum total length of a domain name or number is 255 characters.
            // https://datatracker.ietf.org/doc/html/rfc2821#section-4.5.3.1
            // https://datatracker.ietf.org/doc/html/rfc5321#section-4.5.3.1.2
            new Attribute(
                key: 'domain',
                type: ColumnType::String,
                size: 255,
            ),
            new Attribute(
                key: 'issueDate',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'renewDate',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'attempts',
                type: ColumnType::Integer,
            ),
            new Attribute(
                key: 'logs',
                type: ColumnType::String,
                size: 1000000,
            ),
            new Attribute(
                key: 'updated',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_domain',
                type: IndexType::Key,
                attributes: ['domain'],
                lengths: [255],
                orders: ['ASC'],
            ),
        ],
    ],

    'realtime' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('realtime'),
        'name' => 'Realtime Connections',
        'attributes' => [
            new Attribute(
                key: 'container',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'timestamp',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'value',
                type: ColumnType::String,
                size: 16384,
                required: true,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_timestamp',
                type: IndexType::Key,
                attributes: ['timestamp'],
                orders: ['DESC'],
            ),
        ]
    ],

    'rules' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('rules'),
        'name' => 'Rules',
        'attributes' => [
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'domain',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            // 'api', 'redirect', 'deployment' (site or function)
            new Attribute(
                key: 'type',
                type: ColumnType::String,
                size: 32,
            ),
            // 'manual', 'deployment', '' (empty)
            new Attribute(
                key: 'trigger',
                type: ColumnType::String,
                size: 32,
                default: '',
            ),
            new Attribute(
                key: 'redirectUrl',
                type: ColumnType::String,
                size: 2048,
                default: '',
            ),
            new Attribute(
                key: 'redirectStatusCode',
                type: ColumnType::Integer,
            ),
            new Attribute(
                key: 'deploymentResourceType',
                type: ColumnType::String,
                size: 32,
                default: '',
            ),
            new Attribute(
                key: 'deploymentId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                default: '',
            ),
            new Attribute(
                key: 'deploymentInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                default: '',
            ),
            new Attribute(
                key: 'deploymentResourceId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                default: '',
            ),
            new Attribute(
                key: 'deploymentResourceInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                default: '',
            ),
            new Attribute(
                key: 'deploymentVcsProviderBranch',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                default: '',
            ),
            new Attribute(
                key: 'status',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'certificateId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'search',
                type: ColumnType::String,
                size: 16384,
            ),
            // "Appwrite" or empty string
            new Attribute(
                key: 'owner',
                type: ColumnType::String,
                size: 16,
                default: '',
            ),
            new Attribute(
                key: 'region',
                type: ColumnType::String,
                size: 16,
                required: true,
            ),
            new Attribute(
                key: 'logs',
                type: ColumnType::String,
                size: 1000000,
                default: '',
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_search',
                type: IndexType::Fulltext,
                attributes: ['search'],
            ),
            new Index(
                key: '_key_domain',
                type: IndexType::Unique,
                attributes: ['domain'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_projectInternalId',
                type: IndexType::Key,
                attributes: ['projectInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_projectId',
                type: IndexType::Key,
                attributes: ['projectId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_type',
                type: IndexType::Key,
                attributes: ['type'],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_trigger',
                type: IndexType::Key,
                attributes: ['trigger'],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_deploymentResourceType',
                type: IndexType::Key,
                attributes: ['deploymentResourceType'],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_deploymentResourceId',
                type: IndexType::Key,
                attributes: ['deploymentResourceId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_deploymentResourceInternalId',
                type: IndexType::Key,
                attributes: ['deploymentResourceInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_deploymentId',
                type: IndexType::Key,
                attributes: ['deploymentId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_deploymentInternalId',
                type: IndexType::Key,
                attributes: ['deploymentInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_deploymentVcsProviderBranch',
                type: IndexType::Key,
                attributes: ['deploymentVcsProviderBranch'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_owner',
                type: IndexType::Key,
                attributes: ['owner'],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_piid_diid_drt',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'deploymentInternalId', 'deploymentResourceType'],
            ),
            new Index(
                key: '_key_region_status_createdAt',
                type: IndexType::Key,
                attributes: ['region', 'status', '$createdAt'],
            ),
        ],
    ],

    'installations' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('installations'),
        'name' => 'installations',
        'attributes' => [
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'providerInstallationId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'organization',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'provider',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'personal',
                type: ColumnType::Boolean,
                default: false,
            ),
            new Attribute(
                key: 'personalAccessToken',
                type: ColumnType::Text,
                size: 65535,
                filters: ['encrypt'],
            ),
            new Attribute(
                key: 'personalAccessTokenExpiry',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'personalRefreshToken',
                type: ColumnType::Text,
                size: 65535,
                filters: ['encrypt'],
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_projectInternalId',
                type: IndexType::Key,
                attributes: ['projectInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_projectId',
                type: IndexType::Key,
                attributes: ['projectId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_providerInstallationId',
                type: IndexType::Key,
                attributes: ['providerInstallationId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
        ],
    ],

    'repositories' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('repositories'),
        'name' => 'repositories',
        'attributes' => [
            new Attribute(
                key: 'installationId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'installationInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'providerRepositoryId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'resourceId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'resourceInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'resourceType',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'providerPullRequestIds',
                type: ColumnType::String,
                size: 128,
                array: true,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_installationId',
                type: IndexType::Key,
                attributes: ['installationId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_installationInternalId',
                type: IndexType::Key,
                attributes: ['installationInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_projectInternalId',
                type: IndexType::Key,
                attributes: ['projectInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_projectId',
                type: IndexType::Key,
                attributes: ['projectId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_providerRepositoryId',
                type: IndexType::Key,
                attributes: ['providerRepositoryId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_resourceId',
                type: IndexType::Key,
                attributes: ['resourceId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_resourceInternalId',
                type: IndexType::Key,
                attributes: ['resourceInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_resourceType',
                type: IndexType::Key,
                attributes: ['resourceType'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_piid_riid_rt',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'resourceInternalId', 'resourceType'],
            ),
        ],
    ],

    'vcsComments' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('vcsComments'),
        'name' => 'vcsComments',
        'attributes' => [
            new Attribute(
                key: 'installationId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'installationInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'providerRepositoryId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'providerCommentId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'providerPullRequestId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'providerBranch',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_installationId',
                type: IndexType::Key,
                attributes: ['installationId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_installationInternalId',
                type: IndexType::Key,
                attributes: ['installationInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_projectInternalId',
                type: IndexType::Key,
                attributes: ['projectInternalId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_projectId',
                type: IndexType::Key,
                attributes: ['projectId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_providerRepositoryId',
                type: IndexType::Key,
                attributes: ['providerRepositoryId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_providerPullRequestId',
                type: IndexType::Key,
                attributes: ['providerPullRequestId'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_providerBranch',
                type: IndexType::Key,
                attributes: ['providerBranch'],
                lengths: [Database::LENGTH_KEY],
                orders: ['ASC'],
            ),
            new Index(
                key: '_key_piid_prid_rt',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'providerRepositoryId'],
            ),
        ],
    ],

    'vcsCommentLocks' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('vcsCommentLocks'),
        'name' => 'vcsCommentLocks',
        'attributes' => [],
        'indexes' => []
    ],

    'reports' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('reports'),
        'name' => 'Reports',
        'attributes' => [
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::Id,
                required: true,
            ),
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'appInternalId',
                type: ColumnType::Id,
            ),
            new Attribute(
                key: 'appId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
            ),
            new Attribute(
                key: 'type',
                type: ColumnType::String,
                size: 64,
                required: true,
            ),
            new Attribute(
                key: 'title',
                type: ColumnType::String,
                size: 256,
                required: true,
            ),
            new Attribute(
                key: 'summary',
                type: ColumnType::Text,
                size: 65535,
                default: '',
            ),
            // Resource type the report is about. Plural noun, e.g. databases, sites, urls.
            new Attribute(
                key: 'targetType',
                type: ColumnType::String,
                size: 64,
                required: true,
            ),
            // Free-form target identifier (URL for lighthouse, resource ID for db).
            // Indexed by `_key_project_target` with an explicit prefix length.
            new Attribute(
                key: 'target',
                type: ColumnType::Text,
                size: 65535,
                required: true,
            ),
            // Category strings, e.g. 'performance', 'accessibility'. Native array
            // column — we never query on individual entries (MySQL JSON-array
            // indexes are weak), this is read+rewrite only.
            new Attribute(
                key: 'categories',
                type: ColumnType::String,
                size: 64,
                array: true,
            ),
            // Virtual attribute — insights live in the `insights` collection
            // back-referenced by `reportInternalId`. The subQuery filter joins
            // them at read time.
            new Attribute(
                key: 'insights',
                type: ColumnType::Text,
                size: 65535,
                filters: ['subQueryReportInsights'],
            ),
            new Attribute(
                key: 'analyzedAt',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_project_app_type',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'appInternalId', 'type'],
            ),
            new Index(
                key: '_key_project_target',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'appInternalId', 'targetType', 'target'],
                lengths: [null, null, null, 700],
            ),
        ],
    ],

    'insights' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('insights'),
        'name' => 'Insights',
        'attributes' => [
            new Attribute(
                key: 'projectInternalId',
                type: ColumnType::Id,
                required: true,
            ),
            new Attribute(
                key: 'projectId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'reportInternalId',
                type: ColumnType::Id,
                required: true,
            ),
            new Attribute(
                key: 'reportId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                default: '',
            ),
            new Attribute(
                key: 'type',
                type: ColumnType::String,
                size: 64,
                required: true,
            ),
            new Attribute(
                key: 'severity',
                type: ColumnType::String,
                size: 16,
                required: true,
            ),
            new Attribute(
                key: 'status',
                type: ColumnType::String,
                size: 16,
                required: true,
                default: 'active',
            ),
            new Attribute(
                key: 'resourceType',
                type: ColumnType::String,
                size: 64,
                required: true,
            ),
            new Attribute(
                key: 'resourceId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                required: true,
            ),
            new Attribute(
                key: 'resourceInternalId',
                type: ColumnType::Id,
                required: true,
            ),
            new Attribute(
                key: 'parentResourceType',
                type: ColumnType::String,
                size: 64,
                default: '',
            ),
            new Attribute(
                key: 'parentResourceId',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                default: '',
            ),
            new Attribute(
                key: 'parentResourceInternalId',
                type: ColumnType::Id,
            ),
            new Attribute(
                key: 'title',
                type: ColumnType::String,
                size: 256,
                required: true,
            ),
            new Attribute(
                key: 'summary',
                type: ColumnType::Text,
                size: 65535,
                default: '',
            ),
            new Attribute(
                key: 'ctas',
                type: ColumnType::Text,
                size: 65535,
                filters: ['json'],
            ),
            new Attribute(
                key: 'analyzedAt',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'dismissedAt',
                type: ColumnType::Datetime,
                signed: false,
                filters: ['datetime'],
            ),
            new Attribute(
                key: 'dismissedBy',
                type: ColumnType::String,
                size: Database::LENGTH_KEY,
                default: '',
            ),
        ],
        'indexes' => [
            new Index(
                key: '_key_project_report',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'reportInternalId'],
            ),
            new Index(
                key: '_key_project_resource',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'resourceType', 'resourceId'],
            ),
            new Index(
                key: '_key_project_parent_resource',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'parentResourceType', 'parentResourceId'],
            ),
            new Index(
                key: '_key_project_type',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'type'],
            ),
            new Index(
                key: '_key_project_severity',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'severity'],
            ),
            new Index(
                key: '_key_project_status',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'status'],
            ),
            new Index(
                key: '_key_project_dismissedAt',
                type: IndexType::Key,
                attributes: ['projectInternalId', 'dismissedAt'],
                orders: ['ASC', 'DESC'],
            ),
        ],
    ],

];

// Organization API keys subquery
$platformCollections['teams']['attributes'][] = new Attribute(
    key: 'keys',
    type: ColumnType::String,
    size: 16384,
    filters: ['subQueryOrganizationKeys'],
);

// Account API keys subquery
$platformCollections['users']['attributes'][] = new Attribute(
    key: 'keys',
    type: ColumnType::String,
    size: 16384,
    filters: ['subQueryAccountKeys'],
);

return $platformCollections;
