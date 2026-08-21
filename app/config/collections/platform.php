<?php

use Utopia\Config\Config;
use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Index;

$providers = Config::getParam('oAuthProviders', []);

$platformCollections = [
    'projects' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('projects'),
        'name' => 'Projects',
        'attributes' => [
            Attribute::string(key: 'teamInternalId', required: true),
            Attribute::string(key: 'teamId'),
            Attribute::string(key: 'name', size: 128),
            Attribute::string(key: 'region', size: 128),
            Attribute::string(key: 'description', size: 256),
            Attribute::string(key: 'database', size: 256, required: true),
            Attribute::string(key: 'logo'),
            Attribute::string(key: 'url', size: 16384),
            Attribute::string(key: 'version', size: 16),
            Attribute::string(key: 'legalName', size: 256),
            Attribute::string(key: 'legalCountry', size: 256),
            Attribute::string(key: 'legalState', size: 256),
            Attribute::string(key: 'legalCity', size: 256),
            Attribute::string(key: 'legalAddress', size: 256),
            Attribute::string(key: 'legalTaxId', size: 256),
            Attribute::datetime(key: 'accessedAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'services', size: 16384, default: [], filters: ['json']),
            Attribute::string(key: 'apis', size: 16384, default: [], filters: ['json']),
            Attribute::string(key: 'smtp', size: 16384, default: [], filters: ['json', 'encrypt']),
            // TODO make sure size fits
            Attribute::string(key: 'templates', size: 1_000_000, default: [], filters: ['json']),
            Attribute::string(key: 'auths', size: 16384, default: [], filters: ['json']),
            Attribute::string(key: 'oAuthProviders', size: 16384, default: [], filters: ['json', 'encrypt']),
            Attribute::string(key: 'platforms', size: 16384, filters: ['subQueryPlatforms']),
            Attribute::string(key: 'webhooks', size: 16384, filters: ['subQueryWebhooks']),
            Attribute::string(key: 'keys', size: 16384, filters: ['subQueryKeys']),
            Attribute::string(key: 'devKeys', size: 16384, filters: ['subQueryDevKeys']),
            Attribute::string(key: 'search', size: 16384),
            Attribute::integer(key: 'pingCount', default: 0, signed: false),
            Attribute::datetime(key: 'pingedAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'labels', size: 128, default: [], array: true),
            Attribute::string(key: 'onboarding', size: 65536, default: [], filters: ['json']),
            Attribute::string(key: 'status', size: 100, signed: false),
        ],
        'indexes' => [
            Index::fullText(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_name', attributes: ['name'], lengths: [128], orders: ['ASC']),
            Index::key(key: '_key_team', attributes: ['teamId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_pingCount', attributes: ['pingCount']),
            Index::key(key: '_key_pingedAt', attributes: ['pingedAt']),
            Index::key(key: '_key_database', attributes: ['database']),
            Index::key(key: '_key_region_accessed_at', attributes: ['region', 'accessedAt']),
            Index::key(key: '_key_accessedAt', attributes: ['accessedAt']),
            Index::key(key: '_key_teamInternalId', attributes: ['teamInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
        ],
    ],

    'schedules' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('schedules'),
        'name' => 'schedules',
        'attributes' => [
            Attribute::string(key: 'resourceType', size: 100),
            Attribute::string(key: 'resourceInternalId'),
            Attribute::string(key: 'resourceId'),
            Attribute::datetime(key: 'resourceUpdatedAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'projectId'),
            Attribute::id(key: 'projectInternalId'),
            Attribute::string(key: 'schedule', size: 100),
            Attribute::string(key: 'data', size: 65535, default: new \stdClass(), filters: ['json', 'encrypt']),
            Attribute::boolean(key: 'active'),
            Attribute::string(key: 'region', size: 10, required: true),
        ],
        'indexes' => [
            Index::key(key: '_key_region_resourceType_resourceUpdatedAt', attributes: ['region', 'resourceType', 'resourceUpdatedAt']),
            Index::key(key: '_key_region_resourceType_projectId_resourceId', attributes: ['region', 'resourceType', 'projectId', 'resourceId']),
            Index::key(key: '_key_region_resourceType_projectInternalId_resourceId', attributes: ['region', 'resourceType', 'projectInternalId', 'resourceId']),
            Index::key(key: '_key_project_id_region', attributes: ['projectId', 'region']),
            Index::key(key: '_key_project_internal_id_region', attributes: ['projectInternalId', 'region']),
            Index::key(key: '_key_region_rt_active', attributes: ['region', 'resourceType', 'active']),
        ],
    ],

    'platforms' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('platforms'),
        'name' => 'platforms',
        'attributes' => [
            Attribute::string(key: 'projectInternalId', required: true),
            Attribute::string(key: 'projectId'),
            Attribute::string(key: 'type'),
            Attribute::string(key: 'name', size: 256, required: true),
            // For app platforms
            Attribute::string(key: 'key'),
            // Unused at the moment
            Attribute::string(key: 'store', size: 256),
            // For web platforms
            Attribute::string(key: 'hostname', size: 256),
        ],
        'indexes' => [
            Index::key(key: '_key_project', attributes: ['projectInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_project_id', attributes: ['projectId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
        ],
    ],

    'keys' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('keys'),
        'name' => 'keys',
        'attributes' => [
            Attribute::string(key: 'resourceType'),
            Attribute::string(key: 'resourceId'),
            Attribute::string(key: 'resourceInternalId'),
            Attribute::string(key: 'name', required: true),
            Attribute::string(key: 'scopes', required: true, array: true),
            // var_dump of \bin2hex(\random_bytes(128)) => string(256) doubling for encryption
            Attribute::string(key: 'secret', size: 512, required: true, filters: ['encrypt']),
            Attribute::datetime(key: 'expire', signed: false, filters: ['datetime']),
            Attribute::datetime(key: 'accessedAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'sdks', required: true, array: true),
        ],
        'indexes' => [
            Index::key(key: '_key_resource', attributes: ['resourceType', 'resourceInternalId']),
            Index::key(key: '_key_accessedAt', attributes: ['accessedAt']),
        ],
    ],

    'devKeys' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('devKeys'),
        'name' => 'Dev keys',
        'attributes' => [
            Attribute::string(key: 'projectInternalId', required: true),
            Attribute::string(key: 'projectId', required: true, default: 0),
            Attribute::string(key: 'name', required: true),
            // var_dump of \bin2hex(\random_bytes(128)) => string(256) doubling for encryption
            Attribute::string(key: 'secret', size: 512, required: true, filters: ['encrypt']),
            Attribute::datetime(key: 'expire', signed: false, filters: ['datetime']),
            Attribute::datetime(key: 'accessedAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'sdks', required: true, array: true),
        ],
        'indexes' => [
            Index::key(key: '_key_project', attributes: ['projectInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_accessedAt', attributes: ['accessedAt']),
        ],
    ],

    'webhooks' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('webhooks'),
        'name' => 'webhooks',
        'attributes' => [
            Attribute::string(key: 'projectInternalId', required: true),
            Attribute::string(key: 'projectId'),
            Attribute::string(key: 'name', required: true),
            Attribute::string(key: 'url', required: true),
            Attribute::string(key: 'httpUser'),
            // TODO will the length suffice after encryption?
            Attribute::string(key: 'httpPass', filters: ['encrypt']),
            Attribute::boolean(key: 'security', required: true),
            Attribute::string(key: 'events', required: true, array: true),
            Attribute::string(key: 'signatureKey', size: 2048),
            Attribute::boolean(key: 'enabled', default: true),
            Attribute::string(key: 'logs', size: 1000000, default: ''),
            Attribute::integer(key: 'attempts', default: 0),
        ],
        'indexes' => [
            Index::key(key: '_key_project', attributes: ['projectInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_project_id', attributes: ['projectId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
        ],
    ],

    'notifications' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('notifications'),
        'name' => 'Notifications',
        'attributes' => [
            Attribute::string(key: 'messageId'),
            Attribute::string(key: 'recipientHash', size: 64, required: true),
            Attribute::string(key: 'type', size: 100, default: 'info'),
            Attribute::string(key: 'channel', size: 64, required: true),
            Attribute::string(key: 'resourceType', size: 64, required: true),
            Attribute::string(key: 'resourceId', required: true),
            Attribute::string(key: 'projectId', required: true),
            Attribute::id(key: 'projectInternalId', required: true),
            Attribute::id(key: 'resourceInternalId', required: true),
            Attribute::string(key: 'parentResourceType', size: 64, required: true),
            Attribute::string(key: 'parentResourceId', required: true),
            Attribute::id(key: 'parentResourceInternalId', required: true),
            Attribute::string(key: 'title', size: 256, required: true),
            Attribute::string(key: 'body', size: 65535, required: true),
            Attribute::boolean(key: 'read', default: false),
            Attribute::datetime(key: 'firstSeen', signed: false, filters: ['datetime']),
            Attribute::datetime(key: 'lastSeen', signed: false, filters: ['datetime']),
        ],
        'indexes' => [
            Index::key(key: '_key_messageId', attributes: ['messageId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::unique(key: '_key_recipient', attributes: ['messageId', 'channel', 'recipientHash'], lengths: [Database::LENGTH_KEY, 64, 64], orders: ['ASC', 'ASC', 'ASC']),
            Index::key(key: '_key_project', attributes: ['projectId', 'projectInternalId'], lengths: [Database::LENGTH_KEY, 0], orders: ['ASC', 'ASC']),
            Index::key(key: '_key_project_resource', attributes: ['projectId', 'projectInternalId', 'resourceType', 'resourceId', 'resourceInternalId'], lengths: [Database::LENGTH_KEY, 0, 64, Database::LENGTH_KEY, 0], orders: ['ASC', 'ASC', 'ASC', 'ASC', 'ASC']),
            Index::key(key: '_key_project_parent_resource', attributes: ['projectId', 'projectInternalId', 'parentResourceType', 'parentResourceId', 'parentResourceInternalId'], lengths: [Database::LENGTH_KEY, 0, 64, Database::LENGTH_KEY, 0], orders: ['ASC', 'ASC', 'ASC', 'ASC', 'ASC']),
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
            Attribute::string(key: 'domain'),
            Attribute::datetime(key: 'issueDate', signed: false, filters: ['datetime']),
            Attribute::datetime(key: 'renewDate', signed: false, filters: ['datetime']),
            Attribute::integer(key: 'attempts'),
            Attribute::string(key: 'logs', size: 1000000),
            Attribute::datetime(key: 'updated', signed: false, filters: ['datetime']),
        ],
        'indexes' => [
            Index::key(key: '_key_domain', attributes: ['domain'], lengths: [255], orders: ['ASC']),
        ],
    ],

    'realtime' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('realtime'),
        'name' => 'Realtime Connections',
        'attributes' => [
            Attribute::string(key: 'container', required: true),
            Attribute::datetime(key: 'timestamp', signed: false, filters: ['datetime']),
            Attribute::string(key: 'value', size: 16384, required: true),
        ],
        'indexes' => [
            Index::key(key: '_key_timestamp', attributes: ['timestamp'], orders: ['DESC']),
        ]
    ],

    'rules' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('rules'),
        'name' => 'Rules',
        'attributes' => [
            Attribute::string(key: 'projectId', required: true),
            Attribute::string(key: 'projectInternalId', required: true),
            Attribute::string(key: 'domain', required: true),
            // 'api', 'redirect', 'deployment' (site or function)
            Attribute::string(key: 'type', size: 32),
            // 'manual', 'deployment', '' (empty)
            Attribute::string(key: 'trigger', size: 32, default: ''),
            Attribute::string(key: 'redirectUrl', size: 2048, default: ''),
            Attribute::integer(key: 'redirectStatusCode'),
            Attribute::string(key: 'deploymentResourceType', size: 32, default: ''),
            Attribute::string(key: 'deploymentId', default: ''),
            Attribute::string(key: 'deploymentInternalId', default: ''),
            Attribute::string(key: 'deploymentResourceId', default: ''),
            Attribute::string(key: 'deploymentResourceInternalId', default: ''),
            Attribute::string(key: 'deploymentVcsProviderBranch', default: ''),
            Attribute::string(key: 'status'),
            Attribute::string(key: 'certificateId'),
            Attribute::string(key: 'search', size: 16384),
            // "Appwrite" or empty string
            Attribute::string(key: 'owner', size: 16, default: ''),
            Attribute::string(key: 'region', size: 16, required: true),
            Attribute::string(key: 'logs', size: 1000000, default: ''),
        ],
        'indexes' => [
            Index::fullText(key: '_key_search', attributes: ['search']),
            Index::unique(key: '_key_domain', attributes: ['domain'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_projectInternalId', attributes: ['projectInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_projectId', attributes: ['projectId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_type', attributes: ['type'], orders: ['ASC']),
            Index::key(key: '_key_trigger', attributes: ['trigger'], orders: ['ASC']),
            Index::key(key: '_key_deploymentResourceType', attributes: ['deploymentResourceType'], orders: ['ASC']),
            Index::key(key: '_key_deploymentResourceId', attributes: ['deploymentResourceId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_deploymentResourceInternalId', attributes: ['deploymentResourceInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_deploymentId', attributes: ['deploymentId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_deploymentInternalId', attributes: ['deploymentInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_deploymentVcsProviderBranch', attributes: ['deploymentVcsProviderBranch'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_owner', attributes: ['owner'], orders: ['ASC']),
            Index::key(key: '_key_piid_diid_drt', attributes: ['projectInternalId', 'deploymentInternalId', 'deploymentResourceType']),
            Index::key(key: '_key_region_status_createdAt', attributes: ['region', 'status', '$createdAt']),
        ],
    ],

    'installations' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('installations'),
        'name' => 'installations',
        'attributes' => [
            Attribute::string(key: 'projectId', required: true),
            Attribute::string(key: 'projectInternalId', required: true),
            Attribute::string(key: 'providerInstallationId', required: true),
            Attribute::string(key: 'organization', required: true),
            Attribute::string(key: 'provider', required: true),
            Attribute::boolean(key: 'personal', default: false),
            Attribute::text(key: 'personalAccessToken', size: 65535, filters: ['encrypt']),
            Attribute::datetime(key: 'personalAccessTokenExpiry', signed: false, filters: ['datetime']),
            Attribute::text(key: 'personalRefreshToken', size: 65535, filters: ['encrypt']),
        ],
        'indexes' => [
            Index::key(key: '_key_projectInternalId', attributes: ['projectInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_projectId', attributes: ['projectId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_providerInstallationId', attributes: ['providerInstallationId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
        ],
    ],

    'repositories' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('repositories'),
        'name' => 'repositories',
        'attributes' => [
            Attribute::string(key: 'installationId', required: true),
            Attribute::string(key: 'installationInternalId'),
            Attribute::string(key: 'projectId', required: true),
            Attribute::string(key: 'projectInternalId', required: true),
            Attribute::string(key: 'providerRepositoryId', required: true),
            Attribute::string(key: 'resourceId', required: true),
            Attribute::string(key: 'resourceInternalId'),
            Attribute::string(key: 'resourceType', required: true),
            Attribute::string(key: 'providerPullRequestIds', size: 128, array: true),
        ],
        'indexes' => [
            Index::key(key: '_key_installationId', attributes: ['installationId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_installationInternalId', attributes: ['installationInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_projectInternalId', attributes: ['projectInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_projectId', attributes: ['projectId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_providerRepositoryId', attributes: ['providerRepositoryId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_resourceId', attributes: ['resourceId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_resourceInternalId', attributes: ['resourceInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_resourceType', attributes: ['resourceType'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_piid_riid_rt', attributes: ['projectInternalId', 'resourceInternalId', 'resourceType']),
        ],
    ],

    'vcsComments' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('vcsComments'),
        'name' => 'vcsComments',
        'attributes' => [
            Attribute::string(key: 'installationId', required: true),
            Attribute::string(key: 'installationInternalId'),
            Attribute::string(key: 'projectId', required: true),
            Attribute::string(key: 'projectInternalId', required: true),
            Attribute::string(key: 'providerRepositoryId', required: true),
            Attribute::string(key: 'providerCommentId', required: true),
            Attribute::string(key: 'providerPullRequestId', required: true),
            Attribute::string(key: 'providerBranch', required: true),
        ],
        'indexes' => [
            Index::key(key: '_key_installationId', attributes: ['installationId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_installationInternalId', attributes: ['installationInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_projectInternalId', attributes: ['projectInternalId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_projectId', attributes: ['projectId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_providerRepositoryId', attributes: ['providerRepositoryId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_providerPullRequestId', attributes: ['providerPullRequestId'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_providerBranch', attributes: ['providerBranch'], lengths: [Database::LENGTH_KEY], orders: ['ASC']),
            Index::key(key: '_key_piid_prid_rt', attributes: ['projectInternalId', 'providerRepositoryId']),
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
            Attribute::id(key: 'projectInternalId', required: true),
            Attribute::string(key: 'projectId', required: true),
            Attribute::id(key: 'appInternalId'),
            Attribute::string(key: 'appId'),
            Attribute::string(key: 'type', size: 64, required: true),
            Attribute::string(key: 'title', size: 256, required: true),
            Attribute::text(key: 'summary', size: 65535, default: ''),
            // Resource type the report is about. Plural noun, e.g. databases, sites, urls.
            Attribute::string(key: 'targetType', size: 64, required: true),
            // Free-form target identifier (URL for lighthouse, resource ID for db).
            // Indexed by `_key_project_target` with an explicit prefix length.
            Attribute::text(key: 'target', size: 65535, required: true),
            // Category strings, e.g. 'performance', 'accessibility'. Native array
            // column — we never query on individual entries (MySQL JSON-array
            // indexes are weak), this is read+rewrite only.
            Attribute::string(key: 'categories', size: 64, array: true),
            // Virtual attribute — insights live in the `insights` collection
            // back-referenced by `reportInternalId`. The subQuery filter joins
            // them at read time.
            Attribute::text(key: 'insights', size: 65535, filters: ['subQueryReportInsights']),
            Attribute::datetime(key: 'analyzedAt', signed: false, filters: ['datetime']),
        ],
        'indexes' => [
            Index::key(key: '_key_project_app_type', attributes: ['projectInternalId', 'appInternalId', 'type']),
            Index::key(key: '_key_project_target', attributes: ['projectInternalId', 'appInternalId', 'targetType', 'target'], lengths: [null, null, null, 700]),
        ],
    ],

    'insights' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('insights'),
        'name' => 'Insights',
        'attributes' => [
            Attribute::id(key: 'projectInternalId', required: true),
            Attribute::string(key: 'projectId', required: true),
            Attribute::id(key: 'reportInternalId', required: true),
            Attribute::string(key: 'reportId', default: ''),
            Attribute::string(key: 'type', size: 64, required: true),
            Attribute::string(key: 'severity', size: 16, required: true),
            Attribute::string(key: 'status', size: 16, required: true, default: 'active'),
            Attribute::string(key: 'resourceType', size: 64, required: true),
            Attribute::string(key: 'resourceId', required: true),
            Attribute::id(key: 'resourceInternalId', required: true),
            Attribute::string(key: 'parentResourceType', size: 64, default: ''),
            Attribute::string(key: 'parentResourceId', default: ''),
            Attribute::id(key: 'parentResourceInternalId'),
            Attribute::string(key: 'title', size: 256, required: true),
            Attribute::text(key: 'summary', size: 65535, default: ''),
            Attribute::text(key: 'ctas', size: 65535, filters: ['json']),
            Attribute::datetime(key: 'analyzedAt', signed: false, filters: ['datetime']),
            Attribute::datetime(key: 'dismissedAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'dismissedBy', default: ''),
        ],
        'indexes' => [
            Index::key(key: '_key_project_report', attributes: ['projectInternalId', 'reportInternalId']),
            Index::key(key: '_key_project_resource', attributes: ['projectInternalId', 'resourceType', 'resourceId']),
            Index::key(key: '_key_project_parent_resource', attributes: ['projectInternalId', 'parentResourceType', 'parentResourceId']),
            Index::key(key: '_key_project_type', attributes: ['projectInternalId', 'type']),
            Index::key(key: '_key_project_severity', attributes: ['projectInternalId', 'severity']),
            Index::key(key: '_key_project_status', attributes: ['projectInternalId', 'status']),
            Index::key(key: '_key_project_dismissedAt', attributes: ['projectInternalId', 'dismissedAt'], orders: ['ASC', 'DESC']),
        ],
    ],

];

// Organization API keys subquery
$platformCollections['teams']['attributes'][] = Attribute::string(key: 'keys', size: 16384, filters: ['subQueryOrganizationKeys']);

// Account API keys subquery
$platformCollections['users']['attributes'][] = Attribute::string(key: 'keys', size: 16384, filters: ['subQueryAccountKeys']);

return $platformCollections;
