<?php

use Appwrite\Auth\Auth;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Database;
use Appwrite\Event\Event;
use Appwrite\Network\Validator\Origin;
use Appwrite\Realtime\Parser;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Process;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;
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

$server->onStart(function (SwooleServer $server) use ($stats) {
    Console::success('Server started succefully');
    Console::info("Master pid {$server->master_pid}, manager pid {$server->manager_pid}");

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

    Process::signal(2, function () use ($server) {
        Console::log('Stop by Ctrl+C');
        $server->shutdown();
    });
});

$server->onWorkerStart(function (SwooleServer $swooleServer, int $workerId) use ($server, $register, $stats, &$subscriptions, &$connections) {
    Console::success('Worker ' . $workerId . ' started succefully');

    $attempts = 0;
    $start = time();
    $redisPool = $register->get('redisPool');

    /**
     * Sending current connections to project channels on the console project every 5 seconds.
     */
    Timer::tick(5000, function () use ($server, $stats, &$subscriptions) {
        if (
            array_key_exists('console', $subscriptions)
            && array_key_exists('role:member', $subscriptions['console'])
            && array_key_exists('project', $subscriptions['console']['role:member'])
        ) {
            $payload = [];
            foreach ($stats as $projectId => $value) {
                $payload[$projectId] = $value['connectionsTotal'];
            }
            $server->send(array_keys($subscriptions['console']['role:member']['project']), json_encode([
                'event' => 'stats.connections',
                'channels' => ['project'],
                'timestamp' => time(),
                'payload' => $payload
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

            /** @var Redis $redis */
            $redis = $redisPool->get();
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

            if ($redis->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established (worker: ' . $workerId . ')');
            } else {
                Console::error('Pub/sub failed (worker: ' . $workerId . ')');
            }

            $redis->subscribe(['realtime'], function ($redis, $channel, $payload) use ($server, $workerId, $stats, $register, &$connections, &$subscriptions) {
                $event = json_decode($payload, true);

                if ($event['permissionsChanged'] && isset($event['userId'])) {
                    $project = $event['project'];
                    $userId = $event['userId'];

                    if (array_key_exists($project, $subscriptions) && array_key_exists('user:' . $userId, $subscriptions[$project])) {
                        $connection = array_key_first(reset($subscriptions[$project]['user:' . $userId]));
                    } else {
                        return;
                    }

                    /**
                     * This is redundant soon and will be gone with merging the usage branch.
                     */
                    $db = $register->get('dbPool')->get();
                    $cache = $register->get('redisPool')->get();

                    $projectDB = new Database();
                    $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
                    $projectDB->setNamespace('app_' . $project);
                    $projectDB->setMocks(Config::getParam('collections', []));

                    $user = $projectDB->getDocument($userId);

                    Parser::setUser($user);

                    $roles = Auth::getRoles($user);

                    Parser::subscribe($project, $connection, $roles, $subscriptions, $connections, $connections[$connection]['channels']);

                    $register->get('dbPool')->put($db);
                    $register->get('redisPool')->put($cache);
                }

                $receivers = Parser::identifyReceivers($event, $subscriptions);

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

$server->onOpen(function (SwooleServer $swooleServer, SwooleRequest $request) use ($server, $register, $stats, &$subscriptions, &$connections) {
    $app = new App('UTC');
    $connection = $request->fd;
    $request = new Request($request);

    /** @var PDO $db */
    $db = $register->get('dbPool')->get();
    /** @var Redis $redis */
    $redis = $register->get('redisPool')->get();

    Console::info("Connection open (user: {$connection}, worker: {$swooleServer->getWorkerId()})");

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
        $timeLimit = new TimeLimit('url:{url},ip:{ip}', 128, 60, function () use (&$db) {
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

        Parser::setUser($user);

        $roles = Parser::getRoles();
        $channels = Parser::parseChannels($request->getQuery('channels', []));

        /**
         * Channels Check
         */
        if (empty($channels)) {
            throw new Exception('Missing channels', 1008);
        }

        Parser::subscribe($project->getId(), $connection, $roles, $subscriptions, $connections, $channels);

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
    } finally {
        /**
         * Put used PDO and Redis Connections back into their pools.
         */
        $register->get('dbPool')->put($db);
        $register->get('redisPool')->put($redis);
    }
});

$server->onMessage(function (SwooleServer $swooleServer, Frame $frame) use ($server) {
    $connection = $frame->fd;
    $server->send([$connection], 'Sending messages is not allowed.');
    $server->close($connection, 1003);
});

$server->onClose(function (SwooleServer $server, int $connection) use (&$connections, &$subscriptions, $stats) {
    if (array_key_exists($connection, $connections)) {
        $stats->decr($connections[$connection]['projectId'], 'connectionsTotal');
    }
    Parser::unsubscribe($connection, $subscriptions, $connections);
    Console::info('Connection close: ' . $connection);
});

$server->start();
