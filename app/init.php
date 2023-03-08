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

ini_set('memory_limit', '512M');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('default_socket_timeout', -1);
error_reporting(E_ALL);

use Appwrite\Extend\Exception;
use Appwrite\Auth\Auth;
use Appwrite\SMS\Adapter\Mock;
use Appwrite\SMS\Adapter\Telesign;
use Appwrite\SMS\Adapter\TextMagic;
use Appwrite\SMS\Adapter\Twilio;
use Appwrite\SMS\Adapter\Msg91;
use Appwrite\SMS\Adapter\Vonage;
use Appwrite\Event\Audit;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Event\Phone;
use Appwrite\Event\Delete;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\IP;
use Appwrite\Network\Validator\URL;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\URL\URL as AppwriteURL;
use Appwrite\Usage\Stats;
use Utopia\App;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Database\ID;
use Utopia\Database\Document;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\DatetimeValidator;
use Utopia\Database\Validator\Structure;
use Utopia\Logger\Logger;
use Utopia\Config\Config;
use Utopia\Locale\Locale;
use Utopia\Registry\Registry;
use Utopia\Storage\Device;
use Utopia\DSN\DSN;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Wasabi;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Event\Func;
use MaxMind\Db\Reader;
use PHPMailer\PHPMailer\PHPMailer;
use Swoole\Database\PDOProxy;
use Utopia\CLI\Console;
use Utopia\Queue;
use Utopia\Queue\Connection;
use Utopia\Storage\Storage;

const APP_NAME = 'Appwrite';
const APP_DOMAIN = 'appwrite.io';
const APP_EMAIL_TEAM = 'team@localhost.test'; // Default email address
const APP_EMAIL_SECURITY = ''; // Default security email address
const APP_USERAGENT = APP_NAME . '-Server v%s. Please report abuse at %s';
const APP_MODE_DEFAULT = 'default';
const APP_MODE_ADMIN = 'admin';
const APP_PAGING_LIMIT = 12;
const APP_LIMIT_COUNT = 5000;
const APP_LIMIT_USERS = 10000;
const APP_LIMIT_ANTIVIRUS = 20000000; //20MB
const APP_LIMIT_ENCRYPTION = 20000000; //20MB
const APP_LIMIT_COMPRESSION = 20000000; //20MB
const APP_LIMIT_ARRAY_PARAMS_SIZE = 100; // Default maximum of how many elements can there be in API parameter that expects array value
const APP_LIMIT_ARRAY_ELEMENT_SIZE = 4096; // Default maximum length of element in array parameter represented by maximum URL length.
const APP_LIMIT_SUBQUERY = 1000;
const APP_LIMIT_WRITE_RATE_DEFAULT = 60; // Default maximum write rate per rate period
const APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT = 60; // Default maximum write rate period in seconds
const APP_KEY_ACCCESS = 24 * 60 * 60; // 24 hours
const APP_CACHE_UPDATE = 24 * 60 * 60; // 24 hours
const APP_CACHE_BUSTER = 501;
const APP_VERSION_STABLE = '1.1.2';
const APP_DATABASE_ATTRIBUTE_EMAIL = 'email';
const APP_DATABASE_ATTRIBUTE_ENUM = 'enum';
const APP_DATABASE_ATTRIBUTE_IP = 'ip';
const APP_DATABASE_ATTRIBUTE_DATETIME = 'datetime';
const APP_DATABASE_ATTRIBUTE_URL = 'url';
const APP_DATABASE_ATTRIBUTE_INT_RANGE = 'intRange';
const APP_DATABASE_ATTRIBUTE_FLOAT_RANGE = 'floatRange';
const APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH = 1073741824; // 2^32 bits / 4 bits per char
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
// Database Reconnect
const DATABASE_RECONNECT_SLEEP = 2;
const DATABASE_RECONNECT_MAX_ATTEMPTS = 10;
// Database Worker Types
const DATABASE_TYPE_CREATE_ATTRIBUTE = 'createAttribute';
const DATABASE_TYPE_CREATE_INDEX = 'createIndex';
const DATABASE_TYPE_DELETE_ATTRIBUTE = 'deleteAttribute';
const DATABASE_TYPE_DELETE_INDEX = 'deleteIndex';
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
const DELETE_TYPE_TEAMS = 'teams';
const DELETE_TYPE_EXECUTIONS = 'executions';
const DELETE_TYPE_AUDIT = 'audit';
const DELETE_TYPE_ABUSE = 'abuse';
const DELETE_TYPE_USAGE = 'usage';
const DELETE_TYPE_REALTIME = 'realtime';
const DELETE_TYPE_BUCKETS = 'buckets';
const DELETE_TYPE_SESSIONS = 'sessions';
const DELETE_TYPE_CACHE_BY_TIMESTAMP = 'cacheByTimeStamp';
const DELETE_TYPE_CACHE_BY_RESOURCE  = 'cacheByResource';
const DELETE_TYPE_SCHEDULES = 'schedules';
// Compression type
const COMPRESSION_TYPE_NONE = 'none';
const COMPRESSION_TYPE_GZIP = 'gzip';
const COMPRESSION_TYPE_ZSTD = 'zstd';
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
const MAX_OUTPUT_CHUNK_SIZE = 2 * 1024 * 1024; // 2MB

