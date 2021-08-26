<?php

use Appwrite\Auth\Auth;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Network\Validator\Origin;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;
use Utopia\WebSocket\Server;
use Utopia\WebSocket\Adapter;

require_once __DIR__ . '/init.php';

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

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
$documentId = null;

$adapter = new Adapter\Swoole(port: App::getEnv('PORT', 80));
$adapter->setPackageMaxLength(64000); // Default maximum Package Size (64kb)

$server = new Server($adapter);

$server->onStart(function () use ($stats, $register, $containerId, &$documentId) {
    Console::success('Server started succefully');

    $getConsoleDb = function () use ($register) {
        $db = $register->get('dbPool')->get();
        $cache = $register->get('redisPool')->get();

        $consoleDb = new Database();
        $consoleDb->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
        $consoleDb->setNamespace('app_console');
        $consoleDb->setMocks(Config::getParam('collections', []));

        return [
            $consoleDb,
            function () use ($register, $db, $cache) {
                $register->get('dbPool')->put($db);
                $register->get('redisPool')->put($cache);
            }
        ];
    };

    /**
     * Create document for this worker to share stats across Containers.
     */
    go(function () use ($getConsoleDb, $containerId, &$documentId) {
        try {
            [$consoleDb, $returnConsoleDb] = call_user_func($getConsoleDb);
            $document = [
                '$collection' => Database::SYSTEM_COLLECTION_CONNECTIONS,
                '$permissions' => [
                    'read' => ['*'],
                    'write' => ['*'],
                ],
                'container' => $containerId,
                'timestamp' => time(),
                'value' => '{}'
            ];
            Authorization::disable();
            $document = $consoleDb->createDocument($document);
            Authorization::enable();
            $documentId = $document->getId();
        } catch (\Throwable $th) {
            Console::error('[Error] Type: ' . get_class($th));
            Console::error('[Error] Message: ' . $th->getMessage());
            Console::error('[Error] File: ' . $th->getFile());
            Console::error('[Error] Line: ' . $th->getLine());
        } finally {
            call_user_func($returnConsoleDb);
        }
    });

    /**
     * Save current connections to the Database every 5 seconds.
     */
    Timer::tick(5000, function () use ($stats, $getConsoleDb, $containerId, &$documentId) {
        [$consoleDb, $returnConsoleDb] = call_user_func($getConsoleDb);

        foreach ($stats as $projectId => $value) {
            if (empty($value['connections']) && empty($value['messages'])) {
                continue;
            }

            $connections = $value['connections'];
            $messages = $value['messages'];

            $usage = new Event('v1-usage', 'UsageV1');
            $usage
                ->setParam('projectId', $projectId)
                ->setParam('realtimeConnections', $connections)
                ->setParam('realtimeMessages', $messages)
                ->setParam('networkRequestSize', 0)
                ->setParam('networkResponseSize', 0);

            $stats->set($projectId, [
                'messages' => 0,
                'connections' => 0
            ]);

            if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
                $usage->trigger();
            }
        }
        $payload = [];
        foreach ($stats as $projectId => $value) {
            if (!empty($value['connectionsTotal'])) {
                $payload[$projectId] = $value['connectionsTotal'];
            }
        }
        if (empty($payload)) {
            return;
        }
        $document = [
            '$id' => $documentId,
            '$collection' => Database::SYSTEM_COLLECTION_CONNECTIONS,
            '$permissions' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'container' => $containerId,
            'timestamp' => time(),
            'value' => json_encode($payload)
        ];
        try {
            $document = $consoleDb->updateDocument($document);
        } catch (\Throwable $th) {
            Console::error('[Error] Type: ' . get_class($th));
            Console::error('[Error] Message: ' . $th->getMessage());
            Console::error('[Error] File: ' . $th->getFile());
            Console::error('[Error] Line: ' . $th->getLine());
        } finally {
            call_user_func($returnConsoleDb);
        }
    });
});

