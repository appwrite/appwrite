<?php

namespace Appwrite\Usage\Calculators;

use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Query;

class Aggregator extends Database {
    
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

        foreach ($databasesGeneralMetrics as $metric) {
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

        $this->foreachDocument($projectId, 'databases', [], function (Document $database) use ($databasesDatabaseMetrics, $projectId) {
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

            $this->foreachDocument($projectId, 'database_' . $database->getInternalId(), [], function (Document $collection) use ($databasesCollectionMetrics, $projectId) {
                $collectionId = $collection->getId();
                foreach ($databasesCollectionMetrics as $metric) {
                    $metric = str_replace('collectionId', $collectionId, $metric);
                    $this->aggregateDailyMetric($projectId, $metric);
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

        foreach ($storageGeneralMetrics as $metric) {
            $this->aggregateDailyMetric($projectId, $metric);
        }

        $storageBucketMetrics = [
            'storage.buckets.bucketId.files.create',
            'storage.buckets.bucketId.files.read',
            'storage.buckets.bucketId.files.update',
            'storage.buckets.bucketId.files.delete',
        ];

        $this->foreachDocument($projectId, 'buckets', [], function (Document $bucket) use ($storageBucketMetrics, $projectId) {
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

        $this->aggregateDailyMetric($projectId, 'functions.executions');
        $this->aggregateDailyMetric($projectId, 'functions.builds');
        $this->aggregateDailyMetric($projectId, 'functions.failures');

        $functionMetrics = [
            'functions.functionId.executions',
            'functions.functionId.builds',
            'functions.functionId.compute',
            'function.functionId.executions.failure',
            'function.functionId.builds.failure',
        ];

        $this->foreachDocument($projectId, 'functions', [], function (Document $function) use ($functionMetrics, $projectId) {
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

        foreach ($metrics as $metric) {
            $this->aggregateDailyMetric($projectId, $metric);
        }
    }

    protected function aggregateGeneralMetrics(string $projectId): void
    {
        $this->aggregateDailyMetric($projectId, 'requests');
        $this->aggregateDailyMetric($projectId, 'network');
        $this->aggregateDailyMetric($projectId, 'inbound');
        $this->aggregateDailyMetric($projectId, 'outbound');

        //Required for billing
        $this->aggregateMonthlyMetric($projectId, 'inbound');
        $this->aggregateMonthlyMetric($projectId, 'outbound');
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