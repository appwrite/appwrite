<?php

use Appwrite\Auth\EncryptionKey;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Databases;
use Appwrite\Mqtt\Handler;
use Appwrite\PubSub\Adapter\Pool as PubSubPool;
use Appwrite\Usage\Context as UsageContext;
use Swoole\Coroutine;
use Swoole\Runtime;
use Swoole\Timer;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\DI\Container;
use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Server;
use Utopia\Pools\Group;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Queue;
use Utopia\Registry\Registry;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

require_once __DIR__ . '/init.php';

try {
    EncryptionKey::assertProduction(
        System::getEnv('_APP_ENV', 'production'),
        System::getEnv('_APP_OPENSSL_KEY_V1')
    );
} catch (\RuntimeException $exception) {
    Console::error($exception->getMessage());
    exit(1);
}

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

    return $ctx['dbForPlatform'] = (new Databases($pools, $getCache()))->console();
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

    return $ctx['dbForProject'][$project->getSequence()] = (new Databases($pools, $getCache()))->project($project);
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

if (!$container->has('publisherForUsage')) {
    $container->set('publisherForUsage', function (Group $pools): UsagePublisher {
        $statsUsageConnection = System::getEnv('_APP_CONNECTIONS_QUEUE_STATS_USAGE', '');
        $publisherPoolName = 'publisher';

        if (!empty($statsUsageConnection)) {
            try {
                $pools->get('publisher_' . $statsUsageConnection);
                $publisherPoolName = 'publisher_' . $statsUsageConnection;
            } catch (\Throwable) {
                // Fallback to the default publisher pool when the custom one is unavailable.
            }
        }

        return new UsagePublisher(
            new BrokerPool(publisher: $pools->get($publisherPoolName)),
            new Queue(System::getEnv('_APP_STATS_USAGE_QUEUE_NAME', QueueEvent::STATS_USAGE_QUEUE_NAME)),
        );
    }, ['pools']);
}

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
$server->onWorkerStart(function (int $workerId) use ($server, $handler, $mqtt, $register, $container, $telemetry): void {
    if (!$telemetry instanceof NoTelemetry) {
        // Liveness signal that is off the client path. Every other broker metric is a synchronous
        // counter or histogram, recorded only when a client acts, so a freshly rolled worker in a
        // quiet region exports nothing until the first CONNECT. This uptime gauge is observed on
        // every collect, so the broker reports within one cycle of a worker starting, whatever the
        // traffic, and resets to zero on restart. The alert that pages when the broker stops
        // reporting reads this series, so a rollout no longer looks like an outage.
        $workerStartedAt = microtime(true);
        $uptime = $telemetry->createObservableGauge('mqtt.server.uptime', 's', 'Seconds since the broker worker started.');
        $uptime->observe(fn (callable $observe) => $observe(microtime(true) - $workerStartedAt, []));

        Timer::tick(60000, fn () => $telemetry->collect());
    }

    // Flush accumulated per-project usage (connections, deliveries) to the stats-usage queue.
    Timer::tick(60000, function () use ($mqtt, $container): void {
        $usage = $mqtt->flushUsage();
        if ($usage === []) {
            return;
        }

        go(function () use ($usage, $container): void {
            try {
                /** @var UsagePublisher $publisherForUsage */
                $publisherForUsage = $container->get('publisherForUsage');
                $getConsoleDB = $container->get('getConsoleDB');
                $dbForPlatform = $getConsoleDB();

                foreach ($usage as $projectId => $counts) {
                    $project = $dbForPlatform->getAuthorization()->skip(
                        fn () => $dbForPlatform->getDocument('projects', $projectId)
                    );
                    if ($project->isEmpty()) {
                        continue;
                    }

                    $context = new UsageContext();
                    if (($counts['connections'] ?? 0) > 0) {
                        $context->addMetric(METRIC_MQTT_CONNECTIONS, (int) $counts['connections']);
                    }
                    if (($counts['delivered'] ?? 0) > 0) {
                        $context->addMetric(METRIC_MQTT_MESSAGES_DELIVERED, (int) $counts['delivered']);
                    }

                    $publisherForUsage->enqueue(new UsageMessage(
                        project: $project,
                        metrics: $context->getMetrics(),
                    ));
                }
            } catch (\Throwable $error) {
                Console::warning('Failed to publish MQTT usage: ' . $error->getMessage());
            }
        });
    });

    go(function () use ($server, $handler, $mqtt, $register): void {
        $attempts = 0;
        while ($attempts < 60) {
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
                    $publishedAt = (float) ($event['publishedAt'] ?? 0.0);
                    $message = base64_decode((string) ($event['payload'] ?? ''));

                    $span = Span::init('mqtt.deliver');
                    $span->set('project.id', $projectId);
                    $span->set('mqtt.topic', $topic);
                    $span->set('mqtt.qos', $qos);
                    $span->set('mqtt.is_broker', true);

                    $delivered = $handler->deliver($server, $projectId, $topic, $message, $qos, $sequence, $publishedAt);

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
