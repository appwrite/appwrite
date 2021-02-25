<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Auth\Auth;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Extend\PDO;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\Process;
use Swoole\WebSocket\Frame;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Registry\Registry;
use Utopia\Swoole\Request as SwooleRequest;
use PDO as PDONative;

/**
 * TODO List
 * 
 * - Abuse Control / x mesages per connection
 * - CORS Validation
 * - Limit payload size
 * - Message structure: { status: "ok"|"error", event: EVENT_NAME, data: <any arbitrary data> }
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

$server = new Server("0.0.0.0", 80);
$server->set([
    'worker_num' => 1
]);
$subscriptions = [];
$connections = [];

$register = new Registry();

$register->set('db', function () { // Register DB connection
    $dbHost = App::getEnv('_APP_DB_HOST', '');
    $dbUser = App::getEnv('_APP_DB_USER', '');
    $dbPass = App::getEnv('_APP_DB_PASS', '');
    $dbScheme = App::getEnv('_APP_DB_SCHEMA', '');

    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
        PDONative::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        PDONative::ATTR_TIMEOUT => 3, // Seconds
        PDONative::ATTR_PERSISTENT => true
    ));

    // Connection settings
    $pdo->setAttribute(PDONative::ATTR_DEFAULT_FETCH_MODE, PDONative::FETCH_ASSOC);   // Return arrays
    $pdo->setAttribute(PDONative::ATTR_ERRMODE, PDONative::ERRMODE_EXCEPTION);        // Handle all errors with exceptions

    return $pdo;
});

$register->set('cache', function () { // Register cache connection
    $redis = new Redis();
    $redis->pconnect(App::getEnv('_APP_REDIS_HOST', ''), App::getEnv('_APP_REDIS_PORT', ''));
    $user = App::getEnv('_APP_REDIS_USER', '');
    $pass = App::getEnv('_APP_REDIS_PASS', '');
    $auth = [];
    if (!empty($user)) {
        $auth["user"] = $user;
    }
    if (!empty($pass)) {
        $auth["pass"] = $pass;
    }
    if (!empty($auth)) {
        $redis->auth($auth);
    }
    $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

    return $redis;
});

$server->on("workerStart", function ($server, $workerId) use (&$subscriptions, &$connections, &$register) {
    Console::success('Worker ' . ++$workerId . ' started succefully');

    $attempts = 0;
    $start = time();

    while ($attempts < 300) {
        try {
            if ($attempts > 0) {
                Console::error('Pub/sub connection lost (lasted ' . (time() - $start) . ' seconds, worker: ' . $workerId . ').
                    Attempting restart in 5 seconds (attempt #' . $attempts . ')');
                sleep(5); // 1 sec delay between connection attempts
            }

            $redis = $register->get('cache');

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

                $receivers = [];

                foreach ($connections as $fd => $connection) {
                    if ($connection['projectId'] !== $event['project']) {
                        continue;
                    }

                    foreach ($connection['roles'] as $role) {
                        if (\array_key_exists($role, $subscriptions[$event['project']])) {
                            foreach ($event['data']['channels'] as $channel) {
                                if (\array_key_exists($channel, $subscriptions[$event['project']][$role]) && \in_array($role, $event['permissions'])) {
                                    foreach (array_keys($subscriptions[$event['project']][$role][$channel]) as $ids) {
                                        $receivers[] = $ids;
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }

                $receivers = array_keys(array_flip($receivers));

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

$server->on("start", function (Server $server) {
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
        $consoleDB->setAdapter(new MySQLAdapter($register)); // TODO: Add Redis
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

    $channels = $request->getQuery('channels', []);

    if (empty($project->getId())) {
        $server->push($connection, 'Missing or unknown project ID');
        $server->close($connection);
    }

    if (empty($request->getQuery('channels', []))) {
        $server->push($connection, 'Missing or unknown channels');
        $server->close($connection);
    }

    $roles = ['*', 'user:' . $user->getId(), 'role:' . (($user->isEmpty()) ? Auth::USER_ROLE_GUEST : Auth::USER_ROLE_MEMBER)];
    $channels = array_flip($channels);

    \array_map(function ($node) use (&$roles) {
        if (isset($node['teamId']) && isset($node['roles'])) {
            $roles[] = 'team:' . $node['teamId'];

            foreach ($node['roles'] as $nodeRole) { // Set all team roles
                $roles[] = 'team:' . $node['teamId'] . '/' . $nodeRole;
            }
        }
    }, $user->getAttribute('memberships', []));

    /**
     * Build Subscriptions Tree
     * 
     * [PROJECT_ID] -> 
     *      [ROLE_X] -> 
     *          [CHANNEL_NAME_X] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Y] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Z] -> [CONNECTION_ID]
     *      [ROLE_Y] -> 
     *          [CHANNEL_NAME_X] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Y] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Z] -> [CONNECTION_ID]
     */

    if (!isset($subscriptions[$project->getId()])) { // Init Project
        $subscriptions[$project->getId()] = [];
    }

    foreach ($roles as $key => $role) {
        if (!isset($subscriptions[$project->getId()][$role])) { // Add user first connection
            $subscriptions[$project->getId()][$role] = [];
        }

        foreach ($channels as $channel => $list) {
            $subscriptions[$project->getId()][$role][$channel][$connection] = true;
        }
    }

    $connections[$connection] = [
        'projectId' => $project->getId(),
        'roles' => $roles,
    ];
});

$server->on('message', function (Server $server, Frame $frame) {
    if ($frame->data === 'reload') {
        $server->reload();
    }

    Console::info('Recieved message: ' . $frame->data . ' (user: ' . $frame->fd . ', worker: ' . $server->getWorkerId() . ')');
});

$server->on('close', function (Server $server, int $fd) use (&$connections, &$subscriptions) {
    $projectId = $connections[$fd]['projectId'] ?? '';
    $roles = $connections[$fd]['roles'] ?? [];

    foreach ($roles as $key => $role) {
        foreach ($subscriptions[$projectId][$role] as $channel => $list) {
            unset($subscriptions[$projectId][$role][$channel][$fd]); // Remove connection

            if (empty($subscriptions[$projectId][$role][$channel])) {
                unset($subscriptions[$projectId][$role][$channel]);  // Remove channel when no connections
            }
        }

        if (empty($subscriptions[$projectId][$role])) {
            unset($subscriptions[$projectId][$role]); // Remove role when no channels
        }
    }

    if (empty($subscriptions[$projectId])) { // Remove project when no roles
        unset($subscriptions[$projectId]);
    }

    unset($connections[$fd]);

    Console::info('Connection close: ' . $fd);
});

$server->start();