<?php

namespace Appwrite\Usage\Calculators;

use Appwrite\Usage\Calculator;
use Utopia\Database\Database;
use Utopia\Database\Document;
use InfluxDB\Database as InfluxDatabase;
use DateTime;

class TimeSeries extends Calculator {
 
    protected InfluxDatabase $influxDB;
    protected Database $database;
    protected $errorHandler;
    private array $latestTime = [];

    // all the mertics that we are collecting
    protected array $metrics = [
        'project.$all.network.requests' => [
            'table' => 'appwrite_usage_network_requests',
        ],
        'project.$all.network.bandwidth' => [
            'table' => 'appwrite_usage_network_bandwidth',
        ],
        'project.$all.network.inbound' => [
            'table' => 'appwrite_usage_network_inbound',
        ],
        'project.$all.network.outbound' => [
            'table' => 'appwrite_usage_network_outbound',
        ],
        /* Users service metrics */
        'users.$all.requests.create' => [
            'table' => 'appwrite_usage_users_requests_create',
        ],
        'users.$all.requests.read' => [
            'table' => 'appwrite_usage_users_requests_read',
        ],
        'users.$all.requests.update' => [
            'table' => 'appwrite_usage_users_requests_update',
        ],
        'users.$all.requests.delete' => [
            'table' => 'appwrite_usage_users_requests_delete',
        ],

        'databases.$all.requests.create' => [
            'table' => 'appwrite_usage_databases_requests_create',
        ],
        'databases.$all.requests.read' => [
            'table' => 'appwrite_usage_databases_requests_read',
        ],
        'databases.$all.requests.update' => [
            'table' => 'appwrite_usage_databases_requests_update',
        ],
        'databases.$all.requests.delete' => [
            'table' => 'appwrite_usage_databases_requests_delete',
        ],

        'collections.$all.requests.create' => [
            'table' => 'appwrite_usage_dollections_requests_create',
        ],
        'collections.$all.requests.read' => [
            'table' => 'appwrite_usage_collections_requests_read',
        ],
        'collections.$all.requests.update' => [
            'table' => 'appwrite_usage_collections_requests_update',
        ],
        'collections.$all.requests.delete' => [
            'table' => 'appwrite_usage_collections_requests_delete',
        ],

        'documents.$all.requests.create' => [
            'table' => 'appwrite_usage_documents_requests_create',
        ],
        'documents.$all.requests.read' => [
            'table' => 'appwrite_usage_documents_requests_read',
        ],
        'documents.$all.requests.update' => [
            'table' => 'appwrite_usage_documents_requests_update',
        ],
        'documents.$all.requests.delete' => [
            'table' => 'appwrite_usage_documents_requests_delete',
        ],

        'collections.databaseId.requests.create' => [
            'table' => 'appwrite_usage_collections_requests_create',
            'groupBy' => ['databaseId'],
        ],
        'collections.databaseId.requests.read' => [
            'table' => 'appwrite_usage_collections_requests_read',
            'groupBy' => ['databaseId'],
        ],
        'collections.databaseId.requests.update' => [
            'table' => 'appwrite_usage_collections_requests_update',
            'groupBy' => ['databaseId'],
        ],
        'collections.databaseId.requests.delete' => [
            'table' => 'appwrite_usage_collections_requests_delete',
            'groupBy' => ['databaseId'],
        ],

        'documents.databaseId.requests.create' => [
            'table' => 'appwrite_usage_documents_requests_create',
            'groupBy' => ['databaseId'],
        ],
        'documents.databaseId.requests.read' => [
            'table' => 'appwrite_usage_documents_requests_read',
            'groupBy' => ['databaseId'],
        ],
        'documents.databaseId.requests.update' => [
            'table' => 'appwrite_usage_documents_requests_update',
            'groupBy' => ['databaseId'],
        ],
        'documents.databaseId.requests.delete' => [
            'table' => 'appwrite_usage_documents_requests_delete',
            'groupBy' => ['databaseId'],
        ],

        'documents.databaseId/collectionId.requests.create' => [
            'table' => 'appwrite_usage_documents_requests_create',
            'groupBy' => ['databaseId', 'collectionId'],
        ],
        'documents.databaseId/collectionId.requests.read' => [
            'table' => 'appwrite_usage_documents_requests_read',
            'groupBy' => ['databaseId', 'collectionId'],
        ],
        'documents.databaseId/collectionId.requests.update' => [
            'table' => 'appwrite_usage_documents_requests_update',
            'groupBy' => ['databaseId', 'collectionId'],
        ],
        'documents.databaseId/collectionId.requests.delete' => [
            'table' => 'appwrite_usage_documents_requests_delete',
            'groupBy' => ['databaseId', 'collectionId'],
        ],

        'buckets.$all.requests.create' => [
            'table' => 'appwrite_usage_buckets_requests_create',
        ],
        'buckets.$all.requests.read' => [
            'table' => 'appwrite_usage_buckets_requests_read',
        ],
        'buckets.$all.requests.update' => [
            'table' => 'appwrite_usage_buckets_requests_update',
        ],
        'buckets.$all.requests.delete' => [
            'table' => 'appwrite_usage_buckets_requests_delete',
        ],

        'files.$all.requests.create' => [
            'table' => 'appwrite_usage_files_requests_create',
        ],
        'files.$all.requests.read' => [
            'table' => 'appwrite_usage_files_requests_read',
        ],
        'files.$all.requests.update' => [
            'table' => 'appwrite_usage_files_requests_update',
        ],
        'files.$all.requests.delete' => [
            'table' => 'appwrite_usage_files_requests_delete',
        ],
        
        'files.bucketId.requests.create' => [
            'table' => 'appwrite_usage_files_requests_create',
            'groupBy' => ['bucketId'],
        ],
        'files.bucketId.requests.read' => [
            'table' => 'appwrite_usage_files_requests_read',
            'groupBy' => ['bucketId'],
        ],
        'files.bucketId.requests.update' => [
            'table' => 'appwrite_usage_files_requests_update',
            'groupBy' => ['bucketId'],
        ],
        'files.bucketId.requests.delete' => [
            'table' => 'appwrite_usage_files_requests_delete',
            'groupBy' => ['bucketId'],
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
        'functions.executions' => [
            'table' => 'appwrite_usage_functions_executions_all',
        ],
        'functions.builds' => [
            'table' => 'appwrite_usage_functions_builds_all',
        ],
        'functions.failures' => [
            'table' => 'appwrite_usage_functions_executions_all',
            'filters' => [
                'functionStatus' => 'failed',
            ],
        ],
        'functions.functionId.executions' => [
            'table' => 'appwrite_usage_functions_executions_all',
            'groupBy' => ['functionId'],
        ],
        'functions.functionId.builds' => [
            'table' => 'appwrite_usage_functions_builds_all',
            'groupBy' => ['functionId'],
        ],
        'functions.functionId.execution' => [
            'table' => 'appwrite_usage_functions_executions_time',
            'groupBy' => ['functionId'],
        ],
        'functions.functionId.build' => [
            'table' => 'appwrite_usage_functions_builds_time',
            'groupBy' => ['functionId'],
        ],
        'functions.functionId.compute' => [ // Built time + execution time
            'table' => 'appwrite_usage_functions_compute_time',
            'groupBy' => ['functionId'],
        ],
        'functions.functionId.executions.failures' => [
            'table' => 'appwrite_usage_functions_executions_all',
            'groupBy' => ['functionId'],
            'filters' => [
                'functionStatus' => 'failed',
            ],
        ],
        'functions.functionId.builds.failures' => [
            'table' => 'appwrite_usage_functions_builds_all',
            'groupBy' => ['functionId'],
            'filters' => [
                'functionBuildStatus' => 'failed',
            ],
        ],
    ];

    protected array $period = [
        'key' => '30m',
        'startTime' => '-24 hours',
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
            try {
                $this->syncFromInfluxDB($metric, $options, $this->period);
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