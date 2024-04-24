<?php

require_once __DIR__ . '/init/constants.php';
require_once __DIR__ . '/init/config.php';
require_once __DIR__ . '/init/locale.php';
require_once __DIR__ . '/init/database/filters.php';
require_once __DIR__ . '/init/database/formats.php';

ini_set('memory_limit', '-1');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('default_socket_timeout', -1);
error_reporting(E_ALL);

global $http, $container;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Auth\Auth;
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
use Appwrite\Event\Usage;
use Appwrite\Extend\Exception;
use Appwrite\GraphQL\Promises\Adapter\Swoole;
use Appwrite\GraphQL\Schema;
use Appwrite\Hooks\Hooks;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\Origin;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\URL\URL;
use Appwrite\Utopia\Queue\Connections;
use MaxMind\Db\Reader;
use PHPMailer\PHPMailer\PHPMailer;
use Swoole\Coroutine;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOProxy;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;
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
use Utopia\DI\Dependency;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\DSN\DSN;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Http\Validator\Hostname;
use Utopia\Http\Validator\IP;
use Utopia\Http\Validator\Range;
use Utopia\Http\Validator\WhiteList;
use Utopia\Locale\Locale;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Queue;
use Utopia\Queue\Connection;
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
use Utopia\VCS\Adapter\Git\GitHub as VcsGitHub;
use Utopia\Cache\Adapter\None;
use Utopia\DI\Container;

Http::setMode(System::getEnv('_APP_ENV', Http::MODE_TYPE_PRODUCTION));

if (!Http::isProduction()) {
    // Allow specific domains to skip public domain validation in dev environment
    // Useful for existing tests involving webhooks
    PublicDomain::allow(['request-catcher']);
}

