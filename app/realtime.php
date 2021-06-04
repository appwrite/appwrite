<?php

require_once __DIR__ . '/init.php';

use Appwrite\Database\Pool\PDOPool;
use Appwrite\Database\Pool\RedisPool;
use Appwrite\Event\Event;
use Appwrite\Network\Validator\Origin;
use Appwrite\Realtime\Realtime;
use Appwrite\Utopia\Response;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Process;
use Swoole\Table;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Swoole\Request as SwooleRequest;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;

/**
 * TODO List
 * 
 * - JWT Authentication (in path / or in message)
 * 
 * Protocols Support:
 * - Websocket support: https://www.swoole.co.uk/docs/modules/swoole-websocket-server
 */

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$server = new Server('0.0.0.0', 80, SWOOLE_PROCESS);

$server->set([
    'package_max_length' => 64000 // Default maximum Package Size (64kb)
]);

$subscriptions = [];
$connections = [];

$stats = new Table(4096, 1);
$stats->column('projectId', Table::TYPE_STRING, 64);
$stats->column('connections', Table::TYPE_INT);
$stats->column('connectionsTotal', Table::TYPE_INT);
$stats->column('messages', Table::TYPE_INT);
$stats->create();

/**
 * Sends usage stats every 10 seconds.
 */
Timer::tick(10000, function () use (&$stats) {
    /** @var Table $stats */
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

$server->on('workerStart', function ($server, $workerId) use (&$subscriptions, &$register, &$stats) {
    Console::success('Worker ' . $workerId . ' started succefully');

    $attempts = 0;
    $start = time();
    $redisPool = $register->get('redisPool');

    /**
     * Sending current connections to project channels on the console project every 5 seconds.
     */
    $server->tick(5000, function () use (&$server, &$subscriptions, &$stats) {
        if (
            array_key_exists('console', $subscriptions)
            && array_key_exists('role:member', $subscriptions['console'])
            && array_key_exists('project', $subscriptions['console']['role:member'])
        ) {
            $payload = [];
            foreach ($stats as $projectId => $value) {
                $payload[$projectId] = $value['connectionsTotal'];
            }
            foreach ($subscriptions['console']['role:member']['project'] as $connection => $value) {
                foreach ($stats as $projectId => $value) {
                    $server->push(
                        $connection,
                        json_encode([
                            'event' => 'stats.connections',
                            'channels' => ['project'],
                            'timestamp' => time(),
                            'payload' => $payload
                        ]),
                        SWOOLE_WEBSOCKET_OPCODE_TEXT,
                        SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS
                    );
                }
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

            /** @var Swoole\Coroutine\Redis $redis */
            $redis = $redisPool->get();

            if ($redis->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established (worker: ' . $workerId . ')');
            } else {
                Console::error('Pub/sub failed (worker: ' . $workerId . ')');
            }

            $redis->subscribe(['realtime'], function ($redis, $channel, $payload) use ($server, $workerId, &$subscriptions, &$stats) {
                /**
                 * Supported Resources:
                 *  - Collection
                 *  - Document
                 *  - File
                 *  - Account
                 *  - Session
                 *  - Team? (not implemented yet)
                 *  - Membership? (not implemented yet)
                 *  - Function
                 *  - Execution
                 */
                $event = json_decode($payload, true);

                $receivers = Realtime::identifyReceivers($event, $subscriptions);


                // Temporarily print debug logs by default for Alpha testing.
                // if (App::isDevelopment() && !empty($receivers)) {
                if (!empty($receivers)) {
                    Console::log("[Debug][Worker {$workerId}] Receivers: " . count($receivers));
                    Console::log("[Debug][Worker {$workerId}] Receivers Connection IDs: " . json_encode($receivers));
                    Console::log("[Debug][Worker {$workerId}] Event: " . $payload);
                }

                foreach ($receivers as $receiver) {
                    if ($server->exist($receiver) && $server->isEstablished($receiver)) {
                        $server->push(
                            $receiver,
                            json_encode($event['data']),
                            SWOOLE_WEBSOCKET_OPCODE_TEXT,
                            SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS
                        );
                    } else {
                        $server->close($receiver);
                    }
                }
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

$server->on('start', function (Server $server) {
    Console::success('Server started succefully');

    Console::info("Master pid {$server->master_pid}, manager pid {$server->manager_pid}");

    // listen ctrl + c
    Process::signal(2, function () use ($server) {
        Console::log('Stop by Ctrl+C');
        $server->shutdown();
    });
});

$server->on('open', function (Server $server, Request $request) use (&$connections, &$subscriptions, &$register, &$stats) {
    $app = new App('UTC');
    $connection = $request->fd;
    $request = new SwooleRequest($request);

    $db = $register->get('dbPool')->get();
    $redis = $register->get('redisPool')->get();

    $register->set('db', function () use (&$db) {
        return $db;
    });

    $register->set('cache', function () use (&$redis) { // Register cache connection
        return $redis;
    });

    Console::info("Connection open (user: {$connection}, worker: {$server->getWorkerId()})");

    App::setResource('request', function () use ($request) {
        return $request;
    });

    App::setResource('response', function () {
        return new Response(new SwooleResponse());
    });

    try {
        /** @var Appwrite\Database\Document $user */
        $user = $app->getResource('user');

        /** @var Appwrite\Database\Document $project */
        $project = $app->getResource('project');

        /** @var Appwrite\Database\Document $console */
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
        $timeLimit = new TimeLimit('url:{url},ip:{ip}', 128, 60, function () use ($db) {
            return $db;
        });
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

        Realtime::setUser($user);

        $roles = Realtime::getRoles();
        $channels = Realtime::parseChannels($request->getQuery('channels', []));

        /**
         * Channels Check
         */
        if (empty($channels)) {
            throw new Exception('Missing channels', 1008);
        }

        Realtime::subscribe($project->getId(), $connection, $roles, $subscriptions, $connections, $channels);

        $server->push($connection, json_encode($channels));

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
        $server->push($connection, json_encode($response));
        $server->close($connection);
    }
    /**
     * Put used PDO and Redis Connections back into their pools.
     */
    /** @var PDOPool $dbPool */
    $dbPool = $register->get('dbPool');
    $dbPool->put($db);

    /** @var RedisPool $redisPool */
    $redisPool = $register->get('redisPool');
    $redisPool->put($redis);
});

$server->on('message', function (Server $server, Frame $frame) {
    $server->push($frame->fd, 'Sending messages is not allowed.');
    $server->close($frame->fd);
});

$server->on('close', function (Server $server, int $connection) use (&$connections, &$subscriptions, &$stats) {
    if (array_key_exists($connection, $connections)) {
        $stats->decr($connections[$connection]['projectId'], 'connectionsTotal');
    }
    Realtime::unsubscribe($connection, $subscriptions, $connections);
    Console::info('Connection close: ' . $connection);
});

$server->start();
