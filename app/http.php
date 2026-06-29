<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/init/span.php';

$setRequestContext = require __DIR__ . '/init/resources/request.php';

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Constant;
use Swoole\Process;
use Swoole\Table;
use Swoole\Timer;
use Utopia\Audit\Adapter\Database as AdapterDatabase;
use Utopia\Audit\Adapter\SQL as AuditAdapterSQL;
use Utopia\Audit\Audit;
use Utopia\Compression\Compression;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\DI\Container;
use Utopia\Http\Adapter\Swoole\Mode;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Files;
use Utopia\Http\Http;
use Utopia\Logger\Log;
use Utopia\Logger\Log\User;
use Utopia\Span\Span;
use Utopia\System\System;

$files = new Files();
$files->load(__DIR__ . '/../public');

$certifiedDomains = new Table(100_000);
$certifiedDomains->column('value', Table::TYPE_INT, 1);
$certifiedDomains->create();

global $container;
$container->set('certifiedDomains', fn () => $certifiedDomains);
$container->set('pools', function ($register) {
    return $register->get('pools');
}, ['register']);

$payloadSize = 12 * (1024 * 1024); // 12MB - adding slight buffer for headers and other data that might be sent with the payload - update later with valid testing

$swoole = new Server(
    host: "0.0.0.0",
    port: System::getEnv('PORT', 80),
    settings: [
        ...Mode::HYPERLOOP_B->settings(),
        Constant::OPTION_PACKAGE_MAX_LENGTH => $payloadSize,
        Constant::OPTION_OUTPUT_BUFFER_SIZE => $payloadSize,
    ],
    resources: $container,
);

$http = $swoole->getServer();

$http->on(Constant::EVENT_WORKER_START, function ($server, $workerId) {
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

$container->set('bus', fn ($register) => $register->get('bus')->setResolver(fn (string $name) => $swoole->context()->get($name)), ['register']);

include __DIR__ . '/controllers/general.php';

function createDatabase(Container $resources, string $resourceKey, string $dbName, array $collections, mixed $pools, ?callable $extraSetup = null): void
{
    $max = 15;
    $sleep = 2;
    $attempts = 0;

    while (true) {
        try {
            $attempts++;
            $resource = $resources->get($resourceKey);
            /* @var $database Database */
            $database = is_callable($resource) ? $resource() : $resource;
            break; // exit loop on success
        } catch (\Throwable $e) {
            Console::warning("  └── Database not ready ({$dbName}). Retrying connection ({$attempts}): " . $e->getMessage());
            if ($attempts >= $max) {
                throw new \Exception('  └── Failed to connect to database: ' . $e->getMessage());
            }
            sleep($sleep);
        }
    }

    Span::init("database.setup");
    Span::add('database.name', $dbName);

    $attempts = 0;
    while (true) {
        try {
            $attempts++;
            Console::info("  └── Creating database: $dbName...");
            $database->create();
            break; // exit loop on success
        } catch (\Exception $e) {
            if ($e instanceof DuplicateException) {
                Span::add('database.exists', true);
                Console::info("  └── Skip: metadata table already exists");
                break;
            }

            Console::warning("  └── Database create failed. Retrying ({$attempts})...");
            if ($attempts >= $max) {
                throw new \Exception('  └── Failed to create database: ' . $e->getMessage());
            }

            \sleep($sleep);
        }
    }

    // Process collections
    $collectionsCreated = 0;
    foreach ($collections as $key => $collection) {
        if (($collection['$collection'] ?? '') !== Database::METADATA) {
            continue;
        }

        if (!$database->getCollection($key)->isEmpty()) {
            continue;
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
        ]), $collection['attributes']);

        $indexes = array_map(fn ($index) => new Document([
            '$id' => ID::custom($index['$id']),
            'type' => $index['type'],
            'attributes' => $index['attributes'],
            'lengths' => $index['lengths'] ?? [],
            'orders' => $index['orders'] ?? [],
        ]), $collection['indexes']);

        $database->createCollection($key, $attributes, $indexes);
        $collectionsCreated++;
    }

    Span::add('database.collections_created', $collectionsCreated);

    if ($extraSetup) {
        $extraSetup($database);
    }

    Span::current()?->finish();
}

