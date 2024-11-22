<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Constant;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Process;
use Swoole\Table;
use Utopia\Abuse\Adapters\Database\TimeLimit;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Logger\Log\User;
use Utopia\Pools\Group;
use Utopia\Swoole\Files;
use Utopia\System\System;

const DOMAIN_SYNC_TIMER = 30; // 30 seconds

$domains = new Table(1_000_000); // 1 million rows
$domains->column('value', Table::TYPE_INT, 1);
$domains->create();

$http = new Server(
    host: "0.0.0.0",
    port: System::getEnv('PORT', 80),
    mode: SWOOLE_PROCESS,
);

$payloadSize = 12 * (1024 * 1024); // 12MB - adding slight buffer for headers and other data that might be sent with the payload - update later with valid testing
$totalWorkers = swoole_cpu_num() * intval(System::getEnv('_APP_WORKER_PER_CORE', 6));

$http
    ->set([
        'worker_num' => $totalWorkers,
        'dispatch_func' => 'dispatch',
        'open_http2_protocol' => true,
        'http_compression' => false,
        'package_max_length' => $payloadSize,
        'buffer_output_size' => $payloadSize,
    ]);

$http->on(Constant::EVENT_WORKER_START, function ($server, $workerId) {
    Console::success('Worker ' . ++$workerId . ' started successfully');
});

$http->on(Constant::EVENT_BEFORE_RELOAD, function ($server, $workerId) {
    Console::success('Starting reload...');
});

$http->on(Constant::EVENT_AFTER_RELOAD, function ($server, $workerId) {
    Console::success('Reload completed...');
});

/**
 * Assigns HTTP requests to worker threads by analyzing its payload/content.
 *
 * Routes requests as 'safe' or 'risky' based on specific content patterns (like POST actions or certain domains)
 * to optimize load distribution between the workers. Utilizes `$safeThreadsPercent` to manage risk by assigning
 * riskier tasks to a dedicated worker subset. Prefers idle workers, with fallback to random selection if necessary.
 * doc: https://openswoole.com/docs/modules/swoole-server/configuration#dispatch_func
 *
 * @param Server $server Swoole server instance.
 * @param int $fd client ID
 * @param int $type the type of data and its current state
 * @param string|null $data Request content for categorization.
 * @global int $totalThreads Total number of workers.
 * @return int Chosen worker ID for the request.
 */
function dispatch(Server $server, int $fd, int $type, $data = null): int
{
    global $totalWorkers, $domains;

    // If data is not set we can send request to any worker
    // first we try to pick idle worker, if not we randomly pick a worker
    if ($data === null) {
        for ($i = 0; $i < $totalWorkers; $i++) {
            if ($server->getWorkerStatus($i) === SWOOLE_WORKER_IDLE) {
                return $i;
            }
        }
        return rand(0, $totalWorkers - 1);
    }

    $riskyWorkersPercent = intval(System::getEnv('_APP_RISKY_WORKERS_PERCENT', 80)) / 100; // Decimal form 0 to 1

    // Each worker has numeric ID, starting from 0 and incrementing
    // From 0 to riskyWorkers, we consider safe workers
    // From riskyWorkers to totalWorkers, we consider risky workers
    $riskyWorkers = (int) floor($totalWorkers * $riskyWorkersPercent); // Absolute amount of risky workers

    $domain = '';
    // max up to 3 as first line has request details and second line has host
    $lines = explode("\n", $data, 3);
    $request = $lines[0];
    if (count($lines) > 1) {
        $domain = trim(explode('Host: ', $lines[1])[1]);
    }

    // Sync executions are considered risky
    $risky = false;
    if (str_starts_with($request, 'POST') && str_contains($request, '/executions')) {
        $risky = true;
    } elseif (str_ends_with($domain, System::getEnv('_APP_DOMAIN_FUNCTIONS'))) {
        $risky = true;
    } elseif ($domains->get(md5($domain), 'value') === 1) {
        // executions request coming from custom domain
        $risky = true;
    }

    if ($risky) {
        // If risky request, only consider risky workers
        for ($j = $riskyWorkers; $j < $totalWorkers; $j++) {
            /** Reference https://openswoole.com/docs/modules/swoole-server-getWorkerStatus#description */
            if ($server->getWorkerStatus($j) === SWOOLE_WORKER_IDLE) {
                // If idle worker found, give to him
                return $j;
            }
        }

        // If no idle workers, give to random risky worker
        $worker = rand($riskyWorkers, $totalWorkers - 1);
        Console::warning("swoole_dispatch: Risky branch: did not find a idle worker, picking random worker {$worker}");
        return $worker;
    }

    // If safe request, give to any idle worker
    // Its fine to pick risky worker here, because it's idle. Idle is never actually risky
    for ($i = 0; $i < $totalWorkers; $i++) {
        if ($server->getWorkerStatus($i) === SWOOLE_WORKER_IDLE) {
            return $i;
        }
    }

    // If no idle worker found, give to random safe worker
    // We avoid risky workers here, as it could be in work - not idle. Thats exactly when they are risky.
    $worker = rand(0, $riskyWorkers - 1);
    Console::warning("swoole_dispatch: Non-risky branch: did not find a idle worker, picking random worker {$worker}");
    return $worker;
}