$register = new Registry();

App::setMode(App::getEnv('_APP_ENV', App::MODE_TYPE_PRODUCTION));

/*
 * ENV vars
 */
Config::load('events', __DIR__ . '/config/events.php');
Config::load('auth', __DIR__ . '/config/auth.php');
Config::load('errors', __DIR__ . '/config/errors.php');
Config::load('providers', __DIR__ . '/config/providers.php');
Config::load('platforms', __DIR__ . '/config/platforms.php');
Config::load('collections', __DIR__ . '/config/collections.php');
Config::load('runtimes', __DIR__ . '/config/runtimes.php');
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
Config::load('storage-logos', __DIR__ . '/config/storage/logos.php');
Config::load('storage-mimes', __DIR__ . '/config/storage/mimes.php');
Config::load('storage-inputs', __DIR__ . '/config/storage/inputs.php');
Config::load('storage-outputs', __DIR__ . '/config/storage/outputs.php');

$user = App::getEnv('_APP_REDIS_USER', '');
$pass = App::getEnv('_APP_REDIS_PASS', '');
if (!empty($user) || !empty($pass)) {
    Resque::setBackend('redis://' . $user . ':' . $pass . '@' . App::getEnv('_APP_REDIS_HOST', '') . ':' . App::getEnv('_APP_REDIS_PORT', ''));
} else {
    Resque::setBackend(App::getEnv('_APP_REDIS_HOST', '') . ':' . App::getEnv('_APP_REDIS_PORT', ''));
}

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
            return null;
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
        $formatOptions = json_decode($attribute->getAttribute('formatOptions', '[]'), true);
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
                ->setAttribute('max', $formatOptions['max'])
            ;
        }

        return $value;
    }
);

Database::addFilter(
    'subQueryAttributes',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('attributes', [
                Query::equal('collectionInternalId', [$document->getInternalId()]),
                Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                Query::limit($database->getLimitForAttributes()),
            ]);
    }
);

Database::addFilter(
    'subQueryIndexes',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('indexes', [
                Query::equal('collectionInternalId', [$document->getInternalId()]),
                Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                Query::limit(64),
            ]);
    }
);

