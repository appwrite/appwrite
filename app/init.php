<?php

/**
 * Init
 *
 * Initializes both Appwrite API entry point, queue workers, and CLI tasks.
 * Set configuration, framework resources & app constants
 *
 */

if (\file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

\ini_set('memory_limit', '512M');
\ini_set('display_errors', 1);
\ini_set('display_startup_errors', 1);
\ini_set('default_socket_timeout', -1);
\error_reporting(E_ALL);

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Auth\Auth;
use Appwrite\Auth\Key;
use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Certificate;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\Migration;
use Appwrite\Event\Realtime;
use Appwrite\Event\StatsUsage;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\Functions\Specification;
use Appwrite\GraphQL\Promises\Adapter\Swoole;
use Appwrite\GraphQL\Schema;
use Appwrite\Hooks\Hooks;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\Origin;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\PubSub\Adapter\Redis as PubSub;
use Appwrite\URL\URL as AppwriteURL;
use Appwrite\Utopia\Request;
use MaxMind\Db\Reader;
use PHPMailer\PHPMailer\PHPMailer;
use Swoole\Database\PDOProxy;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\App;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\SQL;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Structure;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\DSN\DSN;
use Utopia\Locale\Locale;
use Utopia\Logger\Adapter\AppSignal;
use Utopia\Logger\Adapter\LogOwl;
use Utopia\Logger\Adapter\Raygun;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Queue;
use Utopia\Registry\Registry;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Storage;
use Utopia\System\System;
use Utopia\Validator\Hostname;
use Utopia\Validator\IP;
use Utopia\Validator\Range;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub as VcsGitHub;

const APP_NAME = 'Appwrite';
const APP_DOMAIN = 'appwrite.io';
const APP_EMAIL_TEAM = 'team@localhost.test'; // Default email address
const APP_EMAIL_SECURITY = ''; // Default security email address
const APP_USERAGENT = APP_NAME . '-Server v%s. Please report abuse at %s';
const APP_MODE_DEFAULT = 'default';
const APP_MODE_ADMIN = 'admin';
const APP_PAGING_LIMIT = 12;
const APP_LIMIT_COUNT = 5000;
const APP_LIMIT_USERS = 10_000;
const APP_LIMIT_USER_PASSWORD_HISTORY = 20;
const APP_LIMIT_USER_SESSIONS_MAX = 100;
const APP_LIMIT_USER_SESSIONS_DEFAULT = 10;
const APP_LIMIT_ANTIVIRUS = 20_000_000; //20MB
const APP_LIMIT_ENCRYPTION = 20_000_000; //20MB
const APP_LIMIT_COMPRESSION = 20_000_000; //20MB
const APP_LIMIT_ARRAY_PARAMS_SIZE = 100; // Default maximum of how many elements can there be in API parameter that expects array value
const APP_LIMIT_ARRAY_LABELS_SIZE = 1000; // Default maximum of how many labels elements can there be in API parameter that expects array value
const APP_LIMIT_ARRAY_ELEMENT_SIZE = 4096; // Default maximum length of element in array parameter represented by maximum URL length.
const APP_LIMIT_SUBQUERY = 1000;
const APP_LIMIT_SUBSCRIBERS_SUBQUERY = 1_000_000;
const APP_LIMIT_WRITE_RATE_DEFAULT = 60; // Default maximum write rate per rate period
const APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT = 60; // Default maximum write rate period in seconds
const APP_LIMIT_LIST_DEFAULT = 25; // Default maximum number of items to return in list API calls
const APP_KEY_ACCESS = 24 * 60 * 60; // 24 hours
const APP_USER_ACCESS = 24 * 60 * 60; // 24 hours
const APP_PROJECT_ACCESS = 24 * 60 * 60; // 24 hours
const APP_FILE_ACCESS = 24 * 60 * 60; // 24 hours
const APP_CACHE_UPDATE = 24 * 60 * 60; // 24 hours
const APP_CACHE_BUSTER = 4318;
const APP_VERSION_STABLE = '1.6.1';
const APP_DATABASE_ATTRIBUTE_EMAIL = 'email';
const APP_DATABASE_ATTRIBUTE_ENUM = 'enum';
const APP_DATABASE_ATTRIBUTE_IP = 'ip';
const APP_DATABASE_ATTRIBUTE_DATETIME = 'datetime';
const APP_DATABASE_ATTRIBUTE_URL = 'url';
const APP_DATABASE_ATTRIBUTE_INT_RANGE = 'intRange';
const APP_DATABASE_ATTRIBUTE_FLOAT_RANGE = 'floatRange';
const APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH = 1_073_741_824; // 2^32 bits / 4 bits per char
const APP_DATABASE_TIMEOUT_MILLISECONDS = 15_000;
const APP_DATABASE_QUERY_MAX_VALUES = 500;
const APP_STORAGE_UPLOADS = '/storage/uploads';
const APP_STORAGE_FUNCTIONS = '/storage/functions';
const APP_STORAGE_BUILDS = '/storage/builds';
const APP_STORAGE_CACHE = '/storage/cache';
const APP_STORAGE_CERTIFICATES = '/storage/certificates';
const APP_STORAGE_CONFIG = '/storage/config';
const APP_STORAGE_READ_BUFFER = 20 * (1000 * 1000); //20MB other names `APP_STORAGE_MEMORY_LIMIT`, `APP_STORAGE_MEMORY_BUFFER`, `APP_STORAGE_READ_LIMIT`, `APP_STORAGE_BUFFER_LIMIT`
const APP_SOCIAL_TWITTER = 'https://twitter.com/appwrite';
const APP_SOCIAL_TWITTER_HANDLE = 'appwrite';
const APP_SOCIAL_FACEBOOK = 'https://www.facebook.com/appwrite.io';
const APP_SOCIAL_LINKEDIN = 'https://www.linkedin.com/company/appwrite';
const APP_SOCIAL_INSTAGRAM = 'https://www.instagram.com/appwrite.io';
const APP_SOCIAL_GITHUB = 'https://github.com/appwrite';
const APP_SOCIAL_DISCORD = 'https://appwrite.io/discord';
const APP_SOCIAL_DISCORD_CHANNEL = '564160730845151244';
const APP_SOCIAL_DEV = 'https://dev.to/appwrite';
const APP_SOCIAL_STACKSHARE = 'https://stackshare.io/appwrite';
const APP_SOCIAL_YOUTUBE = 'https://www.youtube.com/c/appwrite?sub_confirmation=1';
const APP_HOSTNAME_INTERNAL = 'appwrite';
const APP_FUNCTION_SPECIFICATION_DEFAULT = Specification::S_1VCPU_512MB;
const APP_FUNCTION_CPUS_DEFAULT = 0.5;
const APP_FUNCTION_MEMORY_DEFAULT = 512;
const APP_PLATFORM_SERVER = 'server';
const APP_PLATFORM_CLIENT = 'client';
const APP_PLATFORM_CONSOLE = 'console';

// Database Reconnect
const DATABASE_RECONNECT_SLEEP = 2;
const DATABASE_RECONNECT_MAX_ATTEMPTS = 10;

// Database Worker Types
const DATABASE_TYPE_CREATE_ATTRIBUTE = 'createAttribute';
const DATABASE_TYPE_CREATE_INDEX = 'createIndex';
const DATABASE_TYPE_DELETE_ATTRIBUTE = 'deleteAttribute';
const DATABASE_TYPE_DELETE_INDEX = 'deleteIndex';
const DATABASE_TYPE_DELETE_COLLECTION = 'deleteCollection';
const DATABASE_TYPE_DELETE_DATABASE = 'deleteDatabase';

// Build Worker Types
const BUILD_TYPE_DEPLOYMENT = 'deployment';
const BUILD_TYPE_RETRY = 'retry';

// Deletion Types
const DELETE_TYPE_DATABASES = 'databases';
const DELETE_TYPE_DOCUMENT = 'document';
const DELETE_TYPE_COLLECTIONS = 'collections';
const DELETE_TYPE_PROJECTS = 'projects';
const DELETE_TYPE_FUNCTIONS = 'functions';
const DELETE_TYPE_DEPLOYMENTS = 'deployments';
const DELETE_TYPE_USERS = 'users';
const DELETE_TYPE_TEAM_PROJECTS = 'teams_projects';
const DELETE_TYPE_EXECUTIONS = 'executions';
const DELETE_TYPE_AUDIT = 'audit';
const DELETE_TYPE_ABUSE = 'abuse';
const DELETE_TYPE_USAGE = 'usage';
const DELETE_TYPE_REALTIME = 'realtime';
const DELETE_TYPE_BUCKETS = 'buckets';
const DELETE_TYPE_INSTALLATIONS = 'installations';
const DELETE_TYPE_RULES = 'rules';
const DELETE_TYPE_SESSIONS = 'sessions';
const DELETE_TYPE_CACHE_BY_TIMESTAMP = 'cacheByTimeStamp';
const DELETE_TYPE_CACHE_BY_RESOURCE  = 'cacheByResource';
const DELETE_TYPE_SCHEDULES = 'schedules';
const DELETE_TYPE_TOPIC = 'topic';
const DELETE_TYPE_TARGET = 'target';
const DELETE_TYPE_EXPIRED_TARGETS = 'invalid_targets';
const DELETE_TYPE_SESSION_TARGETS = 'session_targets';
const DELETE_TYPE_MAINTENANCE = 'maintenance';

// Message types
const MESSAGE_SEND_TYPE_INTERNAL = 'internal';
const MESSAGE_SEND_TYPE_EXTERNAL = 'external';
// Mail Types
const MAIL_TYPE_VERIFICATION = 'verification';
const MAIL_TYPE_MAGIC_SESSION = 'magicSession';
const MAIL_TYPE_RECOVERY = 'recovery';
const MAIL_TYPE_INVITATION = 'invitation';
const MAIL_TYPE_CERTIFICATE = 'certificate';
// Auth Types
const APP_AUTH_TYPE_SESSION = 'Session';
const APP_AUTH_TYPE_JWT = 'JWT';
const APP_AUTH_TYPE_KEY = 'Key';
const APP_AUTH_TYPE_ADMIN = 'Admin';
// Response related
const MAX_OUTPUT_CHUNK_SIZE = 10 * 1024 * 1024; // 10MB
// Function headers
const FUNCTION_ALLOWLIST_HEADERS_REQUEST = ['content-type', 'agent', 'content-length', 'host'];
const FUNCTION_ALLOWLIST_HEADERS_RESPONSE = ['content-type', 'content-length'];
// Message types
const MESSAGE_TYPE_EMAIL = 'email';
const MESSAGE_TYPE_SMS = 'sms';
const MESSAGE_TYPE_PUSH = 'push';
// API key types
const API_KEY_STANDARD = 'standard';
const API_KEY_DYNAMIC = 'dynamic';
// Usage metrics
const METRIC_TEAMS = 'teams';
const METRIC_USERS = 'users';
const METRIC_WEBHOOKS_SENT  = 'webhooks.events.sent';
const METRIC_WEBHOOKS_FAILED  = 'webhooks.events.failed';
const METRIC_WEBHOOK_ID_SENT = '{webhookInternalId}.webhooks.events.sent';
const METRIC_WEBHOOK_ID_FAILED = '{webhookInternalId}.webhooks.events.failed';
const METRIC_AUTH_METHOD_PHONE  = 'auth.method.phone';
const METRIC_AUTH_METHOD_PHONE_COUNTRY_CODE  = METRIC_AUTH_METHOD_PHONE . '.{countryCode}';
const METRIC_MESSAGES = 'messages';
const METRIC_MESSAGES_SENT = METRIC_MESSAGES . '.sent';
const METRIC_MESSAGES_FAILED = METRIC_MESSAGES . '.failed';
const METRIC_MESSAGES_TYPE = METRIC_MESSAGES . '.{type}';
const METRIC_MESSAGES_TYPE_SENT  = METRIC_MESSAGES . '.{type}.sent';
const METRIC_MESSAGES_TYPE_FAILED  = METRIC_MESSAGES . '.{type}.failed';
const METRIC_MESSAGES_TYPE_PROVIDER = METRIC_MESSAGES . '.{type}.{provider}';
const METRIC_MESSAGES_TYPE_PROVIDER_SENT  = METRIC_MESSAGES . '.{type}.{provider}.sent';
const METRIC_MESSAGES_TYPE_PROVIDER_FAILED  = METRIC_MESSAGES . '.{type}.{provider}.failed';
const METRIC_SESSIONS  = 'sessions';
const METRIC_DATABASES = 'databases';
const METRIC_COLLECTIONS = 'collections';
const METRIC_DATABASES_STORAGE = 'databases.storage';
const METRIC_DATABASE_ID_COLLECTIONS = '{databaseInternalId}.collections';
const METRIC_DATABASE_ID_STORAGE = '{databaseInternalId}.databases.storage';
const METRIC_DOCUMENTS = 'documents';
const METRIC_DATABASE_ID_DOCUMENTS = '{databaseInternalId}.documents';
const METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS = '{databaseInternalId}.{collectionInternalId}.documents';
const METRIC_DATABASE_ID_COLLECTION_ID_STORAGE = '{databaseInternalId}.{collectionInternalId}.databases.storage';
const METRIC_DATABASES_OPERATIONS_READS  = 'databases.operations.reads';
const METRIC_DATABASE_ID_OPERATIONS_READS = '{databaseInternalId}.databases.operations.reads';
const METRIC_DATABASES_OPERATIONS_WRITES  = 'databases.operations.writes';
const METRIC_DATABASE_ID_OPERATIONS_WRITES = '{databaseInternalId}.databases.operations.writes';
const METRIC_BUCKETS = 'buckets';
const METRIC_FILES  = 'files';
const METRIC_FILES_STORAGE  = 'files.storage';
const METRIC_FILES_TRANSFORMATIONS  = 'files.transformations';
const METRIC_BUCKET_ID_FILES_TRANSFORMATIONS  = '{bucketInternalId}.files.transformations';
const METRIC_FILES_IMAGES_TRANSFORMED = 'files.imagesTransformed';
const METRIC_BUCKET_ID_FILES_IMAGES_TRANSFORMED = '{bucketInternalId}.files.imagesTransformed';
const METRIC_BUCKET_ID_FILES = '{bucketInternalId}.files';
const METRIC_BUCKET_ID_FILES_STORAGE  = '{bucketInternalId}.files.storage';
const METRIC_FUNCTIONS  = 'functions';
const METRIC_DEPLOYMENTS  = 'deployments';
const METRIC_DEPLOYMENTS_STORAGE  = 'deployments.storage';
const METRIC_BUILDS  = 'builds';
const METRIC_BUILDS_SUCCESS  = 'builds.success';
const METRIC_BUILDS_FAILED  = 'builds.failed';
const METRIC_BUILDS_STORAGE  = 'builds.storage';
const METRIC_BUILDS_COMPUTE  = 'builds.compute';
const METRIC_BUILDS_COMPUTE_SUCCESS  = 'builds.compute.success';
const METRIC_BUILDS_COMPUTE_FAILED  = 'builds.compute.failed';
const METRIC_BUILDS_MB_SECONDS = 'builds.mbSeconds';
const METRIC_FUNCTION_ID_BUILDS  = '{functionInternalId}.builds';
const METRIC_FUNCTION_ID_BUILDS_SUCCESS  = '{functionInternalId}.builds.success';
const METRIC_FUNCTION_ID_BUILDS_FAILED  = '{functionInternalId}.builds.failed';
const METRIC_FUNCTION_ID_BUILDS_STORAGE = '{functionInternalId}.builds.storage';
const METRIC_FUNCTION_ID_BUILDS_COMPUTE  = '{functionInternalId}.builds.compute';
const METRIC_FUNCTION_ID_BUILDS_COMPUTE_SUCCESS  = '{functionInternalId}.builds.compute.success';
const METRIC_FUNCTION_ID_BUILDS_COMPUTE_FAILED  = '{functionInternalId}.builds.compute.failed';
const METRIC_FUNCTION_ID_DEPLOYMENTS  = '{resourceType}.{resourceInternalId}.deployments';
const METRIC_FUNCTION_ID_DEPLOYMENTS_STORAGE  = '{resourceType}.{resourceInternalId}.deployments.storage';
const METRIC_FUNCTION_ID_BUILDS_MB_SECONDS = '{functionInternalId}.builds.mbSeconds';
const METRIC_EXECUTIONS  = 'executions';
const METRIC_EXECUTIONS_COMPUTE  = 'executions.compute';
const METRIC_EXECUTIONS_MB_SECONDS = 'executions.mbSeconds';
const METRIC_FUNCTION_ID_EXECUTIONS  = '{functionInternalId}.executions';
const METRIC_FUNCTION_ID_EXECUTIONS_COMPUTE  = '{functionInternalId}.executions.compute';
const METRIC_FUNCTION_ID_EXECUTIONS_MB_SECONDS = '{functionInternalId}.executions.mbSeconds';
const METRIC_NETWORK_REQUESTS  = 'network.requests';
const METRIC_NETWORK_INBOUND  = 'network.inbound';
const METRIC_NETWORK_OUTBOUND  = 'network.outbound';
const METRIC_MAU = 'users.mau';
const METRIC_DAU = 'users.dau';
const METRIC_WAU = 'users.wau';
const METRIC_WEBHOOKS = 'webhooks';
const METRIC_PLATFORMS = 'platforms';
const METRIC_PROVIDERS = 'providers';
const METRIC_TOPICS = 'topics';
const METRIC_TARGETS = 'targets';
const METRIC_PROVIDER_TYPE_TARGETS = '{providerType}.targets';
const METRIC_KEYS = 'keys';
const METRIC_RESOURCE_TYPE_ID_BUILDS  = '{resourceType}.{resourceInternalId}.builds';
const METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE = '{resourceType}.{resourceInternalId}.builds.storage';
const METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS  = '{resourceType}.{resourceInternalId}.deployments';
const METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE  = '{resourceType}.{resourceInternalId}.deployments.storage';

// Resource types

const RESOURCE_TYPE_PROJECTS = 'projects';
const RESOURCE_TYPE_FUNCTIONS = 'functions';
const RESOURCE_TYPE_DATABASES = 'databases';
const RESOURCE_TYPE_BUCKETS = 'buckets';
const RESOURCE_TYPE_PROVIDERS = 'providers';
const RESOURCE_TYPE_TOPICS = 'topics';
const RESOURCE_TYPE_SUBSCRIBERS = 'subscribers';
const RESOURCE_TYPE_MESSAGES = 'messages';

$register = new Registry();

App::setMode(System::getEnv('_APP_ENV', App::MODE_TYPE_PRODUCTION));

if (!App::isProduction()) {
    // Allow specific domains to skip public domain validation in dev environment
    // Useful for existing tests involving webhooks
    PublicDomain::allow(['request-catcher']);
}

/*
 * ENV vars
 */
Config::load('events', __DIR__ . '/config/events.php');
Config::load('auth', __DIR__ . '/config/auth.php');
Config::load('apis', __DIR__ . '/config/apis.php');  // List of APIs
Config::load('errors', __DIR__ . '/config/errors.php');
Config::load('oAuthProviders', __DIR__ . '/config/oAuthProviders.php');
Config::load('platforms', __DIR__ . '/config/platforms.php');
Config::load('console', __DIR__ . '/config/console.php');
Config::load('collections', __DIR__ . '/config/collections.php');
Config::load('runtimes', __DIR__ . '/config/runtimes.php');
Config::load('runtimes-v2', __DIR__ . '/config/runtimes-v2.php');
Config::load('usage', __DIR__ . '/config/usage.php');
Config::load('roles', __DIR__ . '/config/roles.php');  // User roles and scopes
Config::load('scopes', __DIR__ . '/config/scopes.php');  // User roles and scopes
Config::load('services', __DIR__ . '/config/services.php');  // List of services
Config::load('variables', __DIR__ . '/config/variables.php');  // List of env variables
Config::load('regions', __DIR__ . '/config/regions.php'); // List of available regions
Config::load('avatar-browsers', __DIR__ . '/config/avatars/browsers.php');
Config::load('avatar-credit-cards', __DIR__ . '/config/avatars/credit-cards.php');
Config::load('avatar-flags', __DIR__ . '/config/avatars/flags.php');
Config::load('locale-codes', __DIR__ . '/config/locale/codes.php');
Config::load('locale-currencies', __DIR__ . '/config/locale/currencies.php');
Config::load('locale-eu', __DIR__ . '/config/locale/eu.php');
Config::load('locale-languages', __DIR__ . '/config/locale/languages.php');
Config::load('locale-phones', __DIR__ . '/config/locale/phones.php');
Config::load('locale-countries', __DIR__ . '/config/locale/countries.php');
Config::load('locale-continents', __DIR__ . '/config/locale/continents.php');
Config::load('locale-templates', __DIR__ . '/config/locale/templates.php');
Config::load('storage-logos', __DIR__ . '/config/storage/logos.php');
Config::load('storage-mimes', __DIR__ . '/config/storage/mimes.php');
Config::load('storage-inputs', __DIR__ . '/config/storage/inputs.php');
Config::load('storage-outputs', __DIR__ . '/config/storage/outputs.php');
Config::load('runtime-specifications', __DIR__ . '/config/runtimes/specifications.php');
Config::load('function-templates', __DIR__ . '/config/function-templates.php');

/**
 * New DB Filters
 */
Database::addFilter(
    'casting',
    function (mixed $value) {
        return json_encode(['value' => $value], JSON_PRESERVE_ZERO_FRACTION);
    },
    function (mixed $value) {
        if (is_null($value)) {
            return;
        }

        return json_decode($value, true)['value'];
    }
);

Database::addFilter(
    'enum',
    function (mixed $value, Document $attribute) {
        if ($attribute->isSet('elements')) {
            $attribute->removeAttribute('elements');
        }

        return $value;
    },
    function (mixed $value, Document $attribute) {
        $formatOptions = \json_decode($attribute->getAttribute('formatOptions', '[]'), true);
        if (isset($formatOptions['elements'])) {
            $attribute->setAttribute('elements', $formatOptions['elements']);
        }

        return $value;
    }
);

Database::addFilter(
    'range',
    function (mixed $value, Document $attribute) {
        if ($attribute->isSet('min')) {
            $attribute->removeAttribute('min');
        }
        if ($attribute->isSet('max')) {
            $attribute->removeAttribute('max');
        }

        return $value;
    },
    function (mixed $value, Document $attribute) {
        $formatOptions = json_decode($attribute->getAttribute('formatOptions', '[]'), true);
        if (isset($formatOptions['min']) || isset($formatOptions['max'])) {
            $attribute
                ->setAttribute('min', $formatOptions['min'])
                ->setAttribute('max', $formatOptions['max']);
        }

        return $value;
    }
);

Database::addFilter(
    'subQueryAttributes',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        $attributes = $database->find('attributes', [
            Query::equal('collectionInternalId', [$document->getInternalId()]),
            Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
            Query::limit($database->getLimitForAttributes()),
        ]);

        foreach ($attributes as $attribute) {
            if ($attribute->getAttribute('type') === Database::VAR_RELATIONSHIP) {
                $options = $attribute->getAttribute('options');
                foreach ($options as $key => $value) {
                    $attribute->setAttribute($key, $value);
                }
                $attribute->removeAttribute('options');
            }
        }

        return $attributes;
    }
);

