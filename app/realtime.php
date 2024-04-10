<?php

use Appwrite\Auth\Auth;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Logger\Log;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\WebSocket\Server;
use Utopia\WebSocket\Adapter;

/**
 * @var Registry $register
 */
require_once __DIR__ . '/init.php';

Runtime::enableCoroutine();

$redisConnections = [];

// Allows overriding
if (!function_exists('getConsoleDB')) {
    /**
     * @return array{Database, callable}
     * @throws Exception|\Exception
     */
    function getConsoleDB(): array
    {
        global $register;

        /** @var Group $pools */
        $pools = $register->get('pools');

        $dbConnection = $pools
            ->get('console')
            ->pop();

        $dbAdapter = $dbConnection->getResource();

        [$cache, $reclaimCache] = getCache();

        $database = new Database($dbAdapter, $cache);
        $database->setNamespace('_console');

        return [$database, function () use ($dbConnection, $reclaimCache) {
            $dbConnection->reclaim();
            $reclaimCache();
        }];
    }
}

// Allows overriding
if (!function_exists('getProjectDB')) {
    /**
     * @param Document $project
     * @return array{Database, callable}
     * @throws Exception
     */
    function getProjectDB(Document $project): array
    {
        global $register;

        /** @var Group $pools */
        $pools = $register->get('pools');

        if ($project->isEmpty() || $project->getId() === 'console') {
            return getConsoleDB();
        }

        $dbConnection = $pools
            ->get($project->getAttribute('database'))
            ->pop();

        $dbAdapter = $dbConnection->getResource();

        [$cache, $reclaimCache] = getCache();

        $database = new Database($dbAdapter, $cache);
        $database->setNamespace('_' . $project->getInternalId());

        return [$database, function () use ($dbConnection, $reclaimCache) {
            $dbConnection->reclaim();
            $reclaimCache();
        }];
    }
}

// Allows overriding
if (!function_exists('getCache')) {
    /**
     * @return array{Cache, callable}
     * @throws Exception|\Exception
     */
    function getCache(): array
    {
        global $register;

        /** @var Group $pools */
        $pools = $register->get('pools');

        $list = Config::getParam('pools-cache', []);

        $connections = [];
        $adapters = [];

        foreach ($list as $value) {
            $connection = $pools
                ->get($value)
                ->pop();

            $connections[] = $connection;
            $adapters[] = $connection->getResource();
        }

        $cache = new Cache(new Sharding($adapters));

        return [$cache, function () use ($connections) {
            foreach ($connections as $connection) {
                $connection->reclaim();
            }
        }];
    }
}

if (!function_exists('getPubSub')) {
    /**
     * @return array{Redis, callable}
     * @throws Exception|\Exception
     */
    function getPubSub(): array
    {
        global $register;

        /** @var Group $pools */
        $pools = $register->get('pools');

        $connection = $pools
            ->get('pubsub')
            ->pop();

        $redis = $connection->getResource();

        return [$redis, function () use ($connection) {
            $connection->reclaim();
        }];
    }
}

$realtime = new Realtime();

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
$workerNumber = swoole_cpu_num() * intval(App::getEnv('_APP_WORKER_PER_CORE', 6));

$adapter = new Adapter\Swoole(port: App::getEnv('PORT', 80));

$adapter
    ->setPackageMaxLength(64000) // Default maximum Package Size (64kb)
    ->setWorkerNumber($workerNumber);

$server = new Server($adapter);

function logError(Throwable $error, string $action): void
{
    global $register;

    $logger = $register->get('logger');

    if ($logger && !$error instanceof Exception) {
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        $log = new Log();
        $log->setNamespace("realtime");
        $log->setServer(gethostname());
        $log->setVersion($version);
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());

        $log->addTag('code', $error->getCode());
        $log->addTag('verboseType', get_class($error));

        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());
        $log->addExtra('detailedTrace', $error->getTrace());

        $log->setAction($action);

        $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
        $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Realtime log pushed with status code: ' . $responseCode);
    }

    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());
}

$server->error(function (Throwable $th, string $method) {
    logError($th, $method);
});

