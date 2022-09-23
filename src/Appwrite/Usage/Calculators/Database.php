<?php

namespace Appwrite\Usage\Calculators;

use Exception;
use Utopia\App;
use Appwrite\Usage\Calculator;
use DateTime;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Query;

class Database extends Calculator
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

    public function __construct(UtopiaDatabase $database, callable $errorHandler = null)
    {
        $this->database = $database;
        $this->errorHandler = $errorHandler;
    }

    /**
     * Create Per Period Metric
     *
     * Create given metric for each defined period
     *
     * @param string $projectId
     * @param string $metric
     * @param int $value
     * @param bool $monthly
     * @return void
     * @throws Authorization
     * @throws Structure
     */
    protected function createPerPeriodMetric(string $projectId, string $metric, int $value, bool $monthly = false): void
    {
        foreach ($this->periods as $options) {
            $period = $options['key'];
            $date = new \DateTime();
            if ($period === '30m') {
                $minutes = $date->format('i') >= '30' ? "30" : "00";
                $time = $date->format('Y-m-d H:' . $minutes . ':00');
            } elseif ($period === '1d') {
                $time = $date->format('Y-m-d 00:00:00');
            } else {
                throw new Exception("Period type not found", 500);
            }
            $this->createOrUpdateMetric($projectId, $metric, $period, $time, $value);
        }

        // Required for billing
        if ($monthly) {
            $time = DateTime::createFromFormat('Y-m-d\TH:i:s.v', \date('Y-m-01\T00:00:00.000'))->format(DateTime::RFC3339);
            $this->createOrUpdateMetric($projectId, $metric, '1mo', $time, $value);
        }
    }

    /**
     * Create or Update Metric
     *
     * Create or update each metric in the stats collection for the given project
     *
     * @param string $projectId
     * @param string $metric
     * @param string $period
     * @param string $time
     * @param int $value
     *
     * @return void
     * @throws Authorization
     * @throws Structure
     */
    protected function createOrUpdateMetric(string $projectId, string $metric, string $period, string $time, int $value): void
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
                    'region' => App::getEnv('_APP_REGION', 'default'),
                    'type' => 2, // these are cumulative metrics
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
     *
     * Call provided callback for each document in the collection
     *
     * @param string $projectId
     * @param string $collection
     * @param array $queries
     * @param callable $callback
     *
     * @return void
     * @throws Exception
     */
    protected function foreachDocument(string $projectId, string $collection, array $queries, callable $callback): void
    {
        $limit = 50;
        $results = [];
        $sum = $limit;
        $latestDocument = null;
        $this->database->setNamespace('_' . $projectId);

        while ($sum === $limit) {
            try {
                $paginationQueries = [Query::limit($limit)];
                if ($latestDocument !== null) {
                    $paginationQueries[] =  Query::cursorAfter($latestDocument);
                }
                $results = $this->database->find($collection, \array_merge($paginationQueries, $queries));
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
     *
     * Calculate sum of an attribute of documents in collection
     *
     * @param string $projectId
     * @param string $collection
     * @param string $attribute
     * @param string|null $metric
     * @param int $multiplier
     * @return int
     * @throws Exception
     */
    private function sum(string $projectId, string $collection, string $attribute, string $metric = null, int $multiplier = 1): int
    {
        $this->database->setNamespace('_' . $projectId);

        try {
            $sum = $this->database->sum($collection, $attribute);
            $sum = (int) ($sum * $multiplier);

            if (!is_null($metric)) {
                $this->createPerPeriodMetric($projectId, $metric, $sum);
            }
            return $sum;
        } catch (Exception $e) {
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e, "fetch_sum_project_{$projectId}_collection_{$collection}");
            } else {
                throw $e;
            }
        }
        return 0;
    }

    /**
     * Count
     *
     * Count number of documents in collection
     *
     * @param string $projectId
     * @param string $collection
     * @param ?string $metric
     *
     * @return int
     * @throws Exception
     */
    private function count(string $projectId, string $collection, ?string $metric = null): int
    {
        $this->database->setNamespace('_' . $projectId);

        try {
            $count = $this->database->count($collection);
            if (!is_null($metric)) {
                $this->createPerPeriodMetric($projectId, (string) $metric, $count);
            }
            return $count;
        } catch (Exception $e) {
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e, "fetch_count_project_{$projectId}_collection_{$collection}");
            } else {
                throw $e;
            }
        }
        return 0;
    }

    /**
     * Deployments Total
     *
     * Total sum of storage used by deployments
     *
     * @param string $projectId
     *
     * @return int
     * @throws Exception
     */
    private function deploymentsTotal(string $projectId): int
    {
        return $this->sum($projectId, 'deployments', 'size', 'deployments.$all.storage.size');
    }

    /**
     * Users Stats
     *
     * Metric: users.count
     *
     * @param string $projectId
     *
     * @return void
     * @throws Exception
     */
    private function usersStats(string $projectId): void
    {
        $this->count($projectId, 'users', 'users.$all.count.total');
    }

    /**
     * Storage Stats
     *
     * Metrics: buckets.$all.count.total, files.$all.count.total, files.bucketId,count.total,
     * files.$all.storage.size, files.bucketId.storage.size, project.$all.storage.size
     *
     * @param string $projectId
     *
     * @return void
     * @throws Authorization
     * @throws Structure
     */
    private function storageStats(string $projectId): void
    {
        $projectFilesTotal = 0;
        $projectFilesCount = 0;

        $metric = 'buckets.$all.count.total';
        $this->count($projectId, 'buckets', $metric);

        $this->foreachDocument($projectId, 'buckets', [], function ($bucket) use (&$projectFilesCount, &$projectFilesTotal, $projectId,) {
            $metric = "files.{$bucket->getId()}.count.total";
            $count = $this->count($projectId, 'bucket_' . $bucket->getInternalId(), $metric);
            $projectFilesCount += $count;

            $metric = "files.{$bucket->getId()}.storage.size";
            $sum = $this->sum($projectId, 'bucket_' . $bucket->getInternalId(), 'sizeOriginal', $metric);
            $projectFilesTotal += $sum;
        });

        $this->createPerPeriodMetric($projectId, 'files.$all.count.total', $projectFilesCount);
        $this->createPerPeriodMetric($projectId, 'files.$all.storage.size', $projectFilesTotal);

        $deploymentsTotal = $this->deploymentsTotal($projectId);
        $this->createPerPeriodMetric($projectId, 'project.$all.storage.size', $projectFilesTotal + $deploymentsTotal);
    }

    /**
     * Database Stats
     *
     * Collect all database stats
     * Metrics: databases.$all.count.total, collections.$all.count.total, collections.databaseId.count.total,
     * documents.$all.count.all, documents.databaseId.count.total, documents.databaseId/collectionId.count.total
     *
     * @param string $projectId
     *
     * @return void
     * @throws Authorization
     * @throws Structure
     */
    private function databaseStats(string $projectId): void
    {
        $projectDocumentsCount = 0;
        $projectCollectionsCount = 0;

        $this->count($projectId, 'databases', 'databases.$all.count.total');

        $this->foreachDocument($projectId, 'databases', [], function ($database) use (&$projectDocumentsCount, &$projectCollectionsCount, $projectId) {
            $metric = "collections.{$database->getId()}.count.total";
            $count = $this->count($projectId, 'database_' . $database->getInternalId(), $metric);
            $projectCollectionsCount += $count;
            $databaseDocumentsCount = 0;

            $this->foreachDocument($projectId, 'database_' . $database->getInternalId(), [], function ($collection) use (&$projectDocumentsCount, &$databaseDocumentsCount, $projectId, $database) {
                $metric = "documents.{$database->getId()}/{$collection->getId()}.count.total";

                $count = $this->count($projectId, 'database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $metric);
                $projectDocumentsCount += $count;
                $databaseDocumentsCount += $count;
            });

            $this->createPerPeriodMetric($projectId, "documents.{$database->getId()}.count.total", $databaseDocumentsCount);
        });

        $this->createPerPeriodMetric($projectId, 'collections.$all.count.total', $projectCollectionsCount);
        $this->createPerPeriodMetric($projectId, 'documents.$all.count.total', $projectDocumentsCount);
    }

    /**
     * Collect Stats
     *
     * Collect all database related stats
     *
     * @return void
     * @throws Exception
     */
    public function collect(): void
    {
        $this->foreachDocument('console', 'projects', [], function (Document $project) {
            $projectId = $project->getInternalId();

            $this->usersStats($projectId);
            $this->databaseStats($projectId);
            $this->storageStats($projectId);
        });
    }
}
