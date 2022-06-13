<?php

namespace Appwrite\Stats;

use Utopia\Database\Database;

class UsageDB extends Usage
{
    protected array $collections = [
        'users' => [
            'namespace' => '',
        ],
        'collections' => [
            'metricPrefix' => 'database',
            'namespace' => '',
            'subCollections' => [ // Some collections, like collections and later buckets have child collections that need counting
                'documents' => [
                    'collectionPrefix' => 'collection_',
                    'namespace' => '',
                ],
            ],
        ],
        'buckets' => [
            'metricPrefix' => 'storage',
            'namespace' => '',
            'subCollections' => [
                'files' => [
                    'namespace' => '',
                    'collectionPrefix' => 'bucket_',
                    'total' => [
                        'field' => 'sizeOriginal',
                    ],
                ],
            ],
        ],
    ];

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    public function getCollections(): array
    {
        return $this->collections;
    }

    public function foreachDocument(string $projectId, string $collection, array $queries, callable $callback): void
    {
        $limit = 50;
        $results = [];
        $sum = $limit;
        $latestDocument = null;
        $this->database->setNamespace('_' . $projectId);

        while ($sum === $limit) {
            $results = $this->database->find($collection, $queries, $limit, cursor:$latestDocument);

            $sum = count($results);

            foreach ($results as $document) {
                if (is_callable($callback)) {
                    $callback($document);
                }
            }
            $latestDocument = $results[array_key_last($results)];
        }
    }

    public function sum(string $projectId, string $collection, string $attribute, string $metric): int
    {
        $this->database->setNamespace('_' . $projectId);
        $sum = (int) $this->database->sum($collection, $attribute);

        $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
        $this->createOrUpdateMetric($projectId, $time, '30m', $metric, $sum, 1);

        $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
        $this->createOrUpdateMetric($projectId, $time, '1d', $metric, $sum, 1);
        return $sum;
    }

    public function count(string $projectId, string $collection, string $metric): int
    {
        $this->database->setNamespace("_{$projectId}");
        $count = $this->database->count($collection);
        $metricPrefix = $options['metricPrefix'] ?? '';
        $metric = empty($metricPrefix) ? "{$collection}.count" : "{$metricPrefix}.{$collection}.count";

        $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
        $this->createOrUpdateMetric($projectId, $time, '30m', $metric, $count, 1);
        
        $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
        $this->createOrUpdateMetric($projectId, $time, '1d', $metric, $count, 1);
        return $count;
    }
}