$http->on(Constant::EVENT_START, function ($http) use ($payloadSize, $container) {
    /** @var \Utopia\Pools\Group $pools */
    $pools = $container->get('pools');

    go(function () use ($container, $pools) {

        /** @var array $collections */
        $collections = Config::getParam('collections', []);

        // create logs database first, `getLogsDB` is a callable.
        createDatabase($container, 'getLogsDB', 'logs', $collections['logs'], $pools);

        // create appwrite database, `dbForPlatform` is a direct access call.
        createDatabase($container, 'dbForPlatform', 'appwrite', $collections['console'], $pools, function (Database $dbForPlatform) use ($collections, $container) {
            $authorization = $container->get('authorization');

            if ($dbForPlatform->getCollection(AuditAdapterSQL::COLLECTION)->isEmpty()) {
                $adapter = new AdapterDatabase($dbForPlatform);
                $audit = new Audit($adapter);
                $audit->setup();
            }

            if ($dbForPlatform->getDocument('buckets', 'default')->isEmpty()) {
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
                    'lengths' => $index['lengths'] ?? [],
                    'orders' => $index['orders'] ?? [],
                ]), $files['indexes']);

                $dbForPlatform->createCollection('bucket_' . $bucket->getSequence(), $attributes, $indexes);
            }

            if ($authorization->skip(fn () => $dbForPlatform->getDocument('buckets', 'screenshots')->isEmpty())) {
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
                    'lengths' => $index['lengths'] ?? [],
                    'orders' => $index['orders'] ?? [],
                ]), $files['indexes']);

                $authorization->skip(fn () => $dbForPlatform->createCollection('bucket_' . $bucket->getSequence(), $attributes, $indexes));
            }
        });

        $projectCollections = $collections['projects'];

        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));
        $documentsSharedTables = \explode(',', System::getEnv('_APP_DATABASE_DOCUMENTSDB_SHARED_TABLES', ''));
        $vectorSharedTables = \explode(',', System::getEnv('_APP_DATABASE_VECTORSDB_SHARED_TABLES', ''));

        $cache = $container->get('cache');

        // All shared tables pools that need project metadata collections
        $allSharedTables = \array_values(\array_unique(\array_filter([
            ...$sharedTables,
            ...$documentsSharedTables,
            ...$vectorSharedTables,
        ])));

        foreach ($allSharedTables as $hostname) {
            Span::init('database.setup');
            Span::add('database.hostname', $hostname);

            $adapter = new DatabasePool($pools->get($hostname));
            $dbForProject = (new Database($adapter, $cache))
                ->setDatabase('appwrite')
                ->setSharedTables(true)
                ->setTenant(null)
                ->setNamespace(System::getEnv('_APP_DATABASE_SHARED_NAMESPACE', ''));

            $max = 15;
            $sleep = 2;
            $attempts = 0;
            while (true) {
                try {
                    $attempts++;
                    Console::success('[Setup] - Creating project database: ' . $hostname . '...');
                    $dbForProject->create();
                    break; // exit loop on success
                } catch (DuplicateException) {
                    Span::add('database.exists', true);
                    Console::success('[Setup] - Skip: metadata table already exists');
                    break;
                } catch (\Throwable $e) {
                    Console::warning("  └── Project database create failed. Retrying ({$attempts})...");
                    if ($attempts >= $max) {
                        throw new \Exception('  └── Failed to create project database: ' . $e->getMessage());
                    }
                    sleep($sleep);
                }
            }

            if ($dbForProject->getCollection(AuditAdapterSQL::COLLECTION)->isEmpty()) {
                $adapter = new AdapterDatabase($dbForProject);
                $audit = new Audit($adapter);
                $audit->setup();
            }

            $collectionsCreated = 0;
            foreach ($projectCollections as $key => $collection) {
                if (($collection['$collection'] ?? '') !== Database::METADATA) {
                    continue;
                }
                if (!$dbForProject->getCollection($key)->isEmpty()) {
                    continue;
                }

                $attributes = \array_map(fn ($attribute) => new Document($attribute), $collection['attributes']);
                $indexes = \array_map(fn (array $index) => new Document($index), $collection['indexes']);

                $dbForProject->createCollection($key, $attributes, $indexes);
                $collectionsCreated++;
            }

            Span::add('database.collections_created', $collectionsCreated);
            Span::current()?->finish();
        }
    });

    Span::init('http.server.start');
    Span::add('server.workers', $http->setting[Constant::OPTION_WORKER_NUM] ?? null);
    Span::add('server.payload_size', $payloadSize);
    Span::add('server.master_pid', $http->master_pid);
    Span::add('server.manager_pid', $http->manager_pid);
    Span::current()?->finish();

    // listen ctrl + c
    Process::signal(2, function () use ($http) {
        Console::log('Stop by Ctrl+C');
        $http->shutdown();
    });
});

