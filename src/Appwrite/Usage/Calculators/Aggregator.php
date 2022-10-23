<?php

namespace Appwrite\Usage\Calculators;

use DateTime;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Query;

class Aggregator extends Database
{
    protected function aggregateDatabaseMetrics(string $projectId): void
    {
        $this->database->setNamespace('_' . $projectId);

        $databasesGeneralMetrics = [
            'databases.$all.requests.create',
            'databases.$all.requests.read',
            'databases.$all.requests.update',
            'databases.$all.requests.delete',
            'collections.$all.requests.create',
            'collections.$all.requests.read',
            'collections.$all.requests.update',
            'collections.$all.requests.delete',
            'documents.$all.requests.create',
            'documents.$all.requests.read',
            'documents.$all.requests.update',
            'documents.$all.requests.delete'
        ];

        foreach ($databasesGeneralMetrics as $metric) {
            $this->aggregateDailyMetric($projectId, $metric);
            $this->aggregateMonthlyMetric($projectId, $metric);
        }

        $databasesDatabaseMetrics = [
            'collections.databaseId.requests.create',
            'collections.databaseId.requests.read',
            'collections.databaseId.requests.update',
            'collections.databaseId.requests.delete',
            'documents.databaseId.requests.create',
            'documents.databaseId.requests.read',
            'documents.databaseId.requests.update',
            'documents.databaseId.requests.delete',
        ];

        $this->foreachDocument($projectId, 'databases', [], function (Document $database) use ($databasesDatabaseMetrics, $projectId) {
            $databaseId = $database->getId();
            foreach ($databasesDatabaseMetrics as $metric) {
                $metric = str_replace('databaseId', $databaseId, $metric);
                $this->aggregateDailyMetric($projectId, $metric);
                $this->aggregateMonthlyMetric($projectId, $metric);
            }

            $databasesCollectionMetrics = [
                'documents.' . $databaseId . '/collectionId.requests.create',
                'documents.' . $databaseId . '/collectionId.requests.read',
                'documents.' . $databaseId . '/collectionId.requests.update',
                'documents.' . $databaseId . '/collectionId.requests.delete',
            ];

            $this->foreachDocument($projectId, 'database_' . $database->getInternalId(), [], function (Document $collection) use ($databasesCollectionMetrics, $projectId) {
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
            'buckets.$all.requests.create',
            'buckets.$all.requests.read',
            'buckets.$all.requests.update',
            'buckets.$all.requests.delete',
            'files.$all.requests.create',
            'files.$all.requests.read',
            'files.$all.requests.update',
            'files.$all.requests.delete',
        ];

        foreach ($storageGeneralMetrics as $metric) {
            $this->aggregateDailyMetric($projectId, $metric);
            $this->aggregateMonthlyMetric($projectId, $metric);
        }

        $storageBucketMetrics = [
            'files.bucketId.requests.create',
            'files.bucketId.requests.read',
            'files.bucketId.requests.update',
            'files.bucketId.requests.delete',
        ];

        $this->foreachDocument($projectId, 'buckets', [], function (Document $bucket) use ($storageBucketMetrics, $projectId) {
            $bucketId = $bucket->getId();
            foreach ($storageBucketMetrics as $metric) {
                $metric = str_replace('bucketId', $bucketId, $metric);
                $this->aggregateDailyMetric($projectId, $metric);
                $this->aggregateMonthlyMetric($projectId, $metric);
            }
        });
    }

    protected function aggregateFunctionMetrics(string $projectId): void
    {
        $this->database->setNamespace('_' . $projectId);

        $functionsGeneralMetrics = [
            'project.$all.compute.total',
            'project.$all.compute.time',
            'executions.$all.compute.total',
            'executions.$all.compute.success',
            'executions.$all.compute.failure',
            'executions.$all.compute.time',
            'builds.$all.compute.total',
            'builds.$all.compute.success',
            'builds.$all.compute.failure',
            'builds.$all.compute.time',
        ];

        foreach ($functionsGeneralMetrics as $metric) {
            $this->aggregateDailyMetric($projectId, $metric);
            $this->aggregateMonthlyMetric($projectId, $metric);
        }

        $functionMetrics = [
            'executions.functionId.compute.total',
            'executions.functionId.compute.success',
            'executions.functionId.compute.failure',
            'executions.functionId.compute.time',
            'builds.functionId.compute.total',
            'builds.functionId.compute.success',
            'builds.functionId.compute.failure',
            'builds.functionId.compute.time',
        ];

        $this->foreachDocument($projectId, 'functions', [], function (Document $function) use ($functionMetrics, $projectId) {
            $functionId = $function->getId();
            foreach ($functionMetrics as $metric) {
                $metric = str_replace('functionId', $functionId, $metric);
                $this->aggregateDailyMetric($projectId, $metric);
                $this->aggregateMonthlyMetric($projectId, $metric);
            }
        });
    }

    protected function aggregateUsersMetrics(string $projectId): void
    {
        $metrics = [
            'users.$all.requests.create',
            'users.$all.requests.read',
            'users.$all.requests.update',
            'users.$all.requests.delete',
            'sessions.$all.requests.create',
            'sessions.$all.requests.delete'
        ];

        foreach ($metrics as $metric) {
            $this->aggregateDailyMetric($projectId, $metric);
            $this->aggregateMonthlyMetric($projectId, $metric);
        }
    }

    protected function aggregateGeneralMetrics(string $projectId): void
    {
        $this->aggregateDailyMetric($projectId, 'project.$all.network.requests');
        $this->aggregateDailyMetric($projectId, 'project.$all.network.bandwidth');
        $this->aggregateDailyMetric($projectId, 'project.$all.network.inbound');
        $this->aggregateDailyMetric($projectId, 'project.$all.network.outbound');
        $this->aggregateMonthlyMetric($projectId, 'project.$all.network.requests');
        $this->aggregateMonthlyMetric($projectId, 'project.$all.network.bandwidth');
        $this->aggregateMonthlyMetric($projectId, 'project.$all.network.inbound');
        $this->aggregateMonthlyMetric($projectId, 'project.$all.network.outbound');
    }

    protected function aggregateDailyMetric(string $projectId, string $metric): void
    {
        $beginOfDay = DateTime::createFromFormat('Y-m-d\TH:i:s.v', \date('Y-m-d\T00:00:00.000'))->format(DateTime::RFC3339);
        $endOfDay = DateTime::createFromFormat('Y-m-d\TH:i:s.v', \date('Y-m-d\T23:59:59.999'))->format(DateTime::RFC3339);

        $this->database->setNamespace('_' . $projectId);
        $value = (int) $this->database->sum('stats', 'value', [
            Query::equal('metric', [$metric]),
            Query::equal('period', ['1h']),
            Query::greaterThanEqual('time', $beginOfDay),
            Query::lessThanEqual('time', $endOfDay),
        ]);
        $this->createOrUpdateMetric($projectId, $metric, '1d', $beginOfDay, $value);
    }

    protected function aggregateMonthlyMetric(string $projectId, string $metric): void
    {
        $beginOfMonth = DateTime::createFromFormat('Y-m-d\TH:i:s.v', \date('Y-m-01\T00:00:00.000'))->format(DateTime::RFC3339);
        $endOfMonth = DateTime::createFromFormat('Y-m-d\TH:i:s.v', \date('Y-m-t\T23:59:59.999'))->format(DateTime::RFC3339);
        $this->database->setNamespace('_' . $projectId);
            $value = (int) $this->database->sum('stats', 'value', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['1d']),
                Query::greaterThanEqual('time', $beginOfMonth),
                Query::lessThanEqual('time', $endOfMonth),
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
