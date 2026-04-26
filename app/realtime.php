<?php

use Appwrite\Extend\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Network\Validator\Origin;
use Appwrite\PubSub\Adapter\Pool as PubSubPool;
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
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\WebSocket\Adapter;
use Utopia\WebSocket\Server;

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/init/span.php';

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
            $database
                ->setSharedTables(true)
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

if (!function_exists('triggerStats')) {
    function triggerStats(array $event, string $projectId): void
    {
    }
}

global $container;
$container->set('pools', function ($register) {
    return $register->get('pools');
}, ['register']);

$realtime = getRealtime();

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

        if ($logger && !$error instanceof Exception) {
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
                        $connection = array_key_first(reset($realtime->subscriptions[$projectId]['user:' . $userId]));
                        $subscriptionsBefore = \count($realtime->getSubscriptionMetadata($connection));
                        $consoleDatabase = getConsoleDB();
                        $project = $consoleDatabase->getAuthorization()->skip(fn () => $consoleDatabase->getDocument('projects', $projectId));
                        $database = getProjectDB($project);

                        /** @var User $user */
                        $user = $database->getDocument('users', $userId);

                        $roles = $user->getRoles($database->getAuthorization());
                        $authorization = $realtime->connections[$connection]['authorization'] ?? null;

                        $meta = $realtime->getSubscriptionMetadata($connection);

                        $realtime->unsubscribe($connection);

                        foreach ($meta as $subscriptionId => $subscription) {
                            $queries = Query::parseQueries($subscription['queries'] ?? []);
                            $realtime->subscribe(
                                $projectId,
                                $connection,
                                $subscriptionId,
                                $roles,
                                $subscription['channels'] ?? [],
                                $queries
                            );
                        }

                        // Restore authorization after subscribe
                        if ($authorization !== null) {
                            $realtime->connections[$connection]['authorization'] = $authorization;
                        }

                        $subscriptionsAfter = \count($realtime->getSubscriptionMetadata($connection));
                        $subscriptionDelta = $subscriptionsAfter - $subscriptionsBefore;
                        if ($subscriptionDelta !== 0) {
                            $register->get('telemetry.workerSubscriptionCounter')->add($subscriptionDelta, $register->get('telemetry.workerAttributes'));
                        }
                    }
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
    Span::add('realtime.connectionId', $connection);
    Span::add('realtime.inboundBytes', $rawSize);
    if (!empty($request->getOrigin())) {
        Span::add('realtime.origin', $request->getOrigin());
    }

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
            && !($user->isPrivileged($authorization->getRoles()) || $user->isApp($authorization->getRoles()))
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

        $updateStats = static function (string $projectId, ?string $teamId, string $payloadJson) use ($register, $stats): void {
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
                METRIC_REALTIME_OUTBOUND => \strlen($payloadJson),
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
            $server->send([$connection], $connectedPayloadJson);
            $outboundBytes += \strlen($connectedPayloadJson);
            $updateStats($project->getId(), $project->getAttribute('teamId'), $connectedPayloadJson);
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
        $subscriptionCount = \count($subscriptions);
        if (!empty($subscriptions)) {
            $register->get('telemetry.workerSubscriptionCounter')->add(\count($subscriptions), $register->get('telemetry.workerAttributes'));
        }

        $realtime->connections[$connection]['authorization'] = $authorization;

        $user = empty($user->getId()) ? null : $response->output($user, Response::MODEL_ACCOUNT);

        $connectedPayloadJson = json_encode([
            'type' => 'connected',
            'data' => [
                'channels' => $names,
                'subscriptions' => $mapping,
                'user' => $user
            ]
        ]);

        $server->send([$connection], $connectedPayloadJson);
        $outboundBytes += \strlen($connectedPayloadJson);
        $updateStats($project->getId(), $project->getAttribute('teamId'), $connectedPayloadJson);
        $success = true;

    } catch (Throwable $th) {
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
        Span::error($th);
    } finally {
        Span::add('realtime.success', $success);
        Span::add('realtime.responseCode', $responseCode);
        Span::add('realtime.subscriptionMode', $subscriptionMode);
        Span::add('realtime.channelCount', $channelCount);
        Span::add('realtime.subscriptionCount', $subscriptionCount);
        Span::add('realtime.outboundBytes', $outboundBytes);
        if (!empty($project?->getId())) {
            Span::add('realtime.projectId', $project->getId());
        }
        if (!empty($logUser?->getId())) {
            Span::add('realtime.userId', $logUser->getId());
        }
        Span::current()?->finish();
    }
});

