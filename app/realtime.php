<?php

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime as QueueRealtime;
use Appwrite\Extend\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Network\Validator\Origin;
use Appwrite\Presences\State as PresenceState;
use Appwrite\PubSub\Adapter\Pool as PubSubPool;
use Appwrite\Realtime\Message\Dispatcher as MessageDispatcher;
use Appwrite\Realtime\Message\Handlers\Authentication as AuthenticationHandler;
use Appwrite\Realtime\Message\Handlers\Ping as PingHandler;
use Appwrite\Realtime\Message\Handlers\Presence as PresenceHandler;
use Appwrite\Realtime\Message\Handlers\Subscribe as SubscribeHandler;
use Appwrite\Realtime\Message\Handlers\Unsubscribe as UnsubscribeHandler;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Pools\Group;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Queue;
use Utopia\Registry\Registry;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\WebSocket\Adapter;
use Utopia\WebSocket\Server;

require_once __DIR__ . '/init.php';

if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
    require_once __DIR__ . '/init/span.php';
}

/** @var Registry $register */
$register = $GLOBALS['register'] ?? throw new \RuntimeException('Registry not initialized');

$registerConnectionResources ??= require __DIR__ . '/init/realtime/connection.php';

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

// Log uncaught exceptions in one line instead of relying on Swoole's full backtrace dump
set_exception_handler(function (\Throwable $e) {
    Console::error(sprintf(
        'Realtime uncaught exception: %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
});

global $container;

if (!$container->has('pools')) {
    $container->set('pools', function ($register) {
        return $register->get('pools');
    }, ['register']);
}

if (!$container->has('publisherForUsage')) {
    $container->set('publisherForUsage', function (Group $pools): UsagePublisher {
        $statsUsageConnection = System::getEnv('_APP_CONNECTIONS_QUEUE_STATS_USAGE', '');
        $publisherPoolName = 'publisher';

        if (!empty($statsUsageConnection)) {
            try {
                $pools->get('publisher_' . $statsUsageConnection);
                $publisherPoolName = 'publisher_' . $statsUsageConnection;
            } catch (Throwable) {
                // Fallback to default publisher pool when custom one is unavailable.
            }
        }

        return new UsagePublisher(
            new BrokerPool(publisher: $pools->get($publisherPoolName)),
            new Queue(System::getEnv(
                '_APP_STATS_USAGE_QUEUE_NAME',
                QueueEvent::STATS_USAGE_QUEUE_NAME
            ))
        );
    }, ['pools']);
}

// Allows overriding
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

// Allows overriding
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

        global $register;

        /** @var Group $pools */
        $pools = $register->get('pools');

        if ($project->isEmpty() || $project->getId() === 'console') {
            return getConsoleDB();
        }

        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        $adapter = new DatabasePool($pools->get($dsn->getHost()));
        $database = new Database($adapter, getCache());

        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

        if (\in_array($dsn->getHost(), $sharedTables)) {
            $collections = Config::getParam('collections', []);
            $projectCollections = $collections['projects'] ?? [];
            $projectsGlobalCollections = array_keys($projectCollections);
            $projectsGlobalCollections[] = 'audit';

            $database
                ->setSharedTables(true)
                ->setGlobalCollections($projectsGlobalCollections)
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

// Allows overriding
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

// Allows overriding
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

if (!function_exists('getTimelimit')) {
    function getTimelimit(string $key = "", int $limit = 0, int $seconds = 1): TimeLimitRedis
    {
        $ctx = Coroutine::getContext();

        if (isset($ctx['timelimit'])) {
            return $ctx['timelimit'];
        }

        return $ctx['timelimit'] = new TimeLimitRedis($key, $limit, $seconds, getRedis());
    }
}

if (!function_exists('getRealtime')) {
    function getRealtime(): Realtime
    {
        $ctx = Coroutine::getContext();

        if (isset($ctx['realtime'])) {
            return $ctx['realtime'];
        }

        return $ctx['realtime'] = new Realtime();
    }
}


if (!function_exists('getTelemetry')) {
    function getTelemetry(int $workerId): Utopia\Telemetry\Adapter
    {
        $ctx = Coroutine::getContext();

        if (isset($ctx['telemetry'])) {
            return $ctx['telemetry'];
        }

        return $ctx['telemetry'] = new NoTelemetry();
    }
}

if (!function_exists('getQueueForEvents')) {
    function getQueueForEvents(): QueueEvent
    {
        $ctx = Coroutine::getContext();

        if (!isset($ctx['queueForEvents'])) {
            global $register;
            /** @var Group $pools */
            $pools = $register->get('pools');
            $ctx['queueForEvents'] = new QueueEvent(new BrokerPool(
                publisher: $pools->get('publisher')
            ));
        }

        return $ctx['queueForEvents'];
    }
}

if (!function_exists('getQueueForRealtime')) {
    function getQueueForRealtime(): QueueRealtime
    {
        $ctx = Coroutine::getContext();

        if (!isset($ctx['queueForRealtime'])) {
            $ctx['queueForRealtime'] = new QueueRealtime();
        }

        return $ctx['queueForRealtime'];
    }
}

if (!function_exists('triggerStats')) {
    function triggerStats(array $event, string $projectId): void
    {
    }
}

if (!function_exists('checkForProjectUsage')) {
    function checkForProjectUsage(Document $project): void
    {
    }
}

$realtime = getRealtime();
$presenceState = new PresenceState();

$messageDispatcher = (new MessageDispatcher())
    ->addHandler(new PingHandler())
    ->addHandler(new AuthenticationHandler())
    ->addHandler(new SubscribeHandler())
    ->addHandler(new UnsubscribeHandler())
    ->addHandler(new PresenceHandler());

/**
 * Table for statistics across all workers.
 */
$stats = new Table(4096, 1);
$stats->column('projectId', Table::TYPE_STRING, 64);
$stats->column('teamId', Table::TYPE_STRING, 64);
$stats->column('connections', Table::TYPE_INT);
$stats->column('connectionsTotal', Table::TYPE_INT);
$stats->column('messages', Table::TYPE_INT);
$stats->create();

$containerId = uniqid();
$statsDocument = null;

$workerNumber = intval(System::getEnv('_APP_WORKERS_NUM', 0))
    ?: intval(System::getEnv('_APP_CPU_NUM', swoole_cpu_num())) * intval(System::getEnv('_APP_WORKER_PER_CORE', 6));

$adapter = new Adapter\Swoole(port: System::getEnv('PORT', 80));
$adapter
    ->setPackageMaxLength(64000) // Default maximum Package Size (64kb)
    ->setWorkerNumber($workerNumber);

$server = new Server($adapter);

// Allows overriding
if (!function_exists('logError')) {
    function logError(Throwable $error, string $action, array $tags = [], ?Document $project = null, ?Document $user = null, ?Authorization $authorization = null): void
    {
        global $register;

        $logger = $register->get('realtimeLogger');

        // Match HTTP semantics (app/controllers/general.php): AppwriteException uses its
        // configured publish flag; everything else publishes only for code 0 or >= 500.
        // Without this, expected client errors (e.g. Utopia DB Authorization) hit Sentry.
        if ($error instanceof AppwriteException) {
            $publish = $error->isPublishable();
        } else {
            $publish = $error->getCode() === 0 || $error->getCode() >= 500;
        }

        if ($logger && $publish) {
            $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

            $log = new Log();
            $log->setNamespace("realtime");
            $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());

            $log->addTag('code', $error->getCode());
            $log->addTag('verboseType', get_class($error));
            $log->addTag('projectId', $project?->getId() ?: 'n/a');
            $log->addTag('userId', $user?->getId() ?: 'n/a');

            foreach ($tags as $key => $value) {
                $log->addTag($key, $value ?: 'n/a');
            }

            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('detailedTrace', $error->getTrace());
            $log->addExtra('roles', $authorization?->getRoles() ?? []);

            $log->setAction($action);

            $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            try {
                $responseCode = $logger->addLog($log);
                Console::info('Error log pushed with status code: ' . $responseCode);
            } catch (Throwable $th) {
                Console::error('Error pushing log: ' . $th->getMessage());
            }
        }

        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $error->getMessage());
        Console::error('[Error] File: ' . $error->getFile());
        Console::error('[Error] Line: ' . $error->getLine());
    }
}

$server->error(logError(...));

$server->onStart(function () use ($stats, $containerId, &$statsDocument) {
    sleep(5); // wait for the initial database schema to be ready
    Console::success('Server started successfully');

    /**
     * Create document for this worker to share stats across Containers.
     */
    go(function () use ($containerId, &$statsDocument) {
        $attempts = 0;
        $database = getConsoleDB();

        do {
            try {
                $attempts++;
                $document = new Document([
                    '$id' => ID::unique(),
                    '$collection' => ID::custom('realtime'),
                    '$permissions' => [],
                    'container' => $containerId,
                    'timestamp' => DateTime::now(),
                    'value' => '{}'
                ]);

                $statsDocument = $database->getAuthorization()->skip(fn () => $database->createDocument('realtime', $document));
                break;
            } catch (Throwable) {
                Console::warning("Collection not ready. Retrying connection ({$attempts})...");
                sleep(DATABASE_RECONNECT_SLEEP);
            }
        } while (true);
    });

    /**
     * Save current connections to the Database every 5 seconds.
     */
    // TODO: Remove this if check once it doesn't cause issues for cloud
    if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
        Timer::tick(5000, function () use ($stats, &$statsDocument) {
            $payload = [];
            foreach ($stats as $projectId => $value) {
                $payload[$projectId] = $stats->get($projectId, 'connectionsTotal');
            }
            if (empty($payload) || empty($statsDocument)) {
                return;
            }

            try {
                $database = getConsoleDB();

                $statsDocument
                    ->setAttribute('timestamp', DateTime::now())
                    ->setAttribute('value', json_encode($payload));

                $database->getAuthorization()->skip(fn () => $database->updateDocument('realtime', $statsDocument->getId(), new Document([
                    'timestamp' => $statsDocument->getAttribute('timestamp'),
                    'value' => $statsDocument->getAttribute('value')
                ])));
            } catch (Throwable $th) {
                logError($th, "updateWorkerDocument");
            }
        });
    }
});