$server->onStart(function () use ($stats, $register, $containerId, &$statsDocument) {
    sleep(5); // wait for the initial database schema to be ready
    Console::success('Server started successfully');

    /**
     * Create document for this worker to share stats across Containers.
     */
    go(function () use ($register, $containerId, &$statsDocument) {
        $attempts = 0;

        do {
            try {
                /**
                 * @var Database $database
                 * @var callable $reclaim
                 */
                [$database, $reclaim] = getConsoleDB();

                $attempts++;

                $document = new Document([
                    '$id' => ID::unique(),
                    '$collection' => ID::custom('realtime'),
                    '$permissions' => [],
                    'container' => $containerId,
                    'timestamp' => DateTime::now(),
                    'value' => '{}'
                ]);

                $statsDocument = Authorization::skip(function () use ($database, $document) {
                    return $database->createDocument('realtime', $document);
                });

                break;
            } catch (Throwable) {
                Console::warning("Collection not ready. Retrying connection ({$attempts})...");
                sleep(DATABASE_RECONNECT_SLEEP);
            }
        } while (true);

        if (isset($reclaim)) {
            $reclaim();
        }
    });

    /**
     * Save current connections to the Database every 5 seconds.
     */
    Timer::tick(5000, function () use ($register, $stats, &$statsDocument) {
        $payload = [];

        foreach ($stats as $projectId => $value) {
            $payload[$projectId] = $stats->get($projectId, 'connectionsTotal');
        }

        if (empty($payload) || empty($statsDocument)) {
            return;
        }

        try {
            /**
             * @var Database $database
             * @var callable $reclaim
             */
            [$database, $reclaim] = getConsoleDB();

            $statsDocument
                ->setAttribute('timestamp', DateTime::now())
                ->setAttribute('value', json_encode($payload));

            Authorization::skip(function () use ($database, $statsDocument) {
                $database->updateDocument('realtime', $statsDocument->getId(), $statsDocument);
            });
        } catch (Throwable $th) {
            logError($th, 'updateWorkerDocument');
        } finally {
            if (isset($reclaim)) {
                $reclaim();
            }
        }
    });
});

