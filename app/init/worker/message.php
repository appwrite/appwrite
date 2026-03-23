<?php

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
use Appwrite\Event\Screenshot;
use Appwrite\Event\Webhook;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Utopia\Audit\Adapter\Database as AdapterDatabase;
use Utopia\Audit\Audit as UtopiaAudit;
use Utopia\Cache\Cache;
use Utopia\Console;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Pools\Group;
use Utopia\Queue\Publisher;
use Utopia\Registry\Registry;
use Utopia\Storage\Device\Telemetry as TelemetryDevice;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

/**
 * Register per-job resources on the given container.
 * These resources depend on the queue message or keep mutable state and
 * must be fresh for each worker job.
 */
return function (Container $container): void {
    $container->set('log', fn () => new Log(), []);

    $container->set('usage', fn () => new Context(), []);

    $container->set('authorization', function () {
        $authorization = new Authorization();
        $authorization->disable();

        return $authorization;
    }, []);

    $container->set('dbForPlatform', function (Cache $cache, Group $pools, Authorization $authorization) {
        $adapter = new DatabasePool($pools->get('console'));
        $dbForPlatform = new Database($adapter, $cache);

        $dbForPlatform
            ->setDatabase(APP_DATABASE)
            ->setAuthorization($authorization)
            ->setNamespace('_console')
            ->setDocumentType('users', User::class);

        return $dbForPlatform;
    }, ['cache', 'pools', 'authorization']);

    $container->set('project', function ($message, Database $dbForPlatform) {
        $payload = $message->getPayload() ?? [];
        $project = new Document($payload['project'] ?? []);

        if ($project->isEmpty() || $project->getId() === 'console') {
            return $project;
        }

        return $dbForPlatform->getDocument('projects', $project->getId());
    }, ['message', 'dbForPlatform']);

    $container->set('dbForProject', function (Cache $cache, Group $pools, Document $project, Database $dbForPlatform, Authorization $authorization) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForPlatform;
        }

        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        $adapter = new DatabasePool($pools->get($dsn->getHost()));
        $database = new Database($adapter, $cache);
        $database->setDocumentType('users', User::class);

        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

        if (\in_array($dsn->getHost(), $sharedTables)) {
            $database
                ->setSharedTables(true)
                ->setTenant($project->getSequence())
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
            ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER);

        return $database;
    }, ['cache', 'pools', 'project', 'dbForPlatform', 'authorization']);

    $container->set('getProjectDB', function (Group $pools, Database $dbForPlatform, Cache $cache, Authorization $authorization) {
        $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

        return function (Document $project) use ($pools, $dbForPlatform, $cache, $authorization, &$databases): Database {
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
                $database->setAuthorization($authorization);
                $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

                if (\in_array($dsn->getHost(), $sharedTables)) {
                    $database
                        ->setSharedTables(true)
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
                $database
                    ->setSharedTables(true)
                    ->setTenant($project->getSequence())
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
                ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER);

            return $database;
        };
    }, ['pools', 'dbForPlatform', 'cache', 'authorization']);

    $container->set('getLogsDB', function (Group $pools, Cache $cache, Authorization $authorization) {
        $database = null;

        return function (?Document $project = null) use ($pools, $cache, $authorization, &$database) {
            if ($database !== null && $project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
                $database->setTenant($project->getSequence());

                return $database;
            }

            $adapter = new DatabasePool($pools->get('logs'));
            $database = new Database($adapter, $cache);

            $database
                ->setDatabase(APP_DATABASE)
                ->setAuthorization($authorization)
                ->setSharedTables(true)
                ->setNamespace('logsV1')
                ->setTimeout(APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER)
                ->setMaxQueryValues(APP_DATABASE_QUERY_MAX_VALUES_WORKER);

            if ($project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
                $database->setTenant($project->getSequence());
            }

            return $database;
        };
    }, ['pools', 'cache', 'authorization']);

    $container->set('abuseRetention', function () {
        return \time() - (int) System::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', 86400); // 1 day
    }, []);

    $container->set('auditRetention', function (Document $project) {
        if ($project->getId() === 'console') {
            return DateTime::addSeconds(new \DateTime(), -1 * (int) System::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT_CONSOLE', 15778800)); // 6 months
        }

        return DateTime::addSeconds(new \DateTime(), -1 * (int) System::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', 1209600)); // 14 days
    }, ['project']);

    $container->set('executionRetention', function () {
        return DateTime::addSeconds(new \DateTime(), -1 * (int) System::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', 1209600)); // 14 days
    }, []);

    $container->set('queueForDatabase', function (Publisher $publisher) {
        return new EventDatabase($publisher);
    }, ['publisher']);

    $container->set('queueForMessaging', function (Publisher $publisher) {
        return new Messaging($publisher);
    }, ['publisher']);

    $container->set('queueForMails', function (Publisher $publisher) {
        return new Mail($publisher);
    }, ['publisher']);

    $container->set('queueForBuilds', function (Publisher $publisher) {
        return new Build($publisher);
    }, ['publisher']);

    $container->set('queueForScreenshots', function (Publisher $publisher) {
        return new Screenshot($publisher);
    }, ['publisher']);

    $container->set('queueForDeletes', function (Publisher $publisher) {
        return new Delete($publisher);
    }, ['publisher']);

    $container->set('queueForEvents', function (Publisher $publisher) {
        return new Event($publisher);
    }, ['publisher']);

    $container->set('queueForAudits', function (Publisher $publisher) {
        return new Audit($publisher);
    }, ['publisher']);

    $container->set('queueForWebhooks', function (Publisher $publisher) {
        return new Webhook($publisher);
    }, ['publisher']);

    $container->set('queueForFunctions', function (Publisher $publisher) {
        return new Func($publisher);
    }, ['publisher']);

    $container->set('queueForRealtime', function () {
        return new Realtime();
    }, []);

    $container->set('queueForCertificates', function (Publisher $publisher) {
        return new Certificate($publisher);
    }, ['publisher']);

    $container->set('queueForMigrations', function (Publisher $publisher) {
        return new Migration($publisher);
    }, ['publisher']);

    $container->set('deviceForSites', function (Document $project, Telemetry $telemetry) {
        return new TelemetryDevice($telemetry, getDevice(APP_STORAGE_SITES . '/app-' . $project->getId()));
    }, ['project', 'telemetry']);

    $container->set('deviceForMigrations', function (Document $project, Telemetry $telemetry) {
        return new TelemetryDevice($telemetry, getDevice(APP_STORAGE_IMPORTS . '/app-' . $project->getId()));
    }, ['project', 'telemetry']);

    $container->set('deviceForFunctions', function (Document $project, Telemetry $telemetry) {
        return new TelemetryDevice($telemetry, getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId()));
    }, ['project', 'telemetry']);

    $container->set('deviceForFiles', function (Document $project, Telemetry $telemetry) {
        return new TelemetryDevice($telemetry, getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId()));
    }, ['project', 'telemetry']);

    $container->set('deviceForBuilds', function (Document $project, Telemetry $telemetry) {
        return new TelemetryDevice($telemetry, getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId()));
    }, ['project', 'telemetry']);

    $container->set('deviceForCache', function (Document $project, Telemetry $telemetry) {
        return new TelemetryDevice($telemetry, getDevice(APP_STORAGE_CACHE . '/app-' . $project->getId()));
    }, ['project', 'telemetry']);

    $container->set('logError', function (Registry $register, Document $project) {
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
                $log->addTag('verboseType', \get_class($error));
                $log->addTag('projectId', $project->getId() ?? '');

                $log->addExtra('file', $error->getFile());
                $log->addExtra('line', $error->getLine());
                $log->addExtra('trace', $error->getTraceAsString());

                if ($error->getPrevious() !== null) {
                    if ($error->getPrevious()->getMessage() != $error->getMessage()) {
                        $log->addExtra('previousMessage', $error->getPrevious()->getMessage());
                    }
                    $log->addExtra('previousFile', $error->getPrevious()->getFile());
                    $log->addExtra('previousLine', $error->getPrevious()->getLine());
                }

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

            if ($error->getPrevious() !== null) {
                if ($error->getPrevious()->getMessage() != $error->getMessage()) {
                    Console::warning("Previous Failed: {$error->getPrevious()->getMessage()}");
                }
                Console::warning("Previous File: {$error->getPrevious()->getFile()} Line: {$error->getPrevious()->getLine()}");
            }
        };
    }, ['register', 'project']);

    $container->set('getAudit', function (Database $dbForPlatform, callable $getProjectDB) {
        return function (Document $project) use ($dbForPlatform, $getProjectDB) {
            if ($project->isEmpty() || $project->getId() === 'console') {
                $adapter = new AdapterDatabase($dbForPlatform);

                return new UtopiaAudit($adapter);
            }

            $dbForProject = $getProjectDB($project);
            $adapter = new AdapterDatabase($dbForProject);

            return new UtopiaAudit($adapter);
        };
    }, ['dbForPlatform', 'getProjectDB']);

    $container->set('executionsRetentionCount', function (Document $project, array $plan) {
        if ($project->getId() === 'console' || empty($plan)) {
            return 0;
        }

        return (int) ($plan['executionsRetentionCount'] ?? 100);
    }, ['project', 'plan']);
};
