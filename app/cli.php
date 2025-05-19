<?php

require_once __DIR__ . '/init.php';

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Func;
use Appwrite\Event\StatsResources;
use Appwrite\Event\StatsUsage;
use Appwrite\Platform\Appwrite;
use Appwrite\Runtimes\Runtimes;
use Executor\Executor;
use Swoole\Runtime;
use Swoole\Timer;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\CLI;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Publisher;
use Utopia\Registry\Registry;
use Utopia\System\System;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

use function Swoole\Coroutine\run;

// overwriting runtimes to be architecture agnostic for CLI
Config::setParam('runtimes', (new Runtimes('v5'))->getAll(supported: false));

// require controllers after overwriting runtimes
require_once __DIR__ . '/controllers/general.php';

Authorization::disable();

CLI::setResource('register', fn () => $register);

CLI::setResource('cache', function ($pools) {
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource();
    }

    return new Cache(new Sharding($adapters));
}, ['pools']);

CLI::setResource('pools', function (Registry $register) {
    return $register->get('pools');
}, ['register']);

CLI::setResource('dbForPlatform', function ($pools, $cache) {
    $sleep = 3;
    $maxAttempts = 5;
    $attempts = 0;
    $ready = false;

    do {
        $attempts++;
        try {
            // Prepare database connection
            $dbAdapter = $pools
                ->get('console')
                ->pop()
                ->getResource();

            $dbForPlatform = new Database($dbAdapter, $cache);

            $dbForPlatform
                ->setNamespace('_console')
                ->setMetadata('host', \gethostname())
                ->setMetadata('project', 'console');

            // Ensure tables exist
            $collections = Config::getParam('collections', [])['console'];
            $last = \array_key_last($collections);

            if (!($dbForPlatform->exists($dbForPlatform->getDatabase(), $last))) { /** TODO cache ready variable using registry */
                throw new Exception('Tables not ready yet.');
            }

            $ready = true;
        } catch (\Throwable $err) {
            Console::warning($err->getMessage());
            $pools->get('console')->reclaim();
            sleep($sleep);
        }
    } while ($attempts < $maxAttempts && !$ready);

    if (!$ready) {
        throw new Exception("Console is not ready yet. Please try again later.");
    }

    return $dbForPlatform;
}, ['pools', 'cache']);

CLI::setResource('console', function () {
    return new Document(Config::getParam('console'));
}, []);

CLI::setResource('getProjectDB', function (Group $pools, Database $dbForPlatform, $cache) {
    $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

    return function (Document $project) use ($pools, $dbForPlatform, $cache, &$databases) {
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
            $database = $databases[$dsn->getHost()];
            $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

            if (\in_array($dsn->getHost(), $sharedTables)) {
                $database
                    ->setSharedTables(true)
                    ->setTenant($project->getInternalId())
                    ->setNamespace($dsn->getParam('namespace'));
            } else {
                $database
                    ->setSharedTables(false)
                    ->setTenant(null)
                    ->setNamespace('_' . $project->getInternalId());
            }

            return $database;
        }

        $dbAdapter = $pools
            ->get($dsn->getHost())
            ->pop()
            ->getResource();

        $database = new Database($dbAdapter, $cache);
        $databases[$dsn->getHost()] = $database;
        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

        if (\in_array($dsn->getHost(), $sharedTables)) {
            $database
                ->setSharedTables(true)
                ->setTenant($project->getInternalId())
                ->setNamespace($dsn->getParam('namespace'));
        } else {
            $database
                ->setSharedTables(false)
                ->setTenant(null)
                ->setNamespace('_' . $project->getInternalId());
        }

        $database
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', $project->getId());

        return $database;
    };
}, ['pools', 'dbForPlatform', 'cache']);

CLI::setResource('getLogsDB', function (Group $pools, Cache $cache) {
    $database = null;
    return function (?Document $project = null) use ($pools, $cache, $database) {
        if ($database !== null && $project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getInternalId());
            return $database;
        }

        $dbAdapter = $pools
            ->get('logs')
            ->pop()
            ->getResource();

        $database = new Database(
            $dbAdapter,
            $cache
        );

        $database
            ->setSharedTables(true)
            ->setNamespace('logsV1')
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_TASK)
            ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

        // set tenant
        if ($project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getInternalId());
        }

        return $database;
    };
}, ['pools', 'cache']);

CLI::setResource('queueForStatsUsage', function (Publisher $publisher) {
    return new StatsUsage($publisher);
}, ['publisher']);
CLI::setResource('queueForStatsResources', function (Publisher $publisher) {
    return new StatsResources($publisher);
}, ['publisher']);
CLI::setResource('publisher', function (Group $pools) {
    return $pools->get('publisher')->pop()->getResource();
}, ['pools']);
CLI::setResource('queueForFunctions', function (Publisher $publisher) {
    return new Func($publisher);
}, ['publisher']);
CLI::setResource('queueForDeletes', function (Publisher $publisher) {
    return new Delete($publisher);
}, ['publisher']);
CLI::setResource('queueForCertificates', function (Publisher $publisher) {
    return new Certificate($publisher);
}, ['publisher']);
CLI::setResource('logError', function (Registry $register) {
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

CLI::setResource('executor', fn () => new Executor(fn (string $projectId, string $deploymentId) => System::getEnv('_APP_EXECUTOR_HOST')));

CLI::setResource('telemetry', fn () => new NoTelemetry());

$platform = new Appwrite();
$args = $platform->getEnv('argv');

if (!isset($args[0])) {
    Console::error('Missing task name');
    Console::exit(1);
}

\array_shift($args);
$taskName = $args[0];
$platform->init(Service::TYPE_TASK);
$cli = $platform->getCli();

$cli
    ->error()
    ->inject('error')
    ->inject('logError')
    ->action(function (Throwable $error, callable $logError) use ($taskName) {
        call_user_func_array($logError, [
            $error,
            'Task',
            $taskName,
        ]);

        Timer::clearAll();
    });

$cli->shutdown()->action(fn () => Timer::clearAll());

// Enable coroutines, but disable TCP hooks. These don't work until we use `\Utopia\Cache\Adapter\Pool` and `\Utopia\Database\Adapter\Pool`.
Runtime::enableCoroutine(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
run($cli->run(...));