$server->onWorkerStart(function (int $workerId) use ($server, $register, $stats, $realtime) {
    Console::success('Worker ' . $workerId . ' started successfully');

    $telemetry = getTelemetry($workerId);
    $realtimeDelayBuckets = [100, 250, 500, 750, 1000, 1500, 2000, 3000, 5000, 7500, 10000, 15000, 30000];
    $workerTelemetryAttributes = ['workerId' => (string) $workerId];
    $register->set('telemetry', fn () => $telemetry);
    $register->set('telemetry.workerAttributes', fn () => $workerTelemetryAttributes);
    $register->set('telemetry.workerCounter', fn () => $telemetry->createUpDownCounter('realtime.server.active_workers'));
    $register->set('telemetry.workerClientCounter', fn () => $telemetry->createUpDownCounter('realtime.server.worker_clients'));
    $register->set('telemetry.workerSubscriptionCounter', fn () => $telemetry->createUpDownCounter('realtime.server.worker_subscriptions'));
    $register->set('telemetry.connectionCounter', fn () => $telemetry->createUpDownCounter('realtime.server.open_connections'));
    $register->set('telemetry.connectionCreatedCounter', fn () => $telemetry->createCounter('realtime.server.connection.created'));
    $register->set('telemetry.messageSentCounter', fn () => $telemetry->createCounter('realtime.server.message.sent'));
    $register->set('telemetry.deliveryDelayHistogram', fn () => $telemetry->createHistogram(
        name: 'realtime.server.delivery_delay',
        unit: 'ms',
        advisory: ['ExplicitBucketBoundaries' => $realtimeDelayBuckets],
    ));
    $register->set('telemetry.arrivalDelayHistogram', fn () => $telemetry->createHistogram(
        name: 'realtime.server.arrival_delay',
        unit: 'ms',
        advisory: ['ExplicitBucketBoundaries' => $realtimeDelayBuckets],
    ));
    $register->get('telemetry.workerCounter')->add(1);

    $attempts = 0;
    $start = time();

    Timer::tick(5000, function () use ($server, $realtime, $stats) {
        /**
         * Sending current connections to project channels on the console project every 5 seconds.
         */
        // TODO: Remove this if check once it doesn't cause issues for cloud
        if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
            if ($realtime->hasSubscriber('console', Role::users()->toString(), 'project')) {
                $database = getConsoleDB();

                $payload = [];

                $list = $database->getAuthorization()->skip(fn () => $database->find('realtime', [
                    Query::greaterThan('timestamp', DateTime::addSeconds(new \DateTime(), -15)),
                ]));

                /**
                 * Aggregate stats across containers.
                 */
                foreach ($list as $document) {
                    foreach (json_decode($document->getAttribute('value')) as $projectId => $value) {
                        if (array_key_exists($projectId, $payload)) {
                            $payload[$projectId] += $value;
                        } else {
                            $payload[$projectId] = $value;
                        }
                    }
                }

                foreach ($stats as $projectId => $value) {
                    if (!array_key_exists($projectId, $payload)) {
                        continue;
                    }

                    $event = [
                        'project' => 'console',
                        'roles' => ['team:' . $stats->get($projectId, 'teamId')],
                        'data' => [
                            'events' => ['stats.connections'],
                            'channels' => ['project'],
                            'timestamp' => DateTime::formatTz(DateTime::now()),
                            'payload' => [
                                $projectId => $payload[$projectId]
                            ]
                        ]
                    ];

                    $server->send(array_keys($realtime->getSubscribers($event)), json_encode([
                        'type' => 'event',
                        'data' => $event['data']
                    ]));
                }
            }
        }
        /**
         * Sending test message for SDK E2E tests every 5 seconds.
         */
        if ($realtime->hasSubscriber('console', Role::guests()->toString(), 'tests')) {
            $payload = ['response' => 'WS:/v1/realtime:passed'];

            $event = [
                'project' => 'console',
                'roles' => [Role::guests()->toString()],
                'data' => [
                    'events' => ['test.event'],
                    'channels' => ['tests'],
                    'timestamp' => DateTime::formatTz(DateTime::now()),
                    'payload' => $payload
                ]
            ];

            $subscribers = $realtime->getSubscribers($event);

            $groups = [];
            foreach ($subscribers as $id => $matched) {
                $key = implode(',', array_keys($matched));
                $groups[$key]['ids'][] = $id;
                $groups[$key]['subscriptions'] = array_keys($matched);
            }

            foreach ($groups as $group) {
                $data = $event['data'];
                $data['subscriptions'] = $group['subscriptions'];

                $server->send($group['ids'], json_encode([
                    'type' => 'event',
                    'data' => $data
                ]));
            }
        }
    });

    while ($attempts < 300) {
        try {
            if ($attempts > 0) {
                Console::error('Pub/sub connection lost (lasted ' . (time() - $start) . ' seconds, worker: ' . $workerId . ').
                    Attempting restart in 5 seconds (attempt #' . $attempts . ')');
                sleep(5); // 5 sec delay between connection attempts
            }
            $start = time();

            $pubsub = new PubSubPool($register->get('pools')->get('pubsub'));

            if ($pubsub->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established (worker: ' . $workerId . ')');
            } else {
                Console::error('Pub/sub failed (worker: ' . $workerId . ')');
            }

            $pubsub->subscribe(['realtime'], function (mixed $redis, string $channel, string $payload) use ($server, $workerId, $stats, $register, $realtime) {
                $event = json_decode($payload, true);

                $eventTimestamp = $event['data']['timestamp'] ?? null;
                if (\is_string($eventTimestamp)) {
                    try {
                        $eventDate = new \DateTimeImmutable($eventTimestamp, new \DateTimeZone('UTC'));
                        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                        $eventTimestampMs = (float) $eventDate->format('U.u') * 1000;
                        $nowTimestampMs = (float) $now->format('U.u') * 1000;
                        $arrivalDelayMs = (int) \max(0, $nowTimestampMs - $eventTimestampMs);

                        $register->get('telemetry.arrivalDelayHistogram')->record($arrivalDelayMs);
                    } catch (\Throwable) {
                        // Ignore invalid timestamp payloads.
                    }
                }

                if ($event['permissionsChanged'] && isset($event['userId'])) {
                    $projectId = $event['project'];
                    $userId = $event['userId'];

                    if ($realtime->hasSubscriber($projectId, 'user:' . $userId)) {
                        $connections = [];
                        foreach ($realtime->subscriptions[$projectId]['user:' . $userId] as $byConnection) {
                            foreach (\array_keys($byConnection) as $connectionId) {
                                $connections[$connectionId] = true;
                            }
                        }

                        $consoleDatabase = getConsoleDB();
                        $project = $consoleDatabase->getAuthorization()->skip(fn () => $consoleDatabase->getDocument('projects', $projectId));
                        $database = getProjectDB($project);

                        /** @var User $user */
                        $user = $database->getDocument('users', $userId);
                        $roles = $user->getRoles($database->getAuthorization());

                        foreach (\array_keys($connections) as $connection) {
                            $subscriptionsBefore = \count($realtime->getSubscriptionMetadata($connection));
                            $authorization = $realtime->connections[$connection]['authorization'] ?? null;
                            $previousUserId = $realtime->connections[$connection]['userId'] ?? '';

                            $meta = $realtime->getSubscriptionMetadata($connection);

                            $realtime->unsubscribe($connection);

                            foreach ($meta as $subscriptionId => $subscription) {
                                $queries = Query::parseQueries($subscription['queries'] ?? []);
                                $channels = Realtime::rebindAccountChannels(
                                    $subscription['channels'] ?? [],
                                    $previousUserId,
                                    $userId
                                );
                                $realtime->subscribe(
                                    $projectId,
                                    $connection,
                                    $subscriptionId,
                                    $roles,
                                    $channels,
                                    $queries,
                                    $userId
                                );
                            }


                            // Restore authorization after subscribe
                            // meta can be empty as well as the channels are not required query param to connect
                            // channels and queries can be sent via message later on
                            // so if meta is empty we are not subscribing above to the projectId
                            if (!isset($realtime->connections[$connection])) {
                                $realtime->subscribe($projectId, $connection, '', $roles, [], [], $userId);
                            }
                            if ($authorization !== null && isset($realtime->connections[$connection])) {
                                $realtime->connections[$connection]['authorization'] = $authorization;
                            }

                            $subscriptionsAfter = \count($realtime->getSubscriptionMetadata($connection));
                            $subscriptionDelta = $subscriptionsAfter - $subscriptionsBefore;
                            if ($subscriptionDelta !== 0) {
                                $register->get('telemetry.workerSubscriptionCounter')->add($subscriptionDelta, $register->get('telemetry.workerAttributes'));
                            }
                        }
                    }
                }

                // Strip deleted presences from in-memory connection state so onClose doesn't
                // re-fire delete events for rows already removed via HTTP DELETE.
                $deletedPresenceId = Realtime::extractDeletedPresenceId($event);
                if ($deletedPresenceId !== null) {
                    $realtime->removePresenceFromConnections(
                        (string) ($event['project'] ?? ''),
                        $deletedPresenceId,
                    );
                }

                $receivers = $realtime->getSubscribers($event);

                if (System::getEnv('_APP_ENV', 'production') === 'development' && !empty($receivers)) {
                    Console::log("[Debug][Worker {$workerId}] Receivers: " . count($receivers));
                    Console::log("[Debug][Worker {$workerId}] Connection IDs: " . json_encode(array_keys($receivers)));
                    Console::log("[Debug][Worker {$workerId}] Matched: " . json_encode(array_values($receivers)));
                    Console::log("[Debug][Worker {$workerId}] Event: " . $payload);
                }

                // Group connections by matched subscription IDs for batch sending
                $groups = [];
                foreach ($receivers as $id => $matched) {
                    $key = implode(',', array_keys($matched));
                    $groups[$key]['ids'][] = $id;
                    $groups[$key]['subscriptions'] = array_keys($matched);
                }

                $total = 0;
                $outboundBytes = 0;

                foreach ($groups as $group) {
                    $data = $event['data'];
                    $data['subscriptions'] = $group['subscriptions'];

                    $payloadJson = json_encode([
                        'type' => 'event',
                        'data' => $data
                    ]);

                    $server->send($group['ids'], $payloadJson);

                    $count = count($group['ids']);
                    $total += $count;
                    $outboundBytes += strlen($payloadJson) * $count;
                }

                if ($total > 0) {
                    $register->get('telemetry.messageSentCounter')->add($total);
                    $stats->incr($event['project'], 'messages', $total);
                    $updatedAt = $event['data']['payload']['$updatedAt'] ?? null;
                    if (\is_string($updatedAt)) {
                        try {
                            $updatedAtDate = new \DateTimeImmutable($updatedAt, new \DateTimeZone('UTC'));
                            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                            $updatedAtTimestampMs = (float) $updatedAtDate->format('U.u') * 1000;
                            $nowTimestampMs = (float) $now->format('U.u') * 1000;
                            $delayMs = (int) \max(0, $nowTimestampMs - $updatedAtTimestampMs);

                            $register->get('telemetry.deliveryDelayHistogram')->record($delayMs);
                        } catch (\Throwable) {
                            // Ignore invalid timestamp payloads.
                        }
                    }

                    $projectId = $event['project'] ?? null;

                    if (!empty($projectId)) {
                        $metrics = [
                            METRIC_REALTIME_CONNECTIONS_MESSAGES_SENT => $total,
                        ];

                        if ($outboundBytes > 0) {
                            $metrics[METRIC_REALTIME_OUTBOUND] = $outboundBytes;
                        }

                        triggerStats($metrics, $projectId);
                    }
                }
            });
        } catch (Throwable $th) {
            logError($th, "pubSubConnection");

            Console::error('Pub/sub error: ' . $th->getMessage());
            $attempts++;
            sleep(DATABASE_RECONNECT_SLEEP);
            continue;
        }
    }

    Console::error('Failed to restart pub/sub...');
});

$server->onWorkerStop(function (int $workerId) use ($register) {
    Console::warning('Worker ' . $workerId . ' stopping');

    try {
        $register->get('telemetry.workerCounter')->add(-1);
    } catch (\Throwable $th) {
        Console::error('Realtime onWorkerStop telemetry error: ' . $th->getMessage());
    }
});

$server->onOpen(function (int $connection, SwooleRequest $request) use ($server, $register, $stats, &$realtime, $registerConnectionResources) {
    global $container;
    $request = new Request($request);
    $response = new Response(new SwooleResponse());

    Console::info("Connection open (user: {$connection})");

    $connectionContainer = new Container($container);
    $connectionContainer->set('request', fn () => $request);

    $registerConnectionResources($connectionContainer);

    $project = null;
    $logUser = null;
    $authorization = null;
    $rawSize = $request->getSize();
    $channelCount = 0;
    $subscriptionCount = 0;
    $outboundBytes = 0;
    $responseCode = 200;
    $subscriptionMode = 'message';
    $success = false;

    Span::init('realtime.open');
    Span::add('realtime.connection.id', $connection);
    Span::add('realtime.inbound_bytes', $rawSize);
    if (!empty($request->getOrigin())) {
        Span::add('realtime.origin', $request->getOrigin());
    }

    $error = null;
    try {
        /** @var Document $project */
        $project = $connectionContainer->get('project');
        $authorization = $connectionContainer->get('authorization');

        /*
         *  Project Check
         */
        if (empty($project->getId())) {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, 'Missing or unknown project ID');
        }

        $timelimit = $connectionContainer->get('timelimit');
        $user = $connectionContainer->get('user'); /** @var User $user */
        $logUser = $user;

        $apis = $project->getAttribute('apis', []);
        // Websocket is what to check, but realtime is checked too for backwards compatibility
        $websocketEnabled = $apis['websocket'] ?? $apis['realtime'] ?? true;
        if (
            !$websocketEnabled
            && !($user->isPrivileged($authorization->getRoles()) || $user->isKey($authorization->getRoles()))
        ) {
            throw new AppwriteException(AppwriteException::GENERAL_API_DISABLED);
        }

        $projectRegion = $project->getAttribute('region', '');
        $currentRegion = System::getEnv('_APP_REGION', 'default');
        if (!empty($projectRegion) && $projectRegion !== $currentRegion) {
            throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, 'Project is not accessible in this region. Please make sure you are using the correct endpoint');
        }

        /*
         * Abuse Check
         *
         * Abuse limits are connecting 128 times per minute and ip address.
         */
        $timelimit = $timelimit('url:{url},ip:{ip}', 128, 60);
        $timelimit
            ->setParam('{ip}', $request->getIP())
            ->setParam('{url}', $request->getURI());

        $abuse = new Abuse($timelimit);

        if (System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled' && $abuse->check()) {
            throw new Exception(Exception::REALTIME_TOO_MANY_MESSAGES, 'Too many requests');
        }

        triggerStats([
            METRIC_REALTIME_INBOUND => $rawSize,
        ], $project->getId());

        /*
         * Validate Client Domain - Check to avoid CSRF attack.
         * Adding Appwrite API domains to allow XDOMAIN communication.
         * Skip this check for non-web platforms which are not required to send an origin header.
         */
        $origin = $request->getOrigin();
        $originValidator = $connectionContainer->get('originValidator');

        if (!empty($origin) && !$originValidator->isValid($origin) && $project->getId() !== 'console') {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, $originValidator->getDescription());
        }

        $roles = $user->getRoles($authorization);

        $channels = Realtime::convertChannels($request->getQuery('channels', []), $user->getId());
        $channelCount = \count($channels);

        $updateStats = static function (string $projectId, ?string $teamId) use ($register, $stats): void {
            $register->get('telemetry.connectionCounter')->add(1);
            $register->get('telemetry.workerClientCounter')->add(1, $register->get('telemetry.workerAttributes'));
            $register->get('telemetry.connectionCreatedCounter')->add(1);

            $stats->set($projectId, [
                'projectId' => $projectId,
                'teamId' => $teamId
            ]);
            $stats->incr($projectId, 'connections');
            $stats->incr($projectId, 'connectionsTotal');

            triggerStats([
                METRIC_REALTIME_CONNECTIONS => 1,
            ], $projectId);
        };

        /**
         * Channels Check
         */
        if (empty($channels)) {
            // in case of message based 'subscribe' channels will be empty at first and only projectId and roles will be available
            $sanitizedUser = empty($user->getId()) ? null : $response->output($user, Response::MODEL_ACCOUNT);
            $connectedPayloadJson = json_encode([
                'type' => 'connected',
                'data' => [
                    'channels' => [],
                    'subscriptions' => [],
                    'user' => $sanitizedUser
                ]
            ]);

            $realtime->subscribe($project->getId(), $connection, '', $roles, [], [], $user->getId());
            $realtime->connections[$connection]['authorization'] = $authorization;
            $updateStats($project->getId(), $project->getAttribute('teamId'));
            $server->send([$connection], $connectedPayloadJson);
            $outboundBytes += \strlen($connectedPayloadJson);
            triggerStats([
                METRIC_REALTIME_OUTBOUND => \strlen($connectedPayloadJson),
            ], $project->getId());
            $subscriptionMode = 'message';
            $success = true;
            return;
        }

        $names = array_keys($channels);
        $subscriptionMode = 'url';

        try {
            $subscriptions = Realtime::constructSubscriptions(
                $names,
                fn ($channel) => $request->getQuery($channel, null)
            );
        } catch (QueryException $e) {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, $e->getMessage());
        }

        $sanitizedUser = empty($user->getId()) ? null : $response->output($user, Response::MODEL_ACCOUNT);

        $mapping = [];
        foreach ($subscriptions as $index => $subscription) {
            $subscriptionId = ID::unique();

            $realtime->subscribe(
                $project->getId(),
                $connection,
                $subscriptionId,
                $roles,
                $subscription['channels'],
                $subscription['queries'],
                $user->getId()
            );

            $mapping[$index] = $subscriptionId;
        }

        $realtime->connections[$connection]['authorization'] = $authorization;
        $updateStats($project->getId(), $project->getAttribute('teamId'));

        $subscriptionCount = \count($subscriptions);
        if (!empty($subscriptions)) {
            $register->get('telemetry.workerSubscriptionCounter')->add(\count($subscriptions), $register->get('telemetry.workerAttributes'));
        }

        $connectedPayloadJson = json_encode([
            'type' => 'connected',
            'data' => [
                'channels' => $names,
                'subscriptions' => $mapping,
                'user' => $sanitizedUser
            ]
        ]);

        $server->send([$connection], $connectedPayloadJson);
        $outboundBytes += \strlen($connectedPayloadJson);
        triggerStats([
            METRIC_REALTIME_OUTBOUND => \strlen($connectedPayloadJson),
        ], $project->getId());
        $success = true;

    } catch (Throwable $th) {
        $error = $th;

        // Convert known Utopia DB exceptions to AppwriteException so isPublishable()
        // suppresses expected client errors (permission denied, query timeout) from Sentry.
        if ($th instanceof AuthorizationException) {
            $th = new AppwriteException(AppwriteException::USER_UNAUTHORIZED, previous: $th);
        } elseif ($th instanceof TimeoutException) {
            $th = new AppwriteException(AppwriteException::DATABASE_TIMEOUT, previous: $th);
        }

        logError($th, 'realtime', project: $project, user: $logUser, authorization: $authorization);

        // Handle SQL error code is 'HY000'
        $code = $th->getCode();
        if (!\is_int($code)) {
            $code = 500;
        }
        $responseCode = $code;

        $message = $th->getMessage();

        // sanitize 0 && 5xx errors
        $realtimeViolation = $th instanceof AppwriteException && $th->getType() === AppwriteException::REALTIME_POLICY_VIOLATION;
        if (($code === 0 || $code >= 500) && !$realtimeViolation && System::getEnv('_APP_ENV', 'production') !== 'development') {
            $message = 'Error: Server Error';
        }

        $response = [
            'type' => 'error',
            'data' => [
                'code' => $code,
                'message' => $message
            ]
        ];

        $responsePayloadJson = json_encode($response);
        $server->send([$connection], $responsePayloadJson);
        $outboundBytes += \strlen($responsePayloadJson);
        $server->close($connection, $code);

        if (System::getEnv('_APP_ENV', 'production') === 'development') {
            Console::error('[Error] Connection Error');
            Console::error('[Error] Code: ' . $response['data']['code']);
            Console::error('[Error] Message: ' . $response['data']['message']);
        }
    } finally {
        Span::add('realtime.success', $success);
        Span::add('realtime.response_code', $responseCode);
        Span::add('realtime.subscription_mode', $subscriptionMode);
        Span::add('realtime.channel_count', $channelCount);
        Span::add('realtime.subscription_count', $subscriptionCount);
        Span::add('realtime.outbound_bytes', $outboundBytes);
        if (!empty($project?->getId())) {
            Span::add('project.id', $project->getId());
        }
        if (!empty($logUser?->getId())) {
            Span::add('user.id', $logUser->getId());
        }
        Span::current()?->finish(error: $error);
    }
});

