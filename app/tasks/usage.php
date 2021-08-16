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
 * 1. Load all the projects
 * 2. Load latest data entered entered for each project, for each period
 * 3. Start the loop
 * 4. Fore each project, for each metric, for each period - sync data
 */

/**
 * Only succefull operations
 *
 * database.collections.CRUD (project=x)
 * database.documents.CRUD (project=x,collection=y)
 *
 * users.CRUD
 * users.sessions.create (project=x,provider=y)
 * users.sessions.delete (project=x,provider=y)
 *
 * storage.buckets.CRUD (project=x)
 * storage.files.CRUD (project=x,bucket=y)
 *
 * refactor later
 * - functions
 * - realtime
 * - teams
 * - webhooks
 * - keys - really later!
 */

$cli
    ->task('usage')
    ->desc('Schedules syncing data from influxdb to Appwrite console db')
    ->action(function () use ($register) {
        Console::title('Usage Sync V1');
        Console::success(APP_NAME . ' usage sync process v1 has started');

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

        //use projectId from influxdb instead of iterating over projects from DB

        $globalMetrics = [
            'requests' => [
                'method' => 'getGlobalMetrics',
                'table' => 'appwrite_usage_requests_all',
            ],
            'network' => [
                'method' => 'getGlobalMetrics',
                'table' => 'appwrite_usage_network_all',
            ],
            'executions' => [
                'method' => 'getGlobalMetrics',
                'table' => 'appwrite_usage_executions_all',
            ],
            'database.collections.create' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_collections_create',
            ],
            'database.collections.read' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_collections_read',
            ],
            'database.collections.update' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_collections_update',
            ],
            'database.collections.delete' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_collections_delete',
            ],
            'database.documents.create' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_documents_create',
            ],
            'database.documents.read' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_documents_read',
            ],
            'database.documents.update' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_documents_update',
            ],
            'database.documents.delete' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_documents_delete',
            ],
            'database.documents.collectionId.create' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_documents_create',
                'groupBy' => 'collectionId',
            ],
            'database.documents.collectionId.read' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_documents_read',
                'groupBy' => 'collectionId',
            ],
            'database.documents.collectionId.update' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_documents_update',
                'groupBy' => 'collectionId',
            ],
            'database.documents.collectionId.delete' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_database_documents_delete',
                'groupBy' => 'collectionId',
            ],
            'storage.buckets.bucketId.files.create' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_storage_files_create',
                'groupBy' => 'bucketId',
            ],
            'storage.buckets.bucketId.files.read' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_storage_files_read',
                'groupBy' => 'bucketId',
            ],
            'storage.buckets.bucketId.files.update' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_storage_files_update',
                'groupBy' => 'bucketId',
            ],
            'storage.buckets.bucketId.files.delete' => [
                'method' => 'getDatabaseMetrics',
                'table' => 'appwrite_usage_storage_files_delete',
                'groupBy' => 'bucketId',
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
        $dbForConsole = new Database(new MariaDB($db), $cacheAdapter);
        $dbForConsole->setNamespace('project_console_internal');
        $dbForProject = new Database(new MariaDB($db), $cacheAdapter);

        Authorization::disable();
        $latestData = [];

        $firstRun = true;
        Console::loop(function () use ($interval, $register, &$latestData, $dbForProject, $dbForConsole, &$firstRun, $globalMetrics, $periods) {
            $time = date('d-m-Y H:i:s', time());
            Console::info("[{$time}] Aggregating usage data every {$interval} seconds");

            $client = $register->get('influxdb');
            if ($client) {
                $database = $client->selectDB('telegraf');
                // sync data
                foreach ($globalMetrics as $metric => $options) {
                    foreach ($periods as $period) {
                        $start = DateTime::createFromFormat('U', \strtotime($period['startTime']))->format(DateTime::RFC3339);
                        $end = DateTime::createFromFormat('U', \strtotime('now'))->format(DateTime::RFC3339);

                        $table = $options['table'];
                        $groupBy = $options['groupBy'] ?? '';

                        $query = 'SELECT sum(value) AS "value" FROM "' . $table . '" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' GROUP BY time(' . $period['key'] . '), "projectId"' . (empty($groupBy) ? '' : ', "' . $groupBy . '"') . ' FILL(null)';
                        $result = $database->query($query);
                        $points = $result->getPoints();
                        foreach ($points as $point) {
                            $projectId = $point['projectId'];
                            if (!empty($projectId) && $projectId != 'console') {
                                $dbForProject->setNamespace('project_' . $projectId . '_internal');
                                if (!empty($groupBy)) {
                                    $groupedBy = $point[$groupBy];
                                    if (empty($groupedBy)) {
                                        continue;
                                    }
                                    $metric = str_replace($groupBy, $groupedBy, $metric);
                                }
                                $time = \strtotime($point['time']);
                                $id = \md5($time . '_' . $period['key'] . '_' . $metric);
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
                                    $latestData[$projectId][$metric][$period['key']] = $time;
                                } catch (\Exception$e) {
                                    Console::warning("Failed to save data for project {$projectId} and metric {$metric}");
                                }
                            }
                        }
                    }
                }
            }
            $firstRun = false;
        }, $interval);
    });