$swoole->onRequest(function ($utopiaRequest, $utopiaResponse) use ($files, $swoole, $setRequestContext) {
    Span::init('http.request');

    $request = new Request($utopiaRequest->getSwooleRequest());
    $response = new Response($utopiaResponse->getSwooleResponse());

    Span::add('http.method', $request->getMethod());

    if ($files->isFileLoaded($request->getURI())) {
        $time = (60 * 60 * 24 * 45); // 45 days cache

        $response
            ->setContentType($files->getFileMimeType($request->getURI()))
            ->addHeader('Cache-Control', 'public, max-age=' . $time)
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time) . ' GMT') // 45 days cache
            ->send($files->getFileContents($request->getURI()));

        return;
    }

    $app = new Http($swoole, 'UTC');
    $app->context()->set('request', fn () => $request);
    $app->context()->set('response', fn () => $response);
    $app->context()->set('utopia', fn () => $app);

    $setRequestContext($app->context());

    $app->setCompression(System::getEnv('_APP_COMPRESSION_ENABLED', 'enabled') === 'enabled');
    $app->setCompressionMinSize(intval(System::getEnv('_APP_COMPRESSION_MIN_SIZE_BYTES', '1024'))); // 1KB

    $error = null;
    try {
        $authorization = $app->context()->get('authorization');

        $request->setAuthorization($authorization);
        $response->setAuthorization($authorization);
        $authorization->cleanRoles();
        $authorization->addRole(Role::any()->toString());

        $app->run($request, $response);

        $route = $app->match($request)?->route;
        Span::add('http.path', $route?->getPath() ?? 'unknown');
    } catch (\Throwable $th) {
        $error = $th;

        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

        $logger = $app->context()->get("logger");
        if ($logger) {
            try {
                /** @var Utopia\Database\Document $user */
                $user = $app->context()->get('user');
            } catch (\Throwable $_th) {
                // All good, user is optional information for logger
            }

            $route = $app->match($request)?->route;

            $log = $app->context()->get("log");

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

            $log->addTag('method', $route?->getMethod() ?? $request->getMethod());
            $log->addTag('url', $route?->getPath() ?? $request->getURI());
            $log->addTag('verboseType', get_class($th));
            $log->addTag('code', $th->getCode());
            // $log->addTag('projectId', $project->getId()); // TODO: Figure out how to get ProjectID, if it becomes relevant
            $log->addTag('hostname', $request->getHostname());
            $log->addTag('locale', (string)$request->getParam('locale', $request->getHeaderLine('x-appwrite-locale', '')));

            $log->addExtra('file', $th->getFile());
            $log->addExtra('line', $th->getLine());
            $log->addExtra('trace', $th->getTraceAsString());
            $log->addExtra('roles', isset($authorization) ? $authorization->getRoles() : []);

            $sdk = $route?->getLabel("sdk", false);

            $action = 'UNKNOWN_NAMESPACE.UNKNOWN.METHOD';
            if (!empty($sdk)) {
                if (\is_array($sdk)) {
                    $sdk = $sdk[0];
                }
                /** @var Appwrite\SDK\Method $sdk */
                $action = $sdk->getNamespace() . '.' . $sdk->getMethodName();
            } elseif ($route === null) {
                $path = ltrim(parse_url($request->getURI(), PHP_URL_PATH) ?? '/', '/') ?: 'root';
                $action = 'http.' . $request->getMethod() . '.' . $path;
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

        $swooleResponse = $utopiaResponse->getSwooleResponse();
        $swooleResponse->setStatusCode(500);

        $output = ((Http::isDevelopment())) ? [
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
        Span::add('http.response.code', $response->getStatusCode());
        Span::current()?->finish(error: $error);
    }
});

$swoole->start();