Database::addFilter(
    'subQueryPlatforms',
    function (mixed $value) {
        return null;
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
        return null;
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
        return null;
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
        return null;
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
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn() => $database
            ->find('tokens', [
                Query::equal('userInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryMemberships',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return Authorization::skip(fn() => $database
            ->find('memberships', [
                Query::equal('userInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]));
    }
);

Database::addFilter(
    'subQueryVariables',
    function (mixed $value) {
        return null;
    },
    function (mixed $value, Document $document, Database $database) {
        return $database
            ->find('variables', [
                Query::equal('functionInternalId', [$document->getInternalId()]),
                Query::limit(APP_LIMIT_SUBQUERY),
            ]);
    }
);

Database::addFilter(
    'encrypt',
    function (mixed $value) {
        $key = App::getEnv('_APP_OPENSSL_KEY_V1');
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
            return null;
        }
        $value = json_decode($value, true);
        $key = App::getEnv('_APP_OPENSSL_KEY_V' . $value['version']);

        return OpenSSL::decrypt($value['data'], $value['method'], $key, 0, hex2bin($value['iv']), hex2bin($value['tag']));
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
    $elements = $attribute['formatOptions']['elements'];
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
    $providerName = App::getEnv('_APP_LOGGING_PROVIDER', '');
    $providerConfig = App::getEnv('_APP_LOGGING_CONFIG', '');

    if (empty($providerName) || empty($providerConfig)) {
        return null;
    }

    if (!Logger::hasProvider($providerName)) {
        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Logging provider not supported. Logging is disabled");
    }

    $classname = '\\Utopia\\Logger\\Adapter\\' . \ucfirst($providerName);
    $adapter = new $classname($providerConfig);
    return new Logger($adapter);
});
$register->set('pools', function () {
    $group = new Group();

    $fallbackForDB = AppwriteURL::unparse([
        'scheme' => 'mariadb',
        'host' => App::getEnv('_APP_DB_HOST', 'mariadb'),
        'port' => App::getEnv('_APP_DB_PORT', '3306'),
        'user' => App::getEnv('_APP_DB_USER', ''),
        'pass' => App::getEnv('_APP_DB_PASS', ''),
    ]);
    $fallbackForRedis = AppwriteURL::unparse([
        'scheme' => 'redis',
        'host' => App::getEnv('_APP_REDIS_HOST', 'redis'),
        'port' => App::getEnv('_APP_REDIS_PORT', '6379'),
        'user' => App::getEnv('_APP_REDIS_USER', ''),
        'pass' => App::getEnv('_APP_REDIS_PASS', ''),
    ]);

    $connections = [
        'console' => [
            'type' => 'database',
            'dsns' => App::getEnv('_APP_CONNECTIONS_DB_CONSOLE', $fallbackForDB),
            'multiple' => false,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'database' => [
            'type' => 'database',
            'dsns' => App::getEnv('_APP_CONNECTIONS_DB_PROJECT', $fallbackForDB),
            'multiple' => true,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'queue' => [
            'type' => 'queue',
            'dsns' => App::getEnv('_APP_CONNECTIONS_QUEUE', $fallbackForRedis),
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'pubsub' => [
            'type' => 'pubsub',
            'dsns' => App::getEnv('_APP_CONNECTIONS_PUBSUB', $fallbackForRedis),
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'cache' => [
            'type' => 'cache',
            'dsns' => App::getEnv('_APP_CONNECTIONS_CACHE', $fallbackForRedis),
            'multiple' => true,
            'schemes' => ['redis'],
        ],
    ];

    $maxConnections = App::getEnv('_APP_CONNECTIONS_MAX', 151);
    $instanceConnections = $maxConnections / App::getEnv('_APP_POOL_CLIENTS', 14);

    $multiprocessing = App::getEnv('_APP_SERVER_MULTIPROCESS', 'disabled') === 'enabled';

    if ($multiprocessing) {
        $workerCount = swoole_cpu_num() * intval(App::getEnv('_APP_WORKER_PER_CORE', 6));
    } else {
        $workerCount = 1;
    }

    if ($workerCount > $instanceConnections) {
        throw new \Exception('Pool size is too small. Increase the number of allowed database connections or decrease the number of workers.', 500);
    }

    $poolSize = (int)($instanceConnections / $workerCount);

    foreach ($connections as $key => $connection) {
        $type = $connection['type'] ?? '';
        $dsns = $connection['dsns'] ?? '';
        $multipe = $connection['multiple'] ?? false;
        $schemes = $connection['schemes'] ?? [];
        $config = [];
        $dsns = explode(',', $connection['dsns'] ?? '');

        foreach ($dsns as &$dsn) {
            $dsn = explode('=', $dsn);
            $name = ($multipe) ? $key . '_' . $dsn[0] : $key;
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
             * Creation could be reused accross connection types like database, cache, queue, etc.
             *
             * Resource assignment to an adapter will happen below.
             */

            switch ($dsnScheme) {
                case 'mysql':
                case 'mariadb':
                    $resource = function () use ($dsnHost, $dsnPort, $dsnUser, $dsnPass, $dsnDatabase) {
                        return new PDOProxy(function () use ($dsnHost, $dsnPort, $dsnUser, $dsnPass, $dsnDatabase) {
                            return new PDO("mysql:host={$dsnHost};port={$dsnPort};dbname={$dsnDatabase};charset=utf8mb4", $dsnUser, $dsnPass, array(
                                PDO::ATTR_TIMEOUT => 3, // Seconds
                                PDO::ATTR_PERSISTENT => true,
                                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                                PDO::ATTR_ERRMODE => App::isDevelopment() ? PDO::ERRMODE_WARNING : PDO::ERRMODE_SILENT, // If in production mode, warnings are not displayed
                                PDO::ATTR_EMULATE_PREPARES => true,
                                PDO::ATTR_STRINGIFY_FETCHES => true
                            ));
                        });
                    };
                    break;
                case 'redis':
                    $resource = function () use ($dsnHost, $dsnPort, $dsnPass) {
                        $redis = new Redis();
                        @$redis->pconnect($dsnHost, (int)$dsnPort);
                        if ($dsnPass) {
                            $redis->auth($dsnPass);
                        }
                        $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

                        return $redis;
                    };
                    break;

                default:
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, "Invalid scheme");
                    break;
            }

            $pool = new Pool($name, $poolSize, function () use ($type, $resource, $dsn) {
                // Get Adapter
                $adapter = null;

                switch ($type) {
                    case 'database':
                        $adapter = match ($dsn->getScheme()) {
                            'mariadb' => new MariaDB($resource()),
                            'mysql' => new MySQL($resource()),
                            default => null
                        };

                        $adapter->setDefaultDatabase($dsn->getPath());
                        break;
                    case 'pubsub':
                        $adapter = $resource();
                        break;
                    case 'queue':
                        $adapter = match ($dsn->getScheme()) {
                            'redis' => new Queue\Connection\Redis($dsn->getHost(), $dsn->getPort()),
                            default => null
                        };
                        break;
                    case 'cache':
                        $adapter = match ($dsn->getScheme()) {
                            'redis' => new RedisCache($resource()),
                            default => null
                        };
                        break;

                    default:
                        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Server error: Missing adapter implementation.");
                        break;
                }

                return $adapter;
            });

            $group->add($pool);
        }

        Config::setParam('pools-' . $key, $config);
    }

    return $group;
});

$register->set('influxdb', function () {
 // Register DB connection
    $host = App::getEnv('_APP_INFLUXDB_HOST', '');
    $port = App::getEnv('_APP_INFLUXDB_PORT', '');

    if (empty($host) || empty($port)) {
        return;
    }
    $driver = new InfluxDB\Driver\Curl("http://{$host}:{$port}");
    $client = new InfluxDB\Client($host, $port, '', '', false, false, 5);
    $client->setDriver($driver);

    return $client;
});
$register->set('statsd', function () {
    // Register DB connection
    $host = App::getEnv('_APP_STATSD_HOST', 'telegraf');
    $port = App::getEnv('_APP_STATSD_PORT', 8125);

    $connection = new \Domnikl\Statsd\Connection\UdpSocket($host, $port);
    $statsd = new \Domnikl\Statsd\Client($connection);

    return $statsd;
});
$register->set('smtp', function () {
    $mail = new PHPMailer(true);

    $mail->isSMTP();

    $username = App::getEnv('_APP_SMTP_USERNAME', null);
    $password = App::getEnv('_APP_SMTP_PASSWORD', null);

    $mail->XMailer = 'Appwrite Mailer';
    $mail->Host = App::getEnv('_APP_SMTP_HOST', 'smtp');
    $mail->Port = App::getEnv('_APP_SMTP_PORT', 25);
    $mail->SMTPAuth = (!empty($username) && !empty($password));
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = App::getEnv('_APP_SMTP_SECURE', false);
    $mail->SMTPAutoTLS = false;
    $mail->CharSet = 'UTF-8';

    $from = \urldecode(App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'));
    $email = App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);

    $mail->setFrom($email, $from);
    $mail->addReplyTo($email, $from);

    $mail->isHTML(true);

    return $mail;
});
$register->set('geodb', function () {
    return new Reader(__DIR__ . '/assets/dbip/dbip-country-lite-2022-06.mmdb');
});

/*
 * Localization
 */
Locale::$exceptions = false;
Locale::setLanguageFromJSON('af', __DIR__ . '/config/locale/translations/af.json');
Locale::setLanguageFromJSON('ar', __DIR__ . '/config/locale/translations/ar.json');
Locale::setLanguageFromJSON('as', __DIR__ . '/config/locale/translations/as.json');
Locale::setLanguageFromJSON('az', __DIR__ . '/config/locale/translations/az.json');
Locale::setLanguageFromJSON('be', __DIR__ . '/config/locale/translations/be.json');
Locale::setLanguageFromJSON('bg', __DIR__ . '/config/locale/translations/bg.json');
Locale::setLanguageFromJSON('bh', __DIR__ . '/config/locale/translations/bh.json');
Locale::setLanguageFromJSON('bn', __DIR__ . '/config/locale/translations/bn.json');
Locale::setLanguageFromJSON('bs', __DIR__ . '/config/locale/translations/bs.json');
Locale::setLanguageFromJSON('ca', __DIR__ . '/config/locale/translations/ca.json');
Locale::setLanguageFromJSON('cs', __DIR__ . '/config/locale/translations/cs.json');
Locale::setLanguageFromJSON('da', __DIR__ . '/config/locale/translations/da.json');
Locale::setLanguageFromJSON('de', __DIR__ . '/config/locale/translations/de.json');
Locale::setLanguageFromJSON('el', __DIR__ . '/config/locale/translations/el.json');
Locale::setLanguageFromJSON('en', __DIR__ . '/config/locale/translations/en.json');
Locale::setLanguageFromJSON('eo', __DIR__ . '/config/locale/translations/eo.json');
Locale::setLanguageFromJSON('es', __DIR__ . '/config/locale/translations/es.json');
Locale::setLanguageFromJSON('fa', __DIR__ . '/config/locale/translations/fa.json');
Locale::setLanguageFromJSON('fi', __DIR__ . '/config/locale/translations/fi.json');
Locale::setLanguageFromJSON('fo', __DIR__ . '/config/locale/translations/fo.json');
Locale::setLanguageFromJSON('fr', __DIR__ . '/config/locale/translations/fr.json');
Locale::setLanguageFromJSON('ga', __DIR__ . '/config/locale/translations/ga.json');
Locale::setLanguageFromJSON('gu', __DIR__ . '/config/locale/translations/gu.json');
Locale::setLanguageFromJSON('he', __DIR__ . '/config/locale/translations/he.json');
Locale::setLanguageFromJSON('hi', __DIR__ . '/config/locale/translations/hi.json');
Locale::setLanguageFromJSON('hr', __DIR__ . '/config/locale/translations/hr.json');
Locale::setLanguageFromJSON('hu', __DIR__ . '/config/locale/translations/hu.json');
Locale::setLanguageFromJSON('hy', __DIR__ . '/config/locale/translations/hy.json');
Locale::setLanguageFromJSON('id', __DIR__ . '/config/locale/translations/id.json');
Locale::setLanguageFromJSON('is', __DIR__ . '/config/locale/translations/is.json');
Locale::setLanguageFromJSON('it', __DIR__ . '/config/locale/translations/it.json');
Locale::setLanguageFromJSON('ja', __DIR__ . '/config/locale/translations/ja.json');
Locale::setLanguageFromJSON('jv', __DIR__ . '/config/locale/translations/jv.json');
Locale::setLanguageFromJSON('kn', __DIR__ . '/config/locale/translations/kn.json');
Locale::setLanguageFromJSON('km', __DIR__ . '/config/locale/translations/km.json');
Locale::setLanguageFromJSON('ko', __DIR__ . '/config/locale/translations/ko.json');
Locale::setLanguageFromJSON('la', __DIR__ . '/config/locale/translations/la.json');
Locale::setLanguageFromJSON('lb', __DIR__ . '/config/locale/translations/lb.json');
Locale::setLanguageFromJSON('lt', __DIR__ . '/config/locale/translations/lt.json');
Locale::setLanguageFromJSON('lv', __DIR__ . '/config/locale/translations/lv.json');
Locale::setLanguageFromJSON('ml', __DIR__ . '/config/locale/translations/ml.json');
Locale::setLanguageFromJSON('mr', __DIR__ . '/config/locale/translations/mr.json');
Locale::setLanguageFromJSON('ms', __DIR__ . '/config/locale/translations/ms.json');
Locale::setLanguageFromJSON('nb', __DIR__ . '/config/locale/translations/nb.json');
Locale::setLanguageFromJSON('ne', __DIR__ . '/config/locale/translations/ne.json');
Locale::setLanguageFromJSON('nl', __DIR__ . '/config/locale/translations/nl.json');
Locale::setLanguageFromJSON('nn', __DIR__ . '/config/locale/translations/nn.json');
Locale::setLanguageFromJSON('or', __DIR__ . '/config/locale/translations/or.json');
Locale::setLanguageFromJSON('pa', __DIR__ . '/config/locale/translations/pa.json');
Locale::setLanguageFromJSON('pl', __DIR__ . '/config/locale/translations/pl.json');
Locale::setLanguageFromJSON('pt-br', __DIR__ . '/config/locale/translations/pt-br.json');
Locale::setLanguageFromJSON('pt-pt', __DIR__ . '/config/locale/translations/pt-pt.json');
Locale::setLanguageFromJSON('ro', __DIR__ . '/config/locale/translations/ro.json');
Locale::setLanguageFromJSON('ru', __DIR__ . '/config/locale/translations/ru.json');
Locale::setLanguageFromJSON('sa', __DIR__ . '/config/locale/translations/sa.json');
Locale::setLanguageFromJSON('sd', __DIR__ . '/config/locale/translations/sd.json');
Locale::setLanguageFromJSON('si', __DIR__ . '/config/locale/translations/si.json');
Locale::setLanguageFromJSON('sk', __DIR__ . '/config/locale/translations/sk.json');
Locale::setLanguageFromJSON('sl', __DIR__ . '/config/locale/translations/sl.json');
Locale::setLanguageFromJSON('sn', __DIR__ . '/config/locale/translations/sn.json');
Locale::setLanguageFromJSON('sq', __DIR__ . '/config/locale/translations/sq.json');
Locale::setLanguageFromJSON('sv', __DIR__ . '/config/locale/translations/sv.json');
Locale::setLanguageFromJSON('ta', __DIR__ . '/config/locale/translations/ta.json');
Locale::setLanguageFromJSON('te', __DIR__ . '/config/locale/translations/te.json');
Locale::setLanguageFromJSON('th', __DIR__ . '/config/locale/translations/th.json');
Locale::setLanguageFromJSON('tl', __DIR__ . '/config/locale/translations/tl.json');
Locale::setLanguageFromJSON('tr', __DIR__ . '/config/locale/translations/tr.json');
Locale::setLanguageFromJSON('uk', __DIR__ . '/config/locale/translations/uk.json');
Locale::setLanguageFromJSON('ur', __DIR__ . '/config/locale/translations/ur.json');
Locale::setLanguageFromJSON('vi', __DIR__ . '/config/locale/translations/vi.json');
Locale::setLanguageFromJSON('zh-cn', __DIR__ . '/config/locale/translations/zh-cn.json');
Locale::setLanguageFromJSON('zh-tw', __DIR__ . '/config/locale/translations/zh-tw.json');

\stream_context_set_default([ // Set global user agent and http settings
    'http' => [
        'method' => 'GET',
        'user_agent' => \sprintf(
            APP_USERAGENT,
            App::getEnv('_APP_VERSION', 'UNKNOWN'),
            App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)
        ),
        'timeout' => 2,
    ],
]);

// Runtime Execution
App::setResource('logger', function ($register) {
    return $register->get('logger');
}, ['register']);

App::setResource('loggerBreadcrumbs', function () {
    return [];
});

App::setResource('register', fn() => $register);

App::setResource('locale', fn() => new Locale(App::getEnv('_APP_LOCALE', 'en')));

// Queues
App::setResource('events', fn() => new Event('', ''));
App::setResource('audits', fn() => new Audit());
App::setResource('mails', fn() => new Mail());
App::setResource('deletes', fn() => new Delete());
App::setResource('database', fn() => new EventDatabase());
App::setResource('messaging', fn() => new Phone());
App::setResource('queue', function (Group $pools) {
    return $pools->get('queue')->pop()->getResource();
}, ['pools']);
App::setResource('queueForFunctions', function (Connection $queue) {
    return new Func($queue);
}, ['queue']);
App::setResource('usage', function ($register) {
    return new Stats($register->get('statsd'));
}, ['register']);
App::setResource('clients', function ($request, $console, $project) {
    $console->setAttribute('platforms', [ // Always allow current host
        '$collection' => ID::custom('platforms'),
        'name' => 'Current Host',
        'type' => 'web',
        'hostname' => $request->getHostname(),
    ], Document::SET_TYPE_APPEND);

    /**
     * Get All verified client URLs for both console and current projects
     * + Filter for duplicated entries
     */
    $clientsConsole = \array_map(
        fn ($node) => $node['hostname'],
        \array_filter(
            $console->getAttribute('platforms', []),
            fn ($node) => (isset($node['type']) && $node['type'] === 'web' && isset($node['hostname']) && !empty($node['hostname']))
        )
    );

    $clients = \array_unique(
        \array_merge(
            $clientsConsole,
            \array_map(
                fn ($node) => $node['hostname'],
                \array_filter(
                    $project->getAttribute('platforms', []),
                    fn ($node) => (isset($node['type']) && $node['type'] === 'web' && isset($node['hostname']) && !empty($node['hostname']))
                )
            )
        )
    );

    return $clients;
}, ['request', 'console', 'project']);

App::setResource('user', function ($mode, $project, $console, $request, $response, $dbForProject, $dbForConsole) {
    /** @var Appwrite\Utopia\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Utopia\Database\Document $project */
    /** @var Utopia\Database\Database $dbForProject */
    /** @var Utopia\Database\Database $dbForConsole */
    /** @var string $mode */

    Authorization::setDefaultStatus(true);

    Auth::setCookieName('a_session_' . $project->getId());
    $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;

    if (APP_MODE_ADMIN === $mode) {
        Auth::setCookieName('a_session_' . $console->getId());
        $authDuration = Auth::TOKEN_EXPIRATION_LOGIN_LONG;
    }

    $session = Auth::decodeSession(
        $request->getCookie(
            Auth::$cookieName, // Get sessions
            $request->getCookie(Auth::$cookieName . '_legacy', '')
        )
    );// Get fallback session from old clients (no SameSite support)

    // Get fallback session from clients who block 3rd-party cookies
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
            $user = new Document(['$id' => ID::custom(''), '$collection' => 'users']);
        } else {
            $user = $dbForProject->getDocument('users', Auth::$unique);
        }
    } else {
        $user = $dbForConsole->getDocument('users', Auth::$unique);
    }

    if (
        $user->isEmpty() // Check a document has been found in the DB
        || !Auth::sessionVerify($user->getAttribute('sessions', []), Auth::$secret, $authDuration)
    ) { // Validate user has valid login token
        $user = new Document(['$id' => ID::custom(''), '$collection' => 'users']);
    }

    if (APP_MODE_ADMIN === $mode) {
        if ($user->find('teamId', $project->getAttribute('teamId'), 'memberships')) {
            Authorization::setDefaultStatus(false);  // Cancel security segmentation for admin users.
        } else {
            $user = new Document(['$id' => ID::custom(''), '$collection' => 'users']);
        }
    }

    $authJWT = $request->getHeader('x-appwrite-jwt', '');

    if (!empty($authJWT) && !$project->isEmpty()) { // JWT authentication
        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.

        try {
            $payload = $jwt->decode($authJWT);
        } catch (JWTException $error) {
            throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
        }

        $jwtUserId = $payload['userId'] ?? '';
        $jwtSessionId = $payload['sessionId'] ?? '';

        if ($jwtUserId && $jwtSessionId) {
            $user = $dbForProject->getDocument('users', $jwtUserId);
        }

        if (empty($user->find('$id', $jwtSessionId, 'sessions'))) { // Match JWT to active token
            $user = new Document(['$id' => ID::custom(''), '$collection' => 'users']);
        }
    }

    return $user;
}, ['mode', 'project', 'console', 'request', 'response', 'dbForProject', 'dbForConsole']);

App::setResource('project', function ($dbForConsole, $request, $console) {
    /** @var Appwrite\Utopia\Request $request */
    /** @var Utopia\Database\Database $dbForConsole */
    /** @var Utopia\Database\Document $console */

    $projectId = $request->getParam('project', $request->getHeader('x-appwrite-project', 'console'));

    if ($projectId === 'console') {
        return $console;
    }

    $project = Authorization::skip(fn() => $dbForConsole->getDocument('projects', $projectId));

    return $project;
}, ['dbForConsole', 'request', 'console']);

App::setResource('console', function () {
    return new Document([
        '$id' => ID::custom('console'),
        '$internalId' => ID::custom('console'),
        'name' => 'Appwrite',
        '$collection' => ID::custom('projects'),
        'description' => 'Appwrite core engine',
        'logo' => '',
        'teamId' => -1,
        'webhooks' => [],
        'keys' => [],
        'platforms' => [
            [
                '$collection' => ID::custom('platforms'),
                'name' => 'Localhost',
                'type' => 'web',
                'hostname' => 'localhost',
            ], // Current host is added on app init
        ],
        'legalName' => '',
        'legalCountry' => '',
        'legalState' => '',
        'legalCity' => '',
        'legalAddress' => '',
        'legalTaxId' => '',
        'auths' => [
            'invites' => App::getEnv('_APP_CONSOLE_INVITES', 'enabled') === 'enabled',
            'limit' => (App::getEnv('_APP_CONSOLE_WHITELIST_ROOT', 'enabled') === 'enabled') ? 1 : 0, // limit signup to 1 user
            'duration' => Auth::TOKEN_EXPIRATION_LOGIN_LONG, // 1 Year in seconds
        ],
        'authWhitelistEmails' => (!empty(App::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null))) ? \explode(',', App::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null)) : [],
        'authWhitelistIPs' => (!empty(App::getEnv('_APP_CONSOLE_WHITELIST_IPS', null))) ? \explode(',', App::getEnv('_APP_CONSOLE_WHITELIST_IPS', null)) : [],
    ]);
}, []);

App::setResource('dbForProject', function (Group $pools, Database $dbForConsole, Cache $cache, Document $project) {
    if ($project->isEmpty() || $project->getId() === 'console') {
        return $dbForConsole;
    }

    $dbAdapter = $pools
        ->get($project->getAttribute('database'))
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, $cache);
    $database->setNamespace('_' . $project->getInternalId());

    return $database;
}, ['pools', 'dbForConsole', 'cache', 'project']);

App::setResource('dbForConsole', function (Group $pools, Cache $cache) {
    $dbAdapter = $pools
        ->get('console')
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, $cache);

    $database->setNamespace('console');

    return $database;
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

App::setResource('deviceLocal', function () {
    return new Local();
});

App::setResource('deviceFiles', function ($project) {
    return getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
}, ['project']);

App::setResource('deviceFunctions', function ($project) {
    return getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId());
}, ['project']);

App::setResource('deviceBuilds', function ($project) {
    return getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId());
}, ['project']);

function getDevice($root): Device
{
    $connection = App::getEnv('_APP_CONNECTIONS_STORAGE', '');

    $acl = 'private';
    $device = Storage::DEVICE_LOCAL;
    $accessKey = '';
    $accessSecret = '';
    $bucket = '';
    $region = '';

    try {
        $dsn = new DSN($connection);
        $device = $dsn->getScheme();
        $accessKey = $dsn->getUser();
        $accessSecret = $dsn->getPassword();
        $bucket = $dsn->getPath();
        $region = $dsn->getParam('region');
    } catch (\Exception $e) {
        Console::error($e->getMessage() . 'Invalid DSN. Defaulting to Local device.');
    }

    switch ($device) {
        case Storage::DEVICE_S3:
            return new S3($root, $accessKey, $accessSecret, $bucket, $region, $acl);
        case STORAGE::DEVICE_DO_SPACES:
            return new DOSpaces($root, $accessKey, $accessSecret, $bucket, $region, $acl);
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

App::setResource('sms', function () {
    $dsn = new DSN(App::getEnv('_APP_SMS_PROVIDER'));
    $user = $dsn->getUser();
    $secret = $dsn->getPassword();

    return match ($dsn->getHost()) {
        'mock' => new Mock($user, $secret), // used for tests
        'twilio' => new Twilio($user, $secret),
        'text-magic' => new TextMagic($user, $secret),
        'telesign' => new Telesign($user, $secret),
        'msg91' => new Msg91($user, $secret),
        'vonage' => new Vonage($user, $secret),
        default => null
    };
});

App::setResource('servers', function () {
    $platforms = Config::getParam('platforms');
    $server = $platforms[APP_PLATFORM_SERVER];

    $languages = array_map(function ($language) {
        return strtolower($language['name']);
    }, $server['languages']);

    return $languages;
});