include __DIR__ . '/controllers/general.php';

$http->on(Constant::EVENT_START, function (Server $http) use ($payloadSize, $register) {
    $app = new App('UTC');

    go(function () use ($register, $app) {
        $pools = $register->get('pools');
        /** @var Group $pools */
        App::setResource('pools', fn () => $pools);

        // wait for database to be ready
        $attempts = 0;
        $max = 10;
        $sleep = 1;

        do {
            try {
                $attempts++;
                $dbForConsole = $app->getResource('dbForConsole');
                /** @var Utopia\Database\Database $dbForConsole */
                break; // leave the do-while if successful
            } catch (\Throwable $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('Failed to connect to database: ' . $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < $max);

        Console::success('[Setup] - Server database init started...');

        try {
            Console::success('[Setup] - Creating console database...');
            $dbForConsole->create();
        } catch (Duplicate) {
            Console::success('[Setup] - Skip: metadata table already exists');
        }

        if ($dbForConsole->getCollection(Audit::COLLECTION)->isEmpty()) {
            $audit = new Audit($dbForConsole);
            $audit->setup();
        }

        if ($dbForConsole->getCollection(TimeLimit::COLLECTION)->isEmpty()) {
            $adapter = new TimeLimit("", 0, 1, $dbForConsole);
            $adapter->setup();
        }

        /** @var array $collections */
        $collections = Config::getParam('collections', []);
        $consoleCollections = $collections['console'];
        foreach ($consoleCollections as $key => $collection) {
            if (($collection['$collection'] ?? '') !== Database::METADATA) {
                continue;
            }
            if (!$dbForConsole->getCollection($key)->isEmpty()) {
                continue;
            }

            Console::success('[Setup] - Creating console collection: ' . $collection['$id'] . '...');

            $attributes = \array_map(fn ($attribute) => new Document($attribute), $collection['attributes']);
            $indexes = \array_map(fn (array $index) => new Document($index), $collection['indexes']);

            $dbForConsole->createCollection($key, $attributes, $indexes);
        }

        if ($dbForConsole->getDocument('buckets', 'default')->isEmpty() && !$dbForConsole->exists($dbForConsole->getDatabase(), 'bucket_1')) {
            Console::success('[Setup] - Creating default bucket...');
            $dbForConsole->createDocument('buckets', new Document([
                '$id' => ID::custom('default'),
                '$collection' => ID::custom('buckets'),
                'name' => 'Default',
                'maximumFileSize' => (int) System::getEnv('_APP_STORAGE_LIMIT', 0), // 10MB
                'allowedFileExtensions' => [],
                'enabled' => true,
                'compression' => 'gzip',
                'encryption' => true,
                'antivirus' => true,
                'fileSecurity' => true,
                '$permissions' => [
                    Permission::create(Role::any()),
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'search' => 'buckets Default',
            ]));

            $bucket = $dbForConsole->getDocument('buckets', 'default');

            Console::success('[Setup] - Creating files collection for default bucket...');
            $files = $collections['buckets']['files'] ?? [];
            if (empty($files)) {
                throw new Exception('Files collection is not configured.');
            }

            $attributes = \array_map(fn ($attribute) => new Document($attribute), $files['attributes']);
            $indexes = \array_map(fn (array $index) => new Document($index), $files['indexes']);

            $dbForConsole->createCollection('bucket_' . $bucket->getInternalId(), $attributes, $indexes);
        }

        $projectCollections = $collections['projects'];
        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));
        $sharedTablesV1 = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES_V1', ''));
        $sharedTablesV2 = \array_diff($sharedTables, $sharedTablesV1);

        $cache = $app->getResource('cache');

        foreach ($sharedTablesV2 as $hostname) {
            $adapter = $pools
                ->get($hostname)
                ->pop()
                ->getResource();

            $dbForProject = (new Database($adapter, $cache))
                ->setDatabase('appwrite')
                ->setSharedTables(true)
                ->setTenant(null)
                ->setNamespace(System::getEnv('_APP_DATABASE_SHARED_NAMESPACE', ''));

            try {
                Console::success('[Setup] - Creating project database: ' . $hostname . '...');
                $dbForProject->create();
            } catch (Duplicate) {
                Console::success('[Setup] - Skip: metadata table already exists');
            }

            foreach ($projectCollections as $key => $collection) {
                if (($collection['$collection'] ?? '') !== Database::METADATA) {
                    continue;
                }
                if (!$dbForProject->getCollection($key)->isEmpty()) {
                    continue;
                }

                $attributes = \array_map(fn ($attribute) => new Document($attribute), $collection['attributes']);
                $indexes = \array_map(fn (array $index) => new Document($index), $collection['indexes']);

                Console::success('[Setup] - Creating project collection: ' . $collection['$id'] . '...');

                $dbForProject->createCollection($key, $attributes, $indexes);
            }
        }

        $pools->reclaim();

        Console::success('[Setup] - Server database init completed...');
    });

    Console::success('Server started successfully (max payload is ' . number_format($payloadSize) . ' bytes)');
    Console::info("Master pid {$http->master_pid}, manager pid {$http->manager_pid}");

    // Start the task that starts fetching custom domains
    $http->task([], 0);

    // listen ctrl + c
    Process::signal(2, function () use ($http) {
        Console::log('Stop by Ctrl+C');
        $http->shutdown();
    });
});

$http->on(Constant::EVENT_REQUEST, function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) use ($register) {
    App::setResource('swooleRequest', fn () => $swooleRequest);
    App::setResource('swooleResponse', fn () => $swooleResponse);

    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);

    if (Files::isFileLoaded($request->getURI())) {
        $time = (60 * 60 * 24 * 365 * 2); // 45 days cache

        $response
            ->setContentType(Files::getFileMimeType($request->getURI()))
            ->addHeader('Cache-Control', 'public, max-age=' . $time)
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time) . ' GMT') // 45 days cache
            ->send(Files::getFileContents($request->getURI()));

        return;
    }

    $app = new App('UTC');
    $app->setCompression(true);
    $app->setCompressionMinSize(intval(System::getEnv('_APP_COMPRESSION_MIN_SIZE_BYTES', '1024'))); // 1KB

    $pools = $register->get('pools');
    App::setResource('pools', fn () => $pools);

    try {
        Authorization::cleanRoles();
        Authorization::setRole(Role::any()->toString());

        $app->run($request, $response);
    } catch (\Throwable $th) {
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

        $logger = $app->getResource("logger");
        if ($logger) {
            try {
                /** @var Utopia\Database\Document $user */
                $user = $app->getResource('user');
            } catch (\Throwable $_th) {
                // All good, user is optional information for logger
            }

            $route = $app->getRoute();

            $log = $app->getResource("log");

            if (isset($user) && !$user->isEmpty()) {
                $log->setUser(new User($user->getId()));
            }

            $log->setNamespace("http");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($th->getMessage());

            $log->addTag('method', $route->getMethod());
            $log->addTag('url', $route->getPath());
            $log->addTag('verboseType', get_class($th));
            $log->addTag('code', $th->getCode());
            // $log->addTag('projectId', $project->getId()); // TODO: Figure out how to get ProjectID, if it becomes relevant
            $log->addTag('hostname', $request->getHostname());
            $log->addTag('locale', (string)$request->getParam('locale', $request->getHeader('x-appwrite-locale', '')));

            $log->addExtra('file', $th->getFile());
            $log->addExtra('line', $th->getLine());
            $log->addExtra('trace', $th->getTraceAsString());
            $log->addExtra('roles', Authorization::getRoles());

            $action = $route->getLabel("sdk.namespace", "UNKNOWN_NAMESPACE") . '.' . $route->getLabel("sdk.method", "UNKNOWN_METHOD");
            $log->setAction($action);

            $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            try {
                $responseCode = $logger->addLog($log);
                Console::info('Error log pushed with status code: ' . $responseCode);
            } catch (Throwable $th) {
                Console::error('Error pushing log: ' . $th->getMessage());
            }
        }

        Console::error('[Error] Type: ' . get_class($th));
        Console::error('[Error] Message: ' . $th->getMessage());
        Console::error('[Error] File: ' . $th->getFile());
        Console::error('[Error] Line: ' . $th->getLine());

        $swooleResponse->setStatusCode(500);

        $output = ((App::isDevelopment())) ? [
            'message' => 'Error: ' . $th->getMessage(),
            'code' => 500,
            'file' => $th->getFile(),
            'line' => $th->getLine(),
            'trace' => $th->getTrace(),
            'version' => $version,
        ] : [
            'message' => 'Error: Server Error',
            'code' => 500,
            'version' => $version,
        ];

        $swooleResponse->end(\json_encode($output));
    } finally {
        $pools->reclaim();
    }
});