$server->onMessage(function (int $connection, string $message) use ($server, $realtime, $containerId, $register) {
    $project = null;
    $authorization = null;
    $projectId = $realtime->connections[$connection]['projectId'] ?? null;
    $rawSize = \strlen($message);
    $messageType = 'invalid';
    $subscriptionDelta = 0;
    $subscriptionsRequested = 0;
    $subscriptionsRemoved = 0;
    $outboundBytes = 0;
    $responseCode = 200;
    $success = false;

    Span::init('realtime.message');
    Span::add('realtime.connectionId', $connection);
    Span::add('realtime.inboundBytes', $rawSize);
    Span::add('realtime.containerId', $containerId);

    try {
        $response = new Response(new SwooleResponse());

        // Get authorization from connection (stored during onOpen)
        $authorization = $realtime->connections[$connection]['authorization'] ?? null;
        if ($authorization === null) {
            $authorization = new Authorization();
        }

        $database = getConsoleDB();
        $database->setAuthorization($authorization);

        if (!empty($projectId) && $projectId !== 'console') {
            $project = $authorization->skip(fn () => $database->getDocument('projects', $projectId));

            $database = getProjectDB($project);
            $database->setAuthorization($authorization);
        } else {
            $project = null;
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

        // Ping does not require project context; other messages do (e.g. after unsubscribe during auth)
        if (empty($projectId) && ($message['type'] ?? '') !== 'ping') {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, 'Missing project context. Reconnect to the project first.');
        }

        switch ($message['type']) {
            case 'ping':
                $pongPayloadJson = json_encode([
                    'type' => 'pong'
                ]);

                $server->send([$connection], $pongPayloadJson);
                $outboundBytes += \strlen($pongPayloadJson);

                if ($project !== null && !$project->isEmpty()) {
                    $pongOutboundBytes = \strlen($pongPayloadJson);

                    if ($pongOutboundBytes > 0) {
                        triggerStats([
                            METRIC_REALTIME_OUTBOUND => $pongOutboundBytes,
                        ], $project->getId());
                    }
                }

                break;
            case 'authentication':
                if (!array_key_exists('session', $message['data'])) {
                    throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Payload is not valid.');
                }

                $store = new Store();

                $store->decode($message['data']['session']);

                /** @var User $user */
                $user = $database->getDocument('users', $store->getProperty('id', ''));

                /**
                 * TODO:
                 * Moving forward, we should try to use our dependency injection container
                 * to inject the proof for token.
                 * This way we will have one source of truth for the proof for token.
                 */
                $proofForToken = new Token();
                $proofForToken->setHash(new Sha());

                if (
                    empty($user->getId()) // Check a document has been found in the DB
                    || !$user->sessionVerify($store->getProperty('secret', ''), $proofForToken) // Validate user has valid login token
                ) {
                    // cookie not valid
                    throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Session is not valid.');
                }

                $roles = $user->getRoles($database->getAuthorization());

                $authorization = $realtime->connections[$connection]['authorization'] ?? null;
                $projectId = $realtime->connections[$connection]['projectId'] ?? null;

                $subscriptionsBefore = \count($realtime->getSubscriptionMetadata($connection));
                $meta = $realtime->getSubscriptionMetadata($connection);

                $realtime->unsubscribe($connection);

                if (!empty($projectId)) {
                    foreach ($meta as $subscriptionId => $subscription) {
                        $queries = Query::parseQueries($subscription['queries'] ?? []);

                        $realtime->subscribe(
                            $projectId,
                            $connection,
                            $subscriptionId,
                            $roles,
                            $subscription['channels'] ?? [],
                            $queries,
                            $user->getId()
                        );
                    }
                }

                if ($authorization !== null) {
                    $realtime->connections[$connection]['authorization'] = $authorization;
                }

                $subscriptionsAfter = \count($realtime->getSubscriptionMetadata($connection));
                $subscriptionDelta = $subscriptionsAfter - $subscriptionsBefore;
                if ($subscriptionDelta !== 0) {
                    $register->get('telemetry.workerSubscriptionCounter')->add($subscriptionDelta, $register->get('telemetry.workerAttributes'));
                }

                $user = $response->output($user, Response::MODEL_ACCOUNT);

                $authResponsePayloadJson = json_encode([
                    'type' => 'response',
                    'data' => [
                        'to' => 'authentication',
                        'success' => true,
                        'user' => $user
                    ]
                ]);

                $server->send([$connection], $authResponsePayloadJson);
                $outboundBytes += \strlen($authResponsePayloadJson);

                if ($project !== null && !$project->isEmpty()) {
                    $authOutboundBytes = \strlen($authResponsePayloadJson);

                    if ($authOutboundBytes > 0) {
                        triggerStats([
                            METRIC_REALTIME_OUTBOUND => $authOutboundBytes,
                        ], $project->getId());
                    }
                }

                break;

            case 'subscribe':
                /**
                 * Message based upsertion of a subscription
                 * If subscriptionId is given then it will match subId of the connection and update the subscription with channels and queries
                 * If non-existing subid is given or not given a new subid will be generated
                 * Similar to what we have now -> two subscribe() block with same channels and queries still two different subscriptions
                 *
                 * structure of the payload -> array of maps
                 * 'data' : [subscriptionId:"" , channels:[] , queries:[]]
                 */
                if (!is_array($message['data']) || !array_is_list($message['data'])) {
                    throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Payload is not valid.');
                }

                $roles = $realtime->connections[$connection]['roles'] ?? [Role::guests()->toString()];
                $userId = $realtime->connections[$connection]['userId'] ?? '';

                // bulk validation + parsing before subscribing
                $parsedPayloads = [];
                $subscriptionsBefore = \count($realtime->getSubscriptionMetadata($connection));
                foreach ($message['data'] as $payload) {
                    if (!\is_array($payload)) {
                        throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Each subscribe payload must be an object.');
                    }
                    if (!array_key_exists('channels', $payload)) {
                        throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'channels is not present in payload.');
                    }
                    if (!is_array($payload['channels']) || !array_is_list($payload['channels'])) {
                        throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'channels is not a valid array.');
                    }
                    // registering the queries if not present and check in the same payload later on
                    if (!array_key_exists('queries', $payload)) {
                        $payload['queries'] = [];
                    }
                    if (!is_array($payload['queries']) || !array_is_list($payload['queries'])) {
                        throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'queries is not a valid array.');
                    }

                    $subscriptionId = \array_key_exists('subscriptionId', $payload)
                        ? $payload['subscriptionId']
                        : ID::unique();

                    try {
                        $convertedQueries = Realtime::convertQueries($payload['queries']);
                    } catch (QueryException $e) {
                        throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Invalid query: ' . $e->getMessage());
                    }

                    $convertedChannels = \array_keys(Realtime::convertChannels($payload['channels'], $userId));

                    $parsedPayloads[] = [
                        'subscriptionId' => $subscriptionId,
                        'channels' => $payload['channels'],
                        'convertedChannels' => $convertedChannels,
                        'queries' => $convertedQueries,
                    ];
                }

                foreach ($parsedPayloads as $parsedPayload) {
                    $subscriptionId = $parsedPayload['subscriptionId'];
                    $channels = $parsedPayload['convertedChannels'];
                    $queries = $parsedPayload['queries'];
                    $realtime->subscribe($projectId, $connection, $subscriptionId, $roles, $channels, $queries);
                }
                $subscriptionsAfter = \count($realtime->getSubscriptionMetadata($connection));
                $subscriptionDelta = $subscriptionsAfter - $subscriptionsBefore;
                $subscriptionsRequested = \count($parsedPayloads);
                if ($subscriptionDelta !== 0) {
                    $register->get('telemetry.workerSubscriptionCounter')->add($subscriptionDelta, $register->get('telemetry.workerAttributes'));
                }

                $responsePayload = json_encode([
                    'type' => 'response',
                    'data' => [
                        'to' => 'subscribe',
                        'success' => true,
                        'subscriptions' => \array_map(function (array $parsedPayload) {
                            return [
                                'subscriptionId' => $parsedPayload['subscriptionId'],
                                'channels' => $parsedPayload['convertedChannels'],
                                'queries' => \array_map(fn ($q) => $q->toString(), $parsedPayload['queries']),
                            ];
                        }, $parsedPayloads),
                    ]
                ]);

                $server->send([$connection], $responsePayload);
                $outboundBytes += \strlen($responsePayload);

                if ($project !== null && !$project->isEmpty()) {
                    $subscribeOutboundBytes = \strlen($responsePayload);

                    if ($subscribeOutboundBytes > 0) {
                        triggerStats([
                            METRIC_REALTIME_OUTBOUND => $subscribeOutboundBytes,
                        ], $project->getId());
                    }
                }

                break;

            case 'unsubscribe':
                if (!\is_array($message['data']) || !\array_is_list($message['data'])) {
                    throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Payload is not valid.');
                }

                $subscriptionsBefore = \count($realtime->getSubscriptionMetadata($connection));

                // Validate every payload before executing any removal so an invalid entry
                // later in the batch does not leave earlier entries half-applied on the server.
                $validatedIds = [];
                foreach ($message['data'] as $payload) {
                    if (
                        !\is_array($payload)
                        || !\array_key_exists('subscriptionId', $payload)
                        || !\is_string($payload['subscriptionId'])
                        || $payload['subscriptionId'] === ''
                    ) {
                        throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Each unsubscribe payload must include a non-empty subscriptionId.');
                    }
                    $validatedIds[] = $payload['subscriptionId'];
                }

                $unsubscribeResults = [];
                foreach ($validatedIds as $subscriptionId) {
                    $wasRemoved = $realtime->unsubscribeSubscription($connection, $subscriptionId);
                    $unsubscribeResults[] = [
                        'subscriptionId' => $subscriptionId,
                        'removed' => $wasRemoved,
                    ];
                }
                $subscriptionsAfter = \count($realtime->getSubscriptionMetadata($connection));
                $subscriptionDelta = $subscriptionsAfter - $subscriptionsBefore;
                $subscriptionsRequested = \count($validatedIds);
                $subscriptionsRemoved = \count(\array_filter($unsubscribeResults, fn (array $item) => $item['removed']));
                if ($subscriptionDelta !== 0) {
                    $register->get('telemetry.workerSubscriptionCounter')->add($subscriptionDelta, $register->get('telemetry.workerAttributes'));
                }

                $unsubscribeResponsePayload = json_encode([
                    'type' => 'response',
                    'data' => [
                        'to' => 'unsubscribe',
                        'success' => true,
                        'subscriptions' => $unsubscribeResults,
                    ],
                ]);

                $server->send([$connection], $unsubscribeResponsePayload);
                $outboundBytes += \strlen($unsubscribeResponsePayload);

                if ($project !== null && !$project->isEmpty()) {
                    $unsubscribeOutboundBytes = \strlen($unsubscribeResponsePayload);

                    if ($unsubscribeOutboundBytes > 0) {
                        triggerStats([
                            METRIC_REALTIME_OUTBOUND => $unsubscribeOutboundBytes,
                        ], $project->getId());
                    }
                }

                break;

            default:
                throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Message type is not valid.');
        }
        $success = true;
    } catch (Throwable $th) {
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
        Span::error($th);
    } finally {
        Span::add('realtime.success', $success);
        Span::add('realtime.responseCode', $responseCode);
        Span::add('realtime.subscriptionDelta', $subscriptionDelta);
        Span::add('realtime.subscriptionsRequested', $subscriptionsRequested);
        Span::add('realtime.subscriptionsRemoved', $subscriptionsRemoved);
        Span::add('realtime.subscribe.subscriptionsCount', $subscriptionsRequested);
        Span::add('realtime.outboundBytes', $outboundBytes);
        Span::add('realtime.projectId', $project?->getId() ?? $projectId);
        Span::add('realtime.userId', $realtime->connections[$connection]['userId'] ?? null);
        Span::add('realtime.messageType', $messageType);
        Span::current()?->finish();
    }
});

