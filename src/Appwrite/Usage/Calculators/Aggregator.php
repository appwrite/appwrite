<?php

namespace Appwrite\Usage\Calculators;

use DateTime;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Query;

class Aggregator extends Database
{
    protected function aggregateDatabaseMetrics(Document $project): void
    {
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
            $this->aggregateDailyMetric($project, $metric);
            $this->aggregateMonthlyMetric($project, $metric);
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

        $this->foreachDocument($project, 'databases', [], function (Document $database) use ($databasesDatabaseMetrics, $project) {
            $databaseId = $database->getId();
            foreach ($databasesDatabaseMetrics as $metric) {
                $metric = str_replace('databaseId', $databaseId, $metric);
                $this->aggregateDailyMetric($project, $metric);
                $this->aggregateMonthlyMetric($project, $metric);
            }

            $databasesCollectionMetrics = [
                'documents.' . $databaseId . '/collectionId.requests.create',
                'documents.' . $databaseId . '/collectionId.requests.read',
                'documents.' . $databaseId . '/collectionId.requests.update',
                'documents.' . $databaseId . '/collectionId.requests.delete',
            ];

            $this->foreachDocument($project, 'database_' . $database->getInternalId(), [], function (Document $collection) use ($databasesCollectionMetrics, $project) {
                $collectionId = $collection->getId();
                foreach ($databasesCollectionMetrics as $metric) {
                    $metric = str_replace('collectionId', $collectionId, $metric);
                    $this->aggregateDailyMetric($project, $metric);
                    $this->aggregateMonthlyMetric($project, $metric);
                }
            });
        });
    }

    protected function aggregateStorageMetrics(Document $project): void
    {
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
            $this->aggregateDailyMetric($project, $metric);
            $this->aggregateMonthlyMetric($project, $metric);
        }

        $storageBucketMetrics = [
            'files.bucketId.requests.create',
            'files.bucketId.requests.read',
            'files.bucketId.requests.update',
            'files.bucketId.requests.delete',
        ];

        $this->foreachDocument($project, 'buckets', [], function (Document $bucket) use ($storageBucketMetrics, $project) {
            $bucketId = $bucket->getId();
            foreach ($storageBucketMetrics as $metric) {
                $metric = str_replace('bucketId', $bucketId, $metric);
                $this->aggregateDailyMetric($project, $metric);
                $this->aggregateMonthlyMetric($project, $metric);
            }
        });
    }

    protected function aggregateFunctionMetrics(Document $project): void
    {
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
            $this->aggregateDailyMetric($project, $metric);
            $this->aggregateMonthlyMetric($project, $metric);
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

        $this->foreachDocument($project, 'functions', [], function (Document $function) use ($functionMetrics, $project) {
            $functionId = $function->getId();
            foreach ($functionMetrics as $metric) {
                $metric = str_replace('functionId', $functionId, $metric);
                $this->aggregateDailyMetric($project, $metric);
                $this->aggregateMonthlyMetric($project, $metric);
            }
        });
    }

    protected function aggregateUsersMetrics(Document $project): void
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
            $this->aggregateDailyMetric($project, $metric);
            $this->aggregateMonthlyMetric($project, $metric);
        }
    }

    protected function aggregateGeneralMetrics(Document $project): void
    {
        $this->aggregateDailyMetric($project, 'project.$all.network.requests');
        $this->aggregateDailyMetric($project, 'project.$all.network.bandwidth');
        $this->aggregateDailyMetric($project, 'project.$all.network.inbound');
        $this->aggregateDailyMetric($project, 'project.$all.network.outbound');
        $this->aggregateMonthlyMetric($project, 'project.$all.network.requests');
        $this->aggregateMonthlyMetric($project, 'project.$all.network.bandwidth');
        $this->aggregateMonthlyMetric($project, 'project.$all.network.inbound');
        $this->aggregateMonthlyMetric($project, 'project.$all.network.outbound');
    }

    protected function aggregateDailyMetric(Document $project, string $metric): void
    {
        $beginOfDay = DateTime::createFromFormat('Y-m-d\TH:i:s.v', \date('Y-m-d\T00:00:00.000'))->format(DateTime::RFC3339);
        $endOfDay = DateTime::createFromFormat('Y-m-d\TH:i:s.v', \date('Y-m-d\T23:59:59.999'))->format(DateTime::RFC3339);

        $database = call_user_func($this->getProjectDB, $project);
        $value = (int) $database->sum('stats', 'value', [
            Query::equal('metric', [$metric]),
            Query::equal('period', ['30m']),
            Query::greaterThanEqual('time', $beginOfDay),
            Query::lessThanEqual('time', $endOfDay),
        ]);
        $this->createOrUpdateMetric($database, $project->getId(), $metric, '1d', $beginOfDay, $value);
    }

    protected function aggregateMonthlyMetric(Document $project, string $metric): void
    {
        $beginOfMonth = DateTime::createFromFormat('Y-m-d\TH:i:s.v', \date('Y-m-01\T00:00:00.000'))->format(DateTime::RFC3339);
        $endOfMonth = DateTime::createFromFormat('Y-m-d\TH:i:s.v', \date('Y-m-t\T23:59:59.999'))->format(DateTime::RFC3339);
        $database = call_user_func($this->getProjectDB, $project);
            $value = (int) $database->sum('stats', 'value', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['1d']),
                Query::greaterThanEqual('time', $beginOfMonth),
                Query::lessThanEqual('time', $endOfMonth),
            ]);
            $this->createOrUpdateMetric($database, $project->getId(), $metric, '1mo', $beginOfMonth, $value);
    }

    /**
     * Collect Stats
     * Collect all database related stats
     *
     * @return void
     */
    public function collect(): void
    {
        $this->foreachDocument(new Document(['$id' => 'console']), 'projects', [], function (Document $project) {
            $this->aggregateGeneralMetrics($project);
            $this->aggregateFunctionMetrics($project);
            $this->aggregateDatabaseMetrics($project);
            $this->aggregateStorageMetrics($project);
            $this->aggregateUsersMetrics($project);
        });
    }
}