$server->onMessage(function (int $connection, string $message) use ($container, $server, $realtime, $containerId, $register, $presenceState, $messageDispatcher) {
    $project = null;
    $authorization = null;
    $projectId = $realtime->connections[$connection]['projectId'] ?? null;
    $rawSize = \strlen($message);
    $messageType = 'invalid';
    $outboundBytes = 0;
    $responseCode = 200;
    $success = false;

    Span::init('realtime.message');
    Span::add('realtime.connection.id', $connection);
    Span::add('realtime.inbound_bytes', $rawSize);
    Span::add('realtime.container.id', $containerId);

    $error = null;
    try {
        $response = new Response(new SwooleResponse());

        // Build a fresh Authorization per message. The connection-scoped instance is shared
        // across coroutines, and `Authorization::skip()` toggles instance state — concurrent
        // messages on the same connection (e.g. `authentication` + `presence` sent back-to-back)
        // would interleave skip/restore and leak permission checks into supposedly-skipped lookups.
        $authorization = new Authorization();
        $connectionAuthorization = $realtime->connections[$connection]['authorization'] ?? null;
        if ($connectionAuthorization !== null) {
            foreach ($connectionAuthorization->getRoles() as $role) {
                $authorization->addRole($role);
            }
        }
        $connectionRoles = $realtime->connections[$connection]['roles'] ?? [];
        foreach ($connectionRoles as $role) {
            if ($authorization->hasRole($role)) {
                continue;
            }
            $authorization->addRole($role);
        }

        $database = getConsoleDB();
        $database->setAuthorization($authorization);

        if (!empty($projectId) && $projectId !== 'console') {
            // Negative-cache race: if any prior code path queried projects:$projectId
            // before this project existed (e.g. a router probe during connection
            // setup), the Database's shared cache may hold an empty result. Try the
            // cached read first, and only purge/retry when the first lookup reports
            // not-found so the shared cache remains effective for normal traffic.
            try {
                $project = $authorization->skip(fn () => $database->getDocument('projects', $projectId));
            } catch (AppwriteException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }

                $database->purgeCachedDocument('projects', $projectId);
                $project = $authorization->skip(fn () => $database->getDocument('projects', $projectId));
            }

            $database = getProjectDB($project);
            $database->setAuthorization($authorization);
        } else {
            $project = null;
        }

        if ($project !== null) {
            checkForProjectUsage($project);
        }

        /*
         * Abuse Check
         *
         * Abuse limits are sending 32 times per minute and connection.
         */
        $timeLimit = getTimelimit('url:{url},connection:{connection}', 32, 60);

        $timeLimit
            ->setParam('{connection}', $connection)
            ->setParam('{container}', $containerId);

        $abuse = new Abuse($timeLimit);

        if ($abuse->check() && System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
            throw new Exception(Exception::REALTIME_TOO_MANY_MESSAGES, 'Too many messages.');
        }

        // Record realtime inbound bytes for this project
        // not making this a part of the dispatcher as we need to get the inbound bytes as well even if we dont enter the dispatcher
        if ($project !== null && !$project->isEmpty()) {
            triggerStats([
                METRIC_REALTIME_INBOUND => $rawSize,
            ], $project->getId());
        }

        $message = json_decode($message, true);

        if (is_null($message) || (!array_key_exists('type', $message) && !array_key_exists('data', $message))) {
            throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Message format is not valid.');
        }

        $messageType = $message['type'] ?? 'invalid';

        if (!\is_scalar($messageType)) {
            throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Message type is not valid.');
        }

        // Child of the global container: per-message values like $connection and $project
        // live on this scope so concurrent message coroutines don't clobber each other,
        // while globally-registered services (pools, ...) remain reachable via the parent.
        $messageContainer = new Container($container);
        $messageContainer->set('connectionId', fn () => $connection);
        $messageContainer->set('server', fn () => $server);
        $messageContainer->set('realtime', fn () => $realtime);
        $messageContainer->set('register', fn () => $register);
        $messageContainer->set('response', fn () => $response);
        $messageContainer->set('presenceState', fn () => $presenceState);
        $messageContainer->set('database', fn () => $database);
        $messageContainer->set('authorization', fn () => $authorization);
        $messageContainer->set('project', fn () => $project);
        $messageContainer->set('projectId', fn () => $projectId);
        $messageContainer->set('queueForEvents', fn () => getQueueForEvents());
        $messageContainer->set('queueForRealtime', fn () => getQueueForRealtime());

        $responsePayload = $messageDispatcher->dispatch($messageContainer, $message);

        if ($responsePayload !== null) {
            $responseJson = json_encode($responsePayload);
            if ($responseJson === false) {
                throw new \RuntimeException(
                    'Failed to encode realtime response payload: ' . json_last_error_msg()
                );
            }

            $server->send([$connection], $responseJson);
            $bytes = \strlen($responseJson);
            $outboundBytes += $bytes;

            if ($project !== null && !$project->isEmpty()) {
                triggerStats([
                    METRIC_REALTIME_OUTBOUND => $bytes,
                    METRIC_REALTIME_CONNECTIONS_MESSAGES_SENT => 1,
                ], $project->getId());
            }
        }

        $success = true;
    } catch (Throwable $th) {
        $error = $th;

        // Convert known Utopia DB exceptions to AppwriteException so isPublishable()
        // suppresses expected client errors (permission denied, query timeout) from Sentry.
        if ($th instanceof AuthorizationException) {
            $th = new AppwriteException(AppwriteException::USER_UNAUTHORIZED, previous: $th);
        } elseif ($th instanceof TimeoutException) {
            $th = new AppwriteException(AppwriteException::DATABASE_TIMEOUT, previous: $th);
        }

        logError($th, 'realtimeMessage', project: $project, authorization: $authorization);
        $code = $th->getCode();
        if (!is_int($code)) {
            $code = 500;
        }
        $responseCode = $code;

        $message = $th->getMessage();

        // sanitize 0 && 5xx errors
        if (($code === 0 || $code >= 500) && System::getEnv('_APP_ENV', 'production') !== 'development') {
            $message = 'Error: Server Error';
        }

        $response = [
            'type' => 'error',
            'data' => [
                'code' => $code,
                'message' => $message
            ]
        ];

        $responsePayloadJson = json_encode($response);
        $server->send([$connection], $responsePayloadJson);
        $outboundBytes += \strlen($responsePayloadJson);

        if ($th->getCode() === 1008) {
            $server->close($connection, $th->getCode());
        }
    } finally {
        Span::add('realtime.success', $success);
        Span::add('realtime.response_code', $responseCode);
        Span::add('realtime.outbound_bytes', $outboundBytes);
        Span::add('project.id', $project?->getId() ?? $projectId);
        Span::add('user.id', $realtime->connections[$connection]['userId'] ?? null);
        Span::add('realtime.message_type', $messageType);
        Span::current()?->finish(error: $error);
    }
});