$server->onWorkerStart(function (int $workerId) use ($server, $register, $stats, $realtime, &$redisConnections) {
    Console::success('Worker ' . $workerId . ' started successfully');

    $attempts = 0;
    $start = time();

    Timer::tick(5000, function () use ($server, $register, $realtime, $stats) {
        /**
         * Sending current connections to project channels on the console project every 5 seconds.
         */
        if ($realtime->hasSubscriber('console', Role::users()->toString(), 'project')) {
            try {
                /**
                 * @var Database $database
                 * @var callable $reclaim
                 */
                [$database, $reclaim] = getConsoleDB();

                $payload = [];

                $list = Authorization::skip(function () use ($database) {
                    return $database->find('realtime', [
                        Query::greaterThan('timestamp', DateTime::addSeconds(new \DateTime(), -15)),
                    ]);
                });

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

                    $server->send($realtime->getSubscribers($event), json_encode([
                        'type' => 'event',
                        'data' => $event['data']
                    ]));
                }
            } catch (Throwable $th) {
                logError($th, 'sendStats');
            } finally {
                if (isset($reclaim)) {
                    $reclaim();
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

            $server->send($realtime->getSubscribers($event), json_encode([
                'type' => 'event',
                'data' => $event['data']
            ]));
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

            /**
             * @var Redis $redis
             * @var callable $reclaimForRedis
             */
            [$redis, $reclaimForRedis] = getPubSub();

            $redisConnections[$workerId] = [$redis, $reclaimForRedis];

            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

            if ($redis->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established (worker: ' . $workerId . ')');
            } else {
                Console::error('Pub/sub failed (worker: ' . $workerId . ')');
            }

            $redis->subscribe(['realtime'], function (Redis $redis, string $channel, string $payload) use ($server, $workerId, $stats, $register, $realtime) {
                $event = json_decode($payload, true);

                if ($event['permissionsChanged'] && isset($event['userId'])) {
                    $projectId = $event['project'];
                    $userId = $event['userId'];

                    if ($realtime->hasSubscriber($projectId, 'user:' . $userId)) {
                        $connection = array_key_first(reset($realtime->subscriptions[$projectId]['user:' . $userId]));

                        /**
                         * @var Database $dbForConsole
                         * @var Database $dbForProject
                         * @var callable $reclaimForConsole
                         * @var callable $reclaimForProject
                         */
                        [$dbForConsole, $reclaimForConsole] = getConsoleDB();

                        $project = Authorization::skip(function () use ($dbForConsole, $projectId) {
                            return $dbForConsole->getDocument('projects', $projectId);
                        });

                        [$dbForProject, $reclaimForProject] = getProjectDB($project);

                        $user = $dbForProject->getDocument('users', $userId);

                        $roles = Auth::getRoles($user);

                        $realtime->subscribe($projectId, $connection, $roles, $realtime->connections[$connection]['channels']);

                        /**
                         * If we successfully reclaim, clear the callbacks
                         * so the finally block doesn't try to reclaim again.
                         */
                        $reclaimForConsole();
                        $reclaimForConsole = null;

                        $reclaimForProject();
                        $reclaimForProject = null;
                    }
                }

                $receivers = $realtime->getSubscribers($event);

                if (App::isDevelopment() && !empty($receivers)) {
                    Console::log("[Debug][Worker {$workerId}] Receivers: " . count($receivers));
                    Console::log("[Debug][Worker {$workerId}] Receivers Connection IDs: " . json_encode($receivers));
                    Console::log("[Debug][Worker {$workerId}] Event: " . $payload);
                }

                $server->send(
                    $receivers,
                    json_encode([
                        'type' => 'event',
                        'data' => $event['data']
                    ])
                );

                if (($num = count($receivers)) > 0) {
                    $stats->incr($event['project'], 'messages', $num);
                }
            });
        } catch (Throwable $th) {
            logError($th, 'pubSubConnection');
            Console::error('Pub/sub error: ' . $th->getMessage());
            $attempts++;
            sleep(DATABASE_RECONNECT_SLEEP);
            continue;
        } finally {
            if (isset($reclaimForConsole)) {
                $reclaimForConsole();
            }
            if (isset($reclaimForProject)) {
                $reclaimForProject();
            }
        }
    }

    Console::error('Failed to restart pub/sub...');
});

$server->onWorkerStop(function (int $workerId) use ($redisConnections) {
    /**
     * @var Redis $redis
     * @var callable $reclaim
     */
    [$redis, $reclaim] = $redisConnections[$workerId] ?? null;

    $redis?->unsubscribe(['realtime']);

    if ($reclaim) {
        $reclaim();
    }
});

$server->onOpen(function (int $connection, SwooleRequest $request) use ($server, $register, $stats, &$realtime) {
    $app = new App('UTC');
    $request = new Request($request);
    $response = new Response(new SwooleResponse());

    Console::info("Connection open (user: {$connection})");

    App::setResource('pools', function () use ($register) {
        return $register->get('pools');
    });
    App::setResource('request', function () use ($request) {
        return $request;
    });
    App::setResource('response', function () use ($response) {
        return $response;
    });

    try {
        /** @var Document $project */
        $project = $app->getResource('project');

        /*
         *  Project Check
         */
        if (empty($project->getId())) {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, 'Missing or unknown project ID');
        }

        [$dbForProject, $reclaimForProject] = getProjectDB($project);

        /** @var Document $console */
        $console = $app->getResource('console');

        /** @var Document $user */
        $user = $app->getResource('user');

        /*
         * Abuse Check
         *
         * Abuse limits are connecting 128 times per minute and ip address.
         */
        $timeLimit = new TimeLimit('url:{url},ip:{ip}', 128, 60, $dbForProject);
        $timeLimit
            ->setParam('{ip}', $request->getIP())
            ->setParam('{url}', $request->getURI());

        $abuse = new Abuse($timeLimit);

        if (App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled' && $abuse->check()) {
            throw new Exception(Exception::REALTIME_TOO_MANY_MESSAGES, 'Too many requests');
        }

        /*
         * Validate Client Domain - Check to avoid CSRF attack.
         * Adding Appwrite API domains to allow XDOMAIN communication.
         * Skip this check for non-web platforms which are not required to send an origin header.
         */
        $origin = $request->getOrigin();
        $originValidator = new Origin(array_merge($project->getAttribute('platforms', []), $console->getAttribute('platforms', [])));

        if (!$originValidator->isValid($origin) && $project->getId() !== 'console') {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, $originValidator->getDescription());
        }

        $roles = Auth::getRoles($user);

        $channels = Realtime::convertChannels($request->getQuery('channels', []), $user->getId());

        /**
         * Channels Check
         */
        if (empty($channels)) {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, 'Missing channels');
        }

        $realtime->subscribe($project->getId(), $connection, $roles, $channels);

        $user = empty($user->getId()) ? null : $response->output($user, Response::MODEL_ACCOUNT);

        $server->send([$connection], json_encode([
            'type' => 'connected',
            'data' => [
                'channels' => array_keys($channels),
                'user' => $user
            ]
        ]));

        $stats->set($project->getId(), [
            'projectId' => $project->getId(),
            'teamId' => $project->getAttribute('teamId')
        ]);
        $stats->incr($project->getId(), 'connections');
        $stats->incr($project->getId(), 'connectionsTotal');
    } catch (Throwable $th) {
        logError($th, 'initServer');

        $response = [
            'type' => 'error',
            'data' => [
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ]
        ];

        $server->send([$connection], json_encode($response));
        $server->close($connection, $th->getCode());

        if (App::isDevelopment()) {
            Console::error('[Error] Connection Error');
            Console::error('[Error] Code: ' . $response['data']['code']);
            Console::error('[Error] Message: ' . $response['data']['message']);
        }
    } finally {
        if (isset($reclaimForProject)) {
            $reclaimForProject();
        }
    }
});

