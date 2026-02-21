<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Auth\Key;
use Appwrite\Databases\TransactionState;
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
use Appwrite\Event\StatsResources;
use Appwrite\Event\StatsUsage;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\GraphQL\Schema;
use Appwrite\Network\Cors;
use Appwrite\Network\Platform;
use Appwrite\Network\Validator\Origin;
use Appwrite\Network\Validator\Redirect;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Executor\Executor;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\App;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Code;
use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Locale\Locale;
use Utopia\Logger\Log;
use Utopia\Pools\Group;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Publisher;
use Utopia\Storage\Device;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Storage;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub as VcsGitHub;

// Runtime Execution
App::setResource('log', fn () => new Log());
App::setResource('logger', function ($register) {
    return $register->get('logger');
}, ['register']);

App::setResource('hooks', function ($register) {
    return $register->get('hooks');
}, ['register']);

App::setResource('register', fn () => $register);
App::setResource('locale', function () {
    $locale = new Locale(System::getEnv('_APP_LOCALE', 'en'));
    $locale->setFallback(System::getEnv('_APP_LOCALE', 'en'));
    return $locale;
});

App::setResource('localeCodes', function () {
    return array_map(fn ($locale) => $locale['code'], Config::getParam('locale-codes', []));
});

// Queues
App::setResource('publisher', function (Group $pools) {
    return new BrokerPool(publisher: $pools->get('publisher'));
}, ['pools']);
App::setResource('publisherDatabases', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
App::setResource('publisherFunctions', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
App::setResource('publisherMigrations', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
App::setResource('publisherStatsUsage', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
App::setResource('publisherMails', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
App::setResource('publisherDeletes', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
App::setResource('publisherMessaging', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
App::setResource('publisherWebhooks', function (Publisher $publisher) {
    return $publisher;
}, ['publisher']);
App::setResource('queueForMessaging', function (Publisher $publisher) {
    return new Messaging($publisher);
}, ['publisher']);
App::setResource('queueForMails', function (Publisher $publisher) {
    return new Mail($publisher);
}, ['publisher']);
App::setResource('queueForBuilds', function (Publisher $publisher) {
    return new Build($publisher);
}, ['publisher']);
App::setResource('queueForDatabase', function (Publisher $publisher) {
    return new EventDatabase($publisher);
}, ['publisher']);
App::setResource('queueForDeletes', function (Publisher $publisher) {
    return new Delete($publisher);
}, ['publisher']);
App::setResource('queueForEvents', function (Publisher $publisher) {
    return new Event($publisher);
}, ['publisher']);
App::setResource('queueForWebhooks', function (Publisher $publisher) {
    return new Webhook($publisher);
}, ['publisher']);
App::setResource('queueForRealtime', function () {
    return new Realtime();
}, []);
App::setResource('queueForStatsUsage', function (Publisher $publisher) {
    return new StatsUsage($publisher);
}, ['publisher']);
App::setResource('queueForAudits', function (Publisher $publisher) {
    return new Audit($publisher);
}, ['publisher']);
App::setResource('queueForFunctions', function (Publisher $publisher) {
    return new Func($publisher);
}, ['publisher']);
App::setResource('queueForCertificates', function (Publisher $publisher) {
    return new Certificate($publisher);
}, ['publisher']);
App::setResource('queueForMigrations', function (Publisher $publisher) {
    return new Migration($publisher);
}, ['publisher']);
App::setResource('queueForStatsResources', function (Publisher $publisher) {
    return new StatsResources($publisher);
}, ['publisher']);

/**
 * Platform configuration
 */
App::setResource('platform', function () {
    return Config::getParam('platform', []);
}, []);

/**
 * List of allowed request hostnames for the request.
 */
App::setResource('allowedHostnames', function (array $platform, Document $project, Document $rule, Document $devKey, Request $request) {
    $allowed = [...($platform['hostnames'] ?? [])];

    /* Add platform configured hostnames */
    if (!$project->isEmpty() && $project->getId() !== 'console') {
        $platforms = $project->getAttribute('platforms', []);
        $hostnames = Platform::getHostnames($platforms);
        $allowed = [...$allowed, ...$hostnames];
    }

    /* Add the request hostname if a dev key is found */
    if (!$devKey->isEmpty()) {
        $allowed[] = $request->getHostname();
    }

    $originHostname = parse_url($request->getOrigin(), PHP_URL_HOST);

    /* Add request hostname for preflight requests */
    if ($request->getMethod() === 'OPTIONS') {
        $allowed[] = $originHostname;
    }

    /* Allow the request origin if a dev key or rule is found */
    if ((!$rule->isEmpty() || !$devKey->isEmpty()) && !empty($originHostname)) {
        $allowed[] = $originHostname;
    }

    return array_unique($allowed);
}, ['platform', 'project', 'rule', 'devKey', 'request']);

/**
 * List of allowed request schemes for the request.
 */
App::setResource('allowedSchemes', function (Document $project) {
    $allowed = [];

    if (!$project->isEmpty() && $project->getId() !== 'console') {
        /* Add hardcoded schemes */
        $allowed[] = 'exp';
        $allowed[] = 'appwrite-callback-' . $project->getId();

        /* Add platform configured schemes */
        $platforms = $project->getAttribute('platforms', []);
        $schemes = Platform::getSchemes($platforms);
        $allowed = [...$allowed, ...$schemes];
    }

    return array_unique($allowed);
}, ['project']);

/**
 * Rule associated with a request origin.
 */
App::setResource('rule', function (Request $request, Database $dbForPlatform, Document $project, Authorization $authorization) {
    $domain = \parse_url($request->getOrigin(), PHP_URL_HOST);
    if (empty($domain)) {
        return new Document();
    }

    // TODO: (@Meldiron) Remove after 1.7.x migration
    $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
    $rule = $authorization->skip(function () use ($dbForPlatform, $domain, $isMd5) {
        if ($isMd5) {
            return $dbForPlatform->getDocument('rules', md5($domain));
        }

        return $dbForPlatform->findOne('rules', [
            Query::equal('domain', [$domain]),
        ]) ?? new Document();
    });

    if ($rule->getAttribute('projectInternalId') !== $project->getSequence()) {
        return new Document();
    }

    return $rule;
}, ['request', 'dbForPlatform', 'project', 'authorization']);

/**
 * CORS service
 */
App::setResource('cors', fn (array $allowedHostnames) => new Cors(
    $allowedHostnames,
    allowedMethods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    allowedHeaders: [
        'Accept',
        'Origin',
        'Cookie',
        'Set-Cookie',
        // Content
        'Content-Type',
        'Content-Range',
        // Appwrite
        'X-Appwrite-Project',
        'X-Appwrite-Key',
        'X-Appwrite-Dev-Key',
        'X-Appwrite-Locale',
        'X-Appwrite-Mode',
        'X-Appwrite-JWT',
        'X-Appwrite-Response-Format',
        'X-Appwrite-Timeout',
        'X-Appwrite-ID',
        'X-Appwrite-Timestamp',
        'X-Appwrite-Session',
        // SDK generator
        'X-SDK-Version',
        'X-SDK-Name',
        'X-SDK-Language',
        'X-SDK-Platform',
        'X-SDK-GraphQL',
        'X-SDK-Profile',
        // Caching
        'Range',
        'Cache-Control',
        'Expires',
        'Pragma',
        // Server to server
        'X-Fallback-Cookies',
        'X-Requested-With',
        'X-Forwarded-For',
        'X-Forwarded-User-Agent',
    ],
    allowCredentials: true,
    exposedHeaders: [
        'X-Appwrite-Session',
        'X-Fallback-Cookies',
    ],
), ['allowedHostnames']);

App::setResource('originValidator', function (Document $devKey, array $allowedHostnames, array $allowedSchemes) {
    if (!$devKey->isEmpty()) {
        return new URL();
    }
    return new Origin($allowedHostnames, $allowedSchemes);
}, ['devKey', 'allowedHostnames', 'allowedSchemes']);

App::setResource('redirectValidator', function (Document $devKey, array $allowedHostnames, array $allowedSchemes) {
    if (!$devKey->isEmpty()) {
        return new URL();
    }
    return new Redirect($allowedHostnames, $allowedSchemes);
}, ['devKey', 'allowedHostnames', 'allowedSchemes']);

App::setResource('user', function (string $mode, Document $project, Document $console, Request $request, Response $response, Database $dbForProject, Database $dbForPlatform, Store $store, Token $proofForToken, $authorization) {
    /**
     * Handles user authentication and session validation.
     *
     * This function follows a series of steps to determine the appropriate user session
     * based on cookies, headers, and JWT tokens.
     *
     * Process:
     * 1. Checks the cookie based on mode:
     *    - If in admin mode, uses console project id for key.
     *    - Otherwise, sets the key using the project ID
     * 2. If no cookie is found, attempts to retrieve the fallback header `x-fallback-cookies`.
     *    - If this method is used, returns the header: `X-Debug-Fallback: true`.
     * 3. Fetches the user document from the appropriate database based on the mode.
     * 4. If the user document is empty or the session key cannot be verified, sets an empty user document.
     * 5. Regardless of the results from steps 1-4, attempts to fetch the JWT token.
     * 6. If the JWT user has a valid session ID, updates the user variable with the user from `projectDB`,
     *    overwriting the previous value.
     */

    $authorization->setDefaultStatus(true);

    $store->setKey('a_session_' . $project->getId());

    if (APP_MODE_ADMIN === $mode) {
        $store->setKey('a_session_' . $console->getId());
    }

    $store->decode(
        $request->getCookie(
            $store->getKey(), // Get sessions
            $request->getCookie($store->getKey() . '_legacy', '')
        )
    );

    // Get session from header for SSR clients
    if (empty($store->getProperty('id', '')) && empty($store->getProperty('secret', ''))) {
        $sessionHeader = $request->getHeader('x-appwrite-session', '');

        if (!empty($sessionHeader)) {
            $store->decode($sessionHeader);
        }
    }

    // Get fallback session from old clients (no SameSite support) or clients who block 3rd-party cookies
    if ($response) { // if in http context - add debug header
        $response->addHeader('X-Debug-Fallback', 'false');
    }

    if (empty($store->getProperty('id', '')) && empty($store->getProperty('secret', ''))) {
        if ($response) {
            $response->addHeader('X-Debug-Fallback', 'true');
        }
        $fallback = $request->getHeader('x-fallback-cookies', '');
        $fallback = \json_decode($fallback, true);
        $store->decode(((is_array($fallback) && isset($fallback[$store->getKey()])) ? $fallback[$store->getKey()] : ''));
    }

    $user = null;
    if (APP_MODE_ADMIN === $mode) {
        /** @var User $user */
        $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
    } else {
        if ($project->isEmpty()) {
            $user = new User([]);
        } else {
            if (!empty($store->getProperty('id', ''))) {
                if ($project->getId() === 'console') {
                    /** @var User $user */
                    $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
                } else {
                    /** @var User $user */
                    $user = $dbForProject->getDocument('users', $store->getProperty('id', ''));
                }
            }
        }
    }

    if (
        !$user ||
        $user->isEmpty() // Check a document has been found in the DB
        || !$user->sessionVerify($store->getProperty('secret', ''), $proofForToken)
    ) { // Validate user has valid login token
        $user = new User([]);
    }
    // if (APP_MODE_ADMIN === $mode) {
    //     if ($user->find('teamInternalId', $project->getAttribute('teamInternalId'), 'memberships')) {
    //         $authorization->setDefaultStatus(false);  // Cancel security segmentation for admin users.
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
            if ($mode === APP_MODE_ADMIN) {
                $user = $dbForPlatform->getDocument('users', $jwtUserId);
            } else {
                $user = $dbForProject->getDocument('users', $jwtUserId);
            }
        }
        $jwtSessionId = $payload['sessionId'] ?? '';
        if (!empty($jwtSessionId)) {
            if (empty($user->find('$id', $jwtSessionId, 'sessions'))) { // Match JWT to active token
                $user = new User([]);
            }
        }
    }
    $dbForProject->setMetadata('user', $user->getId());
    $dbForPlatform->setMetadata('user', $user->getId());

    return $user;
}, ['mode', 'project', 'console', 'request', 'response', 'dbForProject', 'dbForPlatform', 'store', 'proofForToken', 'authorization']);

App::setResource('project', function ($dbForPlatform, $request, $console, $authorization) {
    /** @var Appwrite\Utopia\Request $request */
    /** @var Utopia\Database\Database $dbForPlatform */
    /** @var Utopia\Database\Document $console */

    $projectId = $request->getParam('project', $request->getHeader('x-appwrite-project', ''));

    if (empty($projectId) || $projectId === 'console') {
        return $console;
    }

    $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

    return $project;
}, ['dbForPlatform', 'request', 'console', 'authorization']);

App::setResource('session', function (User $user, Store $store, Token $proofForToken) {
    if ($user->isEmpty()) {
        return;
    }

    $sessions = $user->getAttribute('sessions', []);
    $sessionId = $user->sessionVerify($store->getProperty('secret', ''), $proofForToken);

    if (!$sessionId) {
        return;
    }
    foreach ($sessions as $session) {
        /** @var Document $session */
        if ($sessionId === $session->getId()) {
            return $session;
        }
    }

    return;
}, ['user', 'store', 'proofForToken']);

App::setResource('store', function (): Store {
    return new Store();
});

App::setResource('proofForPassword', function (): Password {
    $hash = new Argon2();
    $hash
        ->setMemoryCost(7168)
        ->setTimeCost(5)
        ->setThreads(1);

    $password = new Password();
    $password
        ->setHash($hash);

    return $password;
});

App::setResource('proofForToken', function (): Token {
    $token = new Token();
    $token->setHash(new Sha());
    return $token;
});

App::setResource('proofForCode', function (): Code {
    $code = new Code();
    $code->setHash(new Sha());
    return $code;
});

App::setResource('console', function () {
    return new Document(Config::getParam('console'));
}, []);

App::setResource('authorization', function () {
    return new Authorization();
}, []);

App::setResource('dbForProject', function (Group $pools, Database $dbForPlatform, Cache $cache, Document $project, Authorization $authorization) {
    if ($project->isEmpty() || $project->getId() === 'console') {
        return $dbForPlatform;
    }

    try {
        $dsn = new DSN($project->getAttribute('database'));
    } catch (\InvalidArgumentException) {
        // TODO: Temporary until all projects are using shared tables
        $dsn = new DSN('mysql://' . $project->getAttribute('database'));
    }

    $adapter = new DatabasePool($pools->get($dsn->getHost()));
    $database = new Database($adapter, $cache);

    $database
        ->setAuthorization($authorization)
        ->setMetadata('host', \gethostname())
        ->setMetadata('project', $project->getId())
        ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
        ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);
    $database->setDocumentType('users', User::class);

    $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

    if (\in_array($dsn->getHost(), $sharedTables)) {
        $database
            ->setSharedTables(true)
            ->setTenant((int) $project->getSequence())
            ->setNamespace($dsn->getParam('namespace'));
    } else {
        $database
            ->setSharedTables(false)
            ->setTenant(null)
            ->setNamespace('_' . $project->getSequence());
    }

    return $database;
}, ['pools', 'dbForPlatform', 'cache', 'project', 'authorization']);

App::setResource('dbForPlatform', function (Group $pools, Cache $cache, Authorization $authorization) {

    $adapter = new DatabasePool($pools->get('console'));
    $database = new Database($adapter, $cache);

    $database
        ->setAuthorization($authorization)
        ->setNamespace('_console')
        ->setMetadata('host', \gethostname())
        ->setMetadata('project', 'console')
        ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
        ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

    $database->setDocumentType('users', User::class);

    return $database;
}, ['pools', 'cache', 'authorization']);

App::setResource('getProjectDB', function (Group $pools, Database $dbForPlatform, $cache, Authorization $authorization) {
    $databases = [];

    return function (Document $project) use ($pools, $dbForPlatform, $cache, $authorization, &$databases) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForPlatform;
        }

        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        $configure = (function (Database $database) use ($project, $dsn, $authorization) {
            $database
                ->setAuthorization($authorization)
                ->setMetadata('host', \gethostname())
                ->setMetadata('project', $project->getId())
                ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
                ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES)
                ->setDocumentType('users', User::class)
            ;

            $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

            if (\in_array($dsn->getHost(), $sharedTables)) {
                $database
                    ->setSharedTables(true)
                    ->setTenant((int) $project->getSequence())
                    ->setNamespace($dsn->getParam('namespace'));
            } else {
                $database
                    ->setSharedTables(false)
                    ->setTenant(null)
                    ->setNamespace('_' . $project->getSequence());
            }
        });

        if (isset($databases[$dsn->getHost()])) {
            $database = $databases[$dsn->getHost()];
            $configure($database);
            return $database;
        }

        $adapter = new DatabasePool($pools->get($dsn->getHost()));
        $database = new Database($adapter, $cache);
        $databases[$dsn->getHost()] = $database;
        $configure($database);

        return $database;
    };
}, ['pools', 'dbForPlatform', 'cache', 'authorization']);

App::setResource('getLogsDB', function (Group $pools, Cache $cache, Authorization $authorization) {
    $database = null;

    return function (?Document $project = null) use ($pools, $cache, $authorization, &$database) {
        if ($database !== null && $project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant((int) $project->getSequence());
            return $database;
        }

        $adapter = new DatabasePool($pools->get('logs'));
        $database = new Database($adapter, $cache);

        $database
            ->setAuthorization($authorization)
            ->setSharedTables(true)
            ->setNamespace('logsV1')
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
            ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

        // set tenant
        if ($project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant((int) $project->getSequence());
        }

        return $database;
    };
}, ['pools', 'cache', 'authorization']);

App::setResource('telemetry', fn () => new NoTelemetry());

App::setResource('cache', function (Group $pools, Telemetry $telemetry) {
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = new CachePool($pools->get($value));
    }

    $cache = new Cache(new Sharding($adapters));
    $cache->setTelemetry($telemetry);
    return $cache;
}, ['pools', 'telemetry']);