Database::addFilter(
    'subQueryIndexes',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('indexes', [
                Query::equal('collectionInternalId', [$document->getInternalId()]),
                Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                Query::limit($database->getLimitForIndexes()),
            ]);
    }
);

Database::addFilter(
    'subQueryPlatforms',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('platforms', [
                Query::equal('projectInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
    }
);

Database::addFilter(
    'subQueryKeys',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('keys', [
                Query::equal('projectInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
    }
);

Database::addFilter(
    'subQueryWebhooks',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('webhooks', [
                Query::equal('projectInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
    }
);

Database::addFilter(
    'subQuerySessions',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn () => $database->find('sessions', [
            Query::equal('userInternalId', [$document->getInternalId()]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));
    }
);

Database::addFilter(
    'subQueryTokens',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn () => $database
            ->find('tokens', [
                Query::equal('userInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryChallenges',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn () => $database
            ->find('challenges', [
                Query::equal('userInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryAuthenticators',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn () => $database
            ->find('authenticators', [
                Query::equal('userInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryMemberships',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn () => $database
            ->find('memberships', [
                Query::equal('userInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryVariables',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('variables', [
                Query::equal('resourceInternalId', [$document->getInternalId()]),
                Query::equal('resourceType', ['function']),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
    }
);

Database::addFilter(
    'encrypt',
    function (mixed $value) {
        $key = System::getEnv('_APP_OPENSSL_KEY_V1');
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $tag = null;

        return json_encode([
            'data' => OpenSSL::encrypt($value, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag),
            'method' => OpenSSL::CIPHER_AES_128_GCM,
            'iv' => \bin2hex($iv),
            'tag' => \bin2hex($tag ?? ''),
            'version' => '1',
        ]);
    },
    function (mixed $value) {
        if (is_null($value)) {
            return;
        }
        $value = json_decode($value, true);
        $key = System::getEnv('_APP_OPENSSL_KEY_V' . $value['version']);

        return OpenSSL::decrypt($value['data'], $value['method'], $key, 0, hex2bin($value['iv']), hex2bin($value['tag']));
    }
);

Database::addFilter(
    'subQueryProjectVariables',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('variables', [
                Query::equal('resourceType', ['project']),
                Query::limit(APP_LIMIT_SUBQUERY)
            ]);
    }
);

Database::addFilter(
    'userSearch',
    function (mixed $value, Document $user) {
        $searchValues = [
            $user->getId(),
            $user->getAttribute('email', ''),
            $user->getAttribute('name', ''),
            $user->getAttribute('phone', '')
        ];

        foreach ($user->getAttribute('labels', []) as $label) {
            $searchValues[] = 'label:' . $label;
        }

        $search = implode(' ', \array_filter($searchValues));

        return $search;
    },
    function (mixed $value) {
        return $value;
    }
);

Database::addFilter(
    'subQueryTargets',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn () => $database
            ->find('targets', [
                Query::equal('userInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY)
            ]));
    }
);

Database::addFilter(
    'subQueryTopicTargets',
    function (mixed $value) {
        return;
    },
    function (mixed $value, Document $document, Database $database) {
        $targetIds = Authorization::skip(fn () => \array_map(
            fn ($document) => $document->getAttribute('targetInternalId'),
            $database->find('subscribers', [
                Query::equal('topicInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBSCRIBERS_SUBQUERY)
            ])
        ));
        if (\count($targetIds) > 0) {
            return $database->skipValidation(fn () => $database->find('targets', [
                Query::equal('$internalId', $targetIds)
            ]));
        }
        return [];
    }
);

Database::addFilter(
    'providerSearch',
    function (mixed $value, Document $provider) {
        $searchValues = [
            $provider->getId(),
            $provider->getAttribute('name', ''),
            $provider->getAttribute('provider', ''),
            $provider->getAttribute('type', '')
        ];

        $search = \implode(' ', \array_filter($searchValues));

        return $search;
    },
    function (mixed $value) {
        return $value;
    }
);

Database::addFilter(
    'topicSearch',
    function (mixed $value, Document $topic) {
        $searchValues = [
            $topic->getId(),
            $topic->getAttribute('name', ''),
            $topic->getAttribute('description', ''),
        ];

        $search = \implode(' ', \array_filter($searchValues));

        return $search;
    },
    function (mixed $value) {
        return $value;
    }
);

Database::addFilter(
    'messageSearch',
    function (mixed $value, Document $message) {
        $searchValues = [
            $message->getId(),
            $message->getAttribute('description', ''),
            $message->getAttribute('status', ''),
        ];

        $data = \json_decode($message->getAttribute('data', []), true);
        $providerType = $message->getAttribute('providerType', '');

        switch ($providerType) {
            case MESSAGE_TYPE_EMAIL:
                $searchValues[] = $data['subject'];
                $searchValues[] = MESSAGE_TYPE_EMAIL;
                break;
            case MESSAGE_TYPE_SMS:
                $searchValues[] = $data['content'];
                $searchValues[] = MESSAGE_TYPE_SMS;
                break;
            case MESSAGE_TYPE_PUSH:
                $searchValues[] = $data['title'] ?? '';
                $searchValues[] = MESSAGE_TYPE_PUSH;
                break;
        }

        $search = \implode(' ', \array_filter($searchValues));

        return $search;
    },
    function (mixed $value) {
        return $value;
    }
);

/**
 * DB Formats
 */
Structure::addFormat(APP_DATABASE_ATTRIBUTE_EMAIL, function () {
    return new Email();
}, Database::VAR_STRING);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_DATETIME, function () {
    return new DatetimeValidator();
}, Database::VAR_DATETIME);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_ENUM, function ($attribute) {
    $elements = $attribute['formatOptions']['elements'] ?? [];
    return new WhiteList($elements, true);
}, Database::VAR_STRING);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_IP, function () {
    return new IP();
}, Database::VAR_STRING);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_URL, function () {
    return new URL();
}, Database::VAR_STRING);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_INT_RANGE, function ($attribute) {
    $min = $attribute['formatOptions']['min'] ?? -INF;
    $max = $attribute['formatOptions']['max'] ?? INF;
    return new Range($min, $max, Range::TYPE_INTEGER);
}, Database::VAR_INTEGER);

Structure::addFormat(APP_DATABASE_ATTRIBUTE_FLOAT_RANGE, function ($attribute) {
    $min = $attribute['formatOptions']['min'] ?? -INF;
    $max = $attribute['formatOptions']['max'] ?? INF;
    return new Range($min, $max, Range::TYPE_FLOAT);
}, Database::VAR_FLOAT);

/*
 * Registry
 */
$register->set('logger', function () {
    // Register error logger
    $providerName = System::getEnv('_APP_LOGGING_PROVIDER', '');
    $providerConfig = System::getEnv('_APP_LOGGING_CONFIG', '');

    if (empty($providerConfig)) {
        return;
    }

    try {
        $loggingProvider = new DSN($providerConfig ?? '');

        $providerName = $loggingProvider->getScheme();
        $providerConfig = match ($providerName) {
            'sentry' => ['key' => $loggingProvider->getPassword(), 'projectId' => $loggingProvider->getUser() ?? '', 'host' => 'https://' . $loggingProvider->getHost()],
            'logowl' => ['ticket' => $loggingProvider->getUser() ?? '', 'host' => $loggingProvider->getHost()],
            default => ['key' => $loggingProvider->getHost()],
        };
    } catch (Throwable $th) {
        // Fallback for older Appwrite versions up to 1.5.x that use _APP_LOGGING_PROVIDER and _APP_LOGGING_CONFIG environment variables
        Console::warning('Using deprecated logging configuration. Please update your configuration to use DSN format.' . $th->getMessage());
        $configChunks = \explode(";", $providerConfig);

        $providerConfig = match ($providerName) {
            'sentry' => [ 'key' => $configChunks[0], 'projectId' => $configChunks[1] ?? '', 'host' => '',],
            'logowl' => ['ticket' => $configChunks[0] ?? '', 'host' => ''],
            default => ['key' => $providerConfig],
        };
    }

    if (empty($providerName) || empty($providerConfig)) {
        return;
    }

    if (!Logger::hasProvider($providerName)) {
        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Logging provider not supported. Logging is disabled");
    }

    try {
        $adapter = match ($providerName) {
            'sentry' => new Sentry($providerConfig['projectId'], $providerConfig['key'], $providerConfig['host']),
            'logowl' => new LogOwl($providerConfig['ticket'], $providerConfig['host']),
            'raygun' => new Raygun($providerConfig['key']),
            'appsignal' => new AppSignal($providerConfig['key']),
            default => null
        };
    } catch (Throwable $th) {
        $adapter = null;
    }

    if ($adapter === null) {
        Console::error("Logging provider not supported. Logging is disabled");
        return;
    }

    return new Logger($adapter);
});

$register->set('pools', function () {
    $group = new Group();

    $fallbackForDB = 'db_main=' . AppwriteURL::unparse([
        'scheme' => 'mariadb',
        'host' => System::getEnv('_APP_DB_HOST', 'mariadb'),
        'port' => System::getEnv('_APP_DB_PORT', '3306'),
        'user' => System::getEnv('_APP_DB_USER', ''),
        'pass' => System::getEnv('_APP_DB_PASS', ''),
        'path' => System::getEnv('_APP_DB_SCHEMA', ''),
    ]);
    $fallbackForRedis = 'redis_main=' . AppwriteURL::unparse([
        'scheme' => 'redis',
        'host' => System::getEnv('_APP_REDIS_HOST', 'redis'),
        'port' => System::getEnv('_APP_REDIS_PORT', '6379'),
        'user' => System::getEnv('_APP_REDIS_USER', ''),
        'pass' => System::getEnv('_APP_REDIS_PASS', ''),
    ]);

    $connections = [
        'console' => [
            'type' => 'database',
            'dsns' => $fallbackForDB,
            'multiple' => false,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'database' => [
            'type' => 'database',
            'dsns' => $fallbackForDB,
            'multiple' => true,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'logs' => [
            'type' => 'database',
            'dsns' => System::getEnv('_APP_CONNECTIONS_DB_LOGS', $fallbackForDB),
            'multiple' => false,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'publisher' => [
            'type' => 'publisher',
            'dsns' => $fallbackForRedis,
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'consumer' => [
            'type' => 'consumer',
            'dsns' => $fallbackForRedis,
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'pubsub' => [
            'type' => 'pubsub',
            'dsns' => $fallbackForRedis,
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'cache' => [
            'type' => 'cache',
            'dsns' => $fallbackForRedis,
            'multiple' => true,
            'schemes' => ['redis'],
        ],
    ];

    $maxConnections = System::getEnv('_APP_CONNECTIONS_MAX', 151);
    $instanceConnections = $maxConnections / System::getEnv('_APP_POOL_CLIENTS', 14);

    $multiprocessing = System::getEnv('_APP_SERVER_MULTIPROCESS', 'disabled') === 'enabled';

    if ($multiprocessing) {
        $workerCount = intval(System::getEnv('_APP_CPU_NUM', swoole_cpu_num())) * intval(System::getEnv('_APP_WORKER_PER_CORE', 6));
    } else {
        $workerCount = 1;
    }

    if ($workerCount > $instanceConnections) {
        throw new \Exception('Pool size is too small. Increase the number of allowed database connections or decrease the number of workers.', 500);
    }

    $poolSize = (int)($instanceConnections / $workerCount);

    foreach ($connections as $key => $connection) {
        $type = $connection['type'] ?? '';
        $multiple = $connection['multiple'] ?? false;
        $schemes = $connection['schemes'] ?? [];
        $config = [];
        $dsns = explode(',', $connection['dsns'] ?? '');
        foreach ($dsns as &$dsn) {
            $dsn = explode('=', $dsn);
            $name = ($multiple) ? $key . '_' . $dsn[0] : $key;
            $dsn = $dsn[1] ?? '';
            $config[] = $name;
            if (empty($dsn)) {
                //throw new Exception(Exception::GENERAL_SERVER_ERROR, "Missing value for DSN connection in {$key}");
                continue;
            }

            $dsn = new DSN($dsn);
            $dsnHost = $dsn->getHost();
            $dsnPort = $dsn->getPort();
            $dsnUser = $dsn->getUser();
            $dsnPass = $dsn->getPassword();
            $dsnScheme = $dsn->getScheme();
            $dsnDatabase = $dsn->getPath();

            if (!in_array($dsnScheme, $schemes)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, "Invalid console database scheme");
            }

            /**
             * Get Resource
             *
             * Creation could be reused across connection types like database, cache, queue, etc.
             *
             * Resource assignment to an adapter will happen below.
             */
            $resource = match ($dsnScheme) {
                'mysql',
                'mariadb' => function () use ($dsnHost, $dsnPort, $dsnUser, $dsnPass, $dsnDatabase) {
                    return new PDOProxy(function () use ($dsnHost, $dsnPort, $dsnUser, $dsnPass, $dsnDatabase) {
                        return new PDO("mysql:host={$dsnHost};port={$dsnPort};dbname={$dsnDatabase};charset=utf8mb4", $dsnUser, $dsnPass, array(
                            PDO::ATTR_TIMEOUT => 3, // Seconds
                            PDO::ATTR_PERSISTENT => true,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => true,
                            PDO::ATTR_STRINGIFY_FETCHES => true
                        ));
                    });
                },
                'redis' => function () use ($dsnHost, $dsnPort, $dsnPass) {
                    $redis = new \Redis();
                    @$redis->pconnect($dsnHost, (int)$dsnPort);
                    if ($dsnPass) {
                        $redis->auth($dsnPass);
                    }
                    $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

                    return $redis;
                },
                default => throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Invalid scheme'),
            };

            $pool = new Pool($name, $poolSize, function () use ($type, $resource, $dsn) {
                // Get Adapter
                switch ($type) {
                    case 'database':
                        $adapter = match ($dsn->getScheme()) {
                            'mariadb' => new MariaDB($resource()),
                            'mysql' => new MySQL($resource()),
                            default => null
                        };

                        $adapter->setDatabase($dsn->getPath());
                        return $adapter;
                    case 'pubsub':
                        return match ($dsn->getScheme()) {
                            'redis' => new PubSub($resource()),
                            default => null
                        };
                    case 'publisher':
                    case 'consumer':
                        return match ($dsn->getScheme()) {
                            'redis' => new Queue\Broker\Redis(new Queue\Connection\Redis($dsn->getHost(), $dsn->getPort())),
                            default => null
                        };
                    case 'cache':
                        return match ($dsn->getScheme()) {
                            'redis' => new RedisCache($resource()),
                            default => null
                        };
                    default:
                        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Server error: Missing adapter implementation.");
                }
            });

            $group->add($pool);
        }

        Config::setParam('pools-' . $key, $config);
    }

    return $group;
});

$register->set('db', function () {
    // This is usually for our workers or CLI commands scope
    $dbHost = System::getEnv('_APP_DB_HOST', '');
    $dbPort = System::getEnv('_APP_DB_PORT', '');
    $dbUser = System::getEnv('_APP_DB_USER', '');
    $dbPass = System::getEnv('_APP_DB_PASS', '');
    $dbScheme = System::getEnv('_APP_DB_SCHEMA', '');

    return new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbScheme};charset=utf8mb4",
        $dbUser,
        $dbPass,
        SQL::getPDOAttributes()
    );
});

$register->set('smtp', function () {
    $mail = new PHPMailer(true);

    $mail->isSMTP();

    $username = System::getEnv('_APP_SMTP_USERNAME');
    $password = System::getEnv('_APP_SMTP_PASSWORD');

    $mail->XMailer = 'Appwrite Mailer';
    $mail->Host = System::getEnv('_APP_SMTP_HOST', 'smtp');
    $mail->Port = System::getEnv('_APP_SMTP_PORT', 25);
    $mail->SMTPAuth = !empty($username) && !empty($password);
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = System::getEnv('_APP_SMTP_SECURE', '');
    $mail->SMTPAutoTLS = false;
    $mail->CharSet = 'UTF-8';

    $from = \urldecode(System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'));
    $email = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);

    $mail->setFrom($email, $from);
    $mail->addReplyTo($email, $from);

    $mail->isHTML(true);

    return $mail;
});
$register->set('geodb', function () {
    return new Reader(__DIR__ . '/assets/dbip/dbip-country-lite-2024-09.mmdb');
});
$register->set('passwordsDictionary', function () {
    $content = \file_get_contents(__DIR__ . '/assets/security/10k-common-passwords');
    $content = explode("\n", $content);
    $content = array_flip($content);
    return $content;
});
$register->set('promiseAdapter', function () {
    return new Swoole();
});
$register->set('hooks', function () {
    return new Hooks();
});
/*
 * Localization
 */
Locale::$exceptions = false;

$locales = Config::getParam('locale-codes', []);

foreach ($locales as $locale) {
    $code = $locale['code'];

    $path = __DIR__ . '/config/locale/translations/' . $code . '.json';

    if (!\file_exists($path)) {
        $path = __DIR__ . '/config/locale/translations/' . \substr($code, 0, 2) . '.json'; // if `ar-ae` doesn't exist, look for `ar`
        if (!\file_exists($path)) {
            $path = __DIR__ . '/config/locale/translations/en.json'; // if none translation exists, use default from `en.json`
        }
    }

    Locale::setLanguageFromJSON($code, $path);
}

\stream_context_set_default([ // Set global user agent and http settings
    'http' => [
        'method' => 'GET',
        'user_agent' => \sprintf(
            APP_USERAGENT,
            System::getEnv('_APP_VERSION', 'UNKNOWN'),
            System::getEnv('_APP_EMAIL_SECURITY', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY))
        ),
        'timeout' => 2,
    ],
]);

// Runtime Execution
App::setResource('log', fn () => new Log());
App::setResource('logger', function ($register) {
    return $register->get('logger');
}, ['register']);

App::setResource('hooks', function ($register) {
    return $register->get('hooks');
}, ['register']);

App::setResource('register', fn () => $register);
App::setResource('locale', fn () => new Locale(System::getEnv('_APP_LOCALE', 'en')));

App::setResource('localeCodes', function () {
    return array_map(fn ($locale) => $locale['code'], Config::getParam('locale-codes', []));
});

// Queues
App::setResource('publisher', function (Group $pools) {
    return $pools->get('publisher')->pop()->getResource();
}, ['pools']);
App::setResource('consumer', function (Group $pools) {
    return $pools->get('consumer')->pop()->getResource();
}, ['pools']);
App::setResource('queueForMessaging', function (Queue\Publisher $publisher) {
    return new Messaging($publisher);
}, ['publisher']);
App::setResource('queueForMails', function (Queue\Publisher $publisher) {
    return new Mail($publisher);
}, ['publisher']);
App::setResource('queueForBuilds', function (Queue\Publisher $publisher) {
    return new Build($publisher);
}, ['publisher']);
App::setResource('queueForDatabase', function (Queue\Publisher $publisher) {
    return new EventDatabase($publisher);
}, ['publisher']);
App::setResource('queueForDeletes', function (Queue\Publisher $publisher) {
    return new Delete($publisher);
}, ['publisher']);
App::setResource('queueForEvents', function (Queue\Publisher $publisher) {
    return new Event($publisher);
}, ['publisher']);
App::setResource('queueForWebhooks', function (Queue\Publisher $publisher) {
    return new Webhook($publisher);
}, ['publisher']);
App::setResource('queueForRealtime', function () {
    return new Realtime();
}, []);
App::setResource('queueForStatsUsage', function (Queue\Publisher $publisher) {
    return new StatsUsage($publisher);
}, ['publisher']);
App::setResource('queueForAudits', function (Queue\Publisher $publisher) {
    return new Audit($publisher);
}, ['publisher']);
App::setResource('queueForFunctions', function (Queue\Publisher $publisher) {
    return new Func($publisher);
}, ['publisher']);
App::setResource('queueForCertificates', function (Queue\Publisher $publisher) {
    return new Certificate($publisher);
}, ['publisher']);
App::setResource('queueForMigrations', function (Queue\Publisher $publisher) {
    return new Migration($publisher);
}, ['publisher']);
App::setResource('clients', function ($request, $console, $project) {
    $console->setAttribute('platforms', [ // Always allow current host
        '$collection' => ID::custom('platforms'),
        'name' => 'Current Host',
        'type' => Origin::CLIENT_TYPE_WEB,
        'hostname' => $request->getHostname(),
    ], Document::SET_TYPE_APPEND);

    $hostnames = explode(',', System::getEnv('_APP_CONSOLE_HOSTNAMES', ''));
    $validator = new Hostname();
    foreach ($hostnames as $hostname) {
        $hostname = trim($hostname);
        if (!$validator->isValid($hostname)) {
            continue;
        }
        $console->setAttribute('platforms', [
            '$collection' => ID::custom('platforms'),
            'type' => Origin::CLIENT_TYPE_WEB,
            'name' => $hostname,
            'hostname' => $hostname,
        ], Document::SET_TYPE_APPEND);
    }

    /**
     * Get All verified client URLs for both console and current projects
     * + Filter for duplicated entries
     */
    $clientsConsole = \array_map(
        fn ($node) => $node['hostname'],
        \array_filter(
            $console->getAttribute('platforms', []),
            fn ($node) => (isset($node['type']) && ($node['type'] === Origin::CLIENT_TYPE_WEB) && !empty($node['hostname']))
        )
    );

    $clients = $clientsConsole;
    $platforms = $project->getAttribute('platforms', []);

    foreach ($platforms as $node) {
        if (
            isset($node['type']) &&
            ($node['type'] === Origin::CLIENT_TYPE_WEB ||
            $node['type'] === Origin::CLIENT_TYPE_FLUTTER_WEB) &&
            !empty($node['hostname'])
        ) {
            $clients[] = $node['hostname'];
        }
    }

    return \array_unique($clients);
}, ['request', 'console', 'project']);

App::setResource('user', function ($mode, $project, $console, $request, $response, $dbForProject, $dbForPlatform) {
    /** @var Appwrite\Utopia\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Utopia\Database\Document $project */
    /** @var Utopia\Database\Database $dbForProject */
    /** @var Utopia\Database\Database $dbForPlatform */
    /** @var string $mode */

    Authorization::setDefaultStatus(true);

    Auth::setCookieName('a_session_' . $project->getId());

    if (APP_MODE_ADMIN === $mode) {
        Auth::setCookieName('a_session_' . $console->getId());
    }

    $session = Auth::decodeSession(
        $request->getCookie(
            Auth::$cookieName, // Get sessions
            $request->getCookie(Auth::$cookieName . '_legacy', '')
        )
    );

    // Get session from header for SSR clients
    if (empty($session['id']) && empty($session['secret'])) {
        $sessionHeader = $request->getHeader('x-appwrite-session', '');

        if (!empty($sessionHeader)) {
            $session = Auth::decodeSession($sessionHeader);
        }
    }

    // Get fallback session from old clients (no SameSite support) or clients who block 3rd-party cookies
    if ($response) {
        $response->addHeader('X-Debug-Fallback', 'false');
    }

    if (empty($session['id']) && empty($session['secret'])) {
        if ($response) {
            $response->addHeader('X-Debug-Fallback', 'true');
        }
        $fallback = $request->getHeader('x-fallback-cookies', '');
        $fallback = \json_decode($fallback, true);
        $session = Auth::decodeSession(((isset($fallback[Auth::$cookieName])) ? $fallback[Auth::$cookieName] : ''));
    }

    Auth::$unique = $session['id'] ?? '';
    Auth::$secret = $session['secret'] ?? '';

    if (APP_MODE_ADMIN !== $mode) {
        if ($project->isEmpty()) {
            $user = new Document([]);
        } else {
            if ($project->getId() === 'console') {
                $user = $dbForPlatform->getDocument('users', Auth::$unique);
            } else {
                $user = $dbForProject->getDocument('users', Auth::$unique);
            }
        }
    } else {
        $user = $dbForPlatform->getDocument('users', Auth::$unique);
    }

    if (
        $user->isEmpty() // Check a document has been found in the DB
        || !Auth::sessionVerify($user->getAttribute('sessions', []), Auth::$secret)
    ) { // Validate user has valid login token
        $user = new Document([]);
    }

    // if (APP_MODE_ADMIN === $mode) {
    //     if ($user->find('teamInternalId', $project->getAttribute('teamInternalId'), 'memberships')) {
    //         Authorization::setDefaultStatus(false);  // Cancel security segmentation for admin users.
    //     } else {
    //         $user = new Document([]);
    //     }
    // }

    $authJWT = $request->getHeader('x-appwrite-jwt', '');

    if (!empty($authJWT) && !$project->isEmpty()) { // JWT authentication
        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 3600, 0);

        try {
            $payload = $jwt->decode($authJWT);
        } catch (JWTException $error) {
            throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
        }

        $jwtUserId = $payload['userId'] ?? '';
        if (!empty($jwtUserId)) {
            $user = $dbForProject->getDocument('users', $jwtUserId);
        }

        $jwtSessionId = $payload['sessionId'] ?? '';
        if (!empty($jwtSessionId)) {
            if (empty($user->find('$id', $jwtSessionId, 'sessions'))) { // Match JWT to active token
                $user = new Document([]);
            }
        }
    }

    $dbForProject->setMetadata('user', $user->getId());
    $dbForPlatform->setMetadata('user', $user->getId());

    return $user;
}, ['mode', 'project', 'console', 'request', 'response', 'dbForProject', 'dbForPlatform']);

App::setResource('project', function ($dbForPlatform, $request, $console) {
    /** @var Appwrite\Utopia\Request $request */
    /** @var Utopia\Database\Database $dbForPlatform */
    /** @var Utopia\Database\Document $console */

    $projectId = $request->getParam('project', $request->getHeader('x-appwrite-project', ''));

    if (empty($projectId) || $projectId === 'console') {
        return $console;
    }

    $project = Authorization::skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

    return $project;
}, ['dbForPlatform', 'request', 'console']);

App::setResource('session', function (Document $user) {
    if ($user->isEmpty()) {
        return;
    }

    $sessions = $user->getAttribute('sessions', []);
    $sessionId = Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret);

    if (!$sessionId) {
        return;
    }

    foreach ($sessions as $session) {/** @var Document $session */
        if ($sessionId === $session->getId()) {
            return $session;
        }
    }

    return;
}, ['user']);

App::setResource('console', function () {
    return new Document(Config::getParam('console'));
}, []);

App::setResource('dbForProject', function (Group $pools, Database $dbForPlatform, Cache $cache, Document $project) {
    if ($project->isEmpty() || $project->getId() === 'console') {
        return $dbForPlatform;
    }

    try {
        $dsn = new DSN($project->getAttribute('database'));
    } catch (\InvalidArgumentException) {
        // TODO: Temporary until all projects are using shared tables
        $dsn = new DSN('mysql://' . $project->getAttribute('database'));
    }

    $dbAdapter = $pools
        ->get($dsn->getHost())
        ->pop()
        ->getResource();

    $database = new Database($dbAdapter, $cache);

    $database
        ->setMetadata('host', \gethostname())
        ->setMetadata('project', $project->getId())
        ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS)
        ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

    $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

    if (\in_array($dsn->getHost(), $sharedTables)) {
        $database
            ->setSharedTables(true)
            ->setTenant($project->getInternalId())
            ->setNamespace($dsn->getParam('namespace'));
    } else {
        $database
            ->setSharedTables(false)
            ->setTenant(null)
            ->setNamespace('_' . $project->getInternalId());
    }

    return $database;
}, ['pools', 'dbForPlatform', 'cache', 'project']);

App::setResource('dbForPlatform', function (Group $pools, Cache $cache) {
    $dbAdapter = $pools
        ->get('console')
        ->pop()
        ->getResource();

    $database = new Database($dbAdapter, $cache);

    $database
        ->setNamespace('_console')
        ->setMetadata('host', \gethostname())
        ->setMetadata('project', 'console')
        ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS)
        ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

    return $database;
}, ['pools', 'cache']);

App::setResource('getProjectDB', function (Group $pools, Database $dbForPlatform, $cache) {
    $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

    return function (Document $project) use ($pools, $dbForPlatform, $cache, &$databases) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForPlatform;
        }

        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        $configure = (function (Database $database) use ($project, $dsn) {
            $database
                ->setMetadata('host', \gethostname())
                ->setMetadata('project', $project->getId())
                ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS)
                ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

            $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

            if (\in_array($dsn->getHost(), $sharedTables)) {
                $database
                    ->setSharedTables(true)
                    ->setTenant($project->getInternalId())
                    ->setNamespace($dsn->getParam('namespace'));
            } else {
                $database
                    ->setSharedTables(false)
                    ->setTenant(null)
                    ->setNamespace('_' . $project->getInternalId());
            }
        });

        if (isset($databases[$dsn->getHost()])) {
            $database = $databases[$dsn->getHost()];
            $configure($database);
            return $database;
        }

        $dbAdapter = $pools
            ->get($dsn->getHost())
            ->pop()
            ->getResource();

        $database = new Database($dbAdapter, $cache);
        $databases[$dsn->getHost()] = $database;
        $configure($database);

        return $database;
    };
}, ['pools', 'dbForPlatform', 'cache']);

App::setResource('getLogsDB', function (Group $pools, Cache $cache) {
    $database = null;
    return function (?Document $project = null) use ($pools, $cache, $database) {
        if ($database !== null && $project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getInternalId());
            return $database;
        }

        $dbAdapter = $pools
            ->get('logs')
            ->pop()
            ->getResource();

        $database = new Database(
            $dbAdapter,
            $cache
        );

        $database
            ->setSharedTables(true)
            ->setNamespace('logsV1')
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS)
            ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

        // set tenant
        if ($project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getInternalId());
        }

        return $database;
    };
}, ['pools', 'cache']);

App::setResource('cache', function (Group $pools) {
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
}, ['pools']);

App::setResource('redis', function () {
    $host = System::getEnv('_APP_REDIS_HOST', 'localhost');
    $port = System::getEnv('_APP_REDIS_PORT', 6379);
    $pass = System::getEnv('_APP_REDIS_PASS', '');

    $redis = new \Redis();
    @$redis->pconnect($host, (int)$port);
    if ($pass) {
        $redis->auth($pass);
    }
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

    return $redis;
});

App::setResource('timelimit', function (\Redis $redis) {
    return function (string $key, int $limit, int $time) use ($redis) {
        return new TimeLimitRedis($key, $limit, $time, $redis);
    };
}, ['redis']);

App::setResource('deviceForLocal', function () {
    return new Local();
});

App::setResource('deviceForFiles', function ($project) {
    return getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
}, ['project']);

App::setResource('deviceForFunctions', function ($project) {
    return getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId());
}, ['project']);

App::setResource('deviceForBuilds', function ($project) {
    return getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId());
}, ['project']);

function getDevice(string $root, string $connection = ''): Device
{
    $connection = !empty($connection) ? $connection : System::getEnv('_APP_CONNECTIONS_STORAGE', '');

    if (!empty($connection)) {
        $acl = 'private';
        $device = Storage::DEVICE_LOCAL;
        $accessKey = '';
        $accessSecret = '';
        $bucket = '';
        $region = '';
        $url = App::getEnv('_APP_STORAGE_S3_ENDPOINT', '');

        try {
            $dsn = new DSN($connection);
            $device = $dsn->getScheme();
            $accessKey = $dsn->getUser() ?? '';
            $accessSecret = $dsn->getPassword() ?? '';
            $bucket = $dsn->getPath() ?? '';
            $region = $dsn->getParam('region');
        } catch (\Throwable $e) {
            Console::warning($e->getMessage() . 'Invalid DSN. Defaulting to Local device.');
        }

        switch ($device) {
            case Storage::DEVICE_S3:
                return new S3($root, $accessKey, $accessSecret, $bucket, $region, $acl, $url);
            case STORAGE::DEVICE_DO_SPACES:
                $device = new DOSpaces($root, $accessKey, $accessSecret, $bucket, $region, $acl);
                $device->setHttpVersion(S3::HTTP_VERSION_1_1);
                return $device;
            case Storage::DEVICE_BACKBLAZE:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LINODE:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_WASABI:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
        }
    } else {
        switch (strtolower(System::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL) ?? '')) {
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
            case Storage::DEVICE_S3:
                $s3AccessKey = System::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
                $s3SecretKey = System::getEnv('_APP_STORAGE_S3_SECRET', '');
                $s3Region = System::getEnv('_APP_STORAGE_S3_REGION', '');
                $s3Bucket = System::getEnv('_APP_STORAGE_S3_BUCKET', '');
                $s3Acl = 'private';
                $s3EndpointUrl = App::getEnv('_APP_STORAGE_S3_ENDPOINT', '');
                return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl, $s3EndpointUrl);
            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = System::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
                $doSpacesSecretKey = System::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
                $doSpacesRegion = System::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
                $doSpacesBucket = System::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
                $doSpacesAcl = 'private';
                $device = new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
                $device->setHttpVersion(S3::HTTP_VERSION_1_1);
                return $device;
            case Storage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = System::getEnv('_APP_STORAGE_BACKBLAZE_ACCESS_KEY', '');
                $backblazeSecretKey = System::getEnv('_APP_STORAGE_BACKBLAZE_SECRET', '');
                $backblazeRegion = System::getEnv('_APP_STORAGE_BACKBLAZE_REGION', '');
                $backblazeBucket = System::getEnv('_APP_STORAGE_BACKBLAZE_BUCKET', '');
                $backblazeAcl = 'private';
                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
            case Storage::DEVICE_LINODE:
                $linodeAccessKey = System::getEnv('_APP_STORAGE_LINODE_ACCESS_KEY', '');
                $linodeSecretKey = System::getEnv('_APP_STORAGE_LINODE_SECRET', '');
                $linodeRegion = System::getEnv('_APP_STORAGE_LINODE_REGION', '');
                $linodeBucket = System::getEnv('_APP_STORAGE_LINODE_BUCKET', '');
                $linodeAcl = 'private';
                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
            case Storage::DEVICE_WASABI:
                $wasabiAccessKey = System::getEnv('_APP_STORAGE_WASABI_ACCESS_KEY', '');
                $wasabiSecretKey = System::getEnv('_APP_STORAGE_WASABI_SECRET', '');
                $wasabiRegion = System::getEnv('_APP_STORAGE_WASABI_REGION', '');
                $wasabiBucket = System::getEnv('_APP_STORAGE_WASABI_BUCKET', '');
                $wasabiAcl = 'private';
                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
        }
    }
}

App::setResource('mode', function ($request) {
    /** @var Appwrite\Utopia\Request $request */

    /**
     * Defines the mode for the request:
     * - 'default' => Requests for Client and Server Side
     * - 'admin' => Request from the Console on non-console projects
     */
    return $request->getParam('mode', $request->getHeader('x-appwrite-mode', APP_MODE_DEFAULT));
}, ['request']);

App::setResource('geodb', function ($register) {
    /** @var Utopia\Registry\Registry $register */
    return $register->get('geodb');
}, ['register']);

App::setResource('passwordsDictionary', function ($register) {
    /** @var Utopia\Registry\Registry $register */
    return $register->get('passwordsDictionary');
}, ['register']);


App::setResource('servers', function () {
    $platforms = Config::getParam('platforms');
    $server = $platforms[APP_PLATFORM_SERVER];

    $languages = array_map(function ($language) {
        return strtolower($language['name']);
    }, $server['sdks']);

    return $languages;
});

App::setResource('promiseAdapter', function ($register) {
    return $register->get('promiseAdapter');
}, ['register']);

App::setResource('schema', function ($utopia, $dbForProject) {

    $complexity = function (int $complexity, array $args) {
        $queries = Query::parseQueries($args['queries'] ?? []);
        $query = Query::getByType($queries, [Query::TYPE_LIMIT])[0] ?? null;
        $limit = $query ? $query->getValue() : APP_LIMIT_LIST_DEFAULT;

        return $complexity * $limit;
    };

    $attributes = function (int $limit, int $offset) use ($dbForProject) {
        $attrs = Authorization::skip(fn () => $dbForProject->find('attributes', [
            Query::limit($limit),
            Query::offset($offset),
        ]));

        return \array_map(function ($attr) {
            return $attr->getArrayCopy();
        }, $attrs);
    };

    $urls = [
        'list' => function (string $databaseId, string $collectionId, array $args) {
            return "/v1/databases/$databaseId/collections/$collectionId/documents";
        },
        'create' => function (string $databaseId, string $collectionId, array $args) {
            return "/v1/databases/$databaseId/collections/$collectionId/documents";
        },
        'read' => function (string $databaseId, string $collectionId, array $args) {
            return "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['documentId']}";
        },
        'update' => function (string $databaseId, string $collectionId, array $args) {
            return "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['documentId']}";
        },
        'delete' => function (string $databaseId, string $collectionId, array $args) {
            return "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['documentId']}";
        },
    ];

    $params = [
        'list' => function (string $databaseId, string $collectionId, array $args) {
            return [ 'queries' => $args['queries']];
        },
        'create' => function (string $databaseId, string $collectionId, array $args) {
            $id = $args['id'] ?? 'unique()';
            $permissions = $args['permissions'] ?? null;

            unset($args['id']);
            unset($args['permissions']);

            // Order must be the same as the route params
            return [
                'databaseId' => $databaseId,
                'documentId' => $id,
                'collectionId' => $collectionId,
                'data' => $args,
                'permissions' => $permissions,
            ];
        },
        'update' => function (string $databaseId, string $collectionId, array $args) {
            $documentId = $args['id'];
            $permissions = $args['permissions'] ?? null;

            unset($args['id']);
            unset($args['permissions']);

            // Order must be the same as the route params
            return [
                'databaseId' => $databaseId,
                'collectionId' => $collectionId,
                'documentId' => $documentId,
                'data' => $args,
                'permissions' => $permissions,
            ];
        },
    ];

    return Schema::build(
        $utopia,
        $complexity,
        $attributes,
        $urls,
        $params,
    );
}, ['utopia', 'dbForProject']);

App::setResource('contributors', function () {
    $path = 'app/config/contributors.json';
    $list = (file_exists($path)) ? json_decode(file_get_contents($path), true) : [];
    return $list;
});

App::setResource('employees', function () {
    $path = 'app/config/employees.json';
    $list = (file_exists($path)) ? json_decode(file_get_contents($path), true) : [];
    return $list;
});

App::setResource('heroes', function () {
    $path = 'app/config/heroes.json';
    $list = (file_exists($path)) ? json_decode(file_get_contents($path), true) : [];
    return $list;
});

App::setResource('gitHub', function (Cache $cache) {
    return new VcsGitHub($cache);
}, ['cache']);

App::setResource('requestTimestamp', function ($request) {
    //TODO: Move this to the Request class itself
    $timestampHeader = $request->getHeader('x-appwrite-timestamp');
    $requestTimestamp = null;
    if (!empty($timestampHeader)) {
        try {
            $requestTimestamp = new \DateTime($timestampHeader);
        } catch (\Throwable $e) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid X-Appwrite-Timestamp header value');
        }
    }
    return $requestTimestamp;
}, ['request']);

