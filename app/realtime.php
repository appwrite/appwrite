<?php

use Appwrite\Auth\Auth;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Database;
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

$adapter = new Adapter\Swoole(port: App::getEnv('PORT', 80));
$adapter->setPackageMaxLength(64000); // Default maximum Package Size (64kb)

$subscriptions = [];
$connections = [];

$stats = new Table(4096, 1);
$stats->column('projectId', Table::TYPE_STRING, 64);
$stats->column('connections', Table::TYPE_INT);
$stats->column('connectionsTotal', Table::TYPE_INT);
$stats->column('messages', Table::TYPE_INT);
$stats->create();

$server = new Server($adapter);

$realtime = new Realtime();

$server->onStart(function () use ($stats) {
    Console::success('Server started succefully');

    Timer::tick(10000, function () use ($stats) {
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
                'projectId' => $projectId,
                'messages' => 0,
                'connections' => 0
            ]);

            if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
                $usage->trigger();
            }
        }
    });
});

$server->onWorkerStart(function (int $workerId) use ($server, $register, $stats, $realtime) {
    Console::success('Worker ' . $workerId . ' started succefully');

    $attempts = 0;
    $start = time();
    $redisPool = $register->get('redisPool');

    Timer::tick(5000, function () use ($server, $stats, $realtime) {
        /**
         * Sending current connections to project channels on the console project every 5 seconds.
         */
        if ($realtime->hasSubscriber('console', 'role:member', 'project')) {
            foreach ($stats as $projectId => $value) {
                $payload = [
                    'projectId' => $value['connectionsTotal']
                ];
                $event = [
                    'project' => 'console',
                    'roles' => ['team:'.$projectId],
                    'data' => [
                        'event' => 'stats.connections',
                        'channels' => ['project'],
                        'timestamp' => time(),
                        'payload' => $payload
                    ]
                ];

                $server->send($realtime->getSubscribers($event), json_encode($event['data']));
            }
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
            $redis = $redisPool->get();
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

            if ($redis->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established (worker: ' . $workerId . ')');
            } else {
                Console::error('Pub/sub failed (worker: ' . $workerId . ')');
            }

            $redis->subscribe(['realtime'], function ($redis, $channel, $payload) use ($server, $workerId, $stats, $register, $realtime) {
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

                // Temporarily print debug logs by default for Alpha testing.
                // if (App::isDevelopment() && !empty($receivers)) {
                if (!empty($receivers)) {
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
            $redisPool->put($redis);
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

        $stats->incr($project->getId(), 'connections');
        $stats->incr($project->getId(), 'connectionsTotal');
    } catch (\Throwable $th) {
        $response = [
            'code' => $th->getCode(),
            'message' => $th->getMessage()
        ];
        // Temporarily print debug logs by default for Alpha testing.
        //if (App::isDevelopment()) {
        Console::error("[Error] Connection Error");
        Console::error("[Error] Code: " . $response['code']);
        Console::error("[Error] Message: " . $response['message']);
        //}
        $server->send([$connection], json_encode($response));
        $server->close($connection, $th->getCode());

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

$server->onMessage(function (int $connection, string $message) use ($server) {
    $server->send([$connection], 'Sending messages is not allowed.');
    $server->close($connection, 1003);
});

$server->onClose(function (int $connection) use ($realtime, $stats) {
    if (array_key_exists($connection, $realtime->connections)) {
        $stats->decr($realtime->connections[$connection]['projectId'], 'connectionsTotal');
    }
    $realtime->unsubscribe($connection);

    Console::info('Connection close: ' . $connection);
});

$server->start();
