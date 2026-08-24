<?php

use Appwrite\Utopia\Database\Documents\User;
use Swoole\Coroutine;
use Swoole\Runtime;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Mqtt\Broker;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\System\System;

require_once __DIR__ . '/init.php';

if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
    require_once __DIR__ . '/init/span.php';
}

/** @var Registry $register */
$register = $GLOBALS['register'] ?? throw new \RuntimeException('Registry not initialized');

$registerMqttConnectionResources ??= require __DIR__ . '/init/mqtt/connection.php';

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

global $container;

if (!$container->has('pools')) {
    $container->set('pools', function ($register) {
        return $register->get('pools');
    }, ['register']);
}

// DB bootstrap helpers, mirroring app/realtime.php. These are process-level (pools +
// cache), not HTTP; the connection resources call them to reach the project/console DB.
if (!function_exists('getCache')) {
    function getCache(): Cache
    {
        $ctx = Coroutine::getContext();

        if (isset($ctx['cache'])) {
            return $ctx['cache'];
        }

        global $register;

        /** @var Group $pools */
        $pools = $register->get('pools');

        $adapters = [];
        foreach (Config::getParam('pools-cache', []) as $value) {
            $adapters[] = new CachePool($pools->get($value));
        }

        return $ctx['cache'] = new Cache(new Sharding($adapters));
    }
}

if (!function_exists('getConsoleDB')) {
    function getConsoleDB(): Database
    {
        $ctx = Coroutine::getContext();

        if (isset($ctx['dbForPlatform'])) {
            return $ctx['dbForPlatform'];
        }

        global $register;

        /** @var Group $pools */
        $pools = $register->get('pools');

        $adapter = new DatabasePool($pools->get('console'));
        $database = new Database($adapter, getCache());
        $database
            ->setDatabase(APP_DATABASE)
            ->setNamespace('_console')
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', '_console');
        $database->setDocumentType('users', User::class);

        return $ctx['dbForPlatform'] = $database;
    }
}

if (!function_exists('getProjectDB')) {
    function getProjectDB(Document $project): Database
    {
        $ctx = Coroutine::getContext();

        if (!isset($ctx['dbForProject'])) {
            $ctx['dbForProject'] = [];
        }

        if (isset($ctx['dbForProject'][$project->getSequence()])) {
            return $ctx['dbForProject'][$project->getSequence()];
        }

        if ($project->isEmpty() || $project->getId() === 'console') {
            return getConsoleDB();
        }

        global $register;

        /** @var Group $pools */
        $pools = $register->get('pools');

        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        $adapter = new DatabasePool($pools->get($dsn->getHost()));
        $database = new Database($adapter, getCache());

        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

        if (\in_array($dsn->getHost(), $sharedTables)) {
            $projectCollections = Config::getParam('collections', [])['projects'] ?? [];
            $globalCollections = array_keys($projectCollections);
            $globalCollections[] = 'audit';

            $database
                ->setSharedTables(true)
                ->setGlobalCollections($globalCollections)
                ->setTenant($project->getSequence())
                ->setNamespace($dsn->getParam('namespace'));
        } else {
            $database
                ->setSharedTables(false)
                ->setTenant(null)
                ->setNamespace('_' . $project->getSequence());
        }

        $database
            ->setDatabase(APP_DATABASE)
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', $project->getId());
        $database->setDocumentType('users', User::class);

        return $ctx['dbForProject'][$project->getSequence()] = $database;
    }
}

/**
 * Authenticate a CONNECT: resolve the project and verify the session in a child
 * container, using the same domain logic as HTTP/realtime. Returns the identity
 * for the connection, or an empty array when the credential is invalid.
 *
 * @return array{projectId: string, userId: string}|array{}
 */
$authenticate = function (string $projectId, string $secret) use ($container, $registerMqttConnectionResources): array {
    $connectionContainer = new Container($container);
    $connectionContainer->set('projectId', fn () => $projectId);
    $connectionContainer->set('sessionSecret', fn () => $secret);

    $registerMqttConnectionResources($connectionContainer);

    /** @var Document $project */
    $project = $connectionContainer->get('project');
    /** @var User $user */
    $user = $connectionContainer->get('user');

    if ($project->isEmpty() || $user->isEmpty()) {
        return [];
    }

    return [
        'projectId' => $project->getId(),
        'userId' => $user->getId(),
    ];
};

$broker = new Broker(host: '0.0.0.0', port: 1883);
$broker->onConnect($authenticate);
$broker->start();
