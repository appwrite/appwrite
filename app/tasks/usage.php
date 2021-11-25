<?php

global $cli, $register;

require_once __DIR__ . '/../init.php';

use Utopia\App;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

/**
 * Metrics We collect
 *
 * General
 *
 * requests
 * network
 * executions
 *
 * Database
 *
 * database.collections.create
 * database.collections.read
 * database.collections.update
 * database.collections.delete
 * database.documents.create
 * database.documents.read
 * database.documents.update
 * database.documents.delete
 * database.collections.{collectionId}.documents.create
 * database.collections.{collectionId}.documents.read
 * database.collections.{collectionId}.documents.update
 * database.collections.{collectionId}.documents.delete
 *
 * Storage
 * 
 * storage.buckets.create
 * storage.buckets.read
 * storage.buckets.update
 * storage.buckets.delete
 * storage.files.create
 * storage.files.read
 * storage.files.update
 * storage.files.delete
 * storage.buckets.{bucketId}.files.create
 * storage.buckets.{bucketId}.files.read
 * storage.buckets.{bucketId}.files.update
 * storage.buckets.{bucketId}.files.delete
 *
 * Users
 *
 * users.create
 * users.read
 * users.update
 * users.delete
 * users.sessions.create
 * users.sessions.{provider}.create
 * users.sessions.delete
 *
 * Functions
 *
 * functions.{functionId}.executions
 * functions.{functionId}.failures
 * functions.{functionId}.compute
 *
 * Counters
 *
 * users.count
 * storage.buckets.count
 * storage.files.count
 * storage.buckets.{bucketId}.files.count
 * database.collections.count
 * database.documents.count
 * database.collections.{collectionId}.documents.count
 *
 * Totals
 *
 * storage.total
 *
 */