// Fetch domains every `DOMAIN_SYNC_TIMER` seconds and update in the memory
$http->on('Task', function () use ($register, $domains) {
    $lastSyncUpdate = null;
    $pools = $register->get('pools');
    App::setResource('pools', fn () => $pools);
    $app = new App('UTC');

    /** @var Utopia\Database\Database $dbForConsole */
    $dbForConsole = $app->getResource('dbForConsole');

    Console::loop(function () use ($dbForConsole, $domains, &$lastSyncUpdate) {
        try {
            $time = DateTime::now();
            $limit = 1000;
            $sum = $limit;
            $latestDocument = null;

            while ($sum === $limit) {
                $queries = [Query::limit($limit)];
                if ($latestDocument !== null) {
                    $queries[] =  Query::cursorAfter($latestDocument);
                }
                if ($lastSyncUpdate != null) {
                    $queries[] = Query::greaterThanEqual('$updatedAt', $lastSyncUpdate);
                }
                $queries[] = Query::equal('resourceType', ['function']);
                $results = [];
                try {
                    $results = Authorization::skip(fn () =>  $dbForConsole->find('rules', $queries));
                } catch (Throwable $th) {
                    Console::error($th->getMessage());
                }

                $sum = count($results);
                foreach ($results as $document) {
                    $domain = $document->getAttribute('domain');
                    if (str_ends_with($domain, System::getEnv('_APP_DOMAIN_FUNCTIONS'))) {
                        continue;
                    }
                    $domains->set(md5($domain), ['value' => 1]);
                }
                $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
            }
            $lastSyncUpdate = $time;
            if ($sum > 0) {
                Console::log("Sync domains tick: {$sum} domains were updated");
            }
        } catch (Throwable $th) {
            Console::error($th->getMessage());
        }
    }, DOMAIN_SYNC_TIMER, 0, function ($error) {
        Console::error($error);
    });
});

$http->start();