function getDevice($root): Device
{
    $connection = System::getEnv('_APP_CONNECTIONS_STORAGE', '');

    if (!empty($connection)) {
        $acl = 'private';
        $device = Storage::DEVICE_LOCAL;
        $accessKey = '';
        $accessSecret = '';
        $bucket = '';
        $region = '';

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
                return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = System::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
                $doSpacesSecretKey = System::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
                $doSpacesRegion = System::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
                $doSpacesBucket = System::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
                $doSpacesAcl = 'private';
                return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
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

$container = new Container();
$global = new Registry();

$global->set('logger', function () {
    // Register error logger
    $providerName = System::getEnv('_APP_LOGGING_PROVIDER', '');
    $providerConfig = System::getEnv('_APP_LOGGING_CONFIG', '');

    if (empty($providerName) || empty($providerConfig)) {
        return;
    }

    if (!Logger::hasProvider($providerName)) {
        throw new Exception(Exception::GENERAL_SERVER_ERROR, "Logging provider not supported. Logging is disabled");
    }

    $classname = '\\Utopia\\Logger\\Adapter\\' . \ucfirst($providerName);
    $adapter = new $classname($providerConfig);
    return new Logger($adapter);
});

$global->set('geodb', function () {
    /**
     * @disregard P1009 Undefined type
     */
    return new Reader(__DIR__ . '/assets/dbip/dbip-country-lite-2024-02.mmdb');
});

$global->set('hooks', function () {
   return new Hooks();
});

$global->set('pools', (function () {
    $fallbackForDB = 'db_main=' . URL::unparse([
        'scheme' => 'mariadb',
        'host' => System::getEnv('_APP_DB_HOST', 'mariadb'),
        'port' => System::getEnv('_APP_DB_PORT', '3306'),
        'user' => System::getEnv('_APP_DB_USER', ''),
        'pass' => System::getEnv('_APP_DB_PASS', ''),
        'path' => System::getEnv('_APP_DB_SCHEMA', ''),
    ]);
    $fallbackForRedis = 'redis_main=' . URL::unparse([
        'scheme' => 'redis',
        'host' => System::getEnv('_APP_REDIS_HOST', 'redis'),
        'port' => System::getEnv('_APP_REDIS_PORT', '6379'),
        'user' => System::getEnv('_APP_REDIS_USER', ''),
        'pass' => System::getEnv('_APP_REDIS_PASS', ''),
    ]);

    $connections = [
        'console' => [
            'type' => 'database',
            'dsns' => System::getEnv('_APP_CONNECTIONS_DB_CONSOLE', $fallbackForDB),
            'multiple' => false,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'database' => [
            'type' => 'database',
            'dsns' => System::getEnv('_APP_CONNECTIONS_DB_PROJECT', $fallbackForDB),
            'multiple' => true,
            'schemes' => ['mariadb', 'mysql'],
        ],
        'queue' => [
            'type' => 'queue',
            'dsns' => System::getEnv('_APP_CONNECTIONS_QUEUE', $fallbackForRedis),
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'pubsub' => [
            'type' => 'pubsub',
            'dsns' => System::getEnv('_APP_CONNECTIONS_PUBSUB', $fallbackForRedis),
            'multiple' => false,
            'schemes' => ['redis'],
        ],
        'cache' => [
            'type' => 'cache',
            'dsns' => System::getEnv('_APP_CONNECTIONS_CACHE', $fallbackForRedis),
            'multiple' => true,
            'schemes' => ['redis'],
        ],
    ];

    $pools = [];
    $poolSize = (int)System::getEnv('_APP_POOL_CLIENTS', 9000);
    $poolSize = 9000;

    foreach ($connections as $key => $connection) {
        $dsns = $connection['dsns'] ?? '';
        $multipe = $connection['multiple'] ?? false;
        $schemes = $connection['schemes'] ?? [];
        $dsns = explode(',', $connection['dsns'] ?? '');
        $config = [];

        foreach ($dsns as &$dsn) {
            $dsn = explode('=', $dsn);
            $name = ($multipe) ? $dsn[0] : 'main';
            $config[] = $name;
            $dsn = $dsn[1] ?? '';
            
            if (empty($dsn)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, "Missing value for DSN connection in {$key}");
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
                    $pool = new PDOPool((new PDOConfig)
                        ->withHost($dsnHost)
                        ->withPort($dsnPort)
                        ->withDbName($dsnDatabase)
                        ->withCharset('utf8mb4')
                        ->withUsername($dsnUser)
                        ->withPassword($dsnPass)
                        ->withOptions([
                            // No need to set PDO::ATTR_ERRMODE it is overwitten in PDOProxy
                            // PDO::ATTR_TIMEOUT => 3, // Seconds
                            // PDO::ATTR_PERSISTENT => true,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => true,
                            PDO::ATTR_STRINGIFY_FETCHES => true,
                            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,

                        ]),
                        $poolSize
                    );
                    break;
                case 'redis':
                    $pool = new RedisPool((new RedisConfig)
                        ->withHost($dsnHost)
                        ->withPort((int)$dsnPort)
                        ->withAuth($dsnPass)
                    , $poolSize);
                    break;

                default:
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, "Invalid scheme");
            }
            
            $pools['pools-' . $key . '-' . $name] = [
                'pool' => $pool,
                'dsn' => $dsn,
            ];
        }

        Config::setParam('pools-' . $key, $config);
    }

    return function () use ($pools): array {
        return $pools;
    };
})());

$mode = new Dependency();
$mode
    ->setName('mode')
    ->inject('request')
    ->setCallback(function (Request $request) {
        /**
         * Defines the mode for the request:
         * - 'default' => Requests for Client and Server Side
         * - 'admin' => Request from the Console on non-console projects
         */
        return $request->getParam('mode', $request->getHeader('x-appwrite-mode', APP_MODE_DEFAULT));
    });
$container->set($mode);

$user = new Dependency();
$user
    ->setName('user')
    ->inject('mode')
    ->inject('project')
    ->inject('console')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('authorization')
    ->setCallback(function (string $mode, Document $project, Document $console, Request $request, Response $response, Database $dbForProject, Database $dbForConsole, Authorization $authorization) {
        $authorization->setDefaultStatus(true);

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
                    $user = $dbForConsole->getDocument('users', Auth::$unique);
                } else {
                    $user = $dbForProject->getDocument('users', Auth::$unique);
                }
            }
        } else {
            $user = $dbForConsole->getDocument('users', Auth::$unique);
        }
    
        if (
            $user->isEmpty() // Check a document has been found in the DB
            || !Auth::sessionVerify($user->getAttribute('sessions', []), Auth::$secret)
        ) { // Validate user has valid login token
            $user = new Document([]);
        }
    
        if (APP_MODE_ADMIN === $mode) {
            if ($user->find('teamId', $project->getAttribute('teamId'), 'memberships')) {
                $authorization->setDefaultStatus(false);  // Cancel security segmentation for admin users.
            } else {
                $user = new Document([]);
            }
        }
    
        $authJWT = $request->getHeader('x-appwrite-jwt', '');
    
        if (!empty($authJWT) && !$project->isEmpty()) { // JWT authentication
            $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.
    
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
                $user = new Document([]);
            }
        }
    
        // Adds logs to database queries
        $dbForProject->setMetadata('user', $user->getId());
        $dbForConsole->setMetadata('user', $user->getId());
    
        return $user;
    });
