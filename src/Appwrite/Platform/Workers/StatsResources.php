<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Platform\Action;
use Exception;
use Throwable;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Queue\Message;

class StatsResources extends Action
{
    /**
     * Date format for different periods
     */
    protected array $periods = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00'
    ];

    /**
     * @var array $documents
     *
     * Array of documents to batch write
     *
     */
    protected array $documents = [];

    public static function getName(): string
    {
        return 'stats-resources';
    }


    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Stats resources worker')
            ->inject('message')
            ->inject('project')
            ->inject('getProjectDB')
            ->inject('getLogsDB')
            ->inject('dbForPlatform')
            ->inject('logError')
            ->callback($this->action(...));
    }

    /**
     * @param Message $message
     * @param Document $project
     * @param callable $getProjectDB
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    public function action(Message $message, Document $project, callable $getProjectDB, callable $getLogsDB, Database $dbForPlatform, callable $logError): void
    {
        $this->logError = $logError;

        $payload = $message->getPayload() ?? [];
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        if (empty($project->getAttribute('database'))) {
            return;
        }

        // Reset documents for each job
        $this->documents = [];

        $startTime = microtime(true);
        $this->countForProject($dbForPlatform, $getLogsDB, $getProjectDB, $project);
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        Console::info('Project: ' . $project->getId() . '(' . $project->getSequence() . ') aggregated in ' . $executionTime .' seconds');
    }

    protected function countForProject(Database $dbForPlatform, callable $getLogsDB, callable $getProjectDB, Document $project): void
    {
        Console::info('Begining count for: ' . $project->getId());

        $dbForLogs = null;
        $dbForProject = null;
        try {
            /** @var \Utopia\Database\Database $dbForLogs */
            $dbForLogs = call_user_func($getLogsDB, $project);
            /** @var \Utopia\Database\Database $dbForProject */
            $dbForProject = call_user_func($getProjectDB, $project);
        } catch (Throwable $th) {
            Console::error('Unable to get database');
            Console::error($th->getMessage());
            return;
        }

        try {

            $region = $project->getAttribute('region');

            $platforms = $dbForPlatform->count('platforms', [
                Query::equal('projectInternalId', [$project->getSequence()])
            ]);
            $webhooks = $dbForPlatform->count('webhooks', [
                Query::equal('projectInternalId', [$project->getSequence()])
            ]);
            $keys = $dbForPlatform->count('keys', [
                Query::equal('projectInternalId', [$project->getSequence()])
            ]);

            $domains = $dbForPlatform->count('rules', [
                Query::equal('projectInternalId', [$project->getSequence()]),
                Query::equal('owner', ['']),
            ]);


            $databases = $dbForProject->count('databases');
            $buckets = $dbForProject->count('buckets');
            $users = $dbForProject->count('users');

            $last30Days = (new \DateTime())->sub(\DateInterval::createFromDateString('30 days'))->format('Y-m-d 00:00:00');
            $usersMAU = $dbForProject->count('users', [
                Query::greaterThanEqual('accessedAt', $last30Days)
            ]);
            $last24Hours = (new \DateTime())->sub(\DateInterval::createFromDateString('24 hours'))->format('Y-m-d h:m:00');
            $usersDAU = $dbForProject->count('users', [
                Query::greaterThanEqual('accessedAt', $last24Hours)
            ]);
            $last7Days = (new \DateTime())->sub(\DateInterval::createFromDateString('7 days'))->format('Y-m-d 00:00:00');
            $usersWAU = $dbForProject->count('users', [
                Query::greaterThanEqual('accessedAt', $last7Days)
            ]);
            $teams = $dbForProject->count('teams');
            $functions = $dbForProject->count('functions');

            $messages = $dbForProject->count('messages');
            $providers = $dbForProject->count('providers');
            $topics = $dbForProject->count('topics');
            $targets = $dbForProject->count('targets');
            $emailTargets = $dbForProject->count('targets', [
                Query::equal('providerType', [MESSAGE_TYPE_EMAIL])
            ]);
            $pushTargets = $dbForProject->count('targets', [
                Query::equal('providerType', [MESSAGE_TYPE_PUSH])
            ]);
            $smsTargets = $dbForProject->count('targets', [
                Query::equal('providerType', [MESSAGE_TYPE_SMS])
            ]);

            $metrics = [
                METRIC_DATABASES => $databases,
                METRIC_BUCKETS => $buckets,
                METRIC_USERS => $users,
                METRIC_FUNCTIONS => $functions,
                METRIC_TEAMS => $teams,
                METRIC_MESSAGES => $messages,
                METRIC_MAU => $usersMAU,
                METRIC_DAU => $usersDAU,
                METRIC_WAU => $usersWAU,
                METRIC_WEBHOOKS => $webhooks,
                METRIC_PLATFORMS => $platforms,
                METRIC_PROVIDERS => $providers,
                METRIC_TOPICS => $topics,
                METRIC_KEYS => $keys,
                METRIC_DOMAINS => $domains,
                METRIC_TARGETS => $targets,
                str_replace('{providerType}', MESSAGE_TYPE_EMAIL, METRIC_PROVIDER_TYPE_TARGETS) => $emailTargets,
                str_replace('{providerType}', MESSAGE_TYPE_PUSH, METRIC_PROVIDER_TYPE_TARGETS) => $pushTargets,
                str_replace('{providerType}', MESSAGE_TYPE_SMS, METRIC_PROVIDER_TYPE_TARGETS) => $smsTargets,
            ];

            foreach ($metrics as $metric => $value) {
                $this->createStatsDocuments($region, $metric, $value);
            }

            try {
                $this->countForBuckets($dbForProject, $dbForLogs, $region);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_buckets_{$project->getId()}"]);
            }

            try {
                $this->countImageTransformations($dbForProject, $dbForLogs, $region);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_buckets_{$project->getId()}"]);
            }

            try {
                $this->countForDatabase($dbForProject, $region);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_database_{$project->getId()}"]);
            }

            try {
                $this->countForSitesAndFunctions($dbForProject, $region);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_functions_{$project->getId()}"]);
            }

            $this->writeDocuments($dbForLogs, $project);
        } catch (Throwable $th) {
            call_user_func_array($this->logError, [$th, "StatsResources", "count_for_project_{$project->getId()}"]);
        }

        Console::info('End of count for: ' . $project->getId());
    }

    protected function countForBuckets(Database $dbForProject, Database $dbForLogs, string $region)
    {
        $totalFiles = 0;
        $totalStorage = 0;
        $this->foreachDocument($dbForProject, 'buckets', [], function ($bucket) use ($dbForProject, $dbForLogs, $region, &$totalFiles, &$totalStorage) {
            $files = $dbForProject->count('bucket_' . $bucket->getSequence());

            $metric = str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES);
            $this->createStatsDocuments($region, $metric, $files);

            $storage = $dbForProject->sum('bucket_' . $bucket->getSequence(), 'sizeActual');
            $metric = str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES_STORAGE);
            $this->createStatsDocuments($region, $metric, $storage);

            $totalStorage += $storage;
            $totalFiles += $files;
        });

        $this->createStatsDocuments($region, METRIC_FILES, $totalFiles);
        $this->createStatsDocuments($region, METRIC_FILES_STORAGE, $totalStorage);
    }

    /**
     * Need separate function to count per period data
     */
    protected function countImageTransformations(Database $dbForProject, Database $dbForLogs, string $region)
    {
        $totalImageTransformations = 0;
        $last30Days = (new \DateTime())->sub(\DateInterval::createFromDateString('30 days'))->format('Y-m-d 00:00:00');
        $this->foreachDocument($dbForProject, 'buckets', [], function ($bucket) use ($dbForProject, $last30Days, $region, &$totalImageTransformations) {
            $imageTransformations = $dbForProject->count('bucket_' . $bucket->getSequence(), [
                Query::greaterThanEqual('transformedAt', $last30Days),
            ]);
            $metric = str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES_IMAGES_TRANSFORMED);
            $this->createStatsDocuments($region, $metric, $imageTransformations);
            $totalImageTransformations += $imageTransformations;
        });

        $this->createStatsDocuments($region, METRIC_FILES_IMAGES_TRANSFORMED, $totalImageTransformations);
    }

    protected function countForDatabase(Database $dbForProject, string $region)
    {
        $totalCollections = 0;
        $totalDocuments = 0;

        $totalDatabaseStorage = 0;

        $this->foreachDocument($dbForProject, 'databases', [], function ($database) use ($dbForProject, $region, &$totalCollections, &$totalDocuments, &$totalDatabaseStorage) {
            $collections = $dbForProject->count('database_' . $database->getSequence());

            $metric = str_replace('{databaseInternalId}', $database->getSequence(), METRIC_DATABASE_ID_COLLECTIONS);
            $this->createStatsDocuments($region, $metric, $collections);

            [$documents, $storage] = $this->countForCollections($dbForProject, $database, $region);

            $totalDatabaseStorage += $storage;
            $totalDocuments += $documents;
            $totalCollections += $collections;
        });

        $this->createStatsDocuments($region, METRIC_COLLECTIONS, $totalCollections);
        $this->createStatsDocuments($region, METRIC_DOCUMENTS, $totalDocuments);
        $this->createStatsDocuments($region, METRIC_DATABASES_STORAGE, $totalDatabaseStorage);
    }
    protected function countForCollections(Database $dbForProject, Document $database, string $region): array
    {
        $databaseDocuments = 0;
        $databaseStorage = 0;
        $this->foreachDocument($dbForProject, 'database_' . $database->getSequence(), [], function ($collection) use ($dbForProject, $database, $region, &$databaseStorage, &$databaseDocuments) {
            $documents = $dbForProject->count('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());
            $metric = str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getSequence(), $collection->getSequence()], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS);
            $this->createStatsDocuments($region, $metric, $documents);
            $databaseDocuments += $documents;

            $collectionStorage = $dbForProject->getSizeOfCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());
            $metric = str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getSequence(), $collection->getSequence()], METRIC_DATABASE_ID_COLLECTION_ID_STORAGE);
            $this->createStatsDocuments($region, $metric, $collectionStorage);
            $databaseStorage += $collectionStorage;

        });

        $metric = str_replace(['{databaseInternalId}'], [$database->getSequence()], METRIC_DATABASE_ID_DOCUMENTS);
        $this->createStatsDocuments($region, $metric, $databaseDocuments);

        $metric = str_replace(['{databaseInternalId}'], [$database->getSequence()], METRIC_DATABASE_ID_STORAGE);
        $this->createStatsDocuments($region, $metric, $databaseStorage);

        return [$databaseDocuments, $databaseStorage];
    }

    protected function countForSitesAndFunctions(Database $dbForProject, string $region): void
    {
        $deploymentsStorage = $dbForProject->sum('deployments', 'sourceSize');
        $buildsStorage = $dbForProject->sum('deployments', 'buildSize');
        $this->createStatsDocuments($region, METRIC_DEPLOYMENTS_STORAGE, $deploymentsStorage);
        $this->createStatsDocuments($region, METRIC_BUILDS_STORAGE, $buildsStorage);

        $deployments = $dbForProject->count('deployments');
        $this->createStatsDocuments($region, METRIC_DEPLOYMENTS, $deployments);
        $this->createStatsDocuments($region, METRIC_BUILDS, $deployments);

        $this->countForFunctions($dbForProject, $region);
        $this->countForSites($dbForProject, $region);
    }

    protected function countForFunctions(Database $dbForProject, string $region)
    {

        $deploymentsStorage = $dbForProject->sum('deployments', 'sourceSize', [
            Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS])
        ]);
        $buildsStorage = $dbForProject->sum('deployments', 'buildSize', [
            Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS])
        ]);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_FUNCTIONS, METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE), $deploymentsStorage);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_FUNCTIONS, METRIC_RESOURCE_TYPE_BUILDS_STORAGE), $buildsStorage);

        $deployments = $dbForProject->count('deployments', [
            Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS])
        ]);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_FUNCTIONS, METRIC_RESOURCE_TYPE_DEPLOYMENTS), $deployments);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_FUNCTIONS, METRIC_RESOURCE_TYPE_BUILDS), $deployments);


        // Count runtimes
        $runtimes = [];

        $this->foreachDocument($dbForProject, 'functions', [], function (Document $function) use ($dbForProject, $region, &$runtimes) {
            $functionDeploymentsStorage = $dbForProject->sum('deployments', 'sourceSize', [
                Query::equal('resourceInternalId', [$function->getSequence()]),
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
            ]);
            $this->createStatsDocuments($region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE), $functionDeploymentsStorage);

            $functionDeployments = $dbForProject->count('deployments', [
                Query::equal('resourceInternalId', [$function->getSequence()]),
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
            ]);
            $this->createStatsDocuments($region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS), $functionDeployments);

            /**
             * As deployments and builds have 1-1 relationship,
             * the count for one should match the other
             */
            $this->createStatsDocuments($region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS), $functionDeployments);

            $functionBuildsStorage = 0;

            $this->foreachDocument($dbForProject, 'deployments', [
                Query::equal('resourceInternalId', [$function->getSequence()]),
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
            ], function (Document $deployment) use (&$functionBuildsStorage): void {
                $functionBuildsStorage += $deployment->getAttribute('buildSize', 0);
            });

            $this->createStatsDocuments($region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $functionBuildsStorage);

            // Runtimes count
            $runtime = $function->getAttribute('runtime');
            if (!empty($runtime)) {
                $runtimes[$runtime] = ($runtimes[$runtime] ?? 0) + 1;
            }
        });

        // Write runtimes counts
        foreach ($runtimes as $runtime => $count) {
            $this->createStatsDocuments($region, str_replace('{runtime}', $runtime, METRIC_FUNCTIONS_RUNTIME), $count);
        }

    }

    protected function countForSites(Database $dbForProject, string $region)
    {

        $deploymentsStorage = $dbForProject->sum('deployments', 'sourceSize', [
            Query::equal('resourceType', [RESOURCE_TYPE_SITES])
        ]);
        $buildsStorage = $dbForProject->sum('deployments', 'buildSize', [
            Query::equal('resourceType', [RESOURCE_TYPE_SITES])
        ]);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE), $deploymentsStorage);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_BUILDS_STORAGE), $buildsStorage);

        $deployments = $dbForProject->count('deployments', [
            Query::equal('resourceType', [RESOURCE_TYPE_SITES])
        ]);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_DEPLOYMENTS), $deployments);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_BUILDS), $deployments);

        // Count frameworks
        $frameworks = [];

        $this->foreachDocument($dbForProject, 'sites', [], function (Document $site) use ($dbForProject, $region, &$frameworks) {
            $siteDeploymentsStorage = $dbForProject->sum('deployments', 'sourceSize', [
                Query::equal('resourceInternalId', [$site->getSequence()]),
                Query::equal('resourceType', [RESOURCE_TYPE_SITES]),
            ]);
            $this->createStatsDocuments($region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_SITES,$site->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE), $siteDeploymentsStorage);

            $siteDeployments = $dbForProject->count('deployments', [
                Query::equal('resourceInternalId', [$site->getSequence()]),
                Query::equal('resourceType', [RESOURCE_TYPE_SITES]),
            ]);
            $this->createStatsDocuments($region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_SITES,$site->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS), $siteDeployments);

            /**
             * As deployments and builds have 1-1 relationship,
             * the count for one should match the other
             */
            $this->createStatsDocuments($region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_SITES,$site->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS), $siteDeployments);

            $siteBuildsStorage = $dbForProject->sum('deployments', 'buildSize', [
                Query::equal('resourceInternalId', [$site->getSequence()]),
                Query::equal('resourceType', [RESOURCE_TYPE_SITES]),
            ]);

            $this->createStatsDocuments($region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_SITES,$site->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $siteBuildsStorage);

            // Frameworks count
            $framework = $site->getAttribute('framework');
            if (!empty($framework)) {
                $frameworks[$framework] = ($frameworks[$framework] ?? 0) + 1;
            }
        });

        // Write frameworks counts
        foreach ($frameworks as $framework => $count) {
            $this->createStatsDocuments($region, str_replace('{framework}', $framework, METRIC_SITES_FRAMEWORK), $count);
        }
    }

    protected function createStatsDocuments(string $region, string $metric, int $value)
    {
        foreach ($this->periods as $period => $format) {
            $time = 'inf' === $period ? null : \date($format, \time());
            $id = \md5("{$time}_{$period}_{$metric}");

            $this->documents[] = new Document([
                '$id' => $id,
                'metric' => $metric,
                'period' => $period,
                'region' => $region,
                'value' => $value,
                'time' => $time,
            ]);
        }
    }

    protected function writeDocuments(Database $dbForLogs, Document $project): void
    {
        $message = 'Stats writeDocuments project: ' . $project->getId() . '(' . $project->getSequence() . ')';

        /**
         * sort by unique index key reduce locks/deadlocks
         */
        usort($this->documents, function ($a, $b) {
            // Metric DESC
            $cmp = strcmp($b['metric'], $a['metric']);
            if ($cmp !== 0) {
                return $cmp;
            }

            // Period ASC
            $cmp = strcmp($a['period'], $b['period']);
            if ($cmp !== 0) {
                return $cmp;
            }

            // Time ASC, NULLs first
            if ($a['time'] === null) {
                return ($b['time'] === null) ? 0 : -1;
            }
            if ($b['time'] === null) {
                return 1;
            }

            return strcmp($a['time'], $b['time']);
        });

        try {
            $dbForLogs->upsertDocuments(
                'stats',
                $this->documents,
            );

            Console::success($message . ' | Documents: ' . count($this->documents));
        } catch (\Throwable $e) {
            Console::error('Error: ' . $message . ' | Exception: ' . $e->getMessage());
            throw $e;
        }
    }
}
