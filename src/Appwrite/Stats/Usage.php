<?php

namespace Appwrite\Stats;

use Utopia\Database\Database;
use Utopia\Database\Document;
use InfluxDB\Database as InfluxDatabase;
use DateTime;

class Usage
{
    protected InfluxDatabase $influxDB;
    protected Database $database;
    protected $errorHandler;
    private array $latestTime = [];

    // all the mertics that we are collecting
    protected array $metrics = [
        'requests' => [
            'table' => 'appwrite_usage_requests_all',
        ],
        'network' => [
            'table' => 'appwrite_usage_network_all',
        ],
        'executions' => [
            'table' => 'appwrite_usage_executions_all',
        ],
        'databases.create' => [
            'table' => 'appwrite_usage_databases_create',
        ],
        'databases.read' => [
            'table' => 'appwrite_usage_databases_read',
        ],
        'databases.update' => [
            'table' => 'appwrite_usage_databases_update',
        ],
        'databases.delete' => [
            'table' => 'appwrite_usage_databases_delete',
        ],
        'databases.collections.create' => [
            'table' => 'appwrite_usage_databases_collections_create',
        ],
        'databases.collections.read' => [
            'table' => 'appwrite_usage_databases_collections_read',
        ],
        'databases.collections.update' => [
            'table' => 'appwrite_usage_databases_collections_update',
        ],
        'databases.collections.delete' => [
            'table' => 'appwrite_usage_databases_collections_delete',
        ],
        'databases.documents.create' => [
            'table' => 'appwrite_usage_databases_documents_create',
        ],
        'databases.documents.read' => [
            'table' => 'appwrite_usage_databases_documents_read',
        ],
        'databases.documents.update' => [
            'table' => 'appwrite_usage_databases_documents_update',
        ],
        'databases.documents.delete' => [
            'table' => 'appwrite_usage_databases_documents_delete',
        ],
        'databases.databaseId.collections.create' => [
            'table' => 'appwrite_usage_databases_collections_create',
            'groupBy' => ['databaseId'],
        ],
        'databases.databaseId.collections.read' => [
            'table' => 'appwrite_usage_databases_collections_read',
            'groupBy' => ['databaseId'],
        ],
        'databases.databaseId.collections.update' => [
            'table' => 'appwrite_usage_databases_collections_update',
            'groupBy' => ['databaseId'],
        ],
        'databases.databaseId.collections.delete' => [
            'table' => 'appwrite_usage_databases_collections_delete',
            'groupBy' => ['databaseId'],
        ],
        'databases.databaseId.documents.create' => [
            'table' => 'appwrite_usage_databases_documents_create',
            'groupBy' => ['databaseId'],
        ],
        'databases.databaseId.documents.read' => [
            'table' => 'appwrite_usage_databases_documents_read',
            'groupBy' => ['databaseId'],
        ],
        'database.databaseId.documents.update' => [
            'table' => 'appwrite_usage_databases_documents_update',
            'groupBy' => ['databaseId'],
        ],
        'databases.databaseId.documents.delete' => [
            'table' => 'appwrite_usage_databases_documents_delete',
            'groupBy' => ['databaseId'],
        ],
        'databases.databaseId.collections.collectionId.documents.create' => [
            'table' => 'appwrite_usage_databases_documents_create',
            'groupBy' => ['databaseId', 'collectionId'],
        ],
        'databases.databaseId.collections.collectionId.documents.read' => [
            'table' => 'appwrite_usage_databases_documents_read',
            'groupBy' => ['databaseId', 'collectionId'],
        ],
        'databases.databaseId.collections.collectionId.documents.update' => [
            'table' => 'appwrite_usage_databases_documents_update',
            'groupBy' => ['databaseId', 'collectionId'],
        ],
        'databases.databaseId.collections.collectionId.documents.delete' => [
            'table' => 'appwrite_usage_databases_documents_delete',
            'groupBy' => ['databaseId', 'collectionId'],
        ],
        'storage.buckets.create' => [
            'table' => 'appwrite_usage_storage_buckets_create',
        ],
        'storage.buckets.read' => [
            'table' => 'appwrite_usage_storage_buckets_read',
        ],
        'storage.buckets.update' => [
            'table' => 'appwrite_usage_storage_buckets_update',
        ],
        'storage.buckets.delete' => [
            'table' => 'appwrite_usage_storage_buckets_delete',
        ],
        'storage.files.create' => [
            'table' => 'appwrite_usage_storage_files_create',
        ],
        'storage.files.read' => [
            'table' => 'appwrite_usage_storage_files_read',
        ],
        'storage.files.update' => [
            'table' => 'appwrite_usage_storage_files_update',
        ],
        'storage.files.delete' => [
            'table' => 'appwrite_usage_storage_files_delete',
        ],
        'storage.buckets.bucketId.files.create' => [
            'table' => 'appwrite_usage_storage_files_create',
            'groupBy' => ['bucketId'],
        ],
        'storage.buckets.bucketId.files.read' => [
            'table' => 'appwrite_usage_storage_files_read',
            'groupBy' => ['bucketId'],
        ],
        'storage.buckets.bucketId.files.update' => [
            'table' => 'appwrite_usage_storage_files_update',
            'groupBy' => ['bucketId'],
        ],
        'storage.buckets.bucketId.files.delete' => [
            'table' => 'appwrite_usage_storage_files_delete',
            'groupBy' => ['bucketId'],
        ],
        'users.create' => [
            'table' => 'appwrite_usage_users_create',
        ],
        'users.read' => [
            'table' => 'appwrite_usage_users_read',
        ],
        'users.update' => [
            'table' => 'appwrite_usage_users_update',
        ],
        'users.delete' => [
            'table' => 'appwrite_usage_users_delete',
        ],
        'users.sessions.create' => [
            'table' => 'appwrite_usage_users_sessions_create',
        ],
        'users.sessions.provider.create' => [
            'table' => 'appwrite_usage_users_sessions_create',
            'groupBy' => ['provider'],
        ],
        'users.sessions.delete' => [
            'table' => 'appwrite_usage_users_sessions_delete',
        ],
        'functions.functionId.executions' => [
            'table' => 'appwrite_usage_executions_all',
            'groupBy' => ['functionId'],
        ],
        'functions.functionId.compute' => [
            'table' => 'appwrite_usage_executions_time',
            'groupBy' => ['functionId'],
        ],
        'functions.functionId.failures' => [
            'table' => 'appwrite_usage_executions_all',
            'groupBy' => ['functionId'],
            'filters' => [
                'functionStatus' => 'failed',
            ],
        ],
    ];