$container->set($user);

$session = new Dependency();
$session
    ->setName('session')
    ->inject('user')
    ->inject('project')
    ->setCallback(function (Document $user, Document $project) {
        if ($user->isEmpty()) {
            return;
        }

        $sessions = $user->getAttribute('sessions', []);
        $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $sessionId = Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret, $authDuration);

        if (!$sessionId) {
            return;
        }

        foreach ($sessions as $session) {
            if ($sessionId === $session->getId()) {
                return $session;
            }
        }

        return;
    });
$container->set($session);

$console = new Dependency();
$console
    ->setName('console')
    ->setCallback(function () {
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
                    'type' => Origin::CLIENT_TYPE_WEB,
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
                'invites' => System::getEnv('_APP_CONSOLE_INVITES', 'enabled') === 'enabled',
                'limit' => (System::getEnv('_APP_CONSOLE_WHITELIST_ROOT', 'enabled') === 'enabled') ? 1 : 0, // limit signup to 1 user
                'duration' => Auth::TOKEN_EXPIRATION_LOGIN_LONG, // 1 Year in seconds
            ],
            'authWhitelistEmails' => (!empty(System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null))) ? \explode(',', System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null)) : [],
            'authWhitelistIPs' => (!empty(System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null))) ? \explode(',', System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null)) : [],
            'oAuthProviders' => [
                'githubEnabled' => true,
                'githubSecret' => System::getEnv('_APP_CONSOLE_GITHUB_SECRET', ''),
                'githubAppid' => System::getEnv('_APP_CONSOLE_GITHUB_APP_ID', '')
            ],
        ]);
    });
$container->set($console);

$project = new Dependency();
$project
    ->setName('project')
    ->inject('dbForConsole')
    ->inject('request')
    ->inject('console')
    ->inject('authorization')
    ->setCallback(function (Database $dbForConsole, Request $request, Document $console, Authorization $authorization) {
        $projectId = $request->getParam('project', $request->getHeader('x-appwrite-project', ''));

        if (empty($projectId) || $projectId === 'console') {
            return $console;
        }

        $project = $authorization->skip(fn () => $dbForConsole->getDocument('projects', $projectId));

        return $project;
    });
$container->set($project);

$pools = new Dependency();
$pools
    ->setName('pools')
    ->inject('registry')
    ->setCallback(function (Registry $registry) {
        return $registry->get('pools');
    });
$container->set($pools);

$dbForProject = new Dependency();
$dbForProject
    ->setName('dbForProject')
    ->inject('pools')
    ->inject('project')
    ->inject('cache')
    ->inject('dbForConsole')
    ->inject('connections')
    ->inject('authorization')
    ->setCallback(function(array $pools, Document $project, Cache $cache, Database $dbForConsole, Connections $connections, Authorization $authorization) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForConsole;
        }

        $pool = $pools['pools-database-'.$project->getAttribute('database')]['pool'];
        $dsn = $pools['pools-database-'.$project->getAttribute('database')]['dsn'];
    
        $connection = $pool->get();
        $connections->add($connection, $pool);
        $adapter = match ($dsn->getScheme()) {
            'mariadb' => new MariaDB($connection),
            'mysql' => new MySQL($connection),
            default => null
        };

        $adapter->setDatabase($dsn->getPath());
    
        $database = new Database($adapter, $cache);
        $database->setAuthorization($authorization);
    
        $database
            ->setNamespace('_' . $project->getInternalId())
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', $project->getId())
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS);
    
        return $database;
    });
