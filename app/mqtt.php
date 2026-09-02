<?php

use Appwrite\Messaging\Adapter\Mqtt;
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
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Handlers\Auth as AuthHandler;
use Utopia\Mqtt\Handlers\Connect as ConnectHandler;
use Utopia\Mqtt\Handlers\Disconnect as DisconnectHandler;
use Utopia\Mqtt\Handlers\Ping as PingHandler;
use Utopia\Mqtt\Handlers\Publish as PublishHandler;
use Utopia\Mqtt\Handlers\Subscribe as SubscribeHandler;
use Utopia\Mqtt\Handlers\Unsubscribe as UnsubscribeHandler;
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

/**
 * Authenticate a CONNECT: resolve the project and verify the session in a child
 * container, using the same domain logic as HTTP/realtime. Returns the identity
 * for the connection, or an empty array when the credential is invalid.
 *
 * @return array{projectId: string, userId: string}|array{}
 */
$authenticate = function (string $projectId, string $authMethod, string $credential) use ($container, $registerMqttConnectionResources): array {
    $connectionContainer = new Container($container);
    $connectionContainer->set('projectId', fn () => $projectId);
    $connectionContainer->set('authMethod', fn () => $authMethod);
    $connectionContainer->set('credential', fn () => $credential);

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

/**
 * ACL for SUBSCRIBE: refuse blocked accounts. Re-checked per subscribe so a block
 * applied mid-connection takes effect on the next subscription.
 *
 * TODO: also authorize the topic itself — verify it maps to an existing messaging
 * Topic in the project (topics API/collection) and that this user is allowed on it.
 * A subscription to a non-existent or unauthorized topic should be denied. Deferred
 * until the topic subscription model is wired.
 */
$authorize = function (array $identity, string $topic) use ($container, $registerMqttConnectionResources): bool {
    $userId = $identity['userId'] ?? '';
    $projectId = $identity['projectId'] ?? '';
    if ($userId === '' || $projectId === '') {
        return false;
    }

    $connectionContainer = new Container($container);
    $connectionContainer->set('projectId', fn () => $projectId);
    $registerMqttConnectionResources($connectionContainer);

    /** @var Authorization $authorization */
    $authorization = $connectionContainer->get('authorization');
    /** @var Document $project */
    $project = $connectionContainer->get('project');
    if ($project->isEmpty()) {
        return false;
    }

    $dbForProject = getProjectDB($project);
    $dbForProject->setAuthorization($authorization);
    $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

    // status: true = enabled, false = blocked.
    return !$user->isEmpty() && $user->getAttribute('status', true) !== false;
};

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
    ->addHandler(new PublishHandler())
    ->addHandler(new AuthHandler())
    ->addHandler(new PingHandler())
    ->addHandler(new DisconnectHandler());

$server->onStart(fn () => print("MQTT broker started\n"));

$server->onWorkerStart(function (int $workerId) use ($server, $mqtt, $register): void {
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
                    $message = base64_decode((string) ($event['payload'] ?? ''));

                    $subscribers = $mqtt->getSubscribers($projectId, $topic);
                    if ($subscribers === []) {
                        // No local subscriber on this worker; with multiple workers this
                        // counts per-worker rather than as a global drop.
                        $mqtt->metrics->messagesDropped->add(1, ['reason' => 'no_subscriber']);
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
                    }
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
    $authenticate,
    $authorize,
): void {
    $packet = Packet::parse($data);
    $connection = $mqtt->open($fd);

    $span = Span::init('mqtt.' . $packet->name());
    $span->set('mqtt.fd', $fd);
    $span->set('project.id', $connection->projectId);
    $span->set('user.id', $connection->identity['userId'] ?? '');

    $reply = function (string $packet = '', bool $close = false) use ($server, $fd): void {
        if ($packet !== '') {
            $server->send($fd, $packet);
        }
        if ($close) {
            $server->close($fd);
        }
    };

    $packetContainer = new Container();
    $packetContainer->set('mqtt', fn () => $mqtt);
    $packetContainer->set('connection', fn () => $connection);
    $packetContainer->set('packet', fn () => $packet);
    $packetContainer->set('authenticator', fn () => $authenticate);
    $packetContainer->set('authorizer', fn () => $authorize);
    $packetContainer->set('reply', fn () => $reply);

    try {
        $dispatcher->dispatch($packetContainer, $packet->type);
        $span->finish();
    } catch (\Throwable $error) {
        $span->finish(error: $error);
        throw $error;
    }
});

$server->onClose(function (int $fd) use ($mqtt): void {
    $mqtt->close($fd);
});

$server->start();
