<?php

use Utopia\App;
use Utopia\Locale\Locale;
use Appwrite\Event\Usage;
use Appwrite\Extend\Exception;
use Appwrite\Auth\Auth;
use Appwrite\Event\Audit;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Event\Phone;
use Appwrite\Event\Delete;
use Appwrite\GraphQL\Schema;
use Appwrite\Network\Validator\Origin;
use Utopia\Config\Config;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Messaging\Adapters\SMS\Mock;
use Utopia\Messaging\Adapters\SMS\Msg91;
use Utopia\Messaging\Adapters\SMS\Telesign;
use Utopia\Messaging\Adapters\SMS\TextMagic;
use Utopia\Messaging\Adapters\SMS\Twilio;
use Utopia\Messaging\Adapters\SMS\Vonage;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Pools\Group;
use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Event\Func;
use Utopia\CLI\Console;
use Utopia\Queue\Connection;
use Utopia\Storage\Storage;

App::setMode(App::getEnv('_APP_ENV', App::MODE_TYPE_PRODUCTION));

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
App::setResource('queueForUsage', function (Connection $queue) {
    return new Usage($queue);
}, ['queue']);
App::setResource('clients', function ($request, $console, $project) {
    $console->setAttribute('platforms', [ // Always allow current host
        '$collection' => ID::custom('platforms'),
        'name' => 'Current Host',
        'type' => Origin::CLIENT_TYPE_WEB,
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
    }, $server['sdks']);

    return $languages;
});

App::setResource('promiseAdapter', function ($register) {
    return $register->get('promiseAdapter');
}, ['register']);

App::setResource('schema', function ($utopia, $dbForProject) {

    $complexity = function (int $complexity, array $args) {
        $queries = Query::parseQueries($args['queries'] ?? []);
        $query = Query::getByType($queries, Query::TYPE_LIMIT)[0] ?? null;
        $limit = $query ? $query->getValue() : APP_LIMIT_LIST_DEFAULT;

        return $complexity * $limit;
    };

    $attributes = function (int $limit, int $offset) use ($dbForProject) {
        $attrs = Authorization::skip(fn() => $dbForProject->find('attributes', [
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
