<?php

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Dispatcher;
use Appwrite\Mqtt\Handlers\Auth as AuthHandler;
use Appwrite\Mqtt\Handlers\Connect as ConnectHandler;
use Appwrite\Mqtt\Handlers\Disconnect as DisconnectHandler;
use Appwrite\Mqtt\Handlers\Ping as PingHandler;
use Appwrite\Mqtt\Handlers\Puback as PubackHandler;
use Appwrite\Mqtt\Handlers\Subscribe as SubscribeHandler;
use Appwrite\Mqtt\Handlers\Unsubscribe as UnsubscribeHandler;
use Appwrite\Mqtt\KeepAlive;
use Appwrite\PubSub\Adapter\Pool as PubSubPool;
use Appwrite\Utopia\Database\Documents\User;
use Swoole\Coroutine;
use Swoole\Runtime;
use Swoole\Timer;
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
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\V3;
use Utopia\Mqtt\Packet\V5;
use Utopia\Mqtt\Server;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\Span\Span;
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

if (!function_exists('getPlanForUser')) {
    function getPlanForUser(Document $project, string $userId): int
    {
        return (int) System::getEnv('_APP_MQTT_REPLAY_DEPTH', '5');
    }
}

if (!function_exists('getCache')) {
    function getCache(): Cache
    {
        $ctx = Coroutine::getContext();

        if (isset($ctx['cache'])) {
            return $ctx['cache'];
        }

        global $register;

        $pools = $register->get('pools'); /** @var Group $pools */

        $list = Config::getParam('pools-cache', []);
        $adapters = [];

        foreach ($list as $value) {
            $adapters[] = new CachePool($pools->get($value));
        }

        return $ctx['cache'] = new Cache(new Sharding($adapters));
    }
}

if (!function_exists('getRedis')) {
    function getRedis(): \Redis
    {
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
    }
}

// Register the CONNECT authenticator and per-SUBSCRIBE authorizer on the global container
// (see app/init/mqtt/connection.php). The CONNECT/SUBSCRIBE handlers inject them by name,
// resolved through the packet container that inherits from this one.
$registerMqttConnectionResources($container);

/** @var \Utopia\Telemetry\Adapter $telemetry */
$telemetry = $container->get('telemetry');

$adapter = new Adapter\Swoole(host: '0.0.0.0', port: 1883);
$adapter->setPackageMaxLength((int) System::getEnv('_APP_MQTT_MAX_PACKET_SIZE', '64000'));

$server = new Server($adapter);
$server->error(fn (\Throwable $error, string $action) => Console::error("MQTT {$action} error: " . $error->getMessage()));

$mqtt = new Mqtt($telemetry);

$dispatcher = (new Dispatcher())
    ->addHandler(new ConnectHandler())
    ->addHandler(new SubscribeHandler())
    ->addHandler(new UnsubscribeHandler())
    ->addHandler(new PubackHandler())
    ->addHandler(new AuthHandler())
    ->addHandler(new PingHandler())
    ->addHandler(new DisconnectHandler());

$server->onStart(fn () => print("MQTT broker started\n"));