$cli
    ->task('usage')
    ->desc('Schedules syncing data from influxdb to Appwrite console db')
    ->action(function () use ($register) {
        Console::title('Usage Aggregation V1');
        Console::success(APP_NAME . ' usage aggregation process v1 has started');

        $interval = (int) App::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '30'); // 30 seconds (by default)
        $periods = [
            [
                'key' => '30m',
                'startTime' => '-24 hours',
            ],
            [
                'key' => '1d',
                'startTime' => '-90 days',
            ],
        ];

        // all the metrics that we are collecting at the moment
        $globalMetrics = [
            'requests' => [
                'table' => 'appwrite_usage_requests_all',
            ],
            'network' => [
                'table' => 'appwrite_usage_network_all',
            ],
            'executions' => [
                'table' => 'appwrite_usage_executions_all',
            ],
            'database.collections.create' => [
                'table' => 'appwrite_usage_database_collections_create',
            ],
            'database.collections.read' => [
                'table' => 'appwrite_usage_database_collections_read',
            ],
            'database.collections.update' => [
                'table' => 'appwrite_usage_database_collections_update',
            ],
            'database.collections.delete' => [
                'table' => 'appwrite_usage_database_collections_delete',
            ],
            'database.documents.create' => [
                'table' => 'appwrite_usage_database_documents_create',
            ],
            'database.documents.read' => [
                'table' => 'appwrite_usage_database_documents_read',
            ],
            'database.documents.update' => [
                'table' => 'appwrite_usage_database_documents_update',
            ],
            'database.documents.delete' => [
                'table' => 'appwrite_usage_database_documents_delete',
            ],
            'database.collections.collectionId.documents.create' => [
                'table' => 'appwrite_usage_database_documents_create',
                'groupBy' => 'collectionId',
            ],
            'database.collections.collectionId.documents.read' => [
                'table' => 'appwrite_usage_database_documents_read',
                'groupBy' => 'collectionId',
            ],
            'database.collections.collectionId.documents.update' => [
                'table' => 'appwrite_usage_database_documents_update',
                'groupBy' => 'collectionId',
            ],
            'database.collections.collectionId.documents.delete' => [
                'table' => 'appwrite_usage_database_documents_delete',
                'groupBy' => 'collectionId',
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
                'groupBy' => 'bucketId',
            ],
            'storage.buckets.bucketId.files.read' => [
                'table' => 'appwrite_usage_storage_files_read',
                'groupBy' => 'bucketId',
            ],
            'storage.buckets.bucketId.files.update' => [
                'table' => 'appwrite_usage_storage_files_update',
                'groupBy' => 'bucketId',
            ],
            'storage.buckets.bucketId.files.delete' => [
                'table' => 'appwrite_usage_storage_files_delete',
                'groupBy' => 'bucketId',
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
                'groupBy' => 'provider',
            ],
            'users.sessions.delete' => [
                'table' => 'appwrite_usage_users_sessions_delete',
            ],
            'functions.functionId.executions' => [
                'table' => 'appwrite_usage_executions_all',
                'groupBy' => 'functionId',
            ],
            'functions.functionId.compute' => [
                'table' => 'appwrite_usage_executions_time',
                'groupBy' => 'functionId',
            ],
            'functions.functionId.failures' => [
                'table' => 'appwrite_usage_executions_all',
                'groupBy' => 'functionId',
                'filters' => [
                    'functionStatus' => 'failed',
                ],
            ],
        ];

        // TODO Maybe move this to the setResource method, and reuse in the http.php file
        $attempts = 0;
        $max = 10;
        $sleep = 1;

        do { // connect to db
            try {
                $attempts++;
                $db = $register->get('db');
                $redis = $register->get('cache');
                break; // leave the do-while if successful
            } catch (\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('Failed to connect to database: ' . $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < $max);

        // TODO use inject
        $cacheAdapter = new Cache(new Redis($redis));
        $dbForProject = new Database(new MariaDB($db), $cacheAdapter);
        $dbForConsole = new Database(new MariaDB($db), $cacheAdapter);
        $dbForConsole->setNamespace('project_console_internal');

        $latestTime = [];

        Authorization::disable();

        $iterations = 0;
        Console::loop(function () use ($interval, $register, $dbForProject, $dbForConsole, $globalMetrics, $periods, &$latestTime, &$iterations) {
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Aggregating usage data every {$interval} seconds");

            $loopStart = microtime(true);

            /**
             * Aggregate InfluxDB every 30 seconds
             * @var InfluxDB\Client $client
             */
            $client = $register->get('influxdb');
            if ($client) {
                $attempts = 0;
                $max = 10;
                $sleep = 1;

                do { // check if telegraf database is ready
                    try {
                        $attempts++;
                        $database = $client->selectDB('telegraf');
                        if(in_array('telegraf', $client->listDatabases())) {
                            break; // leave the do-while if successful
                        }
                    } catch (\Throwable $th) {
                        Console::warning("InfluxDB not ready. Retrying connection ({$attempts})...");
                        if ($attempts >= $max) {
                            throw new \Exception('InfluxDB database not ready yet');
                        }
                        sleep($sleep);
                    }
                } while ($attempts < $max);

                // sync data
                foreach ($globalMetrics as $metric => $options) { //for each metrics
                    foreach ($periods as $period) { // aggregate data for each period
                        $start = DateTime::createFromFormat('U', \strtotime($period['startTime']))->format(DateTime::RFC3339);
                        if (!empty($latestTime[$metric][$period['key']])) {
                            $start = DateTime::createFromFormat('U', $latestTime[$metric][$period['key']])->format(DateTime::RFC3339);
                        }
                        $end = DateTime::createFromFormat('U', \strtotime('now'))->format(DateTime::RFC3339);

                        $table = $options['table']; //Which influxdb table to query for this metric
                        $groupBy = empty($options['groupBy']) ? '' : ', "' . $options['groupBy'] . '"'; //Some sub level metrics may be grouped by other tags like collectionId, bucketId, etc

                        $filters = $options['filters'] ?? []; // Some metrics might have additional filters, like function's status
                        if (!empty($filters)) {
                            $filters = ' AND ' . implode(' AND ', array_map(function ($filter, $value) {
                                return "\"{$filter}\"='{$value}'";
                            }, array_keys($filters), array_values($filters)));
                        } else {
                            $filters = '';
                        }

                        $query = "SELECT sum(value) AS \"value\" FROM \"{$table}\" WHERE \"time\" > '{$start}' AND \"time\" < '{$end}' AND \"metric_type\"='counter' {$filters} GROUP BY time({$period['key']}), \"projectId\" {$groupBy} FILL(null)";
                        $result = $database->query($query);

                        $points = $result->getPoints();
                        foreach ($points as $point) {
                            $projectId = $point['projectId'];

                            if (!empty($projectId) && $projectId !== 'console') {
                                $dbForProject->setNamespace('project_' . $projectId . '_internal');
                                $metricUpdated = $metric;

                                if (!empty($groupBy)) {
                                    $groupedBy = $point[$options['groupBy']] ?? '';
                                    if (empty($groupedBy)) {
                                        continue;
                                    }
                                    $metricUpdated = str_replace($options['groupBy'], $groupedBy, $metric);
                                }

                                $time = \strtotime($point['time']);
                                $id = \md5($time . '_' . $period['key'] . '_' . $metricUpdated); //Construct unique id for each metric using time, period and metric
                                $value = (!empty($point['value'])) ? $point['value'] : 0;

                                try {
                                    $document = $dbForProject->getDocument('stats', $id);
                                    if ($document->isEmpty()) {
                                        $dbForProject->createDocument('stats', new Document([
                                            '$id' => $id,
                                            'period' => $period['key'],
                                            'time' => $time,
                                            'metric' => $metricUpdated,
                                            'value' => $value,
                                            'type' => 0,
                                        ]));
                                    } else {
                                        $dbForProject->updateDocument(
                                            'stats',
                                            $document->getId(),
                                            $document->setAttribute('value', $value)
                                        );
                                    }
                                    $latestTime[$metric][$period['key']] = $time;
                                } catch (\Exception $e) { // if projects are deleted this might fail
                                    Console::warning("Failed to save data for project {$projectId} and metric {$metricUpdated}: {$e->getMessage()}");
                                }
                            }
                        }
                    }
                }
            }

            /**
             * Aggregate MariaDB every 15 minutes
             * Some of the queries here might contain full-table scans.
             */
            if ($iterations % 30 === 0) { // Every 15 minutes aggregate number of objects in database

                $latestProject = null;

                do { // Loop over all the projects
                    $attempts = 0;
                    $max = 10;
                    $sleep = 1;

                    do { // list projects
                        try {
                            $attempts++;
                            $projects = $dbForConsole->find('projects', [], 100, cursor: $latestProject);
                            break; // leave the do-while if successful
                        } catch (\Exception $e) {
                            Console::warning("Console DB not ready yet. Retrying ({$attempts})...");
                            if ($attempts >= $max) {
                                throw new \Exception('Failed access console db: ' . $e->getMessage());
                            }
                            sleep($sleep);
                        }
                    } while ($attempts < $max);

                    if (empty($projects)) {
                        continue;
                    }

                    $latestProject = $projects[array_key_last($projects)];

                    foreach ($projects as $project) {
                        $projectId = $project->getId();

                        // storage.tags.total
                        $dbForProject->setNamespace('project_' . $projectId . '_internal');
                        $storageTotal = (int) $dbForProject->sum('tags', 'size');

                        $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
                        $id = \md5($time . '_30m_storage.tags.total'); //Construct unique id for each metric using time, period and metric
                        $document = $dbForProject->getDocument('stats', $id);
                        if ($document->isEmpty()) {
                            $dbForProject->createDocument('stats', new Document([
                                '$id' => $id,
                                'period' => '30m',
                                'time' => $time,
                                'metric' => 'storage.tags.total',
                                'value' => $storageTotal,
                                'type' => 1,
                            ]));
                        } else {
                            $dbForProject->updateDocument(
                                'stats',
                                $document->getId(),
                                $document->setAttribute('value', $storageTotal)
                            );
                        }

                        $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
                        $id = \md5($time . '_1d_storage.total'); //Construct unique id for each metric using time, period and metric
                        $document = $dbForProject->getDocument('stats', $id);
                        if ($document->isEmpty()) {
                            $dbForProject->createDocument('stats', new Document([
                                '$id' => $id,
                                'period' => '1d',
                                'time' => $time,
                                'metric' => 'storage.tags.total',
                                'value' => $storageTotal,
                                'type' => 1,
                            ]));
                        } else {
                            $dbForProject->updateDocument(
                                'stats',
                                $document->getId(),
                                $document->setAttribute('value', $storageTotal)
                            );
                        }

                        $collections = [
                            'users' => [
                                'namespace' => 'internal',
                            ],
                            'collections' => [
                                'metricPrefix' => 'database',
                                'namespace' => 'internal',
                                'subCollections' => [ // Some collections, like collections and later buckets have child collections that need counting
                                    'documents' => [
                                        'namespace' => 'external',
                                    ],
                                ],
                            ],
                            'buckets' => [
                                'metricPrefix' => 'storage',
                                'namespace' => 'internal',
                                'subCollections' => [
                                    'files' => [
                                        'namespace' => 'external',
                                        'collectionPrefix' => 'bucket_',
                                        'sum' => [
                                            'field' => 'sizeOriginal'
                                        ]
                                    ],
                                ]
                            ]
                        ];

                        foreach ($collections as $collection => $options) {
                            try {
                                $dbForProject->setNamespace("project_{$projectId}_{$options['namespace']}");
                                $count = $dbForProject->count($collection);
                                $dbForProject->setNamespace("project_{$projectId}_internal");
                                $metricPrefix = $options['metricPrefix'] ?? '';
                                $metric = empty($metricPrefix) ? "{$collection}.count" : "{$metricPrefix}.{$collection}.count";

                                $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
                                $id = \md5($time . '_30m_' . $metric); //Construct unique id for each metric using time, period and metric
                                $document = $dbForProject->getDocument('stats', $id);
                                if ($document->isEmpty()) {
                                    $dbForProject->createDocument('stats', new Document([
                                        '$id' => $id,
                                        'time' => $time,
                                        'period' => '30m',
                                        'metric' => $metric,
                                        'value' => $count,
                                        'type' => 1,
                                    ]));
                                } else {
                                    $dbForProject->updateDocument(
                                        'stats',
                                        $document->getId(),
                                        $document->setAttribute('value', $count)
                                    );
                                }

                                $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
                                $id = \md5($time . '_1d_' . $metric); //Construct unique id for each metric using time, period and metric
                                $document = $dbForProject->getDocument('stats', $id);
                                if ($document->isEmpty()) {
                                    $dbForProject->createDocument('stats', new Document([
                                        '$id' => $id,
                                        'time' => $time,
                                        'period' => '1d',
                                        'metric' => $metric,
                                        'value' => $count,
                                        'type' => 1,
                                    ]));
                                } else {
                                    $dbForProject->updateDocument(
                                        'stats',
                                        $document->getId(),
                                        $document->setAttribute('value', $count)
                                    );
                                }

                                $subCollections = $options['subCollections'] ?? [];

                                if (empty($subCollections)) {
                                    continue;
                                }

                                $latestParent = null;
                                $subCollectionCounts = []; //total project level count of sub collections
                                $subCollectionTotals = []; //total project level sum of sub collections

                                do { // Loop over all the parent collection document for each sub collection
                                    $dbForProject->setNamespace("project_{$projectId}_{$options['namespace']}");
                                    $parents = $dbForProject->find($collection, [], 100, cursor: $latestParent); // Get all the parents for the sub collections for example for documents, this will get all the collections

                                    if (empty($parents)) {
                                        continue;
                                    }

                                    $latestParent = $parents[array_key_last($parents)];

                                    foreach ($parents as $parent) {
                                        foreach ($subCollections as $subCollection => $subOptions) { // Sub collection counts, like database.collections.collectionId.documents.count
                                            $dbForProject->setNamespace("project_{$projectId}_{$subOptions['namespace']}");
                                            $count = $dbForProject->count(($subOptions['collectionPrefix'] ?? '') . $parent->getId());

                                            $subCollectionCounts[$subCollection] = ($subCollectionCounts[$subCollection] ?? 0) + $count; // Project level counts for sub collections like database.documents.count

                                            $dbForProject->setNamespace("project_{$projectId}_internal");

                                            $metric = empty($metricPrefix) ? "{$collection}.{$parent->getId()}.{$subCollection}.count" : "{$metricPrefix}.{$collection}.{$parent->getId()}.{$subCollection}.count";
                                            $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
                                            $id = \md5($time . '_30m_' . $metric); //Construct unique id for each metric using time, period and metric
                                            $document = $dbForProject->getDocument('stats', $id);
                                            if ($document->isEmpty()) {
                                                $dbForProject->createDocument('stats', new Document([
                                                    '$id' => $id,
                                                    'time' => $time,
                                                    'period' => '30m',
                                                    'metric' => $metric,
                                                    'value' => $count,
                                                    'type' => 1,
                                                ]));
                                            } else {
                                                $dbForProject->updateDocument(
                                                    'stats',
                                                    $document->getId(),
                                                    $document->setAttribute('value', $count)
                                                );
                                            }

                                            $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
                                            $id = \md5($time . '_1d_' . $metric); //Construct unique id for each metric using time, period and metric
                                            $document = $dbForProject->getDocument('stats', $id);
                                            if ($document->isEmpty()) {
                                                $dbForProject->createDocument('stats', new Document([
                                                    '$id' => $id,
                                                    'time' => $time,
                                                    'period' => '1d',
                                                    'metric' => $metric,
                                                    'value' => $count,
                                                    'type' => 1,
                                                ]));
                                            } else {
                                                $dbForProject->updateDocument(
                                                    'stats',
                                                    $document->getId(),
                                                    $document->setAttribute('value', $count)
                                                );
                                            }

                                            // check if sum calculation is required
                                            $sum = $subOptions['sum'] ?? [];
                                            if(empty($sum)) {
                                                continue;
                                            }

                                            $dbForProject->setNamespace("project_{$projectId}_{$subOptions['namespace']}");
                                            $total = (int) $dbForProject->sum(($subOptions['collectionPrefix'] ?? '') . $parent->getId(), $sum['field']);

                                            $subCollectionTotals[$subCollection] = ($ssubCollectionTotals[$subCollection] ?? 0) + $total; // Project level sum for sub collections like storage.total

                                            $dbForProject->setNamespace("project_{$projectId}_internal");

                                            $metric = empty($metricPrefix) ? "{$collection}.{$parent->getId()}.{$subCollection}.total" : "{$metricPrefix}.{$collection}.{$parent->getId()}.{$subCollection}.total";
                                            $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
                                            $id = \md5($time . '_30m_' . $metric); //Construct unique id for each metric using time, period and metric
                                            $document = $dbForProject->getDocument('stats', $id);
                                            if ($document->isEmpty()) {
                                                $dbForProject->createDocument('stats', new Document([
                                                    '$id' => $id,
                                                    'time' => $time,
                                                    'period' => '30m',
                                                    'metric' => $metric,
                                                    'value' => $total,
                                                    'type' => 1,
                                                ]));
                                            } else {
                                                $dbForProject->updateDocument('stats', $document->getId(),
                                                $document->setAttribute('value', $total));
                                            }

                                            $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
                                            $id = \md5($time . '_1d_' . $metric); //Construct unique id for each metric using time, period and metric
                                            $document = $dbForProject->getDocument('stats', $id);
                                            if ($document->isEmpty()) {
                                                $dbForProject->createDocument('stats', new Document([
                                                    '$id' => $id,
                                                    'time' => $time,
                                                    'period' => '1d',
                                                    'metric' => $metric,
                                                    'value' => $total,
                                                    'type' => 1,
                                                ]));
                                            } else {
                                                $dbForProject->updateDocument('stats', $document->getId(),
                                                $document->setAttribute('value', $total));
                                            }

                                        }
                                    }
                                } while (!empty($parents));

                                /**
                                 * Inserting project level counts for sub collections like database.documents.count
                                 */
                                foreach ($subCollectionCounts as $subCollection => $count) {
                                    $dbForProject->setNamespace("project_{$projectId}_internal");

                                    $metric = empty($metricPrefix) ? "{$subCollection}.count" : "{$metricPrefix}.{$subCollection}.count";

                                    $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
                                    $id = \md5($time . '_30m_' . $metric); //Construct unique id for each metric using time, period and metric
                                    $document = $dbForProject->getDocument('stats', $id);
                                    if ($document->isEmpty()) {
                                        $dbForProject->createDocument('stats', new Document([
                                            '$id' => $id,
                                            'time' => $time,
                                            'period' => '30m',
                                            'metric' => $metric,
                                            'value' => $count,
                                            'type' => 1,
                                        ]));
                                    } else {
                                        $dbForProject->updateDocument(
                                            'stats',
                                            $document->getId(),
                                            $document->setAttribute('value', $count)
                                        );
                                    }

                                    $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
                                    $id = \md5($time . '_1d_' . $metric); //Construct unique id for each metric using time, period and metric
                                    $document = $dbForProject->getDocument('stats', $id);
                                    if ($document->isEmpty()) {
                                        $dbForProject->createDocument('stats', new Document([
                                            '$id' => $id,
                                            'time' => $time,
                                            'period' => '1d',
                                            'metric' => $metric,
                                            'value' => $count,
                                            'type' => 1,
                                        ]));
                                    } else {
                                        $dbForProject->updateDocument(
                                            'stats',
                                            $document->getId(),
                                            $document->setAttribute('value', $count)
                                        );
                                    }
                                }

                                /**
                                 * Inserting project level sums for sub collections like storage.total
                                 */
                                foreach ($subCollectionTotals as $subCollection => $count) {
                                    $dbForProject->setNamespace("project_{$projectId}_internal");

                                    $metric = empty($metricPrefix) ? "{$subCollection}.total" : "{$metricPrefix}.{$subCollection}.total";

                                    $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
                                    $id = \md5($time . '_30m_' . $metric); //Construct unique id for each metric using time, period and metric
                                    $document = $dbForProject->getDocument('stats', $id);
                                    if ($document->isEmpty()) {
                                        $dbForProject->createDocument('stats', new Document([
                                            '$id' => $id,
                                            'time' => $time,
                                            'period' => '30m',
                                            'metric' => $metric,
                                            'value' => $count,
                                            'type' => 1,
                                        ]));
                                    } else {
                                        $dbForProject->updateDocument('stats', $document->getId(),
                                        $document->setAttribute('value', $count));
                                    }

                                    $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
                                    $id = \md5($time . '_1d_' . $metric); //Construct unique id for each metric using time, period and metric
                                    $document = $dbForProject->getDocument('stats', $id);
                                    if ($document->isEmpty()) {
                                        $dbForProject->createDocument('stats', new Document([
                                            '$id' => $id,
                                            'time' => $time,
                                            'period' => '1d',
                                            'metric' => $metric,
                                            'value' => $count,
                                            'type' => 1,
                                        ]));
                                    } else {
                                        $dbForProject->updateDocument('stats', $document->getId(),
                                        $document->setAttribute('value', $count));
                                    }
                                }
                            } catch (\Exception$e) {
                                Console::warning("Failed to save database counters data for project {$collection}: {$e->getMessage()}");
                            }
                        }
                    }
                } while (!empty($projects));
            }

            $iterations++;
            $loopTook = microtime(true) - $loopStart;
            $now = date('d-m-Y H:i:s', time());

            Console::info("[{$now}] Aggregation took {$loopTook} seconds");
        }, $interval);
    });
