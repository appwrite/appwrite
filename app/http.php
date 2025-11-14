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
use Swoole\Timer;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\CLI\Console;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Logger\Log;
use Utopia\Logger\Log\User;
use Utopia\Pools\Group;
use Utopia\Swoole\Files;
use Utopia\System\System;

Files::load(__DIR__.'/../public');

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
$totalWorkers = intval(System::getEnv('_APP_CPU_NUM', swoole_cpu_num())) * intval(System::getEnv('_APP_WORKER_PER_CORE', 6));

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
    $resolveWorkerId = function (Server $server, $data = null) {
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
        $riskyWorkers = (int)floor($totalWorkers * $riskyWorkersPercent); // Absolute amount of risky workers

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
    };
    $workerId = $resolveWorkerId($server, $data);
    $server->bind($fd, $workerId);
    return $workerId;
}


$http
    ->set([
        Constant::OPTION_WORKER_NUM => $totalWorkers,
        Constant::OPTION_DISPATCH_FUNC => dispatch(...),
        Constant::OPTION_DISPATCH_MODE => SWOOLE_DISPATCH_UIDMOD,
        Constant::OPTION_HTTP_COMPRESSION => false,
        Constant::OPTION_PACKAGE_MAX_LENGTH => $payloadSize,
        Constant::OPTION_OUTPUT_BUFFER_SIZE => $payloadSize,
        Constant::OPTION_TASK_WORKER_NUM => 1, // required for the task to fetch domains background
    ]);

$http->on(Constant::EVENT_WORKER_START, function ($server, $workerId) {
    Console::success('Worker ' . ++$workerId . ' started successfully');
});

$http->on(Constant::EVENT_WORKER_STOP, function ($server, $workerId) {
    Timer::clearAll();
    Console::success('Worker ' . ++$workerId . ' stopped successfully');
});

$http->on(Constant::EVENT_BEFORE_RELOAD, function ($server) {
    Console::success('Starting reload...');
});

$http->on(Constant::EVENT_AFTER_RELOAD, function ($server) {
    Console::success('Reload completed...');
});

include __DIR__ . '/controllers/general.php';

function createDatabase(App $app, string $resourceKey, string $dbName, array $collections, mixed $pools, callable $extraSetup = null): void
{
    $max = 10;
    $sleep = 1;
    $attempts = 0;

    while (true) {
        try {
            $attempts++;
            $resource = $app->getResource($resourceKey);
            /* @var $database Database */
            $database = is_callable($resource) ? $resource() : $resource;
            break; // exit loop on success
        } catch (\Exception $e) {
            Console::warning("  └── Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= $max) {
                throw new \Exception('  └── Failed to connect to database: ' . $e->getMessage());
            }
            sleep($sleep);
        }
    }

    Console::success("[Setup] - $dbName database init started...");

    // Attempt to create the database
    try {
        Console::info("  └── Creating database: $dbName...");
        $database->create();
    } catch (\Exception $e) {
        Console::info("  └── Skip: metadata table already exists");
    }

    // Process collections
    foreach ($collections as $key => $collection) {
        if (($collection['$collection'] ?? '') !== Database::METADATA) {
            continue;
        }

        if (!$database->getCollection($key)->isEmpty()) {
            continue;
        }

        Console::info("    └── Creating collection: {$collection['$id']}...");

        $attributes = array_map(fn ($attr) => new Document([
            '$id' => ID::custom($attr['$id']),
            'type' => $attr['type'],
            'size' => $attr['size'],
            'required' => $attr['required'],
            'signed' => $attr['signed'],
            'array' => $attr['array'],
            'filters' => $attr['filters'],
            'default' => $attr['default'] ?? null,
            'format' => $attr['format'] ?? ''
        ]), $collection['attributes']);

        $indexes = array_map(fn ($index) => new Document([
            '$id' => ID::custom($index['$id']),
            'type' => $index['type'],
            'attributes' => $index['attributes'],
            'lengths' => $index['lengths'],
            'orders' => $index['orders'],
        ]), $collection['indexes']);

        $database->createCollection($key, $attributes, $indexes);
    }

    if ($extraSetup) {
        $extraSetup($database);
    }
}

