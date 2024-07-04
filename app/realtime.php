<?php

use Appwrite\Auth\Auth;
use Appwrite\Extend\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Queue\Connections;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleHttpResponse;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\DI\Container;
use Utopia\DI\Dependency;
use Utopia\Http\Adapter\Swoole\Request as UtopiaRequest;
use Utopia\Http\Adapter\Swoole\Response as HttpResponse;
use Utopia\Http\Adapter\Swoole\Response as UtopiaResponse;
use Utopia\Http\Http;
use Utopia\Logger\Log;
use Utopia\Pools\Connection;
use Utopia\Registry\Registry;
use Utopia\System\System;
use Utopia\WebSocket\Adapter;
use Utopia\WebSocket\Server;

/**
 * @var Registry $global
 * @var Container $container
 */
global $global, $container;


require_once __DIR__ . '/init2.php';

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
$statsDocument = null;
$workerNumber = swoole_cpu_num() * intval(System::getEnv('_APP_WORKER_PER_CORE', 6));

$adapter = new Adapter\Swoole(port: System::getEnv('PORT', 80));
$adapter
    ->setPackageMaxLength(64000) // Default maximum Package Size (64kb)
    ->setWorkerNumber($workerNumber);

$server = new Server($adapter);

$logError = function (Throwable $error, string $action) use ($global) {
    $logger = $global->get('logger');

    if ($logger && !$error instanceof Exception) {
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

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

        $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
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

$server->onStart(function () use ($stats, $container, $containerId, &$statsDocument, $logError) {
    sleep(5); // wait for the initial database schema to be ready
    Console::success('Server started successfully');
    $authorization = $container->get('authorization');
    /**
     * Create document for this worker to share stats across Containers.
     */
    go(function () use ($container, $containerId, &$statsDocument) {
        $attempts = 0;
        $database = $container->get('dbForConsole');

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

                $authorization = $container->get('authorization');
                $statsDocument = $authorization->skip(fn () => $database->createDocument('realtime', $document));
                break;
            } catch (Throwable) {
                Console::warning("Collection not ready. Retrying connection ({$attempts})...");
                sleep(DATABASE_RECONNECT_SLEEP);
            }
        } while (true);
        //   TODO NOW     $global->get('pools')->reclaim();
    });

    /**
     * Save current connections to the Database every 5 seconds.
     */
    // TODO: Remove this if check once it doesn't cause issues for cloud
    if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
        Timer::tick(5000, function () use ($container, $stats, &$statsDocument, $logError, $authorization) {
            $payload = [];
            foreach ($stats as $projectId => $value) {
                $payload[$projectId] = $stats->get($projectId, 'connectionsTotal');
            }
            if (empty($payload) || empty($statsDocument)) {
                return;
            }

            try {
                $database = $container->get('dbForConsole');

                $statsDocument
                    ->setAttribute('timestamp', DateTime::now())
                    ->setAttribute('value', json_encode($payload));

                $authorization->skip(fn () => $database->updateDocument('realtime', $statsDocument->getId(), $statsDocument));
            } catch (Throwable $th) {
                call_user_func($logError, $th, "updateWorkerDocument");
            } finally {
                // TODO NOW  $global->get('pools')->reclaim();
            }
        });
    }
});

