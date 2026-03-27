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
            ->inject('getDatabasesDB')
            ->inject('dbForPlatform')
            ->inject('logError')
            ->callback($this->action(...));
    }

    /**
     * @param Message $message
     * @param Document $project
     * @param callable $getProjectDB
     * @param callable $getLogsDB
     * @param callable $getDatabasesDB
     * @return void
     * @throws \Utopia\Database\Exception
     * @throws Exception
     */
    public function action(Message $message, Document $project, callable $getProjectDB, callable $getLogsDB, callable $getDatabasesDB, Database $dbForPlatform, callable $logError): void
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

        $this->countForProject($dbForPlatform, $getLogsDB, $getProjectDB, $getDatabasesDB, $project);
    }

    protected function countForProject(Database $dbForPlatform, callable $getLogsDB, callable $getProjectDB, callable $getDatabasesDB, Document $project): void
    {
        /** @var \Utopia\Database\Database $dbForLogs */
        $dbForLogs = call_user_func($getLogsDB, $project);
        /** @var \Utopia\Database\Database $dbForProject */
        $dbForProject = call_user_func($getProjectDB, $project);

        try {

            $region = $project->getAttribute('region');

            $last30Days = (new \DateTime())->sub(\DateInterval::createFromDateString('30 days'))->format('Y-m-d 00:00:00');
            $last24Hours = (new \DateTime())->sub(\DateInterval::createFromDateString('24 hours'))->format('Y-m-d H:i:00');
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
                'databases' => fn () => $dbForProject->count('databases', [
                    Query::equal('type', [DATABASE_TYPE_LEGACY, DATABASE_TYPE_TABLESDB])
                ]),
                'documentsdb' => fn () => $dbForProject->count('databases', [
                    Query::equal('type', [DATABASE_TYPE_DOCUMENTSDB])
                ]),
                'vectorsdb' => fn () => $dbForProject->count('databases', [
                    Query::equal('type', [DATABASE_TYPE_VECTORSDB])
                ]),
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
                METRIC_DATABASES_DOCUMENTSDB => $results['documentsdb'],
                METRIC_DATABASES_VECTORSDB => $results['vectorsdb'],
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
                $dbForProject->skipFilters(fn () => $this->countForDatabase($dbForProject, $getDatabasesDB, $region), ['subQueryAttributes', 'subQueryIndexes']);
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
                call_user_func_array($this->logError, [$th, "StatsResources", "bucket_{$bucket->getSequence()}"]);
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

    protected function countForDatabase(Database $dbForProject, callable $getDatabasesDB, string $region)
    {
        $totalCollections = 0;
        $totalDocuments = 0;
        $totalDatabaseStorage = 0;

        // documentsdb
        $totalCollectionsDocumentsdb = 0;
        $totalDocumentsDocumentsdb = 0;
        $totalDatabaseStorageDocumentsdb = 0;

        // vectorsdb
        $totalCollectionsVectordb = 0;
        $totalDocumentsVectordb = 0;
        $totalDatabaseStorageVectordb = 0;


        $this->foreachDocument($dbForProject, 'databases', [], function ($database) use ($dbForProject, $getDatabasesDB, $region, &$totalCollections, &$totalDocuments, &$totalDatabaseStorage, &$totalCollectionsDocumentsdb, &$totalDocumentsDocumentsdb, &$totalDatabaseStorageDocumentsdb, &$totalCollectionsVectordb, &$totalDocumentsVectordb, &$totalDatabaseStorageVectordb) {
            $dbForDatabases = $getDatabasesDB($database);
            $collections = $dbForProject->count('database_' . $database->getSequence());

            $databaseType = $database->getAttribute('type');
            $collectionsMetric = METRIC_DATABASE_ID_COLLECTIONS;
            if (!empty($databaseType) && $databaseType !== DATABASE_TYPE_LEGACY && $databaseType !== DATABASE_TYPE_TABLESDB) {
                $collectionsMetric = $databaseType . '.' . $collectionsMetric;
            }
            $metric = str_replace('{databaseInternalId}', $database->getSequence(), $collectionsMetric);
            $this->createStatsDocuments($region, $metric, $collections);

            [$documents, $storage] = $this->countForCollections($dbForProject, $dbForDatabases, $database, $region);

            switch ($database->getAttribute('type')) {
                case DATABASE_TYPE_DOCUMENTSDB:
                    $totalDatabaseStorageDocumentsdb += $storage;
                    $totalDocumentsDocumentsdb += $documents;
                    $totalCollectionsDocumentsdb += $collections;
                    break;
                case DATABASE_TYPE_VECTORSDB:
                    $totalDatabaseStorageVectordb += $storage;
                    $totalDocumentsVectordb += $documents;
                    $totalCollectionsVectordb += $collections;
                    break;
                default:
                    $totalDatabaseStorage += $storage;
                    $totalDocuments += $documents;
                    $totalCollections += $collections;
            }
        });

        $this->createStatsDocuments($region, METRIC_COLLECTIONS, $totalCollections);
        $this->createStatsDocuments($region, METRIC_DOCUMENTS, $totalDocuments);
        $this->createStatsDocuments($region, METRIC_DATABASES_STORAGE, $totalDatabaseStorage);

        $this->createStatsDocuments($region, METRIC_COLLECTIONS_DOCUMENTSDB, $totalCollectionsDocumentsdb);
        $this->createStatsDocuments($region, METRIC_DOCUMENTS_DOCUMENTSDB, $totalDocumentsDocumentsdb);
        $this->createStatsDocuments($region, METRIC_DATABASES_STORAGE_DOCUMENTSDB, $totalDatabaseStorageDocumentsdb);

        $this->createStatsDocuments($region, METRIC_COLLECTIONS_VECTORSDB, $totalCollectionsVectordb);
        $this->createStatsDocuments($region, METRIC_DOCUMENTS_VECTORSDB, $totalDocumentsVectordb);
        $this->createStatsDocuments($region, METRIC_DATABASES_STORAGE_VECTORSDB, $totalDatabaseStorageVectordb);
    }
    protected function countForCollections(Database $dbForProject, Database $dbForDatabases, Document $database, string $region): array
    {
        $databaseDocuments = 0;
        $databaseStorage = 0;
        $databaseType = $database->getAttribute('type');
        $databaseIdCollectionIdDocumentsMetric = METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS;
        $databaseIdCollectionIdStorageMetric = METRIC_DATABASE_ID_COLLECTION_ID_STORAGE;
        $databaseIdDocumentsMetric = METRIC_DATABASE_ID_DOCUMENTS;
        $databaseIdStorageMetric = METRIC_DATABASE_ID_STORAGE;

        if ($databaseType !== DATABASE_TYPE_LEGACY && $databaseType !== DATABASE_TYPE_TABLESDB) {
            $databaseIdCollectionIdDocumentsMetric = $databaseType . '.' . $databaseIdCollectionIdDocumentsMetric;
            $databaseIdCollectionIdStorageMetric = $databaseType . '.' . $databaseIdCollectionIdStorageMetric;
            $databaseIdDocumentsMetric = $databaseType . '.' . $databaseIdDocumentsMetric;
            $databaseIdStorageMetric = $databaseType . '.' . $databaseIdStorageMetric;
        }

        $this->foreachDocument($dbForProject, 'database_' . $database->getSequence(), [], function ($collection) use ($dbForDatabases, $database, $region, &$databaseStorage, &$databaseDocuments, $databaseIdCollectionIdDocumentsMetric, $databaseIdCollectionIdStorageMetric) {
            $collectionName = 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence();

            try {
                $collectionResults = Promise::map([
                    'documents' => fn () => $dbForDatabases->count($collectionName),
                    'storage' => fn () => $dbForDatabases->getSizeOfCollection($collectionName),
                ])->await();
            } catch (Throwable $th) {
                call_user_func_array($this->logError, [$th, "StatsResources", "collection_{$database->getSequence()}_{$collection->getSequence()}"]);
                return;
            }

            $metric = str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getSequence(), $collection->getSequence()], $databaseIdCollectionIdDocumentsMetric);
            $this->createStatsDocuments($region, $metric, $collectionResults['documents']);
            $databaseDocuments += $collectionResults['documents'];

            $metric = str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getSequence(), $collection->getSequence()], $databaseIdCollectionIdStorageMetric);
            $this->createStatsDocuments($region, $metric, $collectionResults['storage']);
            $databaseStorage += $collectionResults['storage'];
        });

        $metric = str_replace(['{databaseInternalId}'], [$database->getSequence()], $databaseIdDocumentsMetric);
        $this->createStatsDocuments($region, $metric, $databaseDocuments);

        $metric = str_replace(['{databaseInternalId}'], [$database->getSequence()], $databaseIdStorageMetric);
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

        $runtimes = [];
        $this->foreachDocument($dbForProject, 'functions', [], function (Document $function) use ($dbForProject, $region, &$runtimes) {
            $functionResults = Promise::map([
                'deploymentsStorage' => fn () => $dbForProject->sum('deployments', 'sourceSize', [
                    Query::equal('resourceInternalId', [$function->getSequence()]),
                    Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
                ]),
                'deployments' => fn () => $dbForProject->count('deployments', [
                    Query::equal('resourceInternalId', [$function->getSequence()]),
                    Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
                ]),
                'buildsStorage' => fn () => $dbForProject->sum('deployments', 'buildSize', [
                    Query::equal('resourceInternalId', [$function->getSequence()]),
                    Query::equal('resourceType', [RESOURCE_TYPE_FUNCTIONS]),
                ]),
            ])->await();

            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE), $functionResults['deploymentsStorage']);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS), $functionResults['deployments']);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS), $functionResults['deployments']);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $functionResults['buildsStorage']);

            $runtime = $function->getAttribute('runtime');
            if (!empty($runtime)) {
                $runtimes[$runtime] = ($runtimes[$runtime] ?? 0) + 1;
            }
        });

        foreach ($runtimes as $runtime => $count) {
            $this->createStatsDocuments($region, str_replace('{runtime}', $runtime, METRIC_FUNCTIONS_RUNTIME), $count);
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

        $frameworks = [];
        $this->foreachDocument($dbForProject, 'sites', [], function (Document $site) use ($dbForProject, $region, &$frameworks) {
            $siteResults = Promise::map([
                'deploymentsStorage' => fn () => $dbForProject->sum('deployments', 'sourceSize', [
                    Query::equal('resourceInternalId', [$site->getSequence()]),
                    Query::equal('resourceType', [RESOURCE_TYPE_SITES]),
                ]),
                'deployments' => fn () => $dbForProject->count('deployments', [
                    Query::equal('resourceInternalId', [$site->getSequence()]),
                    Query::equal('resourceType', [RESOURCE_TYPE_SITES]),
                ]),
                'buildsStorage' => fn () => $dbForProject->sum('deployments', 'buildSize', [
                    Query::equal('resourceInternalId', [$site->getSequence()]),
                    Query::equal('resourceType', [RESOURCE_TYPE_SITES]),
                ]),
            ])->await();

            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_SITES, $site->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE), $siteResults['deploymentsStorage']);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_SITES, $site->getSequence()], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS), $siteResults['deployments']);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_SITES, $site->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS), $siteResults['deployments']);
            $this->createStatsDocuments($region, str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_SITES, $site->getSequence()], METRIC_RESOURCE_TYPE_ID_BUILDS_STORAGE), $siteResults['buildsStorage']);

            $framework = $site->getAttribute('framework');
            if (!empty($framework)) {
                $frameworks[$framework] = ($frameworks[$framework] ?? 0) + 1;
            }
        });

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
