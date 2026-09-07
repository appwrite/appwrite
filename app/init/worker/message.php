<?php

use Appwrite\Database\Factory as DatabaseFactory;
use Appwrite\Deployment\Deployments;
use Appwrite\Event\Event;
use Appwrite\Event\Message\ProjectContext;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Usage\Context;
use OpenRuntimes\Orchestrator\Jobs;
use Utopia\Audit\Adapter\Database as AdapterDatabase;
use Utopia\Audit\Audit as UtopiaAudit;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Pools\Group;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;
use Utopia\Span\Span;
use Utopia\Storage\Device\Telemetry as TelemetryDevice;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

/**
 * Register per-job resources on the given container.
 * These resources depend on the queue message or keep mutable state and
 * must be fresh for each worker job.
 */
return function (Container $container): void {
    $container->set('usage', fn () => new Context(), []);

    $container->set('authorization', function () {
        $authorization = new Authorization();
        $authorization->disable();

        return $authorization;
    }, []);

    $container->set('databaseFactory', fn (Group $pools, Cache $cache, Authorization $authorization) => new DatabaseFactory(
        $pools,
        $cache,
        $authorization
    ), ['pools', 'cache', 'authorization']);

    $container->set('dbForPlatform', fn (DatabaseFactory $databaseFactory) => $databaseFactory->platform(), ['databaseFactory']);

    $container->set('projectContext', function ($message) {
        $payload = $message->getPayload() ?? [];
        $project = $payload['project'] ?? [];

        return ProjectContext::fromArray(\is_array($project) ? $project : []);
    }, ['message']);

    $container->set('project', function ($message, Database $dbForPlatform) {
        $payload = $message->getPayload() ?? [];
        $project = new Document($payload['project'] ?? []);

        if ($project->isEmpty() || $project->getId() === 'console') {
            return $project;
        }

        $project = $dbForPlatform->getDocument('projects', $project->getId());

        Span::add('project.id', $project->getId());

        return $project;
    }, ['message', 'dbForPlatform']);

    $container->set('dbForProject', fn (DatabaseFactory $databaseFactory, Document $project, Database $dbForPlatform) => $project->isEmpty() || $project->getId() === 'console'
        ? $dbForPlatform
        : $databaseFactory->project(
            $project,
            APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER,
        ), ['databaseFactory', 'project', 'dbForPlatform']);

    $container->set('getProjectDB', function (DatabaseFactory $databaseFactory, Database $dbForPlatform) {
        return function (Document $project) use ($databaseFactory, $dbForPlatform): Database {
            if ($project->isEmpty() || $project->getId() === 'console') {
                return $dbForPlatform;
            }

            return $databaseFactory->project(
                $project,
                APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER
            );
        };
    }, ['databaseFactory', 'dbForPlatform']);

    $container->set('getDatabasesDB', function (DatabaseFactory $databaseFactory, Document $project) {
        return function (Document $database, ?Document $projectDocument = null) use ($databaseFactory, $project): Database {
            $projectDocument ??= $project;

            // Backwards-compatibility: older or seeded legacy databases may not have a DSN stored
            // in the "database" attribute. In that case, fall back to the project's database DSN.
            $databaseConfig = $database->getAttribute('database', '') === ''
                ? new Document(\array_merge($database->getArrayCopy(), ['database' => $projectDocument->getAttribute('database', '')]))
                : $database;

            return $databaseFactory->tenant(
                $databaseConfig,
                $projectDocument,
                APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER,
            );
        };
    }, ['databaseFactory', 'project']);

    $container->set('getLogsDB', function (DatabaseFactory $databaseFactory) {
        $database = null;

        return function (?Document $project = null) use ($databaseFactory, &$database) {
            if ($database !== null && $project !== null && !$project->isEmpty() && $project->getId() !== 'console') {
                $database->setTenant($project->getSequence());

                return $database;
            }

            $database = $databaseFactory->logs(
                $project,
                APP_DATABASE_TIMEOUT_MILLISECONDS_WORKER,
                APP_DATABASE_QUERY_MAX_VALUES_WORKER
            );

            return $database;
        };
    }, ['databaseFactory']);

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

    $container->set('queueForEvents', function (Publisher $publisher) {
        return new Event($publisher);
    }, ['publisher']);

    $container->set('queueForWebhooks', function (Publisher $publisher) {
        return new Webhook($publisher);
    }, ['publisher']);

    $container->set('publisherForNotifications', fn (Publisher $publisher) => new NotificationPublisher(
        $publisher,
        new Queue(System::getEnv('_APP_NOTIFICATIONS_QUEUE_NAME', Event::NOTIFICATIONS_QUEUE_NAME))
    ), ['publisher']);

    $container->set('publisherForFunctions', fn (Publisher $publisher) => new FunctionPublisher(
        $publisher,
        new Queue(System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', Event::FUNCTIONS_QUEUE_NAME), 'utopia-queue', Event::FUNCTIONS_QUEUE_TTL)
    ), ['publisher']);

    $container->set('queueForRealtime', function () {
        return new Realtime();
    }, []);

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

    // Only the Builds worker uses this, handing template-into-repo pushes to
    // the jobs-service.
    $container->set('deployments', function (Jobs $jobs, Database $dbForProject, Document $project, array $platform) {
        return new Deployments($jobs, $dbForProject, $project, $platform);
    }, ['jobs', 'dbForProject', 'project', 'platform']);

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
