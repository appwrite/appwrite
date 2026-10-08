<?php

use Utopia\Auth\Hashes\Argon2;
use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Filter;
use Utopia\Database\Id;
use Utopia\Database\Index;
use Utopia\Database\IntegerWidth;
use Utopia\Query\OrderDirection;

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
            Attribute::datetime(key: 'accessedAt'),
            Attribute::string(key: 'signature'),
        ],
        'indexes' => [
            Index::key(key: '_key_accessedAt', attributes: ['accessedAt']),
            Index::key(key: '_key_resource', attributes: ['resource']),
        ],
    ],

    'users' => [
        '$collection' => Id::custom(Database::METADATA),
        '$id' => Id::custom('users'),
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
            Attribute::string(key: 'hashOptions', size: 65535, default: (new Argon2())->getOptions(), filters: [Filter::Json]),
            Attribute::datetime(key: 'passwordUpdate'),
            Attribute::string(key: 'prefs', size: 65535, default: new \stdClass(), filters: [Filter::Json]),
            Attribute::datetime(key: 'registration'),
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
            Attribute::datetime(key: 'accessedAt'),
            Attribute::string(key: 'emailCanonical', size: 320),
            Attribute::boolean(key: 'emailIsFree'),
            Attribute::boolean(key: 'emailIsDisposable'),
            Attribute::boolean(key: 'passwordPwned'),
            Attribute::boolean(key: 'emailIsCorporate'),
            Attribute::boolean(key: 'emailIsCanonical'),
            Attribute::boolean(key: 'impersonator', default: false),
            Attribute::string(key: 'photoId'),
            Attribute::integer(key: 'photoSize', width: IntegerWidth::Bits64, default: 0),
        ],
        'indexes' => [
            Index::key(key: '_key_name', attributes: ['name'], lengths: [256], orders: [OrderDirection::Asc]),
            Index::unique(key: '_key_email', attributes: ['email'], lengths: [256], orders: [OrderDirection::Asc]),
            Index::unique(key: '_key_phone', attributes: ['phone'], lengths: [16], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_status', attributes: ['status'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_passwordUpdate', attributes: ['passwordUpdate'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_registration', attributes: ['registration'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_emailVerification', attributes: ['emailVerification'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_phoneVerification', attributes: ['phoneVerification'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_passwordPwned', attributes: ['passwordPwned'], orders: [OrderDirection::Asc]),
            Index::fulltext(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_accessedAt', attributes: ['accessedAt']),
            Index::key(key: 'impersonator', attributes: [Id::custom('impersonator')]),
        ],
    ],

    'tokens' => [
        '$collection' => Id::custom(Database::METADATA),
        '$id' => Id::custom('tokens'),
        'name' => 'Tokens',
        'attributes' => [
            Attribute::string(key: 'userInternalId', required: true),
            Attribute::string(key: 'userId'),
            Attribute::integer(key: 'type', required: true),
            // https://www.tutorialspoint.com/how-long-is-the-sha256-hash-in-mysql (512 for encryption)
            Attribute::string(key: 'secret', size: 512, filters: ['encrypt']),
            Attribute::datetime(key: 'expire'),
            Attribute::string(key: 'userAgent', size: 16384),
            // https://stackoverflow.com/a/166157/2299554
            Attribute::string(key: 'ip', size: 45),
        ],
        'indexes' => [
            Index::key(key: '_key_user', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_type_expire', attributes: ['type', 'expire'], orders: [OrderDirection::Asc, OrderDirection::Asc]),
        ],
    ],

    'authenticators' => [
        '$collection' => Id::custom(Database::METADATA),
        '$id' => Id::custom('authenticators'),
        'name' => 'Authenticators',
        'attributes' => [
            Attribute::string(key: 'userInternalId'),
            Attribute::string(key: 'userId'),
            Attribute::string(key: 'type'),
            Attribute::boolean(key: 'verified', default: false),
            Attribute::string(key: 'data', size: 65535, default: [], filters: [Filter::Json, 'encrypt']),
            Attribute::string(key: 'identifier', size: 64),
            Attribute::string(key: 'name', size: 128),
            Attribute::datetime(key: 'accessedAt'),
        ],
        'indexes' => [
            Index::key(key: '_key_userInternalId', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::unique(key: '_key_identifier', attributes: ['identifier'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_accessedAt', attributes: ['accessedAt']),
        ],
    ],

    'challenges' => [
        '$collection' => Id::custom(Database::METADATA),
        '$id' => Id::custom('challenges'),
        'name' => 'Challenges',
        'attributes' => [
            Attribute::string(key: 'userInternalId'),
            Attribute::string(key: 'userId'),
            Attribute::string(key: 'type'),
            // https://www.tutorialspoint.com/how-long-is-the-sha256-hash-in-mysql (512 for encryption)
            Attribute::string(key: 'token', size: 512, filters: ['encrypt']),
            Attribute::string(key: 'code', size: 512, filters: ['encrypt']),
            Attribute::datetime(key: 'expire'),
            // JSON is fine here: only read by ID once, then deleted, never queried
            Attribute::string(key: 'passkey', size: 16384, filters: [Filter::Json, 'encrypt']),
        ],
        'indexes' => [
            Index::key(key: '_key_user', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_expire', attributes: ['expire'], orders: [OrderDirection::Asc]),
        ],
    ],

    'sessions' => [
        '$collection' => Id::custom(Database::METADATA),
        '$id' => Id::custom('sessions'),
        'name' => 'Sessions',
        'attributes' => [
            Attribute::string(key: 'userInternalId', required: true),
            Attribute::string(key: 'userId'),
            Attribute::string(key: 'provider', size: 128),
            Attribute::string(key: 'providerUid', size: 2048),
            Attribute::string(key: 'providerAccessToken', size: 16384, filters: ['encrypt']),
            Attribute::datetime(key: 'providerAccessTokenExpiry'),
            Attribute::string(key: 'providerRefreshToken', size: 16384, filters: ['encrypt']),
            // https://www.tutorialspoint.com/how-long-is-the-sha256-hash-in-mysql (512 for encryption)
            Attribute::string(key: 'secret', size: 512, filters: ['encrypt']),
            Attribute::string(key: 'userAgent', size: 16384),
            // https://stackoverflow.com/a/166157/2299554
            Attribute::string(key: 'ip', size: 45),
            Attribute::string(key: 'countryCode', size: 2),
            Attribute::string(key: 'continentCode', size: 2),
            Attribute::double(key: 'latitude'),
            Attribute::double(key: 'longitude'),
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
            Attribute::datetime(key: 'expire', required: true),
            Attribute::datetime(key: 'mfaUpdatedAt'),
        ],
        'indexes' => [
            Index::key(key: '_key_provider_providerUid', attributes: ['provider', 'providerUid'], lengths: [128, 128], orders: [OrderDirection::Asc, OrderDirection::Asc]),
            Index::key(key: '_key_user', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
        ],
    ],

    'identities' => [
        '$collection' => Id::custom(Database::METADATA),
        '$id' => Id::custom('identities'),
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
            Attribute::datetime(key: 'providerAccessTokenExpiry'),
            Attribute::string(key: 'providerRefreshToken', size: 16384, filters: ['encrypt']),
            // Raw OIDC ID token from the last native sign-in. Kept so clients can
            // read claims the identity does not model — Google's `locale`, for
            // one — without a round trip to the provider.
            Attribute::string(key: 'providerIdToken', size: 16384, filters: ['encrypt']),
            // Used to store data from provider that may or may not be sensitive
            Attribute::string(key: 'secrets', size: 16384, default: [], filters: [Filter::Json, 'encrypt']),
            Attribute::string(key: 'scopes', array: true),
            Attribute::datetime(key: 'expire'),
        ],
        'indexes' => [
            // providerUid is length 2000!
            Index::unique(key: '_key_userInternalId_provider_providerUid', attributes: ['userInternalId', 'provider', 'providerUid'], lengths: [11, 128, 128], orders: [OrderDirection::Asc, OrderDirection::Asc]),
            // providerUid is length 2000!
            Index::unique(key: '_key_provider_providerUid', attributes: ['provider', 'providerUid'], lengths: [128, 128], orders: [OrderDirection::Asc, OrderDirection::Asc]),
            Index::key(key: '_key_userId', attributes: ['userId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_userInternalId', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_provider', attributes: ['provider'], lengths: [128], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_providerUid', attributes: ['providerUid'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_providerEmail', attributes: ['providerEmail'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_providerAccessTokenExpiry', attributes: ['providerAccessTokenExpiry'], orders: [OrderDirection::Asc]),
        ],
    ],

    'teams' => [
        '$collection' => Id::custom(Database::METADATA),
        '$id' => Id::custom('teams'),
        'name' => 'Teams',
        'attributes' => [
            Attribute::string(key: 'name', size: 128),
            Attribute::integer(key: 'total'),
            Attribute::string(key: 'search', size: 16384),
            Attribute::string(key: 'prefs', size: 65535, default: new \stdClass(), filters: [Filter::Json]),
            Attribute::string(key: 'labels', size: 128, array: true),
        ],
        'indexes' => [
            Index::fulltext(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_name', attributes: ['name'], lengths: [128], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_total', attributes: ['total'], orders: [OrderDirection::Asc]),
        ],
    ],

    'memberships' => [
        '$collection' => Id::custom(Database::METADATA),
        '$id' => Id::custom('memberships'),
        'name' => 'Memberships',
        'attributes' => [
            Attribute::string(key: 'userInternalId', required: true),
            Attribute::string(key: 'userId'),
            Attribute::string(key: 'teamInternalId', required: true),
            Attribute::string(key: 'teamId'),
            Attribute::string(key: 'roles', size: 128, array: true),
            Attribute::datetime(key: 'invited'),
            Attribute::datetime(key: 'joined'),
            Attribute::boolean(key: 'confirm'),
            Attribute::string(key: 'secret', size: 256, filters: ['encrypt']),
            Attribute::string(key: 'search', size: 16384),
        ],
        'indexes' => [
            Index::unique(key: '_key_unique', attributes: ['teamInternalId', 'userInternalId'], lengths: [Database::LENGTH_KEY, Database::LENGTH_KEY], orders: [OrderDirection::Asc, OrderDirection::Asc]),
            Index::key(key: '_key_user', attributes: ['userInternalId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_team', attributes: ['teamInternalId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::fulltext(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_userId', attributes: ['userId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_teamId', attributes: ['teamId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_invited', attributes: ['invited'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_joined', attributes: ['joined'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_confirm', attributes: ['confirm'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_team_confirm', attributes: ['teamInternalId', 'confirm']),
        ],
    ],

    'buckets' => [
        '$collection' => Id::custom(Database::METADATA),
        '$id' => Id::custom('buckets'),
        'name' => 'Buckets',
        'attributes' => [
            Attribute::boolean(key: 'enabled', required: true),
            Attribute::string(key: 'name', size: 128, required: true),
            Attribute::boolean(key: 'fileSecurity'),
            Attribute::integer(key: 'maximumFileSize', width: IntegerWidth::Bits64, required: true, signed: false),
            Attribute::string(key: 'allowedFileExtensions', size: 64, required: true, array: true),
            Attribute::string(key: 'compression', size: 10, required: true),
            Attribute::boolean(key: 'encryption', required: true),
            Attribute::boolean(key: 'antivirus', required: true),
            Attribute::boolean(key: 'transformations', default: true),
            Attribute::string(key: 'search', size: 16384),
        ],
        'indexes' => [
            Index::fulltext(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_enabled', attributes: ['enabled'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_name', attributes: ['name'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_fileSecurity', attributes: ['fileSecurity'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_maximumFileSize', attributes: ['maximumFileSize'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_encryption', attributes: ['encryption'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_antivirus', attributes: ['antivirus'], orders: [OrderDirection::Asc]),
        ]
    ],

    'providers' => [
        '$collection' => Id::custom(DATABASE::METADATA),
        '$id' => Id::custom('providers'),
        'name' => 'Providers',
        'attributes' => [
            Attribute::string(key: 'name', size: 128, required: true),
            Attribute::string(key: 'provider', required: true),
            Attribute::string(key: 'type', size: 128, required: true),
            Attribute::boolean(key: 'enabled', required: true, default: true),
            Attribute::string(key: 'credentials', size: 16384, required: true, filters: [Filter::Json, 'encrypt']),
            Attribute::string(key: 'options', size: 16384, default: [], filters: [Filter::Json]),
            Attribute::string(key: 'search', size: 65535, default: '', filters: ['providerSearch']),
        ],
        'indexes' => [
            Index::key(key: '_key_provider', attributes: ['provider'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_type', attributes: ['type'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_enabled_type', attributes: ['enabled', 'type'], orders: [OrderDirection::Asc]),
            Index::fulltext(key: '_key_search', attributes: ['search']),
        ],
    ],

    'messages' => [
        '$collection' => Id::custom(DATABASE::METADATA),
        '$id' => Id::custom('messages'),
        'name' => 'Messages',
        'attributes' => [
            Attribute::string(key: 'providerType', required: true),
            Attribute::string(key: 'status', required: true, default: 'processing'),
            Attribute::string(key: 'data', size: 65535, required: true, filters: [Filter::Json]),
            Attribute::string(key: 'topics', size: 21845, default: [], array: true),
            Attribute::string(key: 'users', size: 21845, default: [], array: true),
            Attribute::string(key: 'targets', size: 21845, default: [], array: true),
            Attribute::datetime(key: 'scheduledAt'),
            Attribute::string(key: 'scheduleInternalId'),
            Attribute::string(key: 'scheduleId'),
            Attribute::datetime(key: 'deliveredAt'),
            Attribute::string(key: 'deliveryErrors', size: 65535, array: true),
            Attribute::integer(key: 'deliveredTotal', default: 0),
            Attribute::string(key: 'search', size: 16384, default: '', filters: ['messageSearch']),
        ],
        'indexes' => [
            Index::fulltext(key: '_key_search', attributes: ['search']),
        ],
    ],

    'pushLedger' => [
        '$collection' => Id::custom(DATABASE::METADATA),
        '$id' => Id::custom('pushLedger'),
        'name' => 'MQTT Messages',
        'attributes' => [
            Attribute::string(key: 'topic', required: true),
            Attribute::string(key: 'data', size: 65535, required: true, filters: [Filter::Json]),
            Attribute::string(key: 'messageId'),
            Attribute::string(key: 'messageInternalId'),
            // The per-topic sequence, copied from the topic counter at insert time. qos and
            // expiry are topic settings now (see the topics collection), not per message.
            Attribute::integer(key: 'sequence', required: true),
        ],
        'indexes' => [
            Index::key(key: '_key_topic_sequence', attributes: ['topic', 'sequence'], orders: [OrderDirection::Asc, OrderDirection::Asc]),
            Index::key(key: '_key_messageInternalId', attributes: ['messageInternalId'], orders: [OrderDirection::Asc]),
            Index::unique(key: '_key_message_topic', attributes: ['messageId', 'topic'], orders: [OrderDirection::Asc, OrderDirection::Asc]),
        ],
    ],

    'topics' => [
        '$collection' => Id::custom(DATABASE::METADATA),
        '$id' => Id::custom('topics'),
        'name' => 'Topics',
        'attributes' => [
            Attribute::string(key: 'name', size: 128, required: true),
            Attribute::string(key: 'subscribe', size: 128, array: true),
            Attribute::integer(key: 'emailTotal', default: 0),
            Attribute::integer(key: 'smsTotal', default: 0),
            Attribute::integer(key: 'pushTotal', default: 0),
            Attribute::string(key: 'targets', size: 16384, filters: ['subQueryTopicTargets']),
            Attribute::string(key: 'search', size: 16384, default: '', filters: ['topicSearch']),
            // monotonic message counter for this topic. It is the tail sequence, so
            // a client's backlog depth is this minus the client's cursor.
            Attribute::integer(key: 'sequence', default: 0),
            // MQTT quality of service for delivery on this topic: 0 is fire-and-forget
            // (only clients connected at publish time), 1 persists each message and replays
            // it when a client reconnects unacknowledged. null lets the subscriber choose.
            Attribute::integer(key: 'qos'),
            // message retention in seconds capped at 7 days. A ledger message expires
            // this long after it is written.
            Attribute::integer(key: 'expiry', signed: false),
        ],

        'indexes' => [
            Index::fulltext(key: '_key_search', attributes: ['search']),
        ],
    ],

    'subscribers' => [
        '$collection' => Id::custom(DATABASE::METADATA),
        '$id' => Id::custom('subscribers'),
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
            Index::fulltext(key: '_fulltext_search', attributes: ['search']),
        ],
    ],

    'targets' => [
        '$collection' => Id::custom(DATABASE::METADATA),
        '$id' => Id::custom('targets'),
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
            Index::key(key: '_key_userId', attributes: ['userId'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_userInternalId', attributes: ['userInternalId'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_providerId', attributes: ['providerId']),
            Index::key(key: '_key_providerInternalId', attributes: ['providerInternalId']),
            Index::unique(key: '_key_identifier', attributes: ['identifier']),
            Index::key(key: '_key_expired', attributes: ['expired']),
            Index::key(key: '_key_session_internal_id', attributes: ['sessionInternalId']),
        ],
    ],

    // note that this is not required for console & projects.
    'files' => [
        '$collection' => Id::custom('buckets'),
        '$id' => Id::custom('files'),
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
            Attribute::string(key: 'metadata', size: 75000, filters: [Filter::Json]),
            Attribute::integer(key: 'sizeOriginal', width: IntegerWidth::Bits64, signed: false),
            Attribute::integer(key: 'sizeActual', width: IntegerWidth::Bits64, signed: false),
            Attribute::string(key: 'algorithm'),
            Attribute::string(key: 'comment', size: 2048),
            Attribute::string(key: 'openSSLVersion', size: 64),
            Attribute::string(key: 'openSSLCipher', size: 64),
            Attribute::string(key: 'openSSLTag', size: 2048),
            Attribute::string(key: 'openSSLIV', size: 2048),
            Attribute::integer(key: 'chunksTotal', signed: false),
            Attribute::integer(key: 'chunksUploaded', signed: false),
            Attribute::datetime(key: 'transformedAt'),
            Attribute::string(key: 'search', size: 16384),
        ],
        'indexes' => [
            Index::fulltext(key: '_key_search', attributes: ['search']),
            Index::key(key: '_key_bucket', attributes: ['bucketId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_name', attributes: ['name'], lengths: [256], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_folder', attributes: ['folder'], lengths: [256], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_signature', attributes: ['signature'], lengths: [256], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_mimeType', attributes: ['mimeType'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_sizeOriginal', attributes: ['sizeOriginal'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_chunksTotal', attributes: ['chunksTotal'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_chunksUploaded', attributes: ['chunksUploaded'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_transformedAt', attributes: ['transformedAt']),
        ]
    ],

    // Naming it presenceLogs as later it might be only be used as a presence events table only and not for the actual presence
    'presenceLogs' => [
        '$collection' => Id::custom(Database::METADATA),
        '$id' => Id::custom('presenceLogs'),
        'name' => 'Presence Logs',
        'attributes' => [
            Attribute::id(key: 'userInternalId', required: true),
            Attribute::string(key: 'userId'),
            Attribute::datetime(key: 'expiresAt'),
            Attribute::string(key: 'status'),
            Attribute::string(key: 'source', required: true),
            Attribute::string(key: 'hostname'),
            Attribute::text(key: 'metadata', size: 65535, default: new \stdClass(), filters: [Filter::Json]),
            Attribute::string(key: 'permissionsHash', size: 32),
        ],
        'indexes' => [
            Index::unique(key: '_unique_userId', attributes: ['userId'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_userInternal', attributes: ['userInternalId'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_expiresAt', attributes: ['expiresAt'], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_status', attributes: ['status'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_source', attributes: ['source'], lengths: [Database::LENGTH_KEY], orders: [OrderDirection::Asc]),
            Index::key(key: '_key_source_status', attributes: ['source', 'status']),
            Index::key(key: '_key_permissionsHash', attributes: ['permissionsHash']),
        ]
    ],
];