App::setResource('redis', function () {
    $host = System::getEnv('_APP_REDIS_HOST', 'localhost');
    $port = System::getEnv('_APP_REDIS_PORT', 6379);
    $pass = System::getEnv('_APP_REDIS_PASS', '');

    $redis = new \Redis();
    @$redis->pconnect($host, (int) $port);
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

App::setResource('deviceForLocal', function (Telemetry $telemetry) {
    return new Device\Telemetry($telemetry, new Local());
}, ['telemetry']);
App::setResource('deviceForFiles', function ($project, Telemetry $telemetry) {
    return new Device\Telemetry($telemetry, getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId()));
}, ['project', 'telemetry']);
App::setResource('deviceForSites', function ($project, Telemetry $telemetry) {
    return new Device\Telemetry($telemetry, getDevice(APP_STORAGE_SITES . '/app-' . $project->getId()));
}, ['project', 'telemetry']);
App::setResource('deviceForMigrations', function ($project, Telemetry $telemetry) {
    return new Device\Telemetry($telemetry, getDevice(APP_STORAGE_IMPORTS . '/app-' . $project->getId()));
}, ['project', 'telemetry']);
App::setResource('deviceForFunctions', function ($project, Telemetry $telemetry) {
    return new Device\Telemetry($telemetry, getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId()));
}, ['project', 'telemetry']);
App::setResource('deviceForBuilds', function ($project, Telemetry $telemetry) {
    return new Device\Telemetry($telemetry, getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId()));
}, ['project', 'telemetry']);

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
        $url = System::getEnv('_APP_STORAGE_S3_ENDPOINT', '');

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
                if (!empty($url)) {
                    $bucketRoot = (!empty($bucket) ? $bucket . '/' : '') . \ltrim($root, '/');
                    return new S3($bucketRoot, $accessKey, $accessSecret, $url, $region, $acl);
                } else {
                    return new AWS($root, $accessKey, $accessSecret, $bucket, $region, $acl);
                }
                // no break
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
                $s3EndpointUrl = System::getEnv('_APP_STORAGE_S3_ENDPOINT', '');
                if (!empty($s3EndpointUrl)) {
                    $bucketRoot = (!empty($s3Bucket) ? $s3Bucket . '/' : '') . \ltrim($root, '/');
                    return new S3($bucketRoot, $s3AccessKey, $s3SecretKey, $s3EndpointUrl, $s3Region, $s3Acl);
                } else {
                    return new AWS($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
                }
                // no break
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
    $platforms = Config::getParam('sdks');
    $server = $platforms[APP_SDK_PLATFORM_SERVER];

    $languages = array_map(function ($language) {
        return strtolower($language['name']);
    }, $server['sdks']);

    return $languages;
});

