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
            ->callback([$this, 'action']);
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
            var_dump($payload);
            return;
        }

        $this->countForProject($dbForPlatform, $getLogsDB, $getProjectDB, $project);
    }


    protected function countForProject(Database $dbForPlatform, callable $getLogsDB, callable $getProjectDB, Document $project): void
    {
        Console::info('Begining count for: ' . $project->getId());

        try {
            /** @var \Utopia\Database\Database $dbForLogs */
            $dbForLogs = call_user_func($getLogsDB, $project);
            /** @var \Utopia\Database\Database $dbForProject */
            $dbForProject = call_user_func($getProjectDB, $project);

            $region = $project->getAttribute('region');

            $platforms = $dbForPlatform->count('platforms', [
                Query::equal('projectInternalId', [$project->getInternalId()])
            ]);
            $webhooks = $dbForPlatform->count('webhooks', [
                Query::equal('projectInternalId', [$project->getInternalId()])
            ]);
            $keys = $dbForPlatform->count('keys', [
                Query::equal('projectInternalId', [$project->getInternalId()])
            ]);
            $databases = $dbForProject->count('databases');
            $buckets = $dbForProject->count('buckets');
            $users = $dbForProject->count('users');

            $last30Days = (new \DateTime())->sub(\DateInterval::createFromDateString('30 days'))->format('Y-m-d 00:00:00');
            $usersActive = $dbForProject->count('users', [
                Query::greaterThanEqual('accessedAt', $last30Days)
            ]);
            $teams = $dbForProject->count('teams');
            $functions = $dbForProject->count('functions');
            $messages = $dbForProject->count('messages');
            $providers = $dbForProject->count('providers');
            $topics = $dbForProject->count('topics');

            $metrics = [
                METRIC_DATABASES => $databases,
                METRIC_BUCKETS => $buckets,
                METRIC_USERS => $users,
                METRIC_FUNCTIONS => $functions,
                METRIC_TEAMS => $teams,
                METRIC_MESSAGES => $messages,
                METRIC_USERS_ACTIVE => $usersActive,
                METRIC_WEBHOOKS => $webhooks,
                METRIC_PLATFORMS => $platforms,
                METRIC_PROVIDERS => $providers,
                METRIC_TOPICS => $topics,
                METRIC_KEYS => $keys,
            ];

            foreach ($metrics as $metric => $value) {
                $this->createOrUpdateMetric($dbForLogs, $region, $metric, $value);
            }

            try {
                $this->countForBuckets($dbForProject, $dbForLogs, $region);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_buckets_{$project->getId()}"]);
            }

            try {
                $this->countForDatabase($dbForProject, $dbForLogs, $region);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_database_{$project->getId()}"]);
            }

            try {
                $this->countForFunctions($dbForProject, $dbForLogs, $region);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_functions_{$project->getId()}"]);
            }
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
            $files = $dbForProject->count('bucket_' . $bucket->getInternalId());

            $metric = str_replace('{bucketInternalId}', $bucket->getInternalId(), METRIC_BUCKET_ID_FILES);
            $this->createOrUpdateMetric($dbForLogs, $region, $metric, $files);

            $storage = $dbForProject->sum('bucket_' . $bucket->getInternalId(), 'sizeActual');
            $metric = str_replace('{bucketInternalId}', $bucket->getInternalId(), METRIC_BUCKET_ID_FILES_STORAGE);
            $this->createOrUpdateMetric($dbForLogs, $region, $metric, $storage);

            $totalStorage += $storage;
            $totalFiles += $files;
        });

        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_FILES, $totalFiles);
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_FILES_STORAGE, $totalStorage);
    }

    protected function countForDatabase(Database $dbForProject, Database $dbForLogs, string $region)
    {
        $totalCollections = 0;
        $totalDocuments = 0;

        $this->foreachDocument($dbForProject, 'databases', [], function ($database) use ($dbForProject, $dbForLogs, $region, &$totalCollections, &$totalDocuments) {
            $collections = $dbForProject->count('database_' . $database->getInternalId());

            $metric = str_replace('{databaseInternalId}', $database->getInternalId(), METRIC_DATABASE_ID_COLLECTIONS);
            $this->createOrUpdateMetric($dbForLogs, $region, $metric, $collections);

            $documents = $this->countForCollections($dbForProject, $dbForLogs, $database, $region);

            $totalDocuments += $documents;
            $totalCollections += $collections;
        });

        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_COLLECTIONS, $totalCollections);
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_DOCUMENTS, $totalDocuments);
    }
    protected function countForCollections(Database $dbForProject, Database $dbForLogs, Document $database, string $region): int
    {
        $databaseDocuments = 0;
        $this->foreachDocument($dbForProject, 'database_' . $database->getInternalId(), [], function ($collection) use ($dbForProject, $dbForLogs, $database, $region, &$totalCollections, &$databaseDocuments) {
            $documents = $dbForProject->count('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());

            $metric = str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getInternalId(), $collection->getInternalId()], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS);
            $this->createOrUpdateMetric($dbForLogs, $region, $metric, $documents);

            $databaseDocuments += $documents;
        });

        $metric = str_replace(['{databaseInternalId}'], [$database->getInternalId()], METRIC_DATABASE_ID_DOCUMENTS);
        $this->createOrUpdateMetric($dbForLogs, $region, $metric, $databaseDocuments);

        return $databaseDocuments;
    }

    protected function countForFunctions(Database $dbForProject, Database $dbForLogs, string $region)
    {
        $deploymentsStorage = $dbForProject->sum('deployments', 'size');
        $buildsStorage = $dbForProject->sum('builds', 'size');
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_DEPLOYMENTS_STORAGE, $deploymentsStorage);
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_BUILDS_STORAGE, $buildsStorage);

        $deployments = $dbForProject->count('deployments');
        $builds = $dbForProject->count('builds');
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_DEPLOYMENTS, $deployments);
        $this->createOrUpdateMetric($dbForLogs, $region, METRIC_BUILDS, $builds);


        $this->foreachDocument($dbForProject, 'functions', [], function (Document $function) use ($dbForProject, $dbForLogs, $region) {
            $functionDeploymentsStorage = $dbForProject->sum('deployments', 'size', [
                Query::equal('resourceInternalId', [$function->getInternalId()]),
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
            ]);
            $this->createOrUpdateMetric($dbForLogs, $region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getInternalId()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE), $functionDeploymentsStorage);

            $functionDeployments = $dbForProject->count('deployments', [
                Query::equal('resourceInternalId', [$function->getInternalId()]),
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
            ]);
            $this->createOrUpdateMetric($dbForLogs, $region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getInternalId()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS), $functionDeployments);

            /**
             * As deployments and builds have 1-1 relationship,
             * the count for one should match the other
             */
            $this->createOrUpdateMetric($dbForLogs, $region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS), $functionDeployments);

            $functionBuildsStorage = 0;

            $this->foreachDocument($dbForProject, 'deployments', [
                Query::equal('resourceInternalId', [$function->getInternalId()]),
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
            ], function (Document $deployment) use ($dbForProject, &$functionBuildsStorage): void {
                $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));
                $functionBuildsStorage += $build->getAttribute('size', 0);
            });

            $this->createOrUpdateMetric($dbForLogs, $region, str_replace(['{resourceType}','{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS,$function->getInternalId()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $functionBuildsStorage);
        });
    }

    protected function createOrUpdateMetric(Database $dbForLogs, string $region, string $metric, int $value)
    {
        foreach ($this->periods as $period => $format) {
            $time = 'inf' === $period ? null : \date($format, \time());
            $id = \md5("{$time}_{$period}_{$metric}");
            $current = $dbForLogs->getDocument('usage', $id);
            if ($current->isEmpty()) {
                $dbForLogs->createDocument('usage', new Document([
                    '$id' => $id,
                    'metric' => $metric,
                    'period' => $period,
                    'region' => $region,
                    'value' => $value,
                ]));
                Console::success('Usage logs created for metric: ' . $metric . ' period:'. $period);
            } else {
                $dbForLogs->updateDocument('usage', $id, $current->setAttribute('value', $value));
                Console::success('Usage logs updated for metric: ' . $metric . ' period:'. $period);
            }
        }
    }
}
