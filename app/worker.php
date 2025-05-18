<?php

require_once __DIR__ . '/init.php';

use Appwrite\Certificates\LetsEncrypt;
use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Certificate;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\Migration;
use Appwrite\Event\Realtime;
use Appwrite\Event\StatsUsage;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Appwrite;
use Executor\Executor;
use Swoole\Runtime;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher;
use Utopia\Queue\Server;
use Utopia\Registry\Registry;
use Utopia\System\System;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

Authorization::disable();
Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Server::setResource('register', fn () => $register);

Server::setResource('dbForPlatform', function (Cache $cache, Registry $register) {
    $pools = $register->get('pools');
    $database = $pools
        ->get('console')
        ->pop()
        ->getResource();

    $adapter = new Database($database, $cache);
    $adapter->setNamespace('_console');

    return $adapter;
}, ['cache', 'register']);

Server::setResource('project', function (Message $message, Database $dbForPlatform) {
    $payload = $message->getPayload() ?? [];
    $project = new Document($payload['project'] ?? []);

    if ($project->getId() === 'console') {
        return $project;
    }

    return $dbForPlatform->getDocument('projects', $project->getId());
}, ['message', 'dbForPlatform']);

Server::setResource('dbForProject', function (Cache $cache, Registry $register, Message $message, Document $project, Database $dbForPlatform) {
    if ($project->isEmpty() || $project->getId() === 'console') {
        return $dbForPlatform;
    }

    $pools = $register->get('pools');

    try {
        $dsn = new DSN($project->getAttribute('database'));
    } catch (\InvalidArgumentException) {
        // TODO: Temporary until all projects are using shared tables
        $dsn = new DSN('mysql://' . $project->getAttribute('database'));
    }

    $adapter = $pools
        ->get($dsn->getHost())
        ->pop()
        ->getResource();

    $database = new Database($adapter, $cache);

    try {
        $dsn = new DSN($project->getAttribute('database'));
    } catch (\InvalidArgumentException) {
        // TODO: Temporary until all projects are using shared tables
        $dsn = new DSN('mysql://' . $project->getAttribute('database'));
    }

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

    $database->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER);

    return $database;
}, ['cache', 'register', 'message', 'project', 'dbForPlatform']);

Server::setResource('getProjectDB', function (Group $pools, Database $dbForPlatform, $cache) {
    $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

    return function (Document $project) use ($pools, $dbForPlatform, $cache, &$databases): Database {
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

        $database->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER);

        return $database;
    };
}, ['pools', 'dbForPlatform', 'cache']);

Server::setResource('getLogsDB', function (Group $pools, Cache $cache) {
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
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER)
            ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES);

        // set tenant
        if ($project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
            $database->setTenant($project->getInternalId());
        }

        return $database;
    };
}, ['pools', 'cache']);

Server::setResource('abuseRetention', function () {
    return time() - (int) System::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', 86400); // 1 day
});

Server::setResource('auditRetention', function (Document $project) {
    if ($project->getId() === 'console') {
        return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT_CONSOLE', 15778800)); // 6 months
    }
    return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', 1209600)); // 14 days
}, ['project']);

Server::setResource('executionRetention', function () {
    return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', 1209600)); // 14 days
});

Server::setResource('cache', function (Registry $register) {
    $pools = $register->get('pools');
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
}, ['register']);

Server::setResource('redis', function () {
    $host = System::getEnv('_APP_REDIS_HOST', 'localhost');
    $port = System::getEnv('_APP_REDIS_PORT', 6379);
    $pass = System::getEnv('_APP_REDIS_PASS', '');

    $redis = new \Redis();
    @$redis->pconnect($host, (int)$port);
    if ($pass) {
        $redis->auth($pass);
    }
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

    return $redis;
});

Server::setResource('timelimit', function (\Redis $redis) {
    return function (string $key, int $limit, int $time) use ($redis) {
        return new TimeLimitRedis($key, $limit, $time, $redis);
    };
}, ['redis']);

Server::setResource('log', fn () => new Log());

Server::setResource('publisher', function (Group $pools) {
    return $pools->get('publisher')->pop()->getResource();
}, ['pools']);

Server::setResource('consumer', function (Group $pools) {
    return $pools->get('consumer')->pop()->getResource();
}, ['pools']);

Server::setResource('queueForStatsUsage', function (Publisher $publisher) {
    return new StatsUsage($publisher);
}, ['publisher']);

Server::setResource('queueForDatabase', function (Publisher $publisher) {
    return new EventDatabase($publisher);
}, ['publisher']);

Server::setResource('queueForMessaging', function (Publisher $publisher) {
    return new Messaging($publisher);
}, ['publisher']);

Server::setResource('queueForMails', function (Publisher $publisher) {
    return new Mail($publisher);
}, ['publisher']);

Server::setResource('queueForBuilds', function (Publisher $publisher) {
    return new Build($publisher);
}, ['publisher']);

Server::setResource('queueForDeletes', function (Publisher $publisher) {
    return new Delete($publisher);
}, ['publisher']);

Server::setResource('queueForEvents', function (Publisher $publisher) {
    return new Event($publisher);
}, ['publisher']);

Server::setResource('queueForAudits', function (Publisher $publisher) {
    return new Audit($publisher);
}, ['publisher']);

Server::setResource('queueForWebhooks', function (Publisher $publisher) {
    return new Webhook($publisher);
}, ['publisher']);

