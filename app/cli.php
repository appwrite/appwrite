<?php

require_once __DIR__ . '/init.php';

use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Certificate as CertificatePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\StatsResources as StatsResourcesPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Platform\Appwrite;
use Appwrite\Runtimes\Runtimes;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Utopia\Database\Documents\User;
use Executor\Executor;
use Swoole\Runtime;
use Swoole\Timer;
use Utopia\Cache\Adapter\Pool as CachePool;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Adapters\Generic;
use Utopia\CLI\CLI;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;
use Utopia\Registry\Registry;
use Utopia\System\System;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

use function Swoole\Coroutine\run;

// overwriting runtimes to be architecture agnostic for CLI
Config::setParam('runtimes', (new Runtimes('v5'))->getAll(supported: false));

// require controllers after overwriting runtimes
require_once __DIR__ . '/controllers/general.php';

global $register;

$platform = new Appwrite();
$args = $_SERVER['argv'] ?? [];

\array_shift($args);
if (! isset($args[0])) {
    Console::error('Missing task name');
    Console::exit(1);
}

$taskName = $args[0];
$container = new Container();
$cli = new CLI(new Generic(), $_SERVER['argv'] ?? [], $container);

$platform->setCli($cli);
$platform->init(Service::TYPE_TASK);

$container->set('register', fn () => $register, []);

$container->set('cache', function ($pools) {
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = new CachePool($pools->get($value));
    }

    return new Cache(new Sharding($adapters));
}, ['pools']);

$container->set('pools', function (Registry $register) {
    return $register->get('pools');
}, ['register']);

$container->set('authorization', function () {
    $authorization = new Authorization();
    $authorization->disable();

    return $authorization;
}, []);

$container->set('dbForPlatform', function ($pools, $cache, $authorization) {
    $sleep = 3;
    $maxAttempts = 5;
    $attempts = 0;
    $ready = false;

    do {
        $attempts++;
        try {
            // Prepare database connection
            $adapter = new DatabasePool($pools->get('console'));
            $dbForPlatform = new Database($adapter, $cache);

            $dbForPlatform
                ->setDatabase(APP_DATABASE)
                ->setAuthorization($authorization)
                ->setNamespace('_console')
                ->setMetadata('host', \gethostname())
                ->setMetadata('project', 'console');
            $dbForPlatform->setDocumentType('users', User::class);

            // Ensure tables exist
            $collections = Config::getParam('collections', [])['console'];
            $last = \array_key_last($collections);

            if (! ($dbForPlatform->exists($dbForPlatform->getDatabase(), $last))) { /** TODO cache ready variable using registry */
                throw new Exception('Tables not ready yet.');
            }

            $ready = true;
        } catch (\Throwable $err) {
            Console::warning($err->getMessage());
            sleep($sleep);
        }
    } while ($attempts < $maxAttempts && ! $ready);

    if (! $ready) {
        throw new Exception('Console is not ready yet. Please try again later.');
    }

    return $dbForPlatform;
}, ['pools', 'cache', 'authorization']);

$container->set('console', function () {
    return new Document(Config::getParam('console'));
}, []);

$container->set(
    'isResourceBlocked',
    fn () => fn (Document $project, string $resourceType, ?string $resourceId) => false,
    []
);

$container->set('getProjectDB', function (Group $pools, Database $dbForPlatform, $cache, $authorization) {
    $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

    return function (Document $project) use ($pools, $dbForPlatform, $cache, $authorization, &$databases) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForPlatform;
        }

        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        if (isset($databases[$dsn->getHost()])) {
            /** @var array $collections */
            $collections = Config::getParam('collections', []);
            $projectCollections = $collections['projects'] ?? [];
            $projectsGlobalCollections = array_keys($projectCollections);
            $projectsGlobalCollections[] = 'audit';

            $database = $databases[$dsn->getHost()];
            $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

            if (\in_array($dsn->getHost(), $sharedTables)) {
                $database
                    ->setSharedTables(true)
                    ->setGlobalCollections($projectsGlobalCollections)
                    ->setTenant($project->getSequence())
                    ->setNamespace($dsn->getParam('namespace'));
            } else {
                $database
                    ->setSharedTables(false)
                    ->setTenant(null)
                    ->setNamespace('_' . $project->getSequence());
            }

            return $database;
        }

        $adapter = new DatabasePool($pools->get($dsn->getHost()));
        $database = new Database($adapter, $cache);

        $databases[$dsn->getHost()] = $database;
        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

        if (\in_array($dsn->getHost(), $sharedTables)) {
            /** @var array $collections */
            $collections = Config::getParam('collections', []);
            $projectCollections = $collections['projects'] ?? [];
            $projectsGlobalCollections = array_keys($projectCollections);
            $projectsGlobalCollections[] = 'audit';

            $database
                ->setSharedTables(true)
                ->setTenant($project->getSequence())
                ->setGlobalCollections($projectsGlobalCollections)
                ->setNamespace($dsn->getParam('namespace'));
        } else {
            $database
                ->setSharedTables(false)
                ->setTenant(null)
                ->setNamespace('_' . $project->getSequence());
        }

        $database
            ->setDatabase(APP_DATABASE)
            ->setAuthorization($authorization)
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', $project->getId());

        return $database;
    };
}, ['pools', 'dbForPlatform', 'cache', 'authorization']);

