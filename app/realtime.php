<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Auth\Auth;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Realtime\Realtime;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\Process;
use Swoole\WebSocket\Frame;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Swoole\Request as SwooleRequest;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;

/**
 * TODO List
 * 
 * - CORS Validation
 * - Limit payload size
 * - JWT Authentication (in path / or in message)
 * 
 * Protocols Support:
 * - Websocket support: https://www.swoole.co.uk/docs/modules/swoole-websocket-server
 * - MQTT support: https://www.swoole.co.uk/docs/modules/swoole-mqtt-server
 * - SSE support: https://github.com/hhxsv5/php-sse
 * - Socket.io support: https://github.com/shuixn/socket.io-swoole-server
 */

ini_set('default_socket_timeout', -1);
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$server = new Server('0.0.0.0', 80);

$server->set([
    'websocket_compression' => true,
    'package_max_length' => 64000 // Default maximum Package Size (64kb)
]);

$subscriptions = [];
$connections = [];

$register->set('redis', function () {
    $user = App::getEnv('_APP_REDIS_USER','');
    $pass = App::getEnv('_APP_REDIS_PASS','');
    $auth = '';
    if(!empty($user)) {
        $auth += $user;
    }
    if(!empty($pass)) {
        $auth += ':' . $pass;
    }

    $config = new RedisConfig();
    $config
        ->withHost(App::getEnv('_APP_REDIS_HOST', ''))
        ->withPort(App::getEnv('_APP_REDIS_PORT', ''))
        ->withAuth($auth)
        ->withTimeout(0)
        ->withReadTimeout(0)
        ->withRetryInterval(0);


    $pool = new RedisPool($config);

    return $pool;
});

$server->on('workerStart', function ($server, $workerId) use (&$subscriptions, &$connections, &$register) {
    Console::success('Worker ' . ++$workerId . ' started succefully');

    $attempts = 0;
    $start = time();

    while ($attempts < 300) {
        try {
            if ($attempts > 0) {
                Console::error('Pub/sub connection lost (lasted ' . (time() - $start) . ' seconds, worker: ' . $workerId . ').
                    Attempting restart in 5 seconds (attempt #' . $attempts . ')');
                sleep(5); // 5 sec delay between connection attempts
            }

            $redis = $register->get('redis')->get();
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

            if ($redis->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established (worker: ' . $workerId . ')');
            } else {
                Console::error('Pub/sub failed (worker: ' . $workerId . ')');
            }

            $redis->subscribe(['realtime'], function ($redis, $channel, $payload) use ($server, &$connections, &$subscriptions) {
                /**
                 * Supported Resources:
                 *  - Collection
                 *  - Document
                 *  - Bucket
                 *  - File
                 *  - User? / Account? (no permissions)
                 *  - Session? (no permissions)
                 *  - Team? (no permissions)
                 *  - Membership? (no permissions)
                 *  - Function
                 *  - Execution
                 */
                $event = json_decode($payload, true);

                $receivers = Realtime::identifyReceivers($event, $connections, $subscriptions);

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
            });
        } catch (\Throwable $th) {
            Console::error('Pub/sub error: ' . $th->getMessage());
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

$server->on('open', function (Server $server, Request $request) use (&$connections, &$subscriptions, &$register) {
    Console::info("Connection open (user: {$request->fd}, worker: {$server->getWorkerId()})");

    $app = new App('');
    $connection = $request->fd;
    $request = new SwooleRequest($request);

    App::setResource('request', function () use ($request) {
        return $request;
    });

    App::setResource('consoleDB', function () use (&$register) {
        $consoleDB = new Database();
        $consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register, true));
        $consoleDB->setNamespace('app_console'); // Should be replaced with param if we want to have parent projects
        $consoleDB->setMocks(Config::getParam('collections', []));

        return $consoleDB;
    }, ['register']);

    App::setResource('project', function ($consoleDB, $request) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Database\Database $consoleDB */

        Authorization::disable();

        $project = $consoleDB->getDocument($request->getQuery('project'));

        Authorization::reset();

        return $project;
    }, ['consoleDB', 'request']);

    App::setResource('user', function ($project, $request, $projectDB) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Database $projectDB */

        Authorization::setDefaultStatus(true);

        Auth::setCookieName('a_session_' . $project->getId());

        $session = Auth::decodeSession(
            $request->getCookie(
                Auth::$cookieName, // Get sessions
                $request->getCookie(Auth::$cookieName . '_legacy', '')
            )
        ); // Get fallback session from old clients (no SameSite support)

        Auth::$unique = $session['id'];
        Auth::$secret = $session['secret'];

        $user = $projectDB->getDocument(Auth::$unique);

        if (
            empty($user->getId()) // Check a document has been found in the DB
            || Database::SYSTEM_COLLECTION_USERS !== $user->getCollection() // Validate returned document is really a user document
            || !Auth::tokenVerify($user->getAttribute('tokens', []), Auth::TOKEN_TYPE_LOGIN, Auth::$secret)
        ) { // Validate user has valid login token
            $user = new Document(['$id' => '', '$collection' => Database::SYSTEM_COLLECTION_USERS]);
        }

        return $user;
    }, ['project', 'request', 'projectDB']);

    /** @var Appwrite\Database\Document $user */
    $user = $app->getResource('user');

    /** @var Appwrite\Database\Document $project */
    $project = $app->getResource('project');

    /*
     * Abuse Check
     */
    $timeLimit = new TimeLimit('url:{url},ip:{ip}', 60, 60, function () use ($register) {
        return $register->get('db');
    });
    $timeLimit
        ->setNamespace('app_' . $project->getId())
        ->setParam('{ip}', $request->getIP())
        ->setParam('{url}', $request->getURI());

    $abuse = new Abuse($timeLimit);

    if ($abuse->check() && App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
        $server->push($connection, 'Too many requests');
        $server->close($connection);
    }

    /*
     *  Project Check
     */
    if (empty($project->getId())) {
        $server->push($connection, 'Missing or unknown project ID');
        $server->close($connection);
    }

    Realtime::setUser($user);

    $roles = Realtime::getRoles();
    $channels = Realtime::parseChannels($request->getQuery('channels', []));

    /**
     * Channels Check
     */
    if (empty($channels)) {
        $server->push($connection, 'Missing channels');
        $server->close($connection);
    }
    
    Realtime::subscribe($project->getId(), $connection, $roles, $subscriptions, $connections, $channels);

    $server->push($connection, json_encode($channels));
});

$server->on('message', function (Server $server, Frame $frame) {
    if ($frame->data === 'reload') {
        $server->reload();
    }

    Console::info('Recieved message: ' . $frame->data . ' (user: ' . $frame->fd . ', worker: ' . $server->getWorkerId() . ')');
});

$server->on('close', function (Server $server, int $fd) use (&$connections, &$subscriptions) {
    Realtime::unsubscribe($fd, $subscriptions, $connections);
    Console::info('Connection close: ' . $fd);
});

$server->start();