App::setResource('plan', function (array $plan = []) {
    return [];
});

App::setResource('smsRates', function () {
    return [];
});

App::setResource('team', function (Document $project, Database $dbForPlatform, App $utopia, Request $request) {
    $teamInternalId = '';
    if ($project->getId() !== 'console') {
        $teamInternalId = $project->getAttribute('teamInternalId', '');
    } else {
        $route = $utopia->match($request);
        $path = $route->getPath();
        if (str_starts_with($path, '/v1/projects/:projectId')) {
            $uri = $request->getURI();
            $pid = explode('/', $uri)[3];
            $p = Authorization::skip(fn () => $dbForPlatform->getDocument('projects', $pid));
            $teamInternalId = $p->getAttribute('teamInternalId', '');
        } elseif ($path === '/v1/projects') {
            $teamId = $request->getParam('teamId', '');
            $team = Authorization::skip(fn () => $dbForPlatform->getDocument('teams', $teamId));
            return $team;
        }
    }

    $team = Authorization::skip(function () use ($dbForPlatform, $teamInternalId) {
        return $dbForPlatform->findOne('teams', [
            Query::equal('$internalId', [$teamInternalId]),
        ]);
    });

    return $team;
}, ['project', 'dbForPlatform', 'utopia', 'request']);

App::setResource(
    'isResourceBlocked',
    fn () => fn (Document $project, string $resourceType, ?string $resourceId) => false
);

App::setResource('previewHostname', function (Request $request) {
    if (App::isDevelopment()) {
        $host = $request->getQuery('appwrite-hostname') ?? '';
        if (!empty($host)) {
            return $host;
        }
    }

    return '';
}, ['request']);

App::setResource('apiKey', function (Request $request, Document $project): ?Key {
    $key = $request->getHeader('x-appwrite-key');

    if (empty($key)) {
        return null;
    }

    return Key::decode($project, $key);
}, ['request', 'project']);
