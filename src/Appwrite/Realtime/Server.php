<?php

namespace Appwrite\Realtime;

use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Event\Event;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Response;
use Exception;
use Swoole\Coroutine\Redis;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Process;
use Swoole\Table;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as SwooleServer;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Exception as UtopiaException;
use Utopia\Registry\Registry;
use Utopia\Swoole\Request as SwooleRequest;


class Server
{
    /**
     * Container scoped Registry.
     * @var Registry
     */
    public Registry $register;

    /**
     * Container scoped Swoole Server.
     * @var SwooleServer
     */
    public SwooleServer $server;

    /**
     * Container scoped Usage Table.
     * @var Table
     */
    public Table $usage;

    /**
     * Container scoped Database connection.
     * @var Database
     */
    public Database $consoleDb;

    /**
     * Container scoped Database connection.
     * @var Database
     */
    public Database $projectDb;

    /**
     * Container scoped Redis connection.
     * @var Redis
     */
    public Redis $cache;

    /**
     * Worker scoped subscription.
     * @var array
     */
    public array $subscriptions;

    /**
     * Worker scoped connections.
     * @var array
     */
    public array $connections;

    public function __construct(Registry &$register, $host = '0.0.0.0', $port = 80, $config = [])
    {
        $this->subscriptions = [];
        $this->connections = [];
        $this->register = $register;

        $this->usage = new Table(4096, 1);
        $this->usage->column('projectId', Table::TYPE_STRING, 64);
        $this->usage->column('connections', Table::TYPE_INT);
        $this->usage->column('connectionsTotal', Table::TYPE_INT);
        $this->usage->column('messages', Table::TYPE_INT);
        $this->usage->create();

        $this->consoleDb = new Database();
        $this->consoleDb->setAdapter(new RedisAdapter(new MySQLAdapter($this->register), $this->register));
        $this->consoleDb->setNamespace('app_console');
        $this->consoleDb->setMocks(Config::getParam('collections', []));

        $this->projectDb = new Database();
        $this->projectDb->setAdapter(new RedisAdapter(new MySQLAdapter($this->register), $this->register));
        $this->projectDb->setMocks(Config::getParam('collections', []));

        $this->server = new SwooleServer($host, $port, SWOOLE_PROCESS);
        $this->server->set($config);
        $this->server->on('start', [$this, 'onStart']);
        $this->server->on('workerStart', [$this, 'onWorkerStart']);
        $this->server->on('open', [$this, 'onOpen']);
        $this->server->on('message', [$this, 'onMessage']);
        $this->server->on('close', [$this, 'onClose']);
        $this->server->container_id = uniqid();
        $this->server->start();
    }

    /**
     * This is executed when the Realtime server starts.
     * 
     * @param SwooleServer $server 
     * @return void 
     */
    public function onStart(SwooleServer $server): void
    {
        Console::success('Server started succefully');
        Console::info("Master pid {$server->master_pid}, manager pid {$server->manager_pid}");

        try {
            go(function() {
                $document = [
                    '$collection' => Database::SYSTEM_COLLECTION_REALTIME_CONNECTIONS,
                    '$permissions' => [
                        'read' => ['*'],
                        'write' => ['*'],
                    ],
                    'container' => $this->server->container_id,
                    'timestamp' => time(),
                    'data' => '{}'
                ];
                Authorization::disable();
                $document = $this->consoleDb->createDocument($document);
                Authorization::enable();
                $this->server->document_id = $document->getId();
            });
        } catch (\Throwable $th) {
            Console::error('[Error] Type: '.get_class($th));
            Console::error('[Error] Message: '.$th->getMessage());
            Console::error('[Error] File: '.$th->getFile());
            Console::error('[Error] Line: '.$th->getLine());
        }


        Timer::tick(10000, function () {
            /** @var Table $stats */
            foreach ($this->usage as $projectId => $value) {
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

                $this->usage->set($projectId, [
                    'projectId' => $projectId,
                    'messages' => 0,
                    'connections' => 0
                ]);

                if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
                    $usage->trigger();
                }
            }
        });

        Timer::tick(10000, function () {
            $payload = [];
            foreach ($this->usage as $projectId => $value) {
                if (!empty($value['connectionsTotal'])) {
                    $payload[$projectId] = $value['connectionsTotal'];
                }
            }
            if (empty($payload)){
                return;
            }
            $document = [
                '$id' => $this->server->document_id,
                '$collection' => Database::SYSTEM_COLLECTION_REALTIME_CONNECTIONS,
                '$permissions' => [
                    'read' => ['*'],
                    'write' => ['*'],
                ],
                'container' => $this->server->container_id,
                'timestamp' => time(),
                'data' => json_encode($payload)
            ];
            try {
                $document = $this->consoleDb->updateDocument($document);
            } catch (\Throwable $th) {
                Console::error('[Error] Type: '.get_class($th));
                Console::error('[Error] Message: '.$th->getMessage());
                Console::error('[Error] File: '.$th->getFile());
                Console::error('[Error] Line: '.$th->getLine());
            }
        });

