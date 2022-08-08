<?php

namespace Appwrite\Stats;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

class UsageDB extends Usage
{
    protected array $periods = [
        [
            'key' => '30m',
            'multiplier' => 1800,
        ],
        [
            'key' => '1d',
            'multiplier' => 86400,
        ],
    ];

    public function __construct(Database $database, callable $errorHandler = null)
    {
        $this->database = $database;
        $this->errorHandler = $errorHandler;
    }
    /**
     * Create Per Period Metric
     * Create given metric for each defined period
     *
     * @param string $projectId
     * @param string $metric
     * @param int $value
     *
     * @return void
     */
    private function createPerPeriodMetric(string $projectId, string $metric, int $value): void
    {
        foreach ($this->periods as $options) {
            $period = $options['key'];
            $time = (int) (floor(time() / $options['multiplier']) * $options['multiplier']);
            $this->createOrUpdateMetric($projectId, $metric, $period, $time, $value);
        }
    }

    /**
     * Create or Update Mertic
     * Create or update each metric in the stats collection for the given project
     *
     * @param string $projectId
     * @param string $metric
     * @param int $value
     *
     * @return void
     */
    private function createOrUpdateMetric(string $projectId, string $metric, string $period, int $time, int $value): void
    {
            $id = \md5("{$time}_{$period}_{$metric}");
            $this->database->setNamespace('_' . $projectId);

            try {
                $document = $this->database->getDocument('stats', $id);
                if ($document->isEmpty()) {
                    $this->database->createDocument('stats', new Document([
                        '$id' => $id,
                        'period' => $period,
                        'time' => $time,
                        'metric' => $metric,
                        'value' => $value,
                        'type' => 1,
                    ]));
                } else {
                    $this->database->updateDocument(
                        'stats',
                        $document->getId(),
                        $document->setAttribute('value', $value)
                    );
                }
            } catch (\Exception$e) { // if projects are deleted this might fail
                if (is_callable($this->errorHandler)) {
                    call_user_func($this->errorHandler, $e, "sync_project_{$projectId}_metric_{$metric}");
                } else {
                    throw $e;
                }
            }
    }

    /**
     * Foreach Document
     * Call provided callback for each document in the collection
     *
     * @param string $projectId
     * @param string $collection
     * @param array $queries
     * @param callable $callback
     *
     * @return void
     */
    private function foreachDocument(string $projectId, string $collection, array $queries, callable $callback): void
    {
        $limit = 50;
        $results = [];
        $sum = $limit;
        $latestDocument = null;
        $this->database->setNamespace('_' . $projectId);

        while ($sum === $limit) {
            try {
                $results = $this->database->find($collection, $queries, $limit, cursor:$latestDocument);
            } catch (\Exception $e) {
                if (is_callable($this->errorHandler)) {
                    call_user_func($this->errorHandler, $e, "fetch_documents_project_{$projectId}_collection_{$collection}");
                    return;
                } else {
                    throw $e;
                }
            }
            if (empty($results)) {
                return;
            }

            $sum = count($results);

            foreach ($results as $document) {
                if (is_callable($callback)) {
                    $callback($document);
                }
            }
            $latestDocument = $results[array_key_last($results)];
        }
    }

