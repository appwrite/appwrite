<?php

/**
 * HTTP init
 *
 * Initializes config for HTTP services
 */

require_once(__DIR__ . "/app.php");

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
use Appwrite\Event\Realtime;
use Appwrite\Event\Usage;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\GraphQL\Schema;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Request;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\App;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\DSN\DSN;
use Utopia\Locale\Locale;
use Utopia\Logger\Log;
use Utopia\Pools\Group;
use Utopia\Queue\Connection;
use Utopia\Storage\Device\Local;
use Utopia\System\System;
use Utopia\Validator\Hostname;
use Utopia\VCS\Adapter\Git\GitHub as VcsGitHub;

if (!App::isProduction()) {
    // Allow specific domains to skip public domain validation in dev environment
    // Useful for existing tests involving webhooks
    PublicDomain::allow(['request-catcher']);
}


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
App::setResource('queue', function (Group $pools) {
    return $pools->get('queue')->pop()->getResource();
}, ['pools']);
App::setResource('queueForMessaging', function (Connection $queue) {
    return new Messaging($queue);
}, ['queue']);
App::setResource('queueForMails', function (Connection $queue) {
    return new Mail($queue);
}, ['queue']);
App::setResource('queueForBuilds', function (Connection $queue) {
    return new Build($queue);
}, ['queue']);
App::setResource('queueForDatabase', function (Connection $queue) {
    return new EventDatabase($queue);
}, ['queue']);
App::setResource('queueForDeletes', function (Connection $queue) {
    return new Delete($queue);
}, ['queue']);
App::setResource('queueForEvents', function (Connection $queue) {
    return new Event($queue);
}, ['queue']);
App::setResource('queueForWebhooks', function (Connection $queue) {
    return new Webhook($queue);
}, ['queue']);
App::setResource('queueForRealtime', function () {
    return new Realtime();
}, []);
App::setResource('queueForAudits', function (Connection $queue) {
    return new Audit($queue);
}, ['queue']);
App::setResource('queueForFunctions', function (Connection $queue) {
    return new Func($queue);
}, ['queue']);
App::setResource('queueForUsage', function (Connection $queue) {
    return new Usage($queue);
}, ['queue']);
App::setResource('queueForCertificates', function (Connection $queue) {
    return new Certificate($queue);
}, ['queue']);
App::setResource('queueForMigrations', function (Connection $queue) {
    return new Migration($queue);
}, ['queue']);
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
    return new Document([
        '$id' => ID::custom('console'),
        '$internalId' => ID::custom('console'),
        'name' => 'Appwrite',
        '$collection' => ID::custom('projects'),
        'description' => 'Appwrite core engine',
        'logo' => '',
        'teamId' => null,
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
            'mockNumbers' => [],
            'invites' => System::getEnv('_APP_CONSOLE_INVITES', 'enabled') === 'enabled',
            'limit' => (System::getEnv('_APP_CONSOLE_WHITELIST_ROOT', 'enabled') === 'enabled') ? 1 : 0, // limit signup to 1 user
            'duration' => Auth::TOKEN_EXPIRATION_LOGIN_LONG, // 1 Year in seconds
            'sessionAlerts' => System::getEnv('_APP_CONSOLE_SESSION_ALERTS', 'disabled') === 'enabled'
        ],
        'authWhitelistEmails' => (!empty(System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null))) ? \explode(',', System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null)) : [],
        'authWhitelistIPs' => (!empty(System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null))) ? \explode(',', System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null)) : [],
        'oAuthProviders' => [
            'githubEnabled' => true,
            'githubSecret' => System::getEnv('_APP_CONSOLE_GITHUB_SECRET', ''),
            'githubAppid' => System::getEnv('_APP_CONSOLE_GITHUB_APP_ID', '')
        ],
    ]);
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