Server::setResource('queueForFunctions', function (Publisher $publisher) {
    return new Func($publisher);
}, ['publisher']);

Server::setResource('queueForRealtime', function () {
    return new Realtime();
}, []);

Server::setResource('queueForCertificates', function (Publisher $publisher) {
    return new Certificate($publisher);
}, ['publisher']);

Server::setResource('queueForMigrations', function (Publisher $publisher) {
    return new Migration($publisher);
}, ['publisher']);

Server::setResource('logger', function (Registry $register) {
    return $register->get('logger');
}, ['register']);

Server::setResource('pools', function (Registry $register) {
    return $register->get('pools');
}, ['register']);

Server::setResource('telemetry', fn () => new NoTelemetry());

Server::setResource('deviceForSites', function (Document $project) {
    return getDevice(APP_STORAGE_SITES . '/app-' . $project->getId());
}, ['project']);

Server::setResource('deviceForImports', function (Document $project) {
    return getDevice(APP_STORAGE_IMPORTS . '/app-' . $project->getId());
}, ['project']);

Server::setResource('deviceForFunctions', function (Document $project) {
    return getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId());
}, ['project']);

Server::setResource('deviceForFiles', function (Document $project) {
    return getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
}, ['project']);

Server::setResource('deviceForBuilds', function (Document $project) {
    return getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId());
}, ['project']);

Server::setResource('deviceForCache', function (Document $project) {
    return getDevice(APP_STORAGE_CACHE . '/app-' . $project->getId());
}, ['project']);

Server::setResource(
    'isResourceBlocked',
    fn () => fn (Document $project, string $resourceType, ?string $resourceId) => false
);

Server::setResource('plan', function (array $plan = []) {
    return [];
});

Server::setResource('certificates', function () {
    $email = System::getEnv('_APP_EMAIL_CERTIFICATES', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS'));
    if (empty($email)) {
        throw new Exception('You must set a valid security email address (_APP_EMAIL_CERTIFICATES) to issue a LetsEncrypt SSL certificate.');
    }

    return new LetsEncrypt($email);
});

Server::setResource('logError', function (Registry $register, Document $project) {
    return function (Throwable $error, string $namespace, string $action, ?array $extras = null) use ($register, $project) {
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
            $log->addTag('projectId', $project->getId() ?? '');

            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());


            foreach (($extras ?? []) as $key => $value) {
                $log->addExtra($key, $value);
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

        Console::warning("Failed: {$error->getMessage()}");
        Console::warning($error->getTraceAsString());
    };
}, ['register', 'project']);

Server::setResource('executor', fn () => new Executor(fn (string $projectId, string $deploymentId) => System::getEnv('_APP_EXECUTOR_HOST')));

$pools = $register->get('pools');
$platform = new Appwrite();
$args = $platform->getEnv('argv');

if (!isset($args[1])) {
    Console::error('Missing worker name');
    Console::exit(1);
}

\array_shift($args);
$workerName = $args[0];

if (\str_starts_with($workerName, 'databases')) {
    $queueName = System::getEnv('_APP_QUEUE_NAME', 'database_db_main');
} else {
    $queueName = System::getEnv('_APP_QUEUE_NAME', 'v1-' . strtolower($workerName));
}

try {
    /**
     * Any worker can be configured with the following env vars:
     * - _APP_WORKERS_NUM           The total number of worker processes
     * - _APP_WORKER_PER_CORE       The number of worker processes per core (ignored if _APP_WORKERS_NUM is set)
     * - _APP_QUEUE_NAME            The name of the queue to read for database events
     */
    $platform->init(Service::TYPE_WORKER, [
        'workersNum' => System::getEnv('_APP_WORKERS_NUM', 1),
        'connection' => $pools->get('consumer')->pop()->getResource(),
        'workerName' => strtolower($workerName) ?? null,
        'queueName' => $queueName
    ]);
} catch (\Throwable $e) {
    Console::error($e->getMessage() . ', File: ' . $e->getFile() .  ', Line: ' . $e->getLine());
}

$worker = $platform->getWorker();

$worker
    ->shutdown()
    ->inject('pools')
    ->action(function (Group $pools) {
        $pools->reclaim();
    });

$worker
    ->error()
    ->inject('error')
    ->inject('logger')
    ->inject('log')
    ->inject('pools')
    ->inject('project')
    ->action(function (Throwable $error, ?Logger $logger, Log $log, Group $pools, Document $project) use ($queueName) {
        $pools->reclaim();
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

        if ($logger) {
            $log->setNamespace("appwrite-worker");
            $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());
            $log->setAction('appwrite-queue-' . $queueName);
            $log->addTag('verboseType', get_class($error));
            $log->addTag('code', $error->getCode());
            $log->addTag('projectId', $project->getId() ?? 'n/a');
            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('roles', Authorization::getRoles());

            $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            try {
                $responseCode = $logger->addLog($log);
                Console::info('Error log pushed with status code: ' . $responseCode);
            } catch (Throwable $th) {
                Console::error('Error pushing log: ' . $th->getMessage());
            }
        }

        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $error->getMessage());
        Console::error('[Error] File: ' . $error->getFile());
        Console::error('[Error] Line: ' . $error->getLine());
    });

$worker->workerStart()
    ->action(function () use ($workerName) {
        Console::info("Worker $workerName  started");
    });

$worker->start();