$server->onMessage(function (int $connection, string $message) use ($server, $register, $realtime, $containerId) {
    try {
        $response = new Response(new SwooleResponse());
        $projectId = $realtime->connections[$connection]['projectId'];
        [$database, $reclaimForConsole] = getConsoleDB();

        if ($projectId !== 'console') {
            $project = Authorization::skip(function () use ($database, $projectId) {
                return $database->getDocument('projects', $projectId);
            });

            [$database, $reclaimForProject] = getProjectDB($project);
        } else {
            $project = null;
        }

        /*
         * Abuse Check
         *
         * Abuse limits are sending 32 times per minute and connection.
         */
        $timeLimit = new TimeLimit('url:{url},connection:{connection}', 32, 60, $database);

        $timeLimit
            ->setParam('{connection}', $connection)
            ->setParam('{container}', $containerId);

        $abuse = new Abuse($timeLimit);

        if ($abuse->check() && App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
            throw new Exception(Exception::REALTIME_TOO_MANY_MESSAGES, 'Too many messages.');
        }

        $message = json_decode($message, true);

        if (is_null($message) || (!array_key_exists('type', $message) && !array_key_exists('data', $message))) {
            throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Message format is not valid.');
        }

        switch ($message['type']) {
            /**
             * This type is used to authenticate.
             */
            case 'authentication':
                if (!array_key_exists('session', $message['data'])) {
                    throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Payload is not valid.');
                }

                $session = Auth::decodeSession($message['data']['session']);
                Auth::$unique = $session['id'] ?? '';
                Auth::$secret = $session['secret'] ?? '';

                $user = $database->getDocument('users', Auth::$unique);
                $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;

                if (
                    empty($user->getId()) // Check a document has been found in the DB
                    || !Auth::sessionVerify($user->getAttribute('sessions', []), Auth::$secret, $authDuration) // Validate user has valid login token
                ) {
                    // cookie not valid
                    throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Session is not valid.');
                }

                $roles = Auth::getRoles($user);
                $channels = Realtime::convertChannels(array_flip($realtime->connections[$connection]['channels']), $user->getId());
                $realtime->subscribe($realtime->connections[$connection]['projectId'], $connection, $roles, $channels);

                $user = $response->output($user, Response::MODEL_ACCOUNT);
                $server->send([$connection], json_encode([
                    'type' => 'response',
                    'data' => [
                        'to' => 'authentication',
                        'success' => true,
                        'user' => $user
                    ]
                ]));

                break;

            default:
                throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Message type is not valid.');
        }
    } catch (Throwable $th) {
        $response = [
            'type' => 'error',
            'data' => [
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ]
        ];

        $server->send([$connection], json_encode($response));

        if ($th->getCode() === 1008) {
            $server->close($connection, $th->getCode());
        }
    } finally {
        if (isset($reclaimForConsole)) {
            $reclaimForConsole();
        }
        if (isset($reclaimForProject)) {
            $reclaimForProject();
        }
    }
});

$server->onClose(function (int $connection) use ($realtime, $stats) {
    if (array_key_exists($connection, $realtime->connections)) {
        $stats->decr($realtime->connections[$connection]['projectId'], 'connectionsTotal');
    }

    $realtime->unsubscribe($connection);

    Console::info('Connection close: ' . $connection);
});

$server->start();
