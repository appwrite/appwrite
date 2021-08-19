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
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

/**
 * Metrics We collect
 *
 * requests
 * network
 * executions
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
 * storage.buckets.{bucketId}.files.create
 * storage.buckets.{bucketId}.files.read
 * storage.buckets.{bucketId}.files.update
 * storage.buckets.{bucketId}.files.delete
 * users.create
 * users.read
 * users.update
 * users.delete
 * users.sessions.create
 * users.sessions.delete
 *
 * Counters
 *
 * users.count
 * storage.files.count
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

        $interval = (int) App::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '30'); //30 seconds
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
                'groupBy' => 'provider',
            ],
            'users.sessions.delete' => [
                'table' => 'appwrite_usage_users_sessions_delete',
            ],
        ];

        $attempts = 0;
        $max = 10;
        $sleep = 1;
        do { // connect to db
            try {
                $attempts++;
                $db = $register->get('db');
                $redis = $register->get('cache');
                break; // leave the do-while if successful
            } catch (\Exception$e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('Failed to connect to database: ' . $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < $max);

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

            $client = $register->get('influxdb');
            if ($client) {
                $database = $client->selectDB('telegraf');
                // sync data
                foreach ($globalMetrics as $metric => $options) { //for each metrics
                    foreach ($periods as $period) { // aggregate data for each period
                        $start = DateTime::createFromFormat('U', \strtotime($period['startTime']))->format(DateTime::RFC3339);
                        if (!empty($latestTime[$metric][$period['key']])) {
                            $start = DateTime::createFromFormat('U', $latestTime[$metric][$period['key']])->format(DateTime::RFC3339);
                        }
                        $end = DateTime::createFromFormat('U', \strtotime('now'))->format(DateTime::RFC3339);

                        $table = $options['table']; //which influxdb table to query for this metric
                        $groupBy = empty($options['groupBy']) ? '' : ', "' . $options['groupBy'] . '"'; //some sub level metrics may be grouped by other tags like collectionId, bucketId, etc

                        $result = $database->query('SELECT sum(value) AS "value" FROM "' . $table . '" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' GROUP BY time(' . $period['key'] . '), "projectId"' . $groupBy . ' FILL(null)');
                        $points = $result->getPoints();
                        foreach ($points as $point) {
                            $projectId = $point['projectId'];
                            if (!empty($projectId) && $projectId != 'console') {
                                $dbForProject->setNamespace('project_' . $projectId . '_internal');
                                if (!empty($groupBy)) {
                                    $groupedBy = $point[$groupBy] ?? '';
                                    if (empty($groupedBy)) {
                                        continue;
                                    }
                                    $metric = str_replace($groupBy, $groupedBy, $metric);
                                }
                                $time = \strtotime($point['time']);
                                $id = \md5($time . '_' . $period['key'] . '_' . $metric); //construct unique id for each metric using time, period and metric
                                $value = (!empty($point['value'])) ? $point['value'] : 0;
                                try {
                                    $document = $dbForProject->getDocument('stats', $id);
                                    if ($document->isEmpty()) {
                                        $dbForProject->createDocument('stats', new Document([
                                            '$id' => $id,
                                            'period' => $period['key'],
                                            'time' => $time,
                                            'metric' => $metric,
                                            'value' => $value,
                                            'type' => 0,
                                        ]));
                                    } else {
                                        $dbForProject->updateDocument('stats', $document->getId(),
                                            $document->setAttribute('value', $value));
                                    }
                                    $latestTime[$metric][$period['key']] = $time;
                                } catch (\Exception$e) {
                                    // if projects are deleted this might fail
                                    Console::warning("Failed to save data for project {$projectId} and metric {$metric}");
                                }
                            }
                        }
                    }
                }
            }

            if ($iterations % 30 == 0) { //every 15 minutes
                // aggregate number of objects in database
                // get count of all the documents per collection -
                // buckets will have the same
                $latestProject = null;
                do {
                    $projects = $dbForConsole->find('projects', [], 100, orderAfter:$latestProject);
                    if (!empty($projects)) {
                        $latestProject = $projects[array_key_last($projects)];

                        foreach ($projects as $project) {
                            $id = $project->getId();

                            // get total storage
                            $dbForProject->setNamespace('project_' . $id . '_internal');
                            $storageTotal = $dbForProject->sum('files', 'sizeOriginal') + $dbForProject->sum('tags', 'size');

                            $dbForProject->createDocument('stats', new Document([
                                '$id' => $dbForProject->getId(),
                                'period' => '15m',
                                'time' => time(),
                                'metric' => 'storage.total',
                                'value' => $storageTotal,
                                'type' => 1,
                            ]));

                            $collections = [
                                'users' => [
                                    'namespace' => 'internal',
                                ],
                                'collections' => [
                                    'metricPrefix' => 'database',
                                    'namespace' => 'internal',
                                    'subCollections' => [
                                        'documents' => [
                                            'namespace' => 'external',
                                        ],
                                    ],
                                ],
                                'files' => [
                                    'metricPrefix' => 'storage',
                                    'namespace' => 'internal',
                                ],
                            ];
                            foreach ($collections as $collection => $options) {
                                $dbForProject->setNamespace("project_{$id}_{$options['namespace']}");
                                $count = $dbForProject->count($collection);
                                $dbForProject->setNamespace("project_{$id}_internal");
                                $metricPrefix = $options['metricPrefix'] ?? '';
                                $metric = empty($metricPrefix) ? "{$collection}.count" : "{$metricPrefix}.{$collection}.count";
                                $dbForProject->createDocument('stats', new Document([
                                    '$id' => $dbForProject->getId(),
                                    'time' => time(),
                                    'period' => '15m',
                                    'metric' => $metric,
                                    'value' => $count,
                                    'type' => 1,
                                ]));

                                $subCollections = $options['subCollections'] ?? [];
                                if (!empty($subCollections)) {
                                    $latestParent = null;
                                    $subCollectionCounts = []; //total project level count of sub collections
                                    do {
                                        $dbForProject->setNamespace("project_{$id}_{$options['namespace']}");
                                        $parents = $dbForProject->find($collection, [], 100, orderAfter:$latestParent);
                                        if (!empty($parents)) {
                                            $latestParent = $parents[array_key_last($parents)];
                                            foreach ($parents as $parent) {
                                                foreach ($subCollections as $subCollection => $subOptions) {
                                                    $dbForProject->setNamespace("project_{$id}_{$subOptions['namespace']}");
                                                    $count = $dbForProject->count($parent->getId());
                                                    $subCollectionsCounts[$subCollection] = ($subCollectionCounts[$subCollection] ?? 0) + $count;

                                                    $dbForProject->setNamespace("project_{$id}_internal");
                                                    $dbForProject->createDocument('stats', new Document([
                                                        '$id' => $dbForProject->getId(),
                                                        'time' => time(),
                                                        'period' => '15m',
                                                        'metric' => empty($metricPrefix) ? "{$collection}.{$parent->getId()}.{$subCollection}.count" : "{$metricPrefix}.{$collection}.{$parent->getId()}.{$subCollection}.count",
                                                        'value' => $count,
                                                        'type' => 1,
                                                    ]));
                                                }
                                            }
                                        }
                                    } while (!empty($parents));

                                    foreach ($subCollectionsCounts as $subCollection => $count) {
                                        $dbForProject->setNamespace("project_{$id}_internal");
                                        $dbForProject->createDocument('stats', new Document([
                                            '$id' => $dbForProject->getId(),
                                            'time' => time(),
                                            'period' => '15m',
                                            'metric' => empty($metricPrefix) ? "{$subCollection}.count" : "{$metricPrefix}.{$subCollection}.count",
                                            'value' => $count,
                                            'type' => 1,
                                        ]));
                                    }
                                }
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