App::setResource('promiseAdapter', function ($register) {
    return $register->get('promiseAdapter');
}, ['register']);

App::setResource('schema', function ($utopia, $dbForProject, $authorization) {

    $complexity = function (int $complexity, array $args) {
        $queries = Query::parseQueries($args['queries'] ?? []);
        $query = Query::getByType($queries, [Query::TYPE_LIMIT])[0] ?? null;
        $limit = $query ? $query->getValue() : APP_LIMIT_LIST_DEFAULT;

        return $complexity * $limit;
    };

    $attributes = function (int $limit, int $offset) use ($dbForProject, $authorization) {
        $attrs = $authorization->skip(fn () => $dbForProject->find('attributes', [
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

    // NOTE: `params` and `urls` are not used internally in the `Schema::build` function below!
    $params = [
        'list' => function (string $databaseId, string $collectionId, array $args) {
            return ['queries' => $args['queries']];
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
}, ['utopia', 'dbForProject', 'authorization']);

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

App::setResource('devKey', function (Request $request, Document $project, array $servers, Database $dbForPlatform, Authorization $authorization) {
    $devKey = $request->getHeader('x-appwrite-dev-key', $request->getParam('devKey', ''));

    // Check if given key match project's development keys
    $key = $project->find('secret', $devKey, 'devKeys');
    if (!$key) {
        return new Document([]);
    }

    // check expiration
    $expire = $key->getAttribute('expire');
    if (!empty($expire) && $expire < DatabaseDateTime::formatTz(DatabaseDateTime::now())) {
        return new Document([]);
    }

    // update access time
    $accessedAt = $key->getAttribute('accessedAt', 0);
    if (empty($accessedAt) || DatabaseDateTime::formatTz(DatabaseDateTime::addSeconds(new \DateTime(), -APP_KEY_ACCESS)) > $accessedAt) {
        $key->setAttribute('accessedAt', DatabaseDateTime::now());
        $authorization->skip(fn () => $dbForPlatform->updateDocument('devKeys', $key->getId(), $key));
        $dbForPlatform->purgeCachedDocument('projects', $project->getId());
    }

    // add sdk to key
    $sdkValidator = new WhiteList($servers, true);
    $sdk = \strtolower($request->getHeader('x-sdk-name', 'UNKNOWN'));

    if ($sdk !== 'UNKNOWN' && $sdkValidator->isValid($sdk)) {
        $sdks = $key->getAttribute('sdks', []);

        if (!in_array($sdk, $sdks)) {
            $sdks[] = $sdk;
            $key->setAttribute('sdks', $sdks);

            /** Update access time as well */
            $key->setAttribute('accessedAt', DatabaseDateTime::now());
            $key = $authorization->skip(fn () => $dbForPlatform->updateDocument('devKeys', $key->getId(), $key));
            $dbForPlatform->purgeCachedDocument('projects', $project->getId());
        }
    }

    return $key;
}, ['request', 'project', 'servers', 'dbForPlatform', 'authorization']);

App::setResource('team', function (Document $project, Database $dbForPlatform, App $utopia, Request $request, Authorization $authorization) {
    $teamInternalId = '';
    if ($project->getId() !== 'console') {
        $teamInternalId = $project->getAttribute('teamInternalId', '');
    } else {
        $route = $utopia->match($request);
        $path = $route->getPath();
        if (str_starts_with($path, '/v1/projects/:projectId')) {
            $uri = $request->getURI();
            $pid = explode('/', $uri)[3];
            $p = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $pid));
            $teamInternalId = $p->getAttribute('teamInternalId', '');
        } elseif ($path === '/v1/projects') {
            $teamId = $request->getParam('teamId', '');

            if (empty($teamId)) {
                return new Document([]);
            }

            $team = $authorization->skip(fn () => $dbForPlatform->getDocument('teams', $teamId));
            return $team;
        }
    }

    if (empty($teamInternalId)) {
        return new Document([]);
    }

    $team = $authorization->skip(function () use ($dbForPlatform, $teamInternalId) {
        return $dbForPlatform->findOne('teams', [
            Query::equal('$sequence', [$teamInternalId]),
        ]);
    });

    return $team;
}, ['project', 'dbForPlatform', 'utopia', 'request', 'authorization']);

App::setResource(
    'isResourceBlocked',
    fn () => fn (Document $project, string $resourceType, ?string $resourceId) => false
);

App::setResource('previewHostname', function (Request $request, ?Key $apiKey) {
    $allowed = false;

    if (App::isDevelopment()) {
        $allowed = true;
    } elseif (!\is_null($apiKey) && $apiKey->getHostnameOverride() === true) {
        $allowed = true;
    }

    if ($allowed) {
        $host = $request->getQuery('appwrite-hostname', $request->getHeader('x-appwrite-hostname', '')) ?? '';
        if (!empty($host)) {
            return $host;
        }
    }

    return '';
}, ['request', 'apiKey']);

App::setResource('apiKey', function (Request $request, Document $project): ?Key {
    $key = $request->getHeader('x-appwrite-key');

    if (empty($key)) {
        return null;
    }

    return Key::decode($project, $key);
}, ['request', 'project']);

App::setResource('executor', fn () => new Executor());

App::setResource('resourceToken', function ($project, $dbForProject, $request, Authorization $authorization) {
    $tokenJWT = $request->getParam('token');

    if (!empty($tokenJWT) && !$project->isEmpty()) { // JWT authentication
        // Use a large but reasonable maxAge to avoid auto-exp when token has no expiry
        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), RESOURCE_TOKEN_ALGORITHM, RESOURCE_TOKEN_MAX_AGE, RESOURCE_TOKEN_LEEWAY); // Instantiate with key, algo, maxAge and leeway.

        try {
            $payload = $jwt->decode($tokenJWT);
        } catch (JWTException $error) {
            return new Document([]);
        }

        $tokenId = $payload['tokenId'] ?? '';
        if (empty($tokenId)) {
            return new Document([]);
        }

        $token = $authorization->skip(fn () => $dbForProject->getDocument('resourceTokens', $tokenId));

        if ($token->isEmpty()) {
            return new Document([]);
        }

        $expiry = $token->getAttribute('expire');

        if ($expiry !== null) {
            $now = new \DateTime();
            $expiryDate = new \DateTime($expiry);

            if ($expiryDate < $now) {
                return new Document([]);
            }
        }

        return match ($token->getAttribute('resourceType')) {
            TOKENS_RESOURCE_TYPE_FILES => (function () use ($token, $dbForProject, $authorization) {
                $sequences = explode(':', $token->getAttribute('resourceInternalId'));
                $ids = explode(':', $token->getAttribute('resourceId'));

                if (count($sequences) !== 2 || count($ids) !== 2) {
                    return new Document([]);
                }

                $accessedAt = $token->getAttribute('accessedAt', 0);
                if (empty($accessedAt) || DatabaseDateTime::formatTz(DatabaseDateTime::addSeconds(new \DateTime(), -APP_RESOURCE_TOKEN_ACCESS)) > $accessedAt) {
                    $token->setAttribute('accessedAt', DatabaseDateTime::now());
                    $authorization->skip(fn () => $dbForProject->updateDocument('resourceTokens', $token->getId(), $token));
                }

                return new Document([
                    'bucketId' => $ids[0],
                    'fileId' => $ids[1],
                    'bucketInternalId' => $sequences[0],
                    'fileInternalId' => $sequences[1],
                ]);
            })(),

            default => throw new Exception(Exception::TOKEN_RESOURCE_TYPE_INVALID),
        };
    }
    return new Document([]);
}, ['project', 'dbForProject', 'request', 'authorization']);

App::setResource('transactionState', function (Database $dbForProject, Authorization $authorization) {
    return new TransactionState($dbForProject, $authorization);
}, ['dbForProject', 'authorization']);
