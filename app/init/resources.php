<?php

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
use Appwrite\GraphQL\Schema;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Request;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\App;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Locale\Locale;
use Utopia\Logger\Log;
use Utopia\Pools\Group;
use Utopia\Queue\Publisher;
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
use Utopia\VCS\Adapter\Git\GitHub as VcsGitHub;
use Utopia\Auth\Store;
use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Proofs\Code;

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

App::setResource('user', function ($mode, $project, $console, $request, $response, $dbForProject, $dbForPlatform, Store $store) {
    /** @var Appwrite\Utopia\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Utopia\Database\Document $project */
    /** @var Utopia\Database\Database $dbForProject */
    /** @var Utopia\Database\Database $dbForPlatform */
    /** @var string $mode */
    /** @var Utopia\Auth\Store $store */

    /**
     * Handles user authentication and session validation.
     *
     * This function follows a series of steps to determine the appropriate user session
     * based on cookies, headers, and JWT tokens.
     *
     * Process:
     * 1. Checks the cookie based on mode:
     *    - If in admin mode, redirects to the console.
     *    - Otherwise, retrieves the project ID from the cookie.
     * 2. If no cookie is found, attempts to retrieve the fallback header `x-fallback-cookies`.
     *    - If this method is used, returns the header: `X-Debug-Fallback: true`.
     * 3. Fetches the user document from the appropriate database based on the mode.
     * 4. If the user document is empty or the session key cannot be verified, sets an empty user document.
     * 5. Regardless of the results from steps 1-4, attempts to fetch the JWT token.
     * 6. If the JWT user has a valid session ID, updates the user variable with the user from `projectDB`,
     *    overwriting the previous value.
     */

    Authorization::setDefaultStatus(true);

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
        $store->decode(((isset($fallback[$store->getKey()])) ? $fallback[$store->getKey()] : ''));
    }

    if (APP_MODE_ADMIN !== $mode) {
        if ($project->isEmpty()) {
            $user = new Document([]);
        } else {
            if ($project->getId() === 'console') {
                $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
            } else {
                $user = $dbForProject->getDocument('users', $store->getProperty('id', ''));
            }
        }
    } else {
        $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
    }

    if (
        $user->isEmpty() // Check a document has been found in the DB
        || !Auth::sessionVerify($user->getAttribute('sessions', []), $store->getProperty('secret', ''))
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
}, ['mode', 'project', 'console', 'request', 'response', 'dbForProject', 'dbForPlatform', 'store']);

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

App::setResource('session', function (Document $user, Store $store) {
    if ($user->isEmpty()) {
        return;
    }

    $sessions = $user->getAttribute('sessions', []);
    $sessionId = Auth::sessionVerify($user->getAttribute('sessions'), $store->getProperty('secret', ''));

    if (!$sessionId) {
        return;
    }

    foreach ($sessions as $session) {/** @var Document $session */
        if ($sessionId === $session->getId()) {
            return $session;
        }
    }

    return;
}, ['user', 'store']);

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
        ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
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
        ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
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
                ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
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
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_API)
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

App::setResource('deviceForSites', function ($project) {
    return getDevice(APP_STORAGE_SITES . '/app-' . $project->getId());
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
                $s3EndpointUrl = System::getEnv('_APP_STORAGE_S3_ENDPOINT', '');
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


App::setResource('store', function (): Store {
    return new Store();
});

App::setResource('proofForPassword', function (): Password {
    return new Password();
});

App::setResource('proofForToken', function (): Token {
    return new Token();
});

App::setResource('proofForCode', function (): Code {
    return new Code();
});