$container->set('getLogsDB', function (Group $pools, Cache $cache, Authorization $authorization) {
    $database = null;

    return function (?Document $project = null) use ($pools, $cache, &$database, $authorization) {
        if ($database !== null && $project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getSequence());
            return $database;
        }

        /** @var array $collections */
        $collections = Config::getParam('collections', []);
        $logsCollections = $collections['logs'] ?? [];
        $logsCollections = array_keys($logsCollections);

        $adapter = new DatabasePool($pools->get('logs'));
        $database = new Database($adapter, $cache);

        $database
            ->setDatabase(APP_DATABASE)
            ->setAuthorization($authorization)
            ->setSharedTables(true)
            ->setNamespace('logsV1')
            ->setGlobalCollections($logsCollections)
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_TASK)
            ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

        // set tenant
        if ($project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getSequence());
        }

        return $database;
    };
}, ['pools', 'cache', 'authorization']);
$container->set('publisher', function (Group $pools) {
    return new BrokerPool(publisher: $pools->get('publisher'));
}, ['pools']);
$container->set('publisherDatabases', function (BrokerPool $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherFunctions', function (BrokerPool $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherMigrations', function (BrokerPool $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('publisherMessaging', function (BrokerPool $publisher) {
    return $publisher;
}, ['publisher']);
$container->set('usage', function () {
    return new UsageContext();
}, []);
$container->set('publisherForUsage', fn (Publisher $publisher) => new UsagePublisher(
    $publisher,
    new Queue(System::getEnv('_APP_STATS_USAGE_QUEUE_NAME', Event::STATS_USAGE_QUEUE_NAME))
), ['publisher']);
$container->set('publisherForCertificates', fn (Publisher $publisher) => new CertificatePublisher(
    $publisher,
    new Queue(System::getEnv('_APP_CERTIFICATES_QUEUE_NAME', Event::CERTIFICATES_QUEUE_NAME))
), ['publisher']);
$container->set('publisherForStatsResources', fn (Publisher $publisher) => new StatsResourcesPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_STATS_RESOURCES_QUEUE_NAME', Event::STATS_RESOURCES_QUEUE_NAME))
), ['publisher']);
$container->set('publisherForFunctions', fn (Publisher $publisher) => new FunctionPublisher(
    $publisher,
    new Queue(System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', Event::FUNCTIONS_QUEUE_NAME), 'utopia-queue', Event::FUNCTIONS_QUEUE_TTL)
), ['publisher']);
$container->set('queueForDeletes', function (Publisher $publisher) {
    return new Delete($publisher);
}, ['publisher']);
$container->set('logError', function (Registry $register) {
    return function (Throwable $error, string $namespace, string $action) use ($register) {
        Console::error('[Error] Timestamp: ' . date('c', time()));
        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $error->getMessage());
        Console::error('[Error] File: ' . $error->getFile());
        Console::error('[Error] Line: ' . $error->getLine());
        Console::error('[Error] Trace: ' . $error->getTraceAsString());

        $logger = $register->get('logger');

        if ($logger) {
            $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

            $log = new Log();
            $log->setNamespace($namespace);
            $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());

            $log->addTag('code', $error->getCode());
            $log->addTag('verboseType', get_class($error));

            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('detailedTrace', $error->getTrace());

            if ($error->getPrevious() !== null) {
                if ($error->getPrevious()->getMessage() != $error->getMessage()) {
                    $log->addExtra('previousMessage', $error->getPrevious()->getMessage());
                }
                $log->addExtra('previousFile', $error->getPrevious()->getFile());
                $log->addExtra('previousLine', $error->getPrevious()->getLine());
            }

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
    };
}, ['register']);

$container->set('executor', fn () => new Executor(), []);

$container->set('bus', function (Registry $register) use ($container) {
    return $register->get('bus')->setResolver(fn (string $name) => $container->get($name));
}, ['register']);

$container->set('telemetry', fn () => new NoTelemetry(), []);

$exitCode = 0;

$cli
    ->error()
    ->inject('error')
    ->inject('logError')
    ->action(function (Throwable $error, callable $logError) use ($taskName, &$exitCode) {
        call_user_func_array($logError, [
            $error,
            'Task',
            $taskName,
        ]);

        $exitCode = 1;
        Timer::clearAll();
    });

$cli->shutdown()->action(fn () => Timer::clearAll());

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
require_once __DIR__ . '/init/span.php';
run($cli->run(...));
Console::exit($exitCode);