$server->onClose(function (int $connection) use ($realtime, $stats, $register) {
    $projectId = null;
    $userId = null;
    $subscriptionsBeforeClose = 0;
    $success = false;

    Span::init('realtime.close');
    Span::add('realtime.connectionId', $connection);

    if (array_key_exists($connection, $realtime->connections)) {
        $projectId = $realtime->connections[$connection]['projectId'] ?? null;
        $userId = $realtime->connections[$connection]['userId'] ?? null;
    }

    try {
        if (array_key_exists($connection, $realtime->connections)) {
            $stats->decr($realtime->connections[$connection]['projectId'], 'connectionsTotal');
            $register->get('telemetry.connectionCounter')->add(-1);
            $register->get('telemetry.workerClientCounter')->add(-1, $register->get('telemetry.workerAttributes'));
            $subscriptionsBeforeClose = \count($realtime->getSubscriptionMetadata($connection));
            if ($subscriptionsBeforeClose > 0) {
                $register->get('telemetry.workerSubscriptionCounter')->add(-$subscriptionsBeforeClose, $register->get('telemetry.workerAttributes'));
            }

            $projectId = $realtime->connections[$connection]['projectId'];

            triggerStats([
                METRIC_REALTIME_CONNECTIONS => -1,
            ], $projectId);
        }
        $success = true;
    } catch (\Throwable $th) {
        // Log only; do not rethrow. If we let this bubble, Swoole dumps full coroutine
        // backtraces and unsubscribe() below would never run (connection cleanup would fail).
        Console::error('Realtime onClose error: ' . $th->getMessage());
        Span::error($th);
    } finally {
        try {
            $realtime->unsubscribe($connection);
        } catch (\Throwable $th) {
            Console::error('Realtime onClose unsubscribe error: ' . $th->getMessage());
            Span::error($th);
        }

        Span::add('realtime.success', $success);
        if (!empty($projectId)) {
            Span::add('realtime.projectId', $projectId);
        }
        if (!empty($userId)) {
            Span::add('realtime.userId', $userId);
        }
        Span::add('realtime.subscriptionsBeforeClose', $subscriptionsBeforeClose);
        Span::current()?->finish();
    }

    Console::info('Connection close: ' . $connection);
});

$server->start();