    protected array $periods = [
        [
            'key' => '30m',
            'multiplier' => 1800,
            'startTime' => '-24 hours',
        ],
        [
            'key' => '1d',
            'multiplier' => 86400,
            'startTime' => '-90 days',
        ],
    ];

    public function __construct(Database $database, InfluxDatabase $influxDB, callable $errorHandler = null)
    {
        $this->database = $database;
        $this->influxDB = $influxDB;
        $this->errorHandler = $errorHandler;
    }

    /**
     * Create or Update Mertic
     * Create or update each metric in the stats collection for the given project
     *
     * @param string $projectId
     * @param int $time
     * @param string $period
     * @param string $metric
     * @param int $value
     * @param int $type
     *
     * @return void
     */
    private function createOrUpdateMetric(string $projectId, int $time, string $period, string $metric, int $value, int $type): void
    {
        $id = \md5("{$time}_{$period}_{$metric}");
        $this->database->setNamespace('_console');
        $project = $this->database->getDocument('projects', $projectId);
        $this->database->setNamespace('_' . $project->getInternalId());

        try {
            $document = $this->database->getDocument('stats', $id);
            if ($document->isEmpty()) {
                $this->database->createDocument('stats', new Document([
                    '$id' => $id,
                    'period' => $period,
                    'time' => $time,
                    'metric' => $metric,
                    'value' => $value,
                    'type' => $type,
                ]));
            } else {
                $this->database->updateDocument(
                    'stats',
                    $document->getId(),
                    $document->setAttribute('value', $value)
                );
            }
            $this->latestTime[$metric][$period] = $time;
        } catch (\Exception $e) { // if projects are deleted this might fail
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e, "sync_project_{$projectId}_metric_{$metric}");
            } else {
                throw $e;
            }
        }
    }

    /**
     * Sync From InfluxDB
     * Sync stats from influxDB to stats collection in the Appwrite database
     *
     * @param string $metric
     * @param array $options
     * @param array $period
     *
     * @return void
     */
    private function syncFromInfluxDB(string $metric, array $options, array $period): void
    {
        $start = DateTime::createFromFormat('U', \strtotime($period['startTime']))->format(DateTime::RFC3339);
        if (!empty($this->latestTime[$metric][$period['key']])) {
            $start = DateTime::createFromFormat('U', $this->latestTime[$metric][$period['key']])->format(DateTime::RFC3339);
        }
        $end = DateTime::createFromFormat('U', \strtotime('now'))->format(DateTime::RFC3339);

        $table = $options['table']; //Which influxdb table to query for this metric
        $groupBy = empty($options['groupBy']) ? '' : ', ' . implode(', ', array_map(fn($groupBy) => '"' . $groupBy . '" ', $options['groupBy'])); //Some sub level metrics may be grouped by other tags like collectionId, bucketId, etc

        $filters = $options['filters'] ?? []; // Some metrics might have additional filters, like function's status
        if (!empty($filters)) {
            $filters = ' AND ' . implode(' AND ', array_map(fn ($filter, $value) => "\"{$filter}\"='{$value}'", array_keys($filters), array_values($filters)));
        } else {
            $filters = '';
        }

        $query = "SELECT sum(value) AS \"value\" ";
        $query .= "FROM \"{$table}\" ";
        $query .= "WHERE \"time\" > '{$start}' ";
        $query .= "AND \"time\" < '{$end}' ";
        $query .= "AND \"metric_type\"='counter' {$filters} ";
        $query .= "GROUP BY time({$period['key']}), \"projectId\" {$groupBy} ";
        $query .= "FILL(null)";

        try {
            $result = $this->influxDB->query($query);
            $points = $result->getPoints();
            foreach ($points as $point) {
                $projectId = $point['projectId'];

                if (!empty($projectId) && $projectId !== 'console') {
                    $metricUpdated = $metric;

                    if (!empty($groupBy)) {
                        foreach ($options['groupBy'] as $groupBy) {
                            $groupedBy = $point[$groupBy] ?? '';
                            if (empty($groupedBy)) {
                                continue;
                            }
                            $metricUpdated = str_replace($groupBy, $groupedBy, $metricUpdated);
                        }
                    }

                    $time = \strtotime($point['time']);
                    $value = (!empty($point['value'])) ? $point['value'] : 0;

                    $this->createOrUpdateMetric(
                        $projectId,
                        $time,
                        $period['key'],
                        $metricUpdated,
                        $value,
                        0
                    );
                }
            }
        } catch (\Exception $e) { // if projects are deleted this might fail
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e, "sync_metric_{$metric}_influxdb");
            } else {
                throw $e;
            }
        }
    }

    /**
     * Collect Stats
     * Collect all the stats from Influd DB to Database
     *
     * @return void
     */
    public function collect(): void
    {
        foreach ($this->metrics as $metric => $options) { //for each metrics
            foreach ($this->periods as $period) { // aggregate data for each period
                try {
                    $this->syncFromInfluxDB($metric, $options, $period);
                } catch (\Exception $e) {
                    if (is_callable($this->errorHandler)) {
                        call_user_func($this->errorHandler, $e);
                    } else {
                        throw $e;
                    }
                }
            }
        }
    }
}
