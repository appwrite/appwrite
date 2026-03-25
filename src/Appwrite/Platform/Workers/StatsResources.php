<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Platform\Action;
use Exception;
use Throwable;
use Utopia\Async\Promise;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Queue\Message;
use Utopia\Span\Span;

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

        $this->countForProject($dbForPlatform, $getLogsDB, $getProjectDB, $project);
    }

    protected function countForProject(Database $dbForPlatform, callable $getLogsDB, callable $getProjectDB, Document $project): void
    {
        /** @var \Utopia\Database\Database $dbForLogs */
        $dbForLogs = call_user_func($getLogsDB, $project);
        /** @var \Utopia\Database\Database $dbForProject */
        $dbForProject = call_user_func($getProjectDB, $project);

        try {

            $region = $project->getAttribute('region');

            $last30Days = (new \DateTime())->sub(\DateInterval::createFromDateString('30 days'))->format('Y-m-d 00:00:00');
            $last24Hours = (new \DateTime())->sub(\DateInterval::createFromDateString('24 hours'))->format('Y-m-d h:m:00');
            $last7Days = (new \DateTime())->sub(\DateInterval::createFromDateString('7 days'))->format('Y-m-d 00:00:00');

            $results = Promise::map([
                'platforms' => fn () => $dbForPlatform->count('platforms', [
                    Query::equal('projectInternalId', [$project->getSequence()])
                ]),
                'webhooks' => fn () => $dbForPlatform->count('webhooks', [
                    Query::equal('projectInternalId', [$project->getSequence()])
                ]),
                'keys' => fn () => $dbForPlatform->count('keys', [
                    Query::equal('resourceType', ['projects']),
                    Query::equal('resourceInternalId', [$project->getSequence()]),
                ]),
                'domains' => fn () => $dbForPlatform->count('rules', [
                    Query::equal('projectInternalId', [$project->getSequence()]),
                    Query::equal('owner', ['']),
                ]),
                'databases' => fn () => $dbForProject->count('databases'),
                'buckets' => fn () => $dbForProject->count('buckets'),
                'users' => fn () => $dbForProject->count('users'),
                'usersMAU' => fn () => $dbForProject->count('users', [
                    Query::greaterThanEqual('accessedAt', $last30Days)
                ]),
                'usersDAU' => fn () => $dbForProject->count('users', [
                    Query::greaterThanEqual('accessedAt', $last24Hours)
                ]),
                'usersWAU' => fn () => $dbForProject->count('users', [
                    Query::greaterThanEqual('accessedAt', $last7Days)
                ]),
                'teams' => fn () => $dbForProject->count('teams'),
                'functions' => fn () => $dbForProject->count('functions'),
                'messages' => fn () => $dbForProject->count('messages'),
                'providers' => fn () => $dbForProject->count('providers'),
                'topics' => fn () => $dbForProject->count('topics'),
                'targets' => fn () => $dbForProject->count('targets'),
                'emailTargets' => fn () => $dbForProject->count('targets', [
                    Query::equal('providerType', [MESSAGE_TYPE_EMAIL])
                ]),
                'pushTargets' => fn () => $dbForProject->count('targets', [
                    Query::equal('providerType', [MESSAGE_TYPE_PUSH])
                ]),
                'smsTargets' => fn () => $dbForProject->count('targets', [
                    Query::equal('providerType', [MESSAGE_TYPE_SMS])
                ]),
            ])->await();

            $metrics = [
                METRIC_DATABASES => $results['databases'],
                METRIC_BUCKETS => $results['buckets'],
                METRIC_USERS => $results['users'],
                METRIC_FUNCTIONS => $results['functions'],
                METRIC_TEAMS => $results['teams'],
                METRIC_MESSAGES => $results['messages'],
                METRIC_MAU => $results['usersMAU'],
                METRIC_DAU => $results['usersDAU'],
                METRIC_WAU => $results['usersWAU'],
                METRIC_WEBHOOKS => $results['webhooks'],
                METRIC_PLATFORMS => $results['platforms'],
                METRIC_PROVIDERS => $results['providers'],
                METRIC_TOPICS => $results['topics'],
                METRIC_KEYS => $results['keys'],
                METRIC_DOMAINS => $results['domains'],
                METRIC_TARGETS => $results['targets'],
                str_replace('{providerType}', MESSAGE_TYPE_EMAIL, METRIC_PROVIDER_TYPE_TARGETS) => $results['emailTargets'],
                str_replace('{providerType}', MESSAGE_TYPE_PUSH, METRIC_PROVIDER_TYPE_TARGETS) => $results['pushTargets'],
                str_replace('{providerType}', MESSAGE_TYPE_SMS, METRIC_PROVIDER_TYPE_TARGETS) => $results['smsTargets'],
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
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_image_transformations_{$project->getId()}"]);
            }

            try {
                $dbForProject->skipFilters(fn () => $this->countForDatabase($dbForProject, $region), ['subQueryAttributes', 'subQueryIndexes']);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_database_{$project->getId()}"]);
            }

            try {
                $dbForProject->skipFilters(fn () => $this->countForSitesAndFunctions($dbForProject, $region), ['subQueryVariables', 'subQueryProjectVariables']);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_functions_{$project->getId()}"]);
            }

            $this->writeDocuments($dbForLogs, $project);
        } catch (Throwable $th) {
            call_user_func_array($this->logError, [$th, "StatsResources", "count_for_project_{$project->getId()}"]);
        }

    }

    protected function countForBuckets(Database $dbForProject, Database $dbForLogs, string $region)
    {
        $totalFiles = 0;
        $totalStorage = 0;
        $this->foreachDocument($dbForProject, 'buckets', [], function ($bucket) use ($dbForProject, $dbForLogs, $region, &$totalFiles, &$totalStorage) {
            try {
                $bucketResults = Promise::map([
                    'files' => fn () => $dbForProject->count('bucket_' . $bucket->getSequence()),
                    'storage' => fn () => $dbForProject->sum('bucket_' . $bucket->getSequence(), 'sizeActual'),
                ])->await();
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_bucket_{$bucket->getSequence()}"]);
                return;
            }

            $files = $bucketResults['files'];
            $storage = $bucketResults['storage'];

            $metric = str_replace('{bucketInternalId}', $bucket->getSequence(), METRIC_BUCKET_ID_FILES);
            $this->createStatsDocuments($region, $metric, $files);

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
            try {
                $imageTransformations = $dbForProject->count('bucket_' . $bucket->getSequence(), [
                    Query::greaterThanEqual('transformedAt', $last30Days),
                ]);
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "count_for_image_transformations_bucket_{$bucket->getSequence()}"]);
                return;
            }

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
            $collectionName = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();

            $collectionResults = Promise::map([
                'documents' => fn () => $dbForProject->count($collectionName),
                'storage' => fn () => $dbForProject->getSizeOfCollection($collectionName),
            ])->await();

            $metric = str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getSequence(), $collection->getSequence()], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS);
            $this->createStatsDocuments($region, $metric, $collectionResults['documents']);
            $databaseDocuments += $collectionResults['documents'];

            $metric = str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getSequence(), $collection->getSequence()], METRIC_DATABASE_ID_COLLECTION_ID_STORAGE);
            $this->createStatsDocuments($region, $metric, $collectionResults['storage']);
            $databaseStorage += $collectionResults['storage'];
        });

        $metric = str_replace(['{databaseInternalId}'], [$database->getSequence()], METRIC_DATABASE_ID_DOCUMENTS);
        $this->createStatsDocuments($region, $metric, $databaseDocuments);

        $metric = str_replace(['{databaseInternalId}'], [$database->getSequence()], METRIC_DATABASE_ID_STORAGE);
        $this->createStatsDocuments($region, $metric, $databaseStorage);

        return [$databaseDocuments, $databaseStorage];
    }

    protected function countForSitesAndFunctions(Database $dbForProject, string $region): void
    {
        $results = Promise::map([
            'deploymentsStorage' => fn () => $dbForProject->sum('deployments', 'sourceSize'),
            'buildsStorage' => fn () => $dbForProject->sum('deployments', 'buildSize'),
            'deployments' => fn () => $dbForProject->count('deployments'),
        ])->await();

        $this->createStatsDocuments($region, METRIC_DEPLOYMENTS_STORAGE, $results['deploymentsStorage']);
        $this->createStatsDocuments($region, METRIC_BUILDS_STORAGE, $results['buildsStorage']);
        $this->createStatsDocuments($region, METRIC_DEPLOYMENTS, $results['deployments']);
        $this->createStatsDocuments($region, METRIC_BUILDS, $results['deployments']);

        $this->countForFunctions($dbForProject, $region);
        $this->countForSites($dbForProject, $region);
    }

    protected function countForFunctions(Database $dbForProject, string $region)
    {
        $results = Promise::map([
            'deploymentsStorage' => fn () => $dbForProject->sum('deployments', 'sourceSize', [
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS])
            ]),
            'buildsStorage' => fn () => $dbForProject->sum('deployments', 'buildSize', [
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS])
            ]),
            'deployments' => fn () => $dbForProject->count('deployments', [
                Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS])
            ]),
        ])->await();

        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_FUNCTIONS, METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE), $results['deploymentsStorage']);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_FUNCTIONS, METRIC_RESOURCE_TYPE_BUILDS_STORAGE), $results['buildsStorage']);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_FUNCTIONS, METRIC_RESOURCE_TYPE_DEPLOYMENTS), $results['deployments']);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_FUNCTIONS, METRIC_RESOURCE_TYPE_BUILDS), $results['deployments']);

        // Per-function deployment stats via groupBy (replaces N+1 loop)
        $groupedDeployments = $dbForProject->find('deployments', [
            Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
            Query::groupBy(['resourceInternalId']),
            Query::count('*', 'deploymentsCount'),
            Query::sum('sourceSize', 'deploymentsStorage'),
            Query::sum('buildSize', 'buildsStorage'),
        ]);

        foreach ($groupedDeployments as $row) {
            $resourceInternalId = $row->getAttribute('resourceInternalId');
            $deploymentsCount = $row->getAttribute('deploymentsCount', 0);
            $deploymentsStorage = $row->getAttribute('deploymentsStorage', 0);
            $buildsStorage = $row->getAttribute('buildsStorage', 0);

            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE), $deploymentsStorage);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS), $deploymentsCount);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_BUILDS), $deploymentsCount);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $buildsStorage);
        }

        // Count runtimes via groupBy (replaces N+1 loop)
        $runtimeGroups = $dbForProject->find('functions', [
            Query::select(['runtime']),
            Query::groupBy(['runtime']),
            Query::count('*', 'count'),
            Query::isNotNull('runtime'),
        ]);

        foreach ($runtimeGroups as $row) {
            $runtime = $row->getAttribute('runtime');
            if (!empty($runtime)) {
                $this->createStatsDocuments($region, str_replace('{runtime}', $runtime, METRIC_FUNCTIONS_RUNTIME), $row->getAttribute('count', 0));
            }
        }
    }

    protected function countForSites(Database $dbForProject, string $region)
    {
        $results = Promise::map([
            'deploymentsStorage' => fn () => $dbForProject->sum('deployments', 'sourceSize', [
                Query::equal('resourceType', [RESOURCE_TYPE_SITES])
            ]),
            'buildsStorage' => fn () => $dbForProject->sum('deployments', 'buildSize', [
                Query::equal('resourceType', [RESOURCE_TYPE_SITES])
            ]),
            'deployments' => fn () => $dbForProject->count('deployments', [
                Query::equal('resourceType', [RESOURCE_TYPE_SITES])
            ]),
        ])->await();

        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE), $results['deploymentsStorage']);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_BUILDS_STORAGE), $results['buildsStorage']);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_DEPLOYMENTS), $results['deployments']);
        $this->createStatsDocuments($region, str_replace("{resourceType}", RESOURCE_TYPE_SITES, METRIC_RESOURCE_TYPE_BUILDS), $results['deployments']);

        // Per-site deployment stats via groupBy (replaces N+1 loop)
        $groupedDeployments = $dbForProject->find('deployments', [
            Query::equal('resourceType', [RESOURCE_TYPE_SITES]),
            Query::groupBy(['resourceInternalId']),
            Query::count('*', 'deploymentsCount'),
            Query::sum('sourceSize', 'deploymentsStorage'),
            Query::sum('buildSize', 'buildsStorage'),
        ]);

        foreach ($groupedDeployments as $row) {
            $resourceInternalId = $row->getAttribute('resourceInternalId');
            $deploymentsCount = $row->getAttribute('deploymentsCount', 0);
            $deploymentsStorage = $row->getAttribute('deploymentsStorage', 0);
            $buildsStorage = $row->getAttribute('buildsStorage', 0);

            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_SITES, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE), $deploymentsStorage);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_SITES, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS), $deploymentsCount);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_SITES, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_BUILDS), $deploymentsCount);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_SITES, $resourceInternalId], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $buildsStorage);
        }

        // Count frameworks via groupBy (replaces N+1 loop)
        $frameworkGroups = $dbForProject->find('sites', [
            Query::select(['framework']),
            Query::groupBy(['framework']),
            Query::count('*', 'count'),
            Query::isNotNull('framework'),
        ]);

        foreach ($frameworkGroups as $row) {
            $framework = $row->getAttribute('framework');
            if (!empty($framework)) {
                $this->createStatsDocuments($region, str_replace('{framework}', $framework, METRIC_SITES_FRAMEWORK), $row->getAttribute('count', 0));
            }
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
        Span::add('documents.count', count($this->documents));

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

        $dbForLogs->upsertDocuments(
            'stats',
            $this->documents,
        );
    }
}