$server->onWorkerStart(function (int $workerId) use ($server, $container, $stats, $realtime, $logError) {
    Console::success('Worker ' . $workerId . ' started successfully');

    $attempts = 0;
    $start = time();

    $authorization = $container->get('authorization');

    Timer::tick(5000, function () use ($server, $container, $realtime, $stats, $logError, $authorization) {
        /**
         * Sending current connections to project channels on the console project every 5 seconds.
         */
        // TODO: Remove this if check once it doesn't cause issues for cloud
        if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
            if ($realtime->hasSubscriber('console', Role::users()->toString(), 'project')) {
                $database = $container->get('dbForConsole');

                $payload = [];

                $list = $authorization->skip(fn () => $database->find('realtime', [
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

                    $server->send($realtime->getSubscribers($event), json_encode([
                        'type' => 'event',
                        'data' => $event['data']
                    ]));
                }
                // TODO NOW $global->get('pools')->reclaim();
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
                Console::error(
                    'Pub/sub connection lost (lasted ' . (time() - $start) . ' seconds, worker: ' . $workerId . ').
                    Attempting restart in 5 seconds (attempt #' . $attempts . ')'
                );
                sleep(5); // 5 sec delay between connection attempts
            }

            $start = time();

            $pools = $container->get('pools');
            /** @var Connections $connections */
            $connections = $container->get('connections');

            $pool = $pools['pools-pubsub-main']['pool'];
            $connection = $pool->get();
            $connections->add($connection, $pool);

            $redis = $connection;

            /** @var Redis $redis */
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

            if ($redis->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established (worker: ' . $workerId . ')');
            } else {
                Console::error('Pub/sub failed (worker: ' . $workerId . ')');
            }

            $redis->subscribe(['realtime'], function (Redis $redis, string $channel, string $payload) use ($server, $workerId, $stats, $realtime, $authorization, $container) {
                $event = json_decode($payload, true);

                if ($event['permissionsChanged'] && isset($event['userId'])) {
                    $projectId = $event['project'];
                    $userId = $event['userId'];

                    if ($realtime->hasSubscriber($projectId, 'user:' . $userId)) {
                        $connection = array_key_first(reset($realtime->subscriptions[$projectId]['user:' . $userId]));
                        $consoleDatabase = $container->get('dbForConsole');

                        $project = $authorization->skip(fn () => $consoleDatabase->getDocument('projects', $projectId));
                        $database = $container->get('getProjectDB')($project);

                        $user = $database->getDocument('users', $userId);

                        $roles = Auth::getRoles($user, $authorization);

                        $realtime->subscribe($projectId, $connection, $roles, $realtime->connections[$connection]['channels']);
                        //TODO NOW $global->get('pools')->reclaim();
                    }
                }

                $receivers = $realtime->getSubscribers($event);

                if (Http::isDevelopment() && !empty($receivers)) {
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
            call_user_func($logError, $th, "pubSubConnection");

            Console::error('Pub/sub error: ' . $th->getMessage());
            $attempts++;
            sleep(DATABASE_RECONNECT_SLEEP);
            continue;
        } finally {
            //$global->get('pools')->reclaim();
            // TODO eldad add connections reclaim
        }
    }

    Console::error('Failed to restart pub/sub...');
});

$server->onOpen(function (int $connection, SwooleRequest $request) use ($server, $container, $stats, &$realtime, $logError) {
    $authorization = $container->get('authorization');

    $request = new Request(new UtopiaRequest($request));
    $response = new Response(new UtopiaResponse(new SwooleResponse()));

    $requestInjection = new Dependency();
    $responseInjection = new Dependency();

    $requestInjection->setName('request')->setCallback(fn () => $request);
    $responseInjection->setName('response')->setCallback(fn () => $response);

    $container->set($requestInjection);
    $container->set($responseInjection);

    Console::info("Connection open (user: {$connection})");

    try {
        $dbForConsole = $container->get('dbForConsole');

        /** @var Document $project */
        $project = $container->refresh('project')->get('project');

        $container->refresh('dbForProject');

        /*
         * Project Check
         */
        if (empty($project->getId())) {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, 'Missing or unknown project ID');
        }

        if (
            array_key_exists('realtime', $project->getAttribute('apis', []))
            && !$project->getAttribute('apis', [])['realtime']
            && !(Auth::isPrivilegedUser($authorization->getRoles()) || Auth::isAppUser($authorization->getRoles()))
        ) {
            throw new AppwriteException(AppwriteException::GENERAL_API_DISABLED);
        }

        $dbForProject = $container->get('getProjectDB')($project);
        /** @var Document $console */
        $console = $container->get('console');
        /** @var Document $user */
        $user = $container->refresh('user')->get('user');
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

        if (System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled' && $abuse->check()) {
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

        $authorization = $container->get('authorization');
        $roles = Auth::getRoles($user, $authorization);

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
        call_user_func($logError, $th, "initServer");

        // Handle SQL error code is 'HY000'
        $code = $th->getCode();
        if (!is_int($code)) {
            $code = 500;
        }

        $response = [
            'type' => 'error',
            'data' => [
                'code' => $code,
                'message' => $th->getMessage()
            ]
        ];

        $server->send([$connection], json_encode($response));
        $server->close($connection, $code);

        if (Http::isDevelopment()) {
            Console::error('[Error] Connection Error');
            Console::error('[Error] Code: ' . $response['data']['code']);
            Console::error('[Error] Message: ' . $response['data']['message']);
        }
    } finally {
        //       TODO NOW $global->get('pools')->reclaim();
    }
});

$server->onWorkerStop(function (int $workerId) use ($container) {
    $connections = $container->get('connections');
    $connections->reclaim();
});

$server->onMessage(function (int $connection, string $message) use ($server, $container, $realtime, $containerId) {
    try {
        $response = new Response(new HttpResponse(new SwooleHttpResponse()));
        $projectId = $realtime->connections[$connection]['projectId'];
        $database = $container->get('dbForConsole');
        $authorization = $container->get('authorization');
        $authentication = $container->get('authentication');

        if ($projectId !== 'console') {
            $project = $authorization->skip(fn () => $database->getDocument('projects', $projectId));
            $database = $container->get('getProjectDB')($project);
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

        if ($abuse->check() && System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
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

                $authentication->setUnique($session['id'] ?? '');
                $authentication->setSecret($session['secret'] ?? '');

                $user = $database->getDocument('users', $authentication->getUnique());

                if (
                    empty($user->getId()) // Check a document has been found in the DB
                    || !Auth::sessionVerify($user->getAttribute('sessions', []), $authentication->getSecret()) // Validate user has valid login token
                ) {
                    // cookie not valid
                    throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Session is not valid.');
                }

                $roles = Auth::getRoles($user, $authorization);
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
        //       TODO NOW $global->get('pools')->reclaim();
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
