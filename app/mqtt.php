<?php

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Handler;
use Appwrite\PubSub\Adapter\Pool as PubSubPool;
use Appwrite\Utopia\Database\Documents\User;
use Swoole\Coroutine;
use Swoole\Runtime;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Server;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\Span\Span;
use Utopia\System\System;

require_once __DIR__ . '/init.php';

require_once __DIR__ . '/init/span.php';

/** @var Registry $register */
$register = $GLOBALS['register'] ?? throw new \RuntimeException('Registry not initialized');

$registerConnectionResources ??= require __DIR__ . '/init/mqtt/connection.php';

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

global $container;

if (!$container->has('pools')) {
    $container->set('pools', function ($register) {
        return $register->get('pools');
    }, ['register']);
}

$container->set('getCache', fn () => function () use ($register): Cache {
    $ctx = Coroutine::getContext();

    if (isset($ctx['cache'])) {
        return $ctx['cache'];
    }

    /** @var Group $pools */
    $pools = $register->get('pools');

    $adapters = [];
    foreach (Config::getParam('pools-cache', []) as $value) {
        $adapters[] = new CachePool($pools->get($value));
    }

    return $ctx['cache'] = new Cache(new Sharding($adapters));
}, []);

$container->set('getConsoleDB', fn () => function () use ($register, $container): Database {
    $ctx = Coroutine::getContext();

    if (isset($ctx['dbForPlatform'])) {
        return $ctx['dbForPlatform'];
    }

    $getCache = $container->get('getCache');

    /** @var Group $pools */
    $pools = $register->get('pools');

    $adapter = new DatabasePool($pools->get('console'));
    $database = new Database($adapter, $getCache());
    $database
        ->setDatabase(APP_DATABASE)
        ->setNamespace('_console')
        ->setMetadata('host', \gethostname())
        ->setMetadata('project', '_console');
    $database->setDocumentType('users', User::class);

    return $ctx['dbForPlatform'] = $database;
}, []);

$container->set('getProjectDB', fn () => function (Document $project) use ($register, $container): Database {
    $ctx = Coroutine::getContext();

    if (!isset($ctx['dbForProject'])) {
        $ctx['dbForProject'] = [];
    }

    if (isset($ctx['dbForProject'][$project->getSequence()])) {
        return $ctx['dbForProject'][$project->getSequence()];
    }

    if ($project->isEmpty() || $project->getId() === 'console') {
        $getConsoleDB = $container->get('getConsoleDB');

        return $getConsoleDB();
    }

    $getCache = $container->get('getCache');

    /** @var Group $pools */
    $pools = $register->get('pools');

    try {
        $dsn = new DSN($project->getAttribute('database'));
    } catch (\InvalidArgumentException) {
        $dsn = new DSN('mysql://' . $project->getAttribute('database'));
    }

    $adapter = new DatabasePool($pools->get($dsn->getHost()));
    $database = new Database($adapter, $getCache());

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
}, []);

$container->set('getPlanForUser', fn () => fn (Document $project, string $userId): int => (int) System::getEnv('_APP_MQTT_REPLAY_DEPTH', '5'), []);

$container->set('getRedis', fn () => function (): \Redis {
    $ctx = Coroutine::getContext();

    if (isset($ctx['redis'])) {
        return $ctx['redis'];
    }

    $host = System::getEnv('_APP_REDIS_HOST', 'localhost');
    $port = System::getEnv('_APP_REDIS_PORT', 6379);
    $pass = System::getEnv('_APP_REDIS_PASS', '');

    $redis = new \Redis();
    @$redis->pconnect($host, (int)$port);
    if ($pass) {
        $redis->auth($pass);
    }
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

    return $ctx['redis'] = $redis;
}, []);

// Register the CONNECT authenticator and per-SUBSCRIBE authorizer on the global container
// (see app/init/mqtt/connection.php). The CONNECT/SUBSCRIBE handlers inject them by name,
// resolved through the packet container that inherits from this one.
$registerConnectionResources($container);

/** @var \Utopia\Telemetry\Adapter $telemetry */
$telemetry = $container->get('telemetry');

$maxPacketSize = (int) System::getEnv('_APP_MQTT_MAX_PACKET_SIZE', '64000');
$adapter = new Adapter\Swoole([
    new Adapter\Swoole\Tcp('0.0.0.0', 1883, $maxPacketSize),
    new Adapter\Swoole\WebSocket('0.0.0.0', (int) System::getEnv('_APP_MQTT_WS_PORT', '8083'), $maxPacketSize),
], workers: 1);

$mqtt = new Mqtt($telemetry, new PubSubPool($register->get('pools')->get('pubsub')));

// The broker owns framing, decoding, dispatch, per-version encoding, packet ids, the QoS
// handshake, keep-alive reaping and subscription matching. Appwrite policy lives in the Handler.
$handler = new Handler($container, $mqtt, $container->get('getCache'), $container->get('getPlanForUser'));
$server = new Server($adapter, $handler);
$server->setTelemetry($telemetry); // broker owns connection/packet/subscription metrics

$server->onStart(fn () => print("MQTT broker started\n"));
$server->error(fn (\Throwable $error, string $action) => Console::error("MQTT {$action} error: " . $error->getMessage()));

// Server-initiated delivery: bridge the Redis 'mqtt' firehose to this worker's local subscribers.
// Appwrite clients never PUBLISH; messages are produced by the Messaging worker onto the channel.
$server->onWorkerStart(function (int $workerId) use ($server, $handler, $mqtt, $register): void {
    go(function () use ($server, $handler, $mqtt, $register): void {
        $attempts = 0;
        while ($attempts < 300) {
            try {
                $pubsub = new PubSubPool($register->get('pools')->get('pubsub'));

                if ($pubsub->ping(true)) {
                    $attempts = 0;
                }

                $pubsub->subscribe(['mqtt'], function (mixed $redis, string $channel, string $payload) use ($server, $handler, $mqtt): void {
                    $event = json_decode($payload, true);
                    if (!\is_array($event)) {
                        return;
                    }

                    $projectId = (string) ($event['project'] ?? '');
                    $topic = (string) ($event['topic'] ?? '');
                    $qos = (int) ($event['qos'] ?? 0);
                    $sequence = (int) ($event['sequence'] ?? 0);
                    $message = base64_decode((string) ($event['payload'] ?? ''));

                    $span = Span::init('mqtt.deliver');
                    $span->set('project.id', $projectId);
                    $span->set('mqtt.topic', $topic);
                    $span->set('mqtt.qos', $qos);
                    $span->set('mqtt.is_broker', true);

                    $delivered = $handler->deliver($server, $projectId, $topic, $message, $qos, $sequence);

                    $span->set('mqtt.subscribers', $delivered);
                    if ($delivered === 0) {
                        $mqtt->messagesDropped->add(1, ['reason' => 'no_subscriber']);
                    }

                    $span->finish();
                });
            } catch (\Throwable $error) {
                $attempts++;
                Console::error('MQTT pub/sub connection error: ' . $error->getMessage());
                sleep(DATABASE_RECONNECT_SLEEP);
            }
        }

        Console::error('Failed to maintain MQTT pub/sub subscription');
    });
});

$server->start();