$server->onWorkerStart(function (int $workerId) use ($server, $register, $stats, $realtime) {
    Console::success('Worker ' . $workerId . ' started succefully');

    $attempts = 0;
    $start = time();

    Timer::tick(5000, function () use ($server, $register, $realtime, $stats) {
        /**
         * Sending current connections to project channels on the console project every 5 seconds.
         */
        if ($realtime->hasSubscriber('console', 'role:member', 'project')) {
            $db = $register->get('dbPool')->get();
            $cache = $register->get('redisPool')->get();

            $consoleDb = new Database();
            $consoleDb->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
            $consoleDb->setNamespace('app_console');
            $consoleDb->setMocks(Config::getParam('collections', []));

            $payload = [];
            $list = $consoleDb->getCollection([
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_CONNECTIONS,
                    'timestamp>' . (time() - 15)
                ],
            ]);

            /**
             * Aggregate stats across containers.
             */
            foreach ($list as $document) {
                foreach (json_decode($document->getAttribute('value')) as $projectId => $value) {
                    if (array_key_exists($projectId, $payload)) {
                        $payload[$projectId] +=  $value;
                    } else {
                        $payload[$projectId] =  $value;
                    }
                }
            }

            foreach ($stats as $projectId => $value) {
                $event = [
                    'project' => 'console',
                    'roles' => ['team:' . $value['teamId']],
                    'data' => [
                        'event' => 'stats.connections',
                        'channels' => ['project'],
                        'timestamp' => time(),
                        'payload' => [
                            $projectId => $payload[$projectId]
                        ]
                    ]
                ];

                $server->send($realtime->getSubscribers($event), json_encode($event['data']));
            }

            $register->get('dbPool')->put($db);
            $register->get('redisPool')->put($cache);
        }
        /**
         * Sending test message for SDK E2E tests every 5 seconds.
         */
        if ($realtime->hasSubscriber('console', 'role:guest', 'tests')) {
            $payload = ['response' => 'WS:/v1/realtime:passed'];

            $event = [
                'project' => 'console',
                'roles' => ['role:guest'],
                'data' => [
                    'event' => 'test.event',
                    'channels' => ['tests'],
                    'timestamp' => time(),
                    'payload' => $payload
                ]
            ];

            $server->send($realtime->getSubscribers($event), json_encode($event['data']));
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

            /** @var Redis $redis */
            $redis = $register->get('redisPool')->get();
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
                    } else {
                        return;
                    }

                    $db = $register->get('dbPool')->get();
                    $cache = $register->get('redisPool')->get();

                    $projectDB = new Database();
                    $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
                    $projectDB->setNamespace('app_' . $projectId);
                    $projectDB->setMocks(Config::getParam('collections', []));

                    $user = $projectDB->getDocument($userId);

                    $roles = Auth::getRoles($user);

                    $realtime->subscribe($projectId, $connection, $roles, $realtime->connections[$connection]['channels']);

                    $register->get('dbPool')->put($db);
                    $register->get('redisPool')->put($cache);
                }

                $receivers = $realtime->getSubscribers($event);

                if (App::isDevelopment() && !empty($receivers)) {
                    Console::log("[Debug][Worker {$workerId}] Receivers: " . count($receivers));
                    Console::log("[Debug][Worker {$workerId}] Receivers Connection IDs: " . json_encode($receivers));
                    Console::log("[Debug][Worker {$workerId}] Event: " . $payload);
                }

                $server->send(
                    $receivers,
                    json_encode($event['data'])
                );

                if (($num = count($receivers)) > 0) {
                    $stats->incr($event['project'], 'messages', $num);
                }
            });
        } catch (\Throwable $th) {
            Console::error('Pub/sub error: ' . $th->getMessage());
            $register->get('redisPool')->put($redis);
            $attempts++;
            continue;
        }

        $attempts++;
    }

    Console::error('Failed to restart pub/sub...');
});