$server->onClose(function (int $connection) use ($realtime, $stats, $register, $container, $presenceState) {
    $projectId = null;
    $userId = null;
    $subscriptionsBeforeClose = 0;
    $success = false;

    Span::init('realtime.close');
    Span::add('realtime.connection.id', $connection);

    if (array_key_exists($connection, $realtime->connections)) {
        $projectId = $realtime->connections[$connection]['projectId'] ?? null;
        $userId = $realtime->connections[$connection]['userId'] ?? null;
    }

    try {
        if (array_key_exists($connection, $realtime->connections)) {
            // These decrements are symmetric with the increments in updateStats (onOpen),
            // which run when the connection enters $realtime->connections — the same gate as the
            // array_key_exists check above. The connectionsTotal stat is keyed by project, so it's
            // additionally guarded: a connection can exist without a stored projectId (e.g. only an
            // authorization entry left after an orphaning re-subscribe).
            $register->get('telemetry.connectionCounter')->add(-1);
            $register->get('telemetry.workerClientCounter')->add(-1, $register->get('telemetry.workerAttributes'));
            if (!empty($projectId)) {
                $stats->decr($projectId, 'connectionsTotal');
            }
            $subscriptionsBeforeClose = \count($realtime->getSubscriptionMetadata($connection));
            if ($subscriptionsBeforeClose > 0) {
                $register->get('telemetry.workerSubscriptionCounter')->add(-$subscriptionsBeforeClose, $register->get('telemetry.workerAttributes'));
            }

            /** @var array<string, Document> $presencesById */
            $presencesById = $realtime->connections[$connection]['presences'] ?? [];

            if (
                !empty($presencesById)
                && !empty($projectId)
                && $projectId !== 'console'
            ) {
                go(function () use ($presencesById, $projectId, $userId, $container, $presenceState): void {
                    // Fresh span: the parent realtime.close span finishes before this coroutine
                    Span::init('realtime.close.presenceCleanup');
                    Span::add('realtime.projectId', $projectId);
                    Span::add('realtime.presenceCount', \count($presencesById));

                    try {
                        $dbForPlatform = getConsoleDB();
                        $project = $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

                        if ($project->isEmpty()) {
                            return;
                        }

                        $presenceIds = \array_keys($presencesById);
                        $presences = \array_values($presencesById);
                        $dbForProject = getProjectDB($project);

                        $user = new User([]);
                        if (!empty($userId)) {
                            try {
                                $fetched = $dbForProject->getAuthorization()->skip(
                                    fn () => $dbForProject->getDocument('users', $userId)
                                );
                                if (!$fetched->isEmpty()) {
                                    $user = new User($fetched->getArrayCopy());
                                }
                            } catch (Throwable) {
                                // Fall back to empty User if lookup fails.
                            }
                        }

                        /** @var UsagePublisher $publisherForUsage */
                        $publisherForUsage = $container->get('publisherForUsage');

                        /** @var array<string, true> $deletedIds */
                        $deletedIds = [];
                        try {
                            $deletionCount = $dbForProject->getAuthorization()->skip(
                                function () use ($dbForProject, $presenceIds, &$deletedIds): int {
                                    return $dbForProject->deleteDocuments(
                                        'presenceLogs',
                                        [Query::equal('$id', $presenceIds)],
                                        onNext: function (Document $deleted) use (&$deletedIds): void {
                                            $deletedIds[$deleted->getId()] = true;
                                        },
                                    );
                                }
                            );
                            $presenceState->triggerUsage($publisherForUsage, $project, -$deletionCount);
                        } catch (Throwable $th) {
                            Span::current()?->setError($th);
                            logError($th, 'realtimeOnClosePresenceDeletion', tags: [
                                'projectId' => $projectId,
                                'presences' => \count($presences)
                            ]);
                        }

                        $queueForEvents = getQueueForEvents();
                        $queueForRealtime = getQueueForRealtime();
                        foreach ($presences as $presence) {
                            if (!isset($deletedIds[$presence->getId()])) {
                                continue;
                            }
                            try {
                                $presenceState->triggerEvent(
                                    $queueForEvents,
                                    $queueForRealtime,
                                    $project,
                                    $user,
                                    'presences.[presenceId].delete',
                                    $presence,
                                );
                            } catch (Throwable) {
                                // Swallow errors to avoid breaking disconnect cleanup
                            }
                        }
                    } catch (Throwable $th) {
                        Span::current()?->setError($th);
                        logError($th, 'realtimeOnClosePresenceCleanup', tags: [
                            'projectId' => $projectId,
                        ]);
                    } finally {
                        Span::current()?->finish();
                    }
                });
            }

            if (!empty($projectId)) {
                triggerStats([
                    METRIC_REALTIME_CONNECTIONS => -1,
                ], $projectId);
            }
        }
        $success = true;
    } catch (\Throwable $th) {
        // Log only; do not rethrow. If we let this bubble, Swoole dumps full coroutine
        // backtraces and unsubscribe() below would never run (connection cleanup would fail).
        Console::error('Realtime onClose error: ' . $th->getMessage());
        Span::current()?->setError($th);
    } finally {
        try {
            $realtime->unsubscribe($connection);
        } catch (\Throwable $th) {
            Console::error('Realtime onClose unsubscribe error: ' . $th->getMessage());
            Span::current()?->setError($th);
        }

        Span::add('realtime.success', $success);
        if (!empty($projectId)) {
            Span::add('project.id', $projectId);
        }
        if (!empty($userId)) {
            Span::add('user.id', $userId);
        }
        Span::add('realtime.subscriptions_before_close', $subscriptionsBeforeClose);
        Span::current()?->finish();
    }

    Console::info('Connection close: ' . $connection);
});

$server->start();