$server->onWorkerStart(function (int $workerId) use ($server, $mqtt, $register): void {
    // Keep-alive reaper: every INTERVAL seconds drain the wheel's due buckets and close any
    // client silent past its deadline (keepAlive x MULTIPLIER). A survivor whose deadline was
    // pushed forward by recent traffic is rescheduled instead. onClose balances the gauge and
    // forgets the fd. Per worker, since connections and the wheel are per worker.
    Timer::tick(KeepAlive::INTERVAL * 1000, function () use ($server, $mqtt): void {
        $now = microtime(true);

        foreach ($mqtt->keepAlive->drain((int) $now) as $fd) {
            $connection = $mqtt->connections[$fd] ?? null;
            if ($connection === null || $connection->keepAlive <= 0) {
                continue;
            }

            if ($connection->expiresAt <= $now) {
                $server->close($fd);
            } else {
                $connection->wheelSlot = $mqtt->keepAlive->schedule($fd, $connection->expiresAt);
            }
        }
    });

    go(function () use ($server, $mqtt, $register): void {
        $attempts = 0;
        while ($attempts < 300) {
            try {
                $pubsub = new PubSubPool($register->get('pools')->get('pubsub'));

                if ($pubsub->ping(true)) {
                    $attempts = 0;
                }

                $pubsub->subscribe(['mqtt'], function (mixed $redis, string $channel, string $payload) use ($server, $mqtt): void {
                    $event = json_decode($payload, true);
                    if (!\is_array($event)) {
                        return;
                    }

                    $projectId = (string) ($event['project'] ?? '');
                    $topic = (string) ($event['topic'] ?? '');
                    $qos = (int) ($event['qos'] ?? 0);
                    $sequence = (int) ($event['sequence'] ?? 0);
                    $message = base64_decode((string) ($event['payload'] ?? ''));

                    // The broker is the sender on this hop (fan-out to subscribers), so the
                    // span is marked is_broker to separate it from client-originated packets.
                    $span = Span::init('mqtt.deliver');
                    $span->set('project.id', $projectId);
                    $span->set('mqtt.topic', $topic);
                    $span->set('mqtt.qos', $qos);
                    $span->set('mqtt.is_broker', true);

                    $subscribers = $mqtt->getSubscribers($projectId, $topic);
                    $span->set('mqtt.subscribers', count($subscribers));

                    if ($subscribers === []) {
                        // No local subscriber on this worker; with multiple workers this
                        // counts per-worker rather than as a global drop.
                        $mqtt->metrics->messagesDropped->add(1, ['reason' => 'no_subscriber']);
                        $span->finish();
                        return;
                    }

                    foreach ($subscribers as $fd => $grantedQos) {
                        $subscriber = $mqtt->connections[$fd] ?? null;
                        if ($subscriber === null) {
                            continue;
                        }
                        $effectiveQos = min($qos, $grantedQos);
                        $packetId = $subscriber->nextPacketId();
                        $server->send($fd, $subscriber->protocol >= 5
                            ? V5::publish($topic, $message, $effectiveQos, $packetId)
                            : V3::publish($topic, $message, $effectiveQos, $packetId));
                        $mqtt->metrics->messagesDelivered->add(1, ['qos' => $effectiveQos]);

                        // Hold QoS 1 deliveries until the subscriber's PUBACK matches them back.
                        if ($effectiveQos === 1) {
                            $subscriber->track($packetId, $topic, $sequence);
                        }
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

$server->onReceive(function (int $fd, string $data) use (
    $server,
    $mqtt,
    $dispatcher,
    $container
): void {
    $packet = Packet::parse($data);
    $connection = $mqtt->open($fd);

    $span = Span::init('mqtt.' . $packet->name());
    $span->set('mqtt.fd', $fd);
    $span->set('mqtt.is_broker', false); // an inbound packet from a client

    $reply = function (string $packet = '', bool $close = false) use ($server, $fd): void {
        if ($packet !== '') {
            $server->send($fd, $packet);
        }
        if ($close) {
            $server->close($fd);
        }
    };

    // authenticator/authorizer are inherited from the global container (registered above).
    $packetContainer = new Container($container);
    $packetContainer->set('mqtt', fn () => $mqtt);
    $packetContainer->set('connection', fn () => $connection);
    $packetContainer->set('packet', fn () => $packet);
    $packetContainer->set('reply', fn () => $reply);

    try {
        $dispatcher->dispatch($packetContainer, $packet->type);

        // Every inbound packet is liveness: push the deadline forward (O(1), no wheel touch).
        // The wheel is seeded once, when CONNECT establishes the interval; later packets only
        // move the deadline and the reaper reschedules lazily when it visits the slot.
        $connection->touch(microtime(true));
        if ($packet->type === Packet::CONNECT && $connection->active && $connection->keepAlive > 0) {
            $connection->wheelSlot = $mqtt->keepAlive->schedule($fd, $connection->expiresAt);
        }

        // The identity, project and clean-start flag are populated by the CONNECT
        // handler during dispatch, so record them afterwards rather than as defaults.
        $span->set('project.id', $connection->projectId);
        $span->set('user.id', $connection->identity['userId'] ?? '');
        $span->set('mqtt.clean_start', $connection->cleanStart);
        $span->finish();
    } catch (\Throwable $error) {
        $span->set('project.id', $connection->projectId);
        $span->set('user.id', $connection->identity['userId'] ?? '');
        $span->finish(error: $error);
        throw $error;
    }
});

$server->onClose(function (int $fd) use ($mqtt): void {
    $mqtt->close($fd);
});

$server->start();