$server->onOpen(function (int $connection, SwooleRequest $request) use ($server, $register, $stats, &$realtime) {
    $app = new App('UTC');
    $request = new Request($request);

    /** @var PDO $db */
    $db = $register->get('dbPool')->get();
    /** @var Redis $redis */
    $redis = $register->get('redisPool')->get();

    Console::info("Connection open (user: {$connection})");

    App::setResource('db', function () use (&$db) {
        return $db;
    });

    App::setResource('cache', function () use (&$redis) {
        return $redis;
    });

    App::setResource('request', function () use ($request) {
        return $request;
    });

    App::setResource('response', function () {
        return new Response(new SwooleResponse());
    });

    try {
        /** @var \Appwrite\Database\Document $user */
        $user = $app->getResource('user');

        /** @var \Appwrite\Database\Document $project */
        $project = $app->getResource('project');

        /** @var \Appwrite\Database\Document $console */
        $console = $app->getResource('console');

        /*
         *  Project Check
         */
        if (empty($project->getId())) {
            throw new Exception('Missing or unknown project ID', 1008);
        }

        /*
         * Abuse Check
         *
         * Abuse limits are connecting 128 times per minute and ip address.
         */
        $timeLimit = new TimeLimit('url:{url},ip:{ip}', 128, 60, $db);
        $timeLimit
            ->setNamespace('app_' . $project->getId())
            ->setParam('{ip}', $request->getIP())
            ->setParam('{url}', $request->getURI());

        $abuse = new Abuse($timeLimit);

        if ($abuse->check() && App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
            throw new Exception('Too many requests', 1013);
        }

        /*
         * Validate Client Domain - Check to avoid CSRF attack.
         * Adding Appwrite API domains to allow XDOMAIN communication.
         * Skip this check for non-web platforms which are not required to send an origin header.
         */
        $origin = $request->getOrigin();
        $originValidator = new Origin(\array_merge($project->getAttribute('platforms', []), $console->getAttribute('platforms', [])));

        if (!$originValidator->isValid($origin) && $project->getId() !== 'console') {
            throw new Exception($originValidator->getDescription(), 1008);
        }

        $roles = Auth::getRoles($user);

        $channels = Realtime::convertChannels($request->getQuery('channels', []), $user->getId());

        /**
         * Channels Check
         */
        if (empty($channels)) {
            throw new Exception('Missing channels', 1008);
        }

        $realtime->subscribe($project->getId(), $connection, $roles, $channels);

        $server->send([$connection], json_encode($channels));

        $stats->set($project->getId(), [
            'projectId' => $project->getId(),
            'teamId' => $project->getAttribute('teamId')
        ]);
        $stats->incr($project->getId(), 'connections');
        $stats->incr($project->getId(), 'connectionsTotal');
    } catch (\Throwable $th) {
        $response = [
            'code' => $th->getCode(),
            'message' => $th->getMessage()
        ];

        $server->send([$connection], json_encode($response));
        $server->close($connection, $th->getCode());

        if (App::isDevelopment()) {
            Console::error("[Error] Connection Error");
            Console::error("[Error] Code: " . $response['code']);
            Console::error("[Error] Message: " . $response['message']);
        }

        if ($th instanceof PDOException) {
            $db = null;
        }
    } finally {
        /**
         * Put used PDO and Redis Connections back into their pools.
         */
        $register->get('dbPool')->put($db);
        $register->get('redisPool')->put($redis);
    }
});

$server->onMessage(function (int $connection, string $message) use ($server, $register, $realtime, $containerId) {
    try {
        $db = $register->get('dbPool')->get();
        $cache = $register->get('redisPool')->get();

        $projectDB = new Database();
        $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
        $projectDB->setNamespace('app_' . $realtime->connections[$connection]['projectId']);
        $projectDB->setMocks(Config::getParam('collections', []));

        /*
         * Abuse Check
         *
         * Abuse limits are sending 32 times per minute and connection.
         */
        $timeLimit = new TimeLimit('url:{url},conection:{connection}', 32, 60, $db);
        $timeLimit
            ->setNamespace('app_' . $realtime->connections[$connection]['projectId'])
            ->setParam('{connection}', $connection)
            ->setParam('{container}', $containerId);

        $abuse = new Abuse($timeLimit);

        if ($abuse->check() && App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
            throw new Exception('Too many messages', 1013);
        }

        $message = json_decode($message, true);

        if (is_null($message) || (!array_key_exists('type', $message) && !array_key_exists('data', $message))) {
            throw new Exception('Message format is not valid.', 1003);
        }

        switch ($message['type']) {
            case 'authentication':
                if (!array_key_exists('session', $message['data'])) {
                    throw new Exception('Payload not valid.', 1003);
                }

                $session = Auth::decodeSession($message['data']['session']);
                Auth::$unique = $session['id'];
                Auth::$secret = $session['secret'];

                $user = $projectDB->getDocument(Auth::$unique);

                if (
                    empty($user->getId()) // Check a document has been found in the DB
                    || Database::SYSTEM_COLLECTION_USERS !== $user->getCollection() // Validate returned document is really a user document
                    || !Auth::sessionVerify($user->getAttribute('sessions', []), Auth::$secret) // Validate user has valid login token
                ) {
                    // cookie not valid
                    throw new Exception('Session not valid.', 1003);
                }

                $roles = Auth::getRoles($user);
                $channels = Realtime::convertChannels(array_flip($realtime->connections[$connection]['channels']), $user->getId());
                $realtime->subscribe($realtime->connections[$connection]['projectId'], $connection, $roles, $channels);

                break;

            default:
                throw new Exception('Message type not valid.', 1003);
                break;
        }
    } catch (\Throwable $th) {
        $response = [
            'code' => $th->getCode(),
            'message' => $th->getMessage()
        ];

        $server->send([$connection], json_encode($response));

        if ($th->getCode() === 1008) {
            $server->close($connection, $th->getCode());
        }
    } finally {
        $register->get('dbPool')->put($db);
        $register->get('redisPool')->put($cache);
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
