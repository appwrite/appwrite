<?php

use Utopia\Auth\Hashes\Argon2;
use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Index;
use Utopia\Query\Schema\Order;

return [
    'cache' => [
        '$collection' => Database::METADATA,
        '$id' => 'cache',
        'name' => 'Cache',
        'attributes' => [
            Attribute::string(key: 'resource'),
            Attribute::string(key: 'resourceType'),
            // https://tools.ietf.org/html/rfc4288#section-4.2
            Attribute::string(key: 'mimeType'),
            Attribute::datetime(key: 'accessedAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'signature'),
        ],
        'indexes' => [
            Index::key(key: '_key_accessedAt', attributes: ['accessedAt']),
            Index::key(key: '_key_resource', attributes: ['resource']),
        ],
    ],

    'users' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('users'),
        'name' => 'Users',
        'attributes' => [
            Attribute::string(key: 'name', size: 256),
            Attribute::string(key: 'email', size: 320),
            // leading '+' and 15 digitts maximum by E.164 format
            Attribute::string(key: 'phone', size: 16),
            Attribute::boolean(key: 'status'),
            Attribute::string(key: 'labels', size: 128, array: true),
            Attribute::string(key: 'passwordHistory', size: 16384, array: true),
            Attribute::string(key: 'password', size: 16384, filters: ['encrypt']),
            // Hashing algorithm used to hash the password
            Attribute::string(key: 'hash', size: 256, default: (new Argon2())->getName()),
            // Configuration of hashing algorithm
            Attribute::string(key: 'hashOptions', size: 65535, default: (new Argon2())->getOptions(), filters: ['json']),
            Attribute::datetime(key: 'passwordUpdate', signed: false, filters: ['datetime']),
            Attribute::string(key: 'prefs', size: 65535, default: new \stdClass(), filters: ['json']),
            Attribute::datetime(key: 'registration', signed: false, filters: ['datetime']),
            Attribute::boolean(key: 'emailVerification'),
            Attribute::boolean(key: 'phoneVerification'),
            Attribute::boolean(key: 'reset'),
            Attribute::boolean(key: 'mfa'),
            Attribute::string(
                key: 'mfaRecoveryCodes',
                size: 256,
                default: [],
                array: true,
                filters: ['encrypt'],
            ),
            Attribute::string(key: 'authenticators', size: 16384, filters: ['subQueryAuthenticators']),
            Attribute::string(key: 'sessions', size: 16384, filters: ['subQuerySessions']),
            Attribute::string(key: 'tokens', size: 16384, filters: ['subQueryTokens']),
            Attribute::string(key: 'challenges', size: 16384, filters: ['subQueryChallenges']),
            Attribute::string(key: 'memberships', size: 16384, filters: ['subQueryMemberships']),
            Attribute::string(key: 'targets', size: 16384, filters: ['subQueryTargets']),
            Attribute::string(key: 'search', size: 16384, filters: ['userSearch']),
            Attribute::datetime(key: 'accessedAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'emailCanonical', size: 320),
            Attribute::boolean(key: 'emailIsFree'),
            Attribute::boolean(key: 'emailIsDisposable'),
            Attribute::boolean(key: 'emailIsCorporate'),
            Attribute::boolean(key: 'emailIsCanonical'),
            Attribute::boolean(key: 'impersonator', default: false),
        ],
        'indexes' => [
            Index::key(key: '_key_name', attributes: ['name'], lengths: [256], orders: [Order::Asc]),
            Index::unique(key: '_key_email', attributes: ['email'], lengths: [256], orders: [Order::Asc]),
            Index::unique(key: '_key_phone', attributes: ['phone'], lengths: [16], orders: [Order::Asc]),
            Index::key(key: '_key_status', attributes: ['status'], orders: [Order::Asc]),
            Index::key(key: '_key_passwordUpdate', attributes: ['passwordUpdate'], orders: [Order::Asc]),
            Index::key(key: '_key_registration', attributes: ['registration'], orders: [Order::Asc]),
            Index::key(key: '_key_emailVerification', attributes: ['emailVerification'], orders: [Order::Asc]),
            Index::key(key: '_key_phoneVerification', attributes: ['phoneVerification'], orders: [Order::Asc]),
            Index::fullText(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_accessedAt', attributes: ['accessedAt']),
            Index::key(key: 'impersonator', attributes: [ID::custom('impersonator')]),
        ],
    ],

    'tokens' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('tokens'),
        'name' => 'Tokens',
        'attributes' => [
            Attribute::string(key: 'userInternalId', required: true),
            Attribute::string(key: 'userId'),
            Attribute::integer(key: 'type', required: true),
            // https://www.tutorialspoint.com/how-long-is-the-sha256-hash-in-mysql (512 for encryption)
            Attribute::string(key: 'secret', size: 512, filters: ['encrypt']),
            Attribute::datetime(key: 'expire', signed: false, filters: ['datetime']),
            Attribute::string(key: 'userAgent', size: 16384),
            // https://stackoverflow.com/a/166157/2299554
            Attribute::string(key: 'ip', size: 45),
        ],
        'indexes' => [
            Index::key(key: '_key_user', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_type_expire', attributes: ['type', 'expire'], orders: [Order::Asc, Order::Asc]),
        ],
    ],

    'authenticators' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('authenticators'),
        'name' => 'Authenticators',
        'attributes' => [
            Attribute::string(key: 'userInternalId'),
            Attribute::string(key: 'userId'),
            Attribute::string(key: 'type'),
            Attribute::boolean(key: 'verified', default: false),
            Attribute::string(key: 'data', size: 65535, default: [], filters: ['json', 'encrypt']),
        ],
        'indexes' => [
            Index::key(key: '_key_userInternalId', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
        ],
    ],

    'challenges' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('challenges'),
        'name' => 'Challenges',
        'attributes' => [
            Attribute::string(key: 'userInternalId'),
            Attribute::string(key: 'userId'),
            Attribute::string(key: 'type'),
            // https://www.tutorialspoint.com/how-long-is-the-sha256-hash-in-mysql (512 for encryption)
            Attribute::string(key: 'token', size: 512, filters: ['encrypt']),
            Attribute::string(key: 'code', size: 512, filters: ['encrypt']),
            Attribute::datetime(key: 'expire', signed: false, filters: ['datetime']),
        ],
        'indexes' => [
            Index::key(key: '_key_user', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_expire', attributes: ['expire'], orders: [Order::Asc]),
        ],
    ],

    'sessions' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('sessions'),
        'name' => 'Sessions',
        'attributes' => [
            Attribute::string(key: 'userInternalId', required: true),
            Attribute::string(key: 'userId'),
            Attribute::string(key: 'provider', size: 128),
            Attribute::string(key: 'providerUid', size: 2048),
            Attribute::string(key: 'providerAccessToken', size: 16384, filters: ['encrypt']),
            Attribute::datetime(key: 'providerAccessTokenExpiry', signed: false, filters: ['datetime']),
            Attribute::string(key: 'providerRefreshToken', size: 16384, filters: ['encrypt']),
            // https://www.tutorialspoint.com/how-long-is-the-sha256-hash-in-mysql (512 for encryption)
            Attribute::string(key: 'secret', size: 512, filters: ['encrypt']),
            Attribute::string(key: 'userAgent', size: 16384),
            // https://stackoverflow.com/a/166157/2299554
            Attribute::string(key: 'ip', size: 45),
            Attribute::string(key: 'countryCode', size: 2),
            Attribute::string(key: 'continentCode', size: 2),
            Attribute::float(key: 'latitude', size: 8),
            Attribute::float(key: 'longitude', size: 8),
            Attribute::string(key: 'timeZone'),
            Attribute::string(key: 'weatherCode'),
            Attribute::string(key: 'postalCode'),
            Attribute::string(key: 'autonomousSystemNumber'),
            Attribute::string(key: 'autonomousSystemOrganization'),
            Attribute::string(key: 'connectionType'),
            Attribute::string(key: 'connectionUsageType'),
            Attribute::string(key: 'connectionOrganization'),
            Attribute::string(key: 'isp'),
            Attribute::string(key: 'osCode', size: 256),
            Attribute::string(key: 'osName', size: 256),
            Attribute::string(key: 'osVersion', size: 256),
            Attribute::string(key: 'clientType', size: 256),
            Attribute::string(key: 'clientCode', size: 256),
            Attribute::string(key: 'clientName', size: 256),
            Attribute::string(key: 'clientVersion', size: 256),
            Attribute::string(key: 'clientEngine', size: 256),
            Attribute::string(key: 'clientEngineVersion', size: 256),
            Attribute::string(key: 'deviceName', size: 256),
            Attribute::string(key: 'deviceBrand', size: 256),
            Attribute::string(key: 'deviceModel', size: 256),
            Attribute::string(key: 'factors', size: 256, default: [], array: true),
            Attribute::datetime(key: 'expire', required: true, signed: false, filters: ['datetime']),
            Attribute::datetime(key: 'mfaUpdatedAt', signed: false, filters: ['datetime']),
        ],
        'indexes' => [
            Index::key(key: '_key_provider_providerUid', attributes: ['provider', 'providerUid'], lengths: [128, 128], orders: [Order::Asc, Order::Asc]),
            Index::key(key: '_key_user', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
        ],
    ],

    'identities' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('identities'),
        'name' => 'Identities',
        'attributes' => [
            Attribute::string(key: 'userInternalId'),
            Attribute::string(key: 'userId'),
            Attribute::string(key: 'provider', size: 128),
            // Decrease to 128 as in index length?
            Attribute::string(key: 'providerUid', size: 2048),
            Attribute::string(key: 'providerEmail', size: 320),
            Attribute::string(key: 'photo', size: 2048),
            Attribute::string(key: 'providerAccessToken', size: 16384, filters: ['encrypt']),
            Attribute::datetime(key: 'providerAccessTokenExpiry', signed: false, filters: ['datetime']),
            Attribute::string(key: 'providerRefreshToken', size: 16384, filters: ['encrypt']),
            // Used to store data from provider that may or may not be sensitive
            Attribute::string(key: 'secrets', size: 16384, default: [], filters: ['json', 'encrypt']),
            Attribute::string(key: 'scopes', array: true),
            Attribute::datetime(key: 'expire', signed: false, filters: ['datetime']),
        ],
        'indexes' => [
            // providerUid is length 2000!
            Index::unique(key: '_key_userInternalId_provider_providerUid', attributes: ['userInternalId', 'provider', 'providerUid'], lengths: [11, 128, 128], orders: [Order::Asc, Order::Asc]),
            // providerUid is length 2000!
            Index::unique(key: '_key_provider_providerUid', attributes: ['provider', 'providerUid'], lengths: [128, 128], orders: [Order::Asc, Order::Asc]),
            Index::key(key: '_key_userId', attributes: ['userId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_userInternalId', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_provider', attributes: ['provider'], lengths: [128], orders: [Order::Asc]),
            Index::key(key: '_key_providerUid', attributes: ['providerUid'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_providerEmail', attributes: ['providerEmail'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_providerAccessTokenExpiry', attributes: ['providerAccessTokenExpiry'], orders: [Order::Asc]),
        ],
    ],

    'teams' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('teams'),
        'name' => 'Teams',
        'attributes' => [
            Attribute::string(key: 'name', size: 128),
            Attribute::integer(key: 'total'),
            Attribute::string(key: 'search', size: 16384),
            Attribute::string(key: 'prefs', size: 65535, default: new \stdClass(), filters: ['json']),
            Attribute::string(key: 'labels', size: 128, array: true),
        ],
        'indexes' => [
            Index::fullText(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_name', attributes: ['name'], lengths: [128], orders: [Order::Asc]),
            Index::key(key: '_key_total', attributes: ['total'], orders: [Order::Asc]),
        ],
    ],

    'memberships' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('memberships'),
        'name' => 'Memberships',
        'attributes' => [
            Attribute::string(key: 'userInternalId', required: true),
            Attribute::string(key: 'userId'),
            Attribute::string(key: 'teamInternalId', required: true),
            Attribute::string(key: 'teamId'),
            Attribute::string(key: 'roles', size: 128, array: true),
            Attribute::datetime(key: 'invited', signed: false, filters: ['datetime']),
            Attribute::datetime(key: 'joined', signed: false, filters: ['datetime']),
            Attribute::boolean(key: 'confirm'),
            Attribute::string(key: 'secret', size: 256, filters: ['encrypt']),
            Attribute::string(key: 'search', size: 16384),
        ],
        'indexes' => [
            Index::unique(key: '_key_unique', attributes: ['teamInternalId', 'userInternalId'], lengths: [Database::LENGTH_KEY, Database::LENGTH_KEY], orders: [Order::Asc, Order::Asc]),
            Index::key(key: '_key_user', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_team', attributes: ['teamInternalId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::fullText(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_userId', attributes: ['userId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_teamId', attributes: ['teamId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_invited', attributes: ['invited'], orders: [Order::Asc]),
            Index::key(key: '_key_joined', attributes: ['joined'], orders: [Order::Asc]),
            Index::key(key: '_key_confirm', attributes: ['confirm'], orders: [Order::Asc]),
            Index::key(key: '_key_team_confirm', attributes: ['teamInternalId', 'confirm']),
        ],
    ],

    'buckets' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('buckets'),
        'name' => 'Buckets',
        'attributes' => [
            Attribute::boolean(key: 'enabled', required: true),
            Attribute::string(key: 'name', size: 128, required: true),
            Attribute::boolean(key: 'fileSecurity', size: 1),
            Attribute::integer(key: 'maximumFileSize', size: 8, required: true, signed: false),
            Attribute::string(key: 'allowedFileExtensions', size: 64, required: true, array: true),
            Attribute::string(key: 'compression', size: 10, required: true),
            Attribute::boolean(key: 'encryption', required: true),
            Attribute::boolean(key: 'antivirus', required: true),
            Attribute::boolean(key: 'transformations', default: true),
            Attribute::string(key: 'search', size: 16384),
        ],
        'indexes' => [
            Index::fullText(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_enabled', attributes: ['enabled'], orders: [Order::Asc]),
            Index::key(key: '_key_name', attributes: ['name'], orders: [Order::Asc]),
            Index::key(key: '_key_fileSecurity', attributes: ['fileSecurity'], orders: [Order::Asc]),
            Index::key(key: '_key_maximumFileSize', attributes: ['maximumFileSize'], orders: [Order::Asc]),
            Index::key(key: '_key_encryption', attributes: ['encryption'], orders: [Order::Asc]),
            Index::key(key: '_key_antivirus', attributes: ['antivirus'], orders: [Order::Asc]),
        ]
    ],

    'stats' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('stats'),
        'name' => 'Stats',
        'attributes' => [
            Attribute::string(key: 'metric', required: true),
            Attribute::string(key: 'region', required: true),
            Attribute::integer(key: 'value', size: 8, required: true),
            Attribute::datetime(key: 'time', signed: false, filters: ['datetime']),
            Attribute::string(key: 'period', size: 4, required: true),
        ],
        'indexes' => [
            Index::key(key: '_key_time', attributes: ['time'], orders: [Order::Desc]),
            Index::key(key: '_key_period_time', attributes: ['period', 'time'], orders: [Order::Asc]),
            Index::unique(key: '_key_metric_period_time', attributes: ['metric', 'period', 'time'], orders: [Order::Desc]),
        ],
    ],

    'providers' => [
        '$collection' => ID::custom(DATABASE::METADATA),
        '$id' => ID::custom('providers'),
        'name' => 'Providers',
        'attributes' => [
            Attribute::string(key: 'name', size: 128, required: true),
            Attribute::string(key: 'provider', required: true),
            Attribute::string(key: 'type', size: 128, required: true),
            Attribute::boolean(key: 'enabled', required: true, default: true),
            Attribute::string(key: 'credentials', size: 16384, required: true, filters: ['json', 'encrypt']),
            Attribute::string(key: 'options', size: 16384, default: [], filters: ['json']),
            Attribute::string(key: 'search', size: 65535, default: '', filters: ['providerSearch']),
        ],
        'indexes' => [
            Index::key(key: '_key_provider', attributes: ['provider'], orders: [Order::Asc]),
            Index::key(key: '_key_type', attributes: ['type'], orders: [Order::Asc]),
            Index::key(key: '_key_enabled_type', attributes: ['enabled', 'type'], orders: [Order::Asc]),
            Index::fullText(key: '_key_search', attributes: ['search']),
        ],
    ],

    'messages' => [
        '$collection' => ID::custom(DATABASE::METADATA),
        '$id' => ID::custom('messages'),
        'name' => 'Messages',
        'attributes' => [
            Attribute::string(key: 'providerType', required: true),
            Attribute::string(key: 'status', required: true, default: 'processing'),
            Attribute::string(key: 'data', size: 65535, required: true, filters: ['json']),
            Attribute::string(key: 'topics', size: 21845, default: [], array: true),
            Attribute::string(key: 'users', size: 21845, default: [], array: true),
            Attribute::string(key: 'targets', size: 21845, default: [], array: true),
            Attribute::datetime(key: 'scheduledAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'scheduleInternalId'),
            Attribute::string(key: 'scheduleId'),
            Attribute::datetime(key: 'deliveredAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'deliveryErrors', size: 65535, array: true),
            Attribute::integer(key: 'deliveredTotal', default: 0),
            Attribute::string(key: 'search', size: 16384, default: '', filters: ['messageSearch']),
        ],
        'indexes' => [
            Index::fullText(key: '_key_search', attributes: ['search']),
        ],
    ],

    'topics' => [
        '$collection' => ID::custom(DATABASE::METADATA),
        '$id' => ID::custom('topics'),
        'name' => 'Topics',
        'attributes' => [
            Attribute::string(key: 'name', size: 128, required: true),
            Attribute::string(key: 'subscribe', size: 128, array: true),
            Attribute::integer(key: 'emailTotal', default: 0),
            Attribute::integer(key: 'smsTotal', default: 0),
            Attribute::integer(key: 'pushTotal', default: 0),
            Attribute::string(key: 'targets', size: 16384, filters: ['subQueryTopicTargets']),
            Attribute::string(key: 'search', size: 16384, default: '', filters: ['topicSearch']),
        ],

        'indexes' => [
            Index::fullText(key: '_key_search', attributes: ['search'], orders: [Order::Asc]),
        ],
    ],

    'subscribers' => [
        '$collection' => ID::custom(DATABASE::METADATA),
        '$id' => ID::custom('subscribers'),
        'name' => 'Subscribers',
        'attributes' => [
            Attribute::string(key: 'targetId', required: true),
            Attribute::string(key: 'targetInternalId', required: true),
            Attribute::string(key: 'userId', required: true),
            Attribute::string(key: 'userInternalId', required: true),
            Attribute::string(key: 'topicId', required: true),
            Attribute::string(key: 'topicInternalId', required: true),
            Attribute::string(key: 'providerType', size: 128, required: true),
            Attribute::string(key: 'search', size: 16384),
        ],
        'indexes' => [
            Index::key(key: '_key_targetId', attributes: ['targetId']),
            Index::key(key: '_key_targetInternalId', attributes: ['targetInternalId']),
            Index::key(key: '_key_userId', attributes: ['userId']),
            Index::key(key: '_key_userInternalId', attributes: ['userInternalId']),
            Index::key(key: '_key_topicId', attributes: ['topicId']),
            Index::key(key: '_key_topicInternalId', attributes: ['topicInternalId']),
            Index::unique(key: '_unique_target_topic', attributes: ['targetInternalId', 'topicInternalId']),
            Index::fullText(key: '_fulltext_search', attributes: ['search']),
        ],
    ],

    'targets' => [
        '$collection' => ID::custom(DATABASE::METADATA),
        '$id' => ID::custom('targets'),
        'name' => 'Targets',
        'attributes' => [
            Attribute::string(key: 'userId', required: true),
            Attribute::string(key: 'userInternalId', required: true),
            Attribute::string(key: 'sessionId'),
            Attribute::string(key: 'sessionInternalId'),
            Attribute::string(key: 'providerType', required: true),
            Attribute::string(key: 'providerId'),
            Attribute::string(key: 'providerInternalId'),
            Attribute::string(key: 'identifier', required: true),
            Attribute::string(key: 'name'),
            Attribute::boolean(key: 'expired', default: false),
        ],
        'indexes' => [
            Index::key(key: '_key_userId', attributes: ['userId'], orders: [Order::Asc]),
            Index::key(key: '_key_userInternalId', attributes: ['userInternalId'], orders: [Order::Asc]),
            Index::key(key: '_key_providerId', attributes: ['providerId']),
            Index::key(key: '_key_providerInternalId', attributes: ['providerInternalId']),
            Index::unique(key: '_key_identifier', attributes: ['identifier']),
            Index::key(key: '_key_expired', attributes: ['expired']),
            Index::key(key: '_key_session_internal_id', attributes: ['sessionInternalId']),
        ],
    ],

    // note that this is not required for console & projects.
    'files' => [
        '$collection' => ID::custom('buckets'),
        '$id' => ID::custom('files'),
        '$name' => 'Files',
        'attributes' => [
            Attribute::string(key: 'bucketId'),
            Attribute::string(key: 'bucketInternalId', required: true),
            Attribute::string(key: 'name', size: 2048),
            Attribute::string(key: 'path', size: 2048),
            Attribute::string(key: 'folder', size: 2048, default: ''),
            Attribute::string(key: 'signature', size: 2048),
            // https://tools.ietf.org/html/rfc4288#section-4.2
            Attribute::string(key: 'mimeType'),
            // https://tools.ietf.org/html/rfc4288#section-4.2
            Attribute::string(key: 'metadata', size: 75000, filters: ['json']),
            Attribute::integer(key: 'sizeOriginal', size: 8, signed: false),
            Attribute::integer(key: 'sizeActual', size: 8, signed: false),
            Attribute::string(key: 'algorithm'),
            Attribute::string(key: 'comment', size: 2048),
            Attribute::string(key: 'openSSLVersion', size: 64),
            Attribute::string(key: 'openSSLCipher', size: 64),
            Attribute::string(key: 'openSSLTag', size: 2048),
            Attribute::string(key: 'openSSLIV', size: 2048),
            Attribute::integer(key: 'chunksTotal', signed: false),
            Attribute::integer(key: 'chunksUploaded', signed: false),
            Attribute::datetime(key: 'transformedAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'search', size: 16384),
        ],
        'indexes' => [
            Index::fullText(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_bucket', attributes: ['bucketId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_name', attributes: ['name'], lengths: [256], orders: [Order::Asc]),
            Index::key(key: '_key_folder', attributes: ['folder'], lengths: [256], orders: [Order::Asc]),
            Index::key(key: '_key_signature', attributes: ['signature'], lengths: [256], orders: [Order::Asc]),
            Index::key(key: '_key_mimeType', attributes: ['mimeType'], orders: [Order::Asc]),
            Index::key(key: '_key_sizeOriginal', attributes: ['sizeOriginal'], orders: [Order::Asc]),
            Index::key(key: '_key_chunksTotal', attributes: ['chunksTotal'], orders: [Order::Asc]),
            Index::key(key: '_key_chunksUploaded', attributes: ['chunksUploaded'], orders: [Order::Asc]),
            Index::key(key: '_key_transformedAt', attributes: ['transformedAt']),
        ]
    ],

    // Naming it presenceLogs as later it might be only be used as a presence events table only and not for the actual presence
    'presenceLogs' => [
        '$collection' => ID::custom(Database::METADATA),
        '$id' => ID::custom('presenceLogs'),
        'name' => 'Presence Logs',
        'attributes' => [
            Attribute::id(key: 'userInternalId', size: Database::LENGTH_KEY, required: true),
            Attribute::string(key: 'userId'),
            Attribute::datetime(key: 'expiresAt', signed: false, filters: ['datetime']),
            Attribute::string(key: 'status'),
            Attribute::string(key: 'source', required: true),
            Attribute::string(key: 'hostname'),
            Attribute::text(key: 'metadata', size: 65535, default: new \stdClass(), filters: ['json']),
            Attribute::string(key: 'permissionsHash', size: 32),
        ],
        'indexes' => [
            Index::unique(key: '_unique_userId', attributes: ['userId'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_userInternal', attributes: ['userInternalId'], orders: [Order::Asc]),
            Index::key(key: '_key_expiresAt', attributes: ['expiresAt'], orders: [Order::Asc]),
            Index::key(key: '_key_status', attributes: ['status'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_source', attributes: ['source'], lengths: [Database::LENGTH_KEY], orders: [Order::Asc]),
            Index::key(key: '_key_source_status', attributes: ['source', 'status']),
            Index::key(key: '_key_permissionsHash', attributes: ['permissionsHash']),
        ]
    ],
];