$container->set($dbForProject);

$dbForConsole = new Dependency();
$dbForConsole
    ->setName('dbForConsole')
    ->inject('pools')
    ->inject('cache')
    ->inject('authorization')
    ->inject('connections')
    ->setCallback(function(array $pools, Cache $cache, Authorization $authorization, Connections $connections): Database {
        $pool = $pools['pools-console-main']['pool'];
        $dsn = $pools['pools-console-main']['dsn'];
        $connection = $pool->get();
        $connections->add($connection, $pool);

        $adapter = match ($dsn->getScheme()) {
            'mariadb' => new MariaDB($connection),
            'mysql' => new MySQL($connection),
            default => null
        };

        $adapter->setDatabase($dsn->getPath());

        $database = new Database($adapter, $cache);
        $database->setAuthorization($authorization);

        $database
            ->setNamespace('_console')
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', 'console')
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS);

        return $database;
    });
$container->set($dbForConsole);

$cache = new Dependency();
$cache
    ->setName('cache')
    ->setCallback(function (): Cache {
        return new Cache(new None());
    });
$container->set($cache);

$authorization = new Dependency();
$authorization
    ->setName('authorization')
    ->setCallback(function (): Authorization {
        return new Authorization();
    });
$container->set($authorization);

$registry = new Dependency();
$registry
    ->setName('registry')
    ->setCallback(function () use (&$global): Registry {
        return $global;
    });
$container->set($registry);

$pools = new Dependency();
$pools
    ->setName('pools')
    ->inject('registry')
    ->setCallback(function (Registry $registry) {
        return $registry->get('pools');
    });
$container->set($pools);

$logger = new Dependency();
$logger
    ->setName('logger')
    ->inject('registry')
    ->setCallback(function (Registry $registry) {
        return $registry->get('logger');
    });
$container->set($logger);

$log = new Dependency();
$log
    ->setName('log')
    ->setCallback(function () {
        return new Log();
    });
$container->set($log);

$connections = new Dependency();
$connections
    ->setName('connections')
    ->setCallback(function () {
        return new Connections();
    });
$container->set($connections);

$locale = new Dependency();
$locale
    ->setName('locale')
    ->setCallback(fn () => new Locale(System::getEnv('_APP_LOCALE', 'en')));
$container->set($locale);

$localeCodes = new Dependency();
$localeCodes
    ->setName('localeCodes')
    ->setCallback(fn () => array_map(fn ($locale) => $locale['code'], Config::getParam('locale-codes', [])));
$container->set($localeCodes);

$queue = new Dependency();
$queue
    ->setName('queue')
    ->inject('pools')
    ->inject('connections')
    ->setCallback(function (array $pools, Connections $connections) {
        $pool = $pools['pools-queue-main']['pool'];
        $dsn = $pools['pools-queue-main']['dsn'];
        $connection = $pool->get();
        $connections->add($connection, $pool);

        return new Queue\Connection\Redis($dsn->getHost(), $dsn->getPort());
    });
$container->set($queue);

$queueForMessaging = new Dependency();
$queueForMessaging
    ->setName('queueForMessaging')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Messaging($queue);
    });
$container->set($queueForMessaging);

$queueForMails = new Dependency();
$queueForMails
    ->setName('queueForMails')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Mail($queue);
    });
$container->set($queueForMails);

$queueForBuilds = new Dependency();
$queueForBuilds
    ->setName('queueForBuilds')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Build($queue);
    });
$container->set($queueForBuilds);

$queueForDatabase = new Dependency();
$queueForDatabase
    ->setName('queueForDatabase')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new EventDatabase($queue);
    });
$container->set($queueForDatabase);

$queueForDeletes = new Dependency();
$queueForDeletes
    ->setName('queueForDeletes')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Delete($queue);
    });