$http->on(Constant::EVENT_START, function (Server $http) use ($payloadSize, $register) {
    $app = new App('UTC');

    go(function () use ($register, $app) {
        $pools = $register->get('pools');
        /** @var Group $pools */
        App::setResource('pools', fn () => $pools);

        /** @var array $collections */
        $collections = Config::getParam('collections', []);

        // create logs database first, `getLogsDB` is a callable.
        createDatabase($app, 'getLogsDB', 'logs', $collections['logs'], $pools);

        // create appwrite database, `dbForPlatform` is a direct access call.
        createDatabase($app, 'dbForPlatform', 'appwrite', $collections['console'], $pools, function (Database $dbForPlatform) use ($collections, $app) {
            $authorization = $app->getResource('authorization');

            if ($dbForPlatform->getCollection(Audit::COLLECTION)->isEmpty()) {
                $audit = new Audit($dbForPlatform);
                $audit->setup();
            }

            if ($dbForPlatform->getDocument('buckets', 'default')->isEmpty()) {
                Console::info("    └── Creating default bucket...");
                $dbForPlatform->createDocument('buckets', new Document([
                    '$id' => ID::custom('default'),
                    '$collection' => ID::custom('buckets'),
                    'name' => 'Default',
                    'maximumFileSize' => (int) System::getEnv('_APP_STORAGE_LIMIT', 0),
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

                $bucket = $dbForPlatform->getDocument('buckets', 'default');

                Console::info("    └── Creating files collection for default bucket...");
                $files = $collections['buckets']['files'] ?? [];
                if (empty($files)) {
                    throw new Exception('Files collection is not configured.');
                }

                $attributes = array_map(fn ($attr) => new Document([
                    '$id' => ID::custom($attr['$id']),
                    'type' => $attr['type'],
                    'size' => $attr['size'],
                    'required' => $attr['required'],
                    'signed' => $attr['signed'],
                    'array' => $attr['array'],
                    'filters' => $attr['filters'],
                    'default' => $attr['default'] ?? null,
                    'format' => $attr['format'] ?? ''
                ]), $files['attributes']);

                $indexes = array_map(fn ($index) => new Document([
                    '$id' => ID::custom($index['$id']),
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]), $files['indexes']);

                $dbForPlatform->createCollection('bucket_' . $bucket->getSequence(), $attributes, $indexes);
            }

            if ($authorization->skip(fn () => $dbForPlatform->getDocument('buckets', 'screenshots')->isEmpty())) {
                Console::info("    └── Creating screenshots bucket...");
                $authorization->skip(fn () => $dbForPlatform->createDocument('buckets', new Document([
                    '$id' => ID::custom('screenshots'),
                    '$collection' => ID::custom('buckets'),
                    'name' => 'Screenshots',
                    'maximumFileSize' => 20000000, // ~20MB
                    'allowedFileExtensions' => [ 'png' ],
                    'enabled' => true,
                    'compression' => Compression::GZIP,
                    'encryption' => false,
                    'antivirus' => false,
                    'fileSecurity' => true,
                    '$permissions' => [],
                    'search' => 'buckets Screenshots',
                ])));

                $bucket = $authorization->skip(fn () => $dbForPlatform->getDocument('buckets', 'screenshots'));

                Console::info("    └── Creating files collection for screenshots bucket...");
                $files = $collections['buckets']['files'] ?? [];
                if (empty($files)) {
                    throw new Exception('Files collection is not configured.');
                }

                $attributes = array_map(fn ($attr) => new Document([
                    '$id' => ID::custom($attr['$id']),
                    'type' => $attr['type'],
                    'size' => $attr['size'],
                    'required' => $attr['required'],
                    'signed' => $attr['signed'],
                    'array' => $attr['array'],
                    'filters' => $attr['filters'],
                    'default' => $attr['default'] ?? null,
                    'format' => $attr['format'] ?? ''
                ]), $files['attributes']);

                $indexes = array_map(fn ($index) => new Document([
                    '$id' => ID::custom($index['$id']),
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]), $files['indexes']);

                $authorization->skip(fn () => $dbForPlatform->createCollection('bucket_' . $bucket->getSequence(), $attributes, $indexes));
            }
        });

        $projectCollections = $collections['projects'];
        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));
        $sharedTablesV1 = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES_V1', ''));
        $sharedTablesV2 = \array_diff($sharedTables, $sharedTablesV1);

        $cache = $app->getResource('cache');

        foreach ($sharedTablesV2 as $hostname) {
            $adapter = new DatabasePool($pools->get($hostname));
            $dbForProject = (new Database($adapter, $cache))
                ->setDatabase('appwrite')
                ->setSharedTables(true)
                ->setTenant(null)
                ->setNamespace(System::getEnv('_APP_DATABASE_SHARED_NAMESPACE', ''));

            try {
                Console::success('[Setup] - Creating project database: ' . $hostname . '...');
                $dbForProject->create();
            } catch (DuplicateException) {
                Console::success('[Setup] - Skip: metadata table already exists');
            }

            if ($dbForProject->getCollection(Audit::COLLECTION)->isEmpty()) {
                $audit = new Audit($dbForProject);
                $audit->setup();
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
    $app->setCompression(System::getEnv('_APP_COMPRESSION_ENABLED', 'enabled') === 'enabled');
    $app->setCompressionMinSize(intval(System::getEnv('_APP_COMPRESSION_MIN_SIZE_BYTES', '1024'))); // 1KB

    $pools = $register->get('pools');
    App::setResource('pools', fn () => $pools);

    try {
        $authorization = $app->getResource('authorization');

        $request->setAuthorization($authorization);
        $response->setAuthorization($authorization);
        $authorization->cleanRoles();
        $authorization->addRole(Role::any()->toString());

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
            } else {
                $log->setUser(new User('guest-' . hash('sha256', $request->getIP())));
            }

            $log->setNamespace("http");
            $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
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
            $log->addExtra('roles', isset($authorization) ? $authorization->getRoles() : []);

            $sdk = $route->getLabel("sdk", false);

            $action = 'UNKNOWN_NAMESPACE.UNKNOWN.METHOD';
            if (!empty($sdk)) {
                /** @var Appwrite\SDK\Method $sdk */
                $action = $sdk->getNamespace() . '.' . $sdk->getMethodName();
            }

            $log->setAction($action);
            $log->addTag('service', $action);

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
        Console::error('[Error] Trace: ' . $th->getTraceAsString());

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
    }
});

// Fetch domains every `DOMAIN_SYNC_TIMER` seconds and update in the memory
$http->on(Constant::EVENT_TASK, function () use ($register, $domains) {
    $lastSyncUpdate = null;
    $pools = $register->get('pools');
    App::setResource('pools', fn () => $pools);
    $app = new App('UTC');

    /** @var Utopia\Database\Database $dbForPlatform */
    $dbForPlatform = $app->getResource('dbForPlatform');

    Timer::tick(DOMAIN_SYNC_TIMER * 1000, function () use ($dbForPlatform, $domains, &$lastSyncUpdate, $app) {
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
                $results = [];
                try {
                    $authorization = $app->getResource('authorization');
                    $results = $authorization->skip(fn () =>  $dbForPlatform->find('rules', $queries));
                } catch (Throwable $th) {
                    Console::error($th->getMessage());
                }

                $sum = count($results);
                foreach ($results as $document) {
                    $domain = $document->getAttribute('domain');
                    if (str_ends_with($domain, System::getEnv('_APP_DOMAIN_FUNCTIONS')) || str_ends_with($domain, System::getEnv('_APP_DOMAIN_SITES'))) {
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
    });
});

$http->start();