        Process::signal(2, function () use ($server) {
            Console::log('Stop by Ctrl+C');
            $server->shutdown();
        });
    }

    /**
     * This is executed when a WebSocket worker process starts.
     * 
     * @param SwooleServer $server 
     * @param int $workerId 
     * @return void 
     * @throws Exception 
     */
    public function onWorkerStart(SwooleServer $server, int $workerId): void
    {
        Console::success('Worker ' . $workerId . ' started succefully');

        $attempts = 0;
        $start = time();
        $redisPool = $this->register->get('redisPool');

        /**
         * Sending current connections to project channels on the console project every 5 seconds.
         */
        $server->tick(5000, function () use (&$server) {
            $this->tickSendProjectUsage($server);
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

                $redis->subscribe(['realtime'], function ($redis, $channel, $payload) use ($server, $workerId) {
                    $this->onRedisPublish($payload, $server, $workerId);
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
    }

    /**
     * This is executed when a new Realtime connection is established.
     * @param SwooleServer $server 
     * @param Request $request 
     * @return void 
     * @throws Exception 
     * @throws UtopiaException 
     */
    public function onOpen(SwooleServer $server, Request $request): void
    {
        $app = new App('UTC');
        $connection = $request->fd;
        $request = new SwooleRequest($request);

        $db = $this->register->get('dbPool')->get();
        $redis = $this->register->get('redisPool')->get();

        $this->register->set('db', function () use (&$db) {
            return $db;
        });

        $this->register->set('cache', function () use (&$redis) {
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

            Parser::setUser($user);

            $roles = Parser::getRoles();
            $channels = Parser::parseChannels($request->getQuery('channels', []));

            /**
             * Channels Check
             */
            if (empty($channels)) {
                throw new Exception('Missing channels', 1008);
            }

            Parser::subscribe($project->getId(), $connection, $roles, $this->subscriptions, $this->connections, $channels);

            $server->push($connection, json_encode($channels));

            $this->usage->incr($project->getId(), 'connections');
            $this->usage->incr($project->getId(), 'connectionsTotal');
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
        $dbPool = $this->register->get('dbPool');
        $dbPool->put($db);

        /** @var RedisPool $redisPool */
        $redisPool = $this->register->get('redisPool');
        $redisPool->put($redis);
    }

    /**
     * This is executed when a message is received by the Realtime server.
     * 
     * @param SwooleServer $server 
     * @param Frame $frame 
     * @return void 
     */
    public function onMessage(SwooleServer $server, Frame $frame)
    {
        $server->push($frame->fd, 'Sending messages is not allowed.');
        $server->close($frame->fd);
    }

    /**
     * This is executed when a Realtime connection is closed.
     * 
     * @param SwooleServer $server 
     * @param int $connection 
     * @return void 
     */
    public function onClose(SwooleServer $server, int $connection)
    {
        if (array_key_exists($connection, $this->connections)) {
            $this->usage->decr($this->connections[$connection]['projectId'], 'connectionsTotal');
        }
        Parser::unsubscribe($connection, $this->subscriptions, $this->connections);
        Console::info('Connection close: ' . $connection);
    }

    /**
     * This is executed when an event is published on realtime channel in Redis.
     * 
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
     * 
     * @param string $payload 
     * @param SwooleServer $server 
     * @param int $workerId 
     * @return void 
     */
    public function onRedisPublish(string $payload, SwooleServer &$server, int $workerId)
    {
        $event = json_decode($payload, true);

        if ($event['permissionsChanged'] && isset($event['userId'])) {
            $this->addPermission($event);
        }

        $receivers = Parser::identifyReceivers($event, $this->subscriptions);

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
            $this->usage->incr($event['project'], 'messages', $num);
        }
    }

    /**
     * This sends the usage to the `console` channel.
     * 
     * @param SwooleServer $server 
     * @return void 
     */
    public function tickSendProjectUsage(SwooleServer &$server)
    {
        if (
            array_key_exists('console', $this->subscriptions)
            && array_key_exists('role:member', $this->subscriptions['console'])
            && array_key_exists('project', $this->subscriptions['console']['role:member'])
        ) {
            $payload = [];
            $list = $this->consoleDb->getCollection([
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_REALTIME_CONNECTIONS,
                    'timestamp>'.(time() - 15)
                ],
            ]);

            foreach ($list as $document) {
                foreach (json_decode($document->getAttribute('data')) as $projectId => $value) {
                    if (array_key_exists($projectId, $payload)) {
                        $payload[$projectId] +=  $value;
                    } else {
                        $payload[$projectId] =  $value;
                    }
                }
            }
            foreach ($this->subscriptions['console']['role:member']['project'] as $connection => $value) {
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

    private function addPermission(array $event)
    {
        $project = $event['project'];
        $userId = $event['userId'];

        if (array_key_exists($project, $this->subscriptions) && array_key_exists('user:'.$userId, $this->subscriptions[$project])) {
            $connection = array_key_first(reset($this->subscriptions[$project]['user:'.$userId]));
        } else {
            return;
        }

        $this->projectDb->setNamespace('app_'.$project);

        $user = $this->projectDb->getDocument($userId);

        Parser::setUser($user);

        $roles = Parser::getRoles();

        Parser::subscribe($project, $connection, $roles, $this->subscriptions, $this->connections, $this->connections[$connection]['channels']);
    }
}
