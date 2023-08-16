<?php

use Appwrite\Auth\Auth;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Network\Validator\Origin;
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
use Appwrite\Utopia\Request;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\WebSocket\Server;
use Utopia\WebSocket\Adapter;

require_once __DIR__ . '/init.php';

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

function getConsoleDB(): Database
{
    global $register;

    /** @var \Utopia\Pools\Group $pools */
    $pools = $register->get('pools');

    $dbAdapter = $pools
        ->get('console')
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, getCache());

    $database->setNamespace('console');

    return $database;
}

function getProjectDB(Document $project): Database
{
    global $register;

    /** @var \Utopia\Pools\Group $pools */
    $pools = $register->get('pools');

    if ($project->isEmpty() || $project->getId() === 'console') {
        return getConsoleDB();
    }

    $dbAdapter = $pools
        ->get($project->getAttribute('database'))
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, getCache());
    $database->setNamespace('_' . $project->getInternalId());

    return $database;
}

function getCache(): Cache
{
    global $register;

    $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
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

$logError = function (Throwable $error, string $action) use ($register) {
    $logger = $register->get('logger');

    if ($logger) {
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        $log = new Log();
        $log->setNamespace("realtime");
        $log->setServer(\gethostname());
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
};

$server->error($logError);

$server->onStart(function () use ($stats, $register, $containerId, &$statsDocument, $logError) {
    sleep(5); // wait for the initial database schema to be ready
    Console::success('Server started successfully');

    /**
     * Create document for this worker to share stats across Containers.
     */
    go(function () use ($register, $containerId, &$statsDocument) {
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

                $statsDocument = Authorization::skip(fn () => $database->createDocument('realtime', $document));
                break;
            } catch (\Throwable $th) {
                Console::warning("Collection not ready. Retrying connection ({$attempts})...");
                sleep(DATABASE_RECONNECT_SLEEP);
            }
        } while (true);
        $register->get('pools')->reclaim();
    });

    /**
     * Save current connections to the Database every 5 seconds.
     */
    Timer::tick(5000, function () use ($register, $stats, &$statsDocument, $logError) {
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

            Authorization::skip(fn () => $database->updateDocument('realtime', $statsDocument->getId(), $statsDocument));
        } catch (\Throwable $th) {
            call_user_func($logError, $th, "updateWorkerDocument");
        } finally {
            $register->get('pools')->reclaim();
        }
    });
});

$server->onWorkerStart(function (int $workerId) use ($server, $register, $stats, $realtime, $logError) {
    Console::success('Worker ' . $workerId . ' started successfully');

    $attempts = 0;
    $start = time();

    Timer::tick(5000, function () use ($server, $register, $realtime, $stats, $logError) {
        /**
         * Sending current connections to project channels on the console project every 5 seconds.
         */
        if ($realtime->hasSubscriber('console', Role::users()->toString(), 'project')) {
            $database = getConsoleDB();

            $payload = [];

            $list = Authorization::skip(fn () => $database->find('realtime', [
                Query::greaterThan('timestamp', DateTime::addSeconds(new \DateTime(), -15)),
            ]));

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
                if (!array_key_exists($projectId, $payload)) {
                    continue;
                }

                $event = [
                    'project' => 'console',
                    'roles' => ['team:' . $stats->get($projectId, 'teamId')],
                    'data' => [
                        'events' => ['stats.connections'],
                        'channels' => ['project'],
                        'timestamp' => DateTime::now(),
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

            $register->get('pools')->reclaim();
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
                    'timestamp' => DateTime::now(),
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

            $redis = $register->get('pools')->get('pubsub')->pop()->getResource(); /** @var Redis $redis */
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
                        $consoleDatabase = getConsoleDB();
                        $project = Authorization::skip(fn() => $consoleDatabase->getDocument('projects', $projectId));
                        $database = getProjectDB($project);

                        $user = $database->getDocument('users', $userId);

                        $roles = Auth::getRoles($user);

                        $realtime->subscribe($projectId, $connection, $roles, $realtime->connections[$connection]['channels']);

                        $register->get('pools')->reclaim();
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
        } catch (\Throwable $th) {
            call_user_func($logError, $th, "pubSubConnection");

            Console::error('Pub/sub error: ' . $th->getMessage());
            $attempts++;
            sleep(DATABASE_RECONNECT_SLEEP);
            continue;
        } finally {
            $register->get('pools')->reclaim();
        }
    }

    Console::error('Failed to restart pub/sub...');
});

$server->onOpen(function (int $connection, SwooleRequest $request) use ($server, $register, $stats, &$realtime, $logError) {
    $app = new App('UTC');
    $request = new Request($request);
    $response = new Response(new SwooleResponse());

    Console::info("Connection open (user: {$connection})");

    App::setResource('pools', fn() => $register->get('pools'));
    App::setResource('request', fn() => $request);
    App::setResource('response', fn() => $response);

    try {
        /** @var \Utopia\Database\Document $project */
        $project = $app->getResource('project');

        /*
         *  Project Check
         */
        if (empty($project->getId())) {
            throw new Exception('Missing or unknown project ID', 1008);
        }

        $dbForProject = getProjectDB($project);
        $console = $app->getResource('console'); /** @var \Utopia\Database\Document $console */
        $user = $app->getResource('user'); /** @var \Utopia\Database\Document $user */

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
    } catch (\Throwable $th) {
        call_user_func($logError, $th, "initServer");

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
        $register->get('pools')->reclaim();
    }
});

$server->onMessage(function (int $connection, string $message) use ($server, $register, $realtime, $containerId) {
    try {
        $app = new App('UTC');
        $response = new Response(new SwooleResponse());
        $projectId = $realtime->connections[$connection]['projectId'];
        $database = getConsoleDB();

        if ($projectId !== 'console') {
            $project = Authorization::skip(fn() => $database->getDocument('projects', $projectId));
            $database = getProjectDB($project);
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
            throw new Exception('Too many messages', 1013);
        }

        $message = json_decode($message, true);

        if (is_null($message) || (!array_key_exists('type', $message) && !array_key_exists('data', $message))) {
            throw new Exception('Message format is not valid.', 1003);
        }

        switch ($message['type']) {
                /**
             * This type is used to authenticate.
             */
            case 'authentication':
                if (!array_key_exists('session', $message['data'])) {
                    throw new Exception('Payload is not valid.', 1003);
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
                    throw new Exception('Session is not valid.', 1003);
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
                throw new Exception('Message type is not valid.', 1003);
        }
    } catch (\Throwable $th) {
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
        $register->get('pools')->reclaim();
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