$container->set($queueForDeletes);

$queueForEvents = new Dependency();
$queueForEvents
    ->setName('queueForEvents')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Event($queue);
    });
$container->set($queueForEvents);

$queueForAudits = new Dependency();
$queueForAudits
    ->setName('queueForAudits')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Audit($queue);
    });
$container->set($queueForAudits);

$queueForFunctions = new Dependency();
$queueForFunctions
    ->setName('queueForFunctions')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Func($queue);
    });
$container->set($queueForFunctions);

$queueForUsage = new Dependency();
$queueForUsage
    ->setName('queueForUsage')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Usage($queue);
    });
$container->set($queueForUsage);

$queueForCertificates = new Dependency();
$queueForCertificates
    ->setName('queueForCertificates')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Certificate($queue);
    });
$container->set($queueForCertificates);

$queueForMigrations = new Dependency();
$queueForMigrations
    ->setName('queueForMigrations')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Migration($queue);
    });
$container->set($queueForMigrations);

$deviceForLocal = new Dependency();
$deviceForLocal
    ->setName('deviceForLocal')
    ->setCallback(function () {
        return new Local();
    });
$container->set($deviceForLocal);

$deviceForFiles = new Dependency();
$deviceForFiles
    ->setName('deviceForFiles')
    ->inject('project')
    ->setCallback(function ($project) {
        return getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
    });
$container->set($deviceForFiles);

$deviceForFunctions = new Dependency();
$deviceForFunctions
    ->setName('deviceForFunctions')
    ->inject('project')
    ->setCallback(function ($project) {
        return getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId());
    });
$container->set($deviceForFunctions);

$deviceForBuilds = new Dependency();
$deviceForBuilds
    ->setName('deviceForBuilds')
    ->inject('project')
    ->setCallback(function ($project) {
        return getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId());
    });
$container->set($deviceForBuilds);

$clients = new Dependency();
$clients
    ->setName('clients')
    ->inject('request')
    ->inject('console')
    ->inject('project')
    ->setCallback(function (Request $request, Document $console, Document $project) {
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
                fn ($node) => (isset($node['type']) && ($node['type'] === Origin::CLIENT_TYPE_WEB) && isset($node['hostname']) && !empty($node['hostname']))
            )
        );
    
        $clients = \array_unique(
            \array_merge(
                $clientsConsole,
                \array_map(
                    fn ($node) => $node['hostname'],
                    \array_filter(
                        $project->getAttribute('platforms', []),
                        fn ($node) => (isset($node['type']) && ($node['type'] === Origin::CLIENT_TYPE_WEB || $node['type'] === Origin::CLIENT_TYPE_FLUTTER_WEB) && isset($node['hostname']) && !empty($node['hostname']))
                    )
                )
            )
        );
    
        return $clients;
    });
$container->set($clients);

$servers = new Dependency();
$servers
    ->setName('servers')
    ->setCallback(function () {
        $platforms = Config::getParam('platforms');
        $server = $platforms[APP_PLATFORM_SERVER];

        $languages = array_map(function ($language) {
            return strtolower($language['name']);
        }, $server['sdks']);

        return $languages;
    });
$container->set($servers);

$geodb = new Dependency();
$geodb
    ->setName('geodb')
    ->inject('registry')
    ->setCallback(function (Registry $register) {
        return $register->get('geodb');
    });
$container->set($geodb);

$passwordsDictionary = new Dependency();
$passwordsDictionary
    ->setName('passwordsDictionary')
    ->setCallback(function () {
        $content = file_get_contents(__DIR__ . '/assets/security/10k-common-passwords');
        $content = explode("\n", $content);
        $content = array_flip($content);
        return $content;
    });

$container->set($passwordsDictionary);

$hooks = new Dependency();
$hooks
    ->setName('hooks')
    ->inject('registry')
    ->setCallback(function (Registry $registry) {
        return $registry->get('hooks');
    });

$container->set($hooks);

$requestTimestamp = new Dependency();
$requestTimestamp
    ->setName('requestTimestamp')
    ->inject('request')
    ->setCallback(function ($request) {
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
    });
$container->set($requestTimestamp);