    /**
     * Sum
     * Calculate sum of a attribute of documents in collection
     *
     * @param string $projectId
     * @param string $collection
     * @param string $attribute
     * @param string $metric
     *
     * @return int
     */
    private function sum(string $projectId, string $collection, string $attribute, string $metric, array $queries = []): int
    {
        $this->database->setNamespace('_' . $projectId);

        try {
            $sum = (int) $this->database->sum($collection, $attribute, $queries);
            $this->createPerPeriodMetric($projectId, $metric, $sum);
            return $sum;
        } catch (\Exception $e) {
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e, "fetch_sum_project_{$projectId}_collection_{$collection}");
            } else {
                throw $e;
            }
        }
    }

    /**
     * Count
     * Count number of documents in collection
     *
     * @param string $projectId
     * @param string $collection
     * @param string $metric
     *
     * @return int
     */
    private function count(string $projectId, string $collection, string $metric): int
    {
        $this->database->setNamespace('_' . $projectId);

        try {
            $count = $this->database->count($collection);
            $this->createPerPeriodMetric($projectId, $metric, $count);
            return $count;
        } catch (\Exception $e) {
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e, "fetch_count_project_{$projectId}_collection_{$collection}");
            } else {
                throw $e;
            }
        }
    }

    /**
     * Deployments Total
     * Total sum of storage used by deployments
     *
     * @param string $projectId
     *
     * @return int
     */
    private function deploymentsTotal(string $projectId): int
    {
        return $this->sum($projectId, 'deployments', 'size', 'stroage.deployments.total');
    }

    /**
     * Users Stats
     * Metric: users.count
     *
     * @param string $projectId
     *
     * @return void
     */
    private function usersStats(string $projectId): void
    {
        $this->count($projectId, 'users', 'users.count');
    }

    /**
     * Storage Stats
     * Metrics: storage.total, storage.files.total, storage.buckets.{bucketId}.files.total,
     * storage.buckets.count, storage.files.count, storage.buckets.{bucketId}.files.count
     *
     * @param string $projectId
     *
     * @return void
     */
    private function storageStats(string $projectId): void
    {
        $deploymentsTotal = $this->deploymentsTotal($projectId);

        $projectFilesTotal = 0;
        $projectFilesCount = 0;

        $metric = 'storage.buckets.count';
        $this->count($projectId, 'buckets', $metric);

        $this->foreachDocument($projectId, 'buckets', [], function ($bucket) use (&$projectFilesCount, &$projectFilesTotal, $projectId,) {
            $metric = "storage.buckets.{$bucket->getId()}.files.count";

            $count = $this->count($projectId, 'bucket_' . $bucket->getInternalId(), $metric);
            $projectFilesCount += $count;

            $metric = "storage.buckets.{$bucket->getId()}.files.total";
            $sum = $this->sum($projectId, 'bucket_' . $bucket->getInternalId(), 'sizeOriginal', $metric);
            $projectFilesTotal += $sum;
        });

        $this->createPerPeriodMetric($projectId, 'storage.files.count', $projectFilesCount);
        $this->createPerPeriodMetric($projectId, 'storage.files.total', $projectFilesTotal);

        $this->createPerPeriodMetric($projectId, 'storage.total', $projectFilesTotal + $deploymentsTotal);
    }

    /**
     * Database Stats
     * Collect all database stats
     * Metrics: database.collections.count, database.collections.{collectionId}.documents.count,
     * database.documents.count
     *
     * @param string $projectId
     *
     * @return void
     */
    private function databaseStats(string $projectId): void
    {
        $projectDocumentsCount = 0;
        $projectCollectionsCount = 0;

        $this->count($projectId, 'databases', 'databases.count');

        $this->foreachDocument($projectId, 'databases', [], function ($database) use (&$projectDocumentsCount, &$projectCollectionsCount, $projectId) {
            $metric = "databases.{$database->getId()}.collections.count";
            $count = $this->count($projectId, 'database_' . $database->getInternalId(), $metric);
            $projectCollectionsCount += $count;
            $databaseDocumentsCount = 0;

            $this->foreachDocument($projectId, 'database_' . $database->getInternalId(), [], function ($collection) use (&$projectDocumentsCount, &$databaseDocumentsCount, $projectId, $database) {
                $metric = "databases.{$database->getId()}.collections.{$collection->getId()}.documents.count";

                $count = $this->count($projectId, 'database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $metric);
                $projectDocumentsCount += $count;
                $databaseDocumentsCount += $count;
            });

            $this->createPerPeriodMetric($projectId, "databases.{$database->getId()}.documents.count", $databaseDocumentsCount);
        });

        $this->createPerPeriodMetric($projectId, 'databases.collections.count', $projectCollectionsCount);
        $this->createPerPeriodMetric($projectId, 'databases.documents.count', $projectDocumentsCount);
    }

    protected function aggregateDatabaseMetrics(string $projectId): void
    {
        $this->database->setNamespace('_' . $projectId);
        
        $databasesGeneralMetrics = [
            'databases.create',
            'databases.read',
            'databases.update',
            'databases.delete',
            'databases.collections.create',
            'databases.collections.read',
            'databases.collections.update',
            'databases.collections.delete',
            'databases.documents.create',
            'databases.documents.read',
            'databases.documents.update',
            'databases.documents.delete',
        ];

        foreach($databasesGeneralMetrics as $metric) {
            $this->aggregateDailyMetric($projectId, $metric);
        }

        $databasesDatabaseMetrics = [
            'databases.databaseId.collections.create',
            'databases.databaseId.collections.read',
            'databases.databaseId.collections.update',
            'databases.databaseId.collections.delete',
            'databases.databaseId.documents.create',
            'databases.databaseId.documents.read',
            'databases.databaseId.documents.update',
            'databases.databaseId.documents.delete',
        ];

        $this->foreachDocument($projectId, 'databases', [], function(Document $database) use ($databasesDatabaseMetrics, $projectId) {
            $databaseId = $database->getId();
            foreach ($databasesDatabaseMetrics as $metric) {
                $metric = str_replace('databaseId', $databaseId, $metric);
                $this->aggregateDailyMetric($projectId, $metric);
            }

            $databasesCollectionMetrics = [
                'databases.' . $databaseId . '.collections.collectionId.documents.create',
                'databases.' . $databaseId . '.collections.collectionId.documents.read',
                'databases.' . $databaseId . '.collections.collectionId.documents.update',
                'databases.' . $databaseId . '.collections.collectionId.documents.delete',
            ];

            $this->foreachDocument($projectId, 'database_' . $database->getInternalId(), [], function(Document $collection) use ($databasesCollectionMetrics, $projectId) {
                $collectionId = $collection->getId();
                foreach ($databasesCollectionMetrics as $metric) {
                    $metric = str_replace('collectionId', $collectionId, $metric);
                    $this->aggregateDailyMetric($projectId, $metric);
                    $this->aggregateMonthlyMetric($projectId, $metric);
                }
            });
        });
    }

    protected function aggregateStorageMetrics(string $projectId): void
    {
        $this->database->setNamespace('_' . $projectId);
        
        $storageGeneralMetrics = [
            'storage.buckets.create',
            'storage.buckets.read',
            'storage.buckets.update',
            'storage.buckets.delete',
            'storage.files.create',
            'storage.files.read',
            'storage.files.update',
            'storage.files.delete',
        ];

        foreach($storageGeneralMetrics as $metric) {
            $this->aggregateDailyMetric($projectId, $metric);
        }

        $storageBucketMetrics = [
            'storage.buckets.bucketId.files.create',
            'storage.buckets.bucketId.files.read',
            'storage.buckets.bucketId.files.update',
            'storage.buckets.bucketId.files.delete',
        ];

        $this->foreachDocument($projectId, 'buckets', [], function(Document $bucket) use ($storageBucketMetrics, $projectId) {
            $bucketId = $bucket->getId();
            foreach ($storageBucketMetrics as $metric) {
                $metric = str_replace('bucketId', $bucketId, $metric);
                $this->aggregateDailyMetric($projectId, $metric);
            }
        });
    }

    protected function aggregateFunctionMetrics(string $projectId): void
    {
        $this->database->setNamespace('_' . $projectId);

        $this->aggregateDailyMetric($projectId, 'executions');

        $functionMetrics = [
            'functions.functionId.executions',
            'functions.functionId.compute',
            'function.functionId.failure',
        ];

        $this->foreachDocument($projectId, 'functions', [], function(Document $function) use ($functionMetrics, $projectId) {
            $functionId = $function->getId();
            foreach ($functionMetrics as $metric) {
                $metric = str_replace('functionId', $functionId, $metric);
                $this->aggregateDailyMetric($projectId, $metric);
            }
        });
    }

    protected function aggregateUsersMetrics(string $projectId): void
    {
        $metrics = [
            'users.create',
            'users.read',
            'users.update',
            'users.delete',
            'users.sessions.create',
            'users.sessions.delete'
        ];

        foreach($metrics as $metric) {
            $this->aggregateDailyMetric($projectId, $metric);
        }
    }

    protected function aggregateGeneralMetrics(string $projectId): void
    {
        $this->aggregateDailyMetric($projectId, 'requests');
        $this->aggregateDailyMetric($projectId, 'network');
    }

    protected function aggregateDailyMetric(string $projectId, string $metric): void
    {
        $beginOfDay = strtotime("today");
        $endOfDay   = strtotime("tomorrow", $beginOfDay) - 1;
        $this->database->setNamespace('_' . $projectId);
        $value = (int) $this->database->sum('stats', 'value', [
            new Query('metric', Query::TYPE_EQUAL, [$metric]),
            new Query('period', Query::TYPE_EQUAL, ['30m']),
            new Query('time', Query::TYPE_GREATEREQUAL, [$beginOfDay]),
            new Query('time', Query::TYPE_LESSEREQUAL, [$endOfDay]),
        ]);
        $this->createOrUpdateMetric($projectId, $metric, '1d', $beginOfDay, $value);
    }

    protected function aggregateMonthlyMetric(string $projectId, string $metric): void
    {
        $beginOfMonth = strtotime("first day of the month");
        $endOfMonth = strtotime("last day of the month");
        $this->database->setNamespace('_' . $projectId);
            $value = (int) $this->database->sum('stats', 'value', [
                new Query('metric', Query::TYPE_EQUAL, [$metric]),
                new Query('period', Query::TYPE_EQUAL, ['1d']),
                new Query('time', Query::TYPE_GREATEREQUAL, [$beginOfMonth]),
                new Query('time', Query::TYPE_LESSEREQUAL, [$endOfMonth]),
            ]);
            $this->createOrUpdateMetric($projectId, $metric, '1mo', $beginOfMonth, $value);
    }

    /**
     * Collect Stats
     * Collect all database related stats
     *
     * @return void
     */
    public function collect(): void
    {
        $this->foreachDocument('console', 'projects', [], function (Document $project) {
            $projectId = $project->getInternalId();

            $this->usersStats($projectId);
            $this->databaseStats($projectId);
            $this->storageStats($projectId);

            // Aggregate new metrics from already collected usage metrics
            // for lower time period (1day and 1 month metric from 30 minute metrics)
            $this->aggregateGeneralMetrics($projectId);
            $this->aggregateFunctionMetrics($projectId);
            $this->aggregateDatabaseMetrics($projectId);
            $this->aggregateStorageMetrics($projectId);
            $this->aggregateUsersMetrics($projectId);
        });
    }
}
