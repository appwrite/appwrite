<?php

global $cli, $register;

use Appwrite\Stats\Usage;
use Appwrite\Stats\UsageDB;
use InfluxDB\Database as InfluxDatabase;
use Utopia\App;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Registry\Registry;

function getDatabase(Registry&$register, string $namespace): Database
{
    $attempts = 0;

    do {
        try {
            $attempts++;

            $db = $register->get('db');
            $redis = $register->get('cache');

            $cache = new Cache(new RedisCache($redis));
            $database = new Database(new MariaDB($db), $cache);
            $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
            $database->setNamespace($namespace);

            if (!$database->exists($database->getDefaultDatabase(), 'realtime')) {
                throw new Exception('Collection not ready');
            }
            break; // leave loop if successful
        } catch (\Exception$e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                throw new \Exception('Failed to connect to database: ' . $e->getMessage());
            }
            sleep(DATABASE_RECONNECT_SLEEP);
        }
    } while ($attempts < DATABASE_RECONNECT_MAX_ATTEMPTS);

    return $database;
}

function getInfluxDB(Registry&$register): InfluxDatabase
{
    /** @var InfluxDB\Client $client */
    $client = $register->get('influxdb');
    $attempts = 0;
    $max = 10;
    $sleep = 1;

    do { // check if telegraf database is ready
        try {
            $attempts++;
            $database = $client->selectDB('telegraf');
            if (in_array('telegraf', $client->listDatabases())) {
                break; // leave the do-while if successful
            }
        } catch (\Throwable$th) {
            Console::warning("InfluxDB not ready. Retrying connection ({$attempts})...");
            if ($attempts >= $max) {
                throw new \Exception('InfluxDB database not ready yet');
            }
            sleep($sleep);
        }
    } while ($attempts < $max);
    return $database;
}

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

        $database = getDatabase($register, '_console');
        $influxDB = getInfluxDB($register);

        $usage = new Usage($database, $influxDB);
        $usageDB = new UsageDB($database);

        $latestTime = [];

        Authorization::disable();

        $iterations = 0;
        Console::loop(function () use ($interval, $database, $usage, $usageDB, &$latestTime, &$iterations) {
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Aggregating usage data every {$interval} seconds");

            $loopStart = microtime(true);

            /**
         * Aggregate InfluxDB every 30 seconds
         */

            // sync data
            foreach ($usage->getMetrics() as $metric => $options) { //for each metrics
                foreach ($usage->getPeriods() as $period) { // aggregate data for each period
                    try {
                        $usage->syncFromInfluxDB($metric, $options, $period, $latestTime);
                    } catch (\Exception$e) {
                        Console::warning("Failed: {$e->getMessage()}");
                        Console::warning($e->getTraceAsString());
                    }
                }
            }

            if ($iterations % 30 != 0) { // Aggregate aggregate number of objects in database only after 15 minutes
                $iterations++;
                $loopTook = microtime(true) - $loopStart;
                $now = date('d-m-Y H:i:s', time());
                Console::info("[{$now}] Aggregation took {$loopTook} seconds");
                return;
            }

            /**
         * Aggregate MariaDB every 15 minutes
         * Some of the queries here might contain full-table scans.
         */
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Aggregating database counters.");
            $usageDB->foreachDocument('console', 'projects', [], function ($project) use ($usageDB) {
                $projectId = $project->getId();

                // Get total storage of deployments
                try {
                    $deploymentsTotal = $usageDB->sum($projectId, 'deployments', 'size', 'storage.deployments.total');
                } catch (\Exception$e) {
                    Console::warning("Failed to save data for project {$projectId} and metric storage.deployments.total: {$e->getMessage()}");
                    Console::warning($e->getTraceAsString());
                }

                foreach ($usageDB->getCollections() as $collection => $options) {
                    try {

                        $metricPrefix = $options['metricPrefix'] ?? '';
                        $metric = empty($metricPrefix) ? "{$collection}.count" : "{$metricPrefix}.{$collection}.count";
                        $usageDB->count($projectId, $collection, $metric);

                        $subCollections = $options['subCollections'] ?? [];

                        if (empty($subCollections)) {
                            continue;
                        }

                        $subCollectionCounts = []; //total project level count of sub collections
                        $subCollectionTotals = []; //total project level sum of sub collections

                        $usageDB->foreachDocument($projectId, $collection, [], function ($parent) use (&$subCollectionCounts, &$subCollectionTotals, $subCollections, $projectId, $usageDB, $collection) {
                            foreach ($subCollections as $subCollection => $subOptions) { // Sub collection counts, like database.collections.collectionId.documents.count

                                $metric = empty($metricPrefix) ? "{$collection}.{$parent->getId()}.{$subCollection}.count" : "{$metricPrefix}.{$collection}.{$parent->getInternalId()}.{$subCollection}.count";

                                $count = $usageDB->count($projectId, ($subOptions['collectionPrefix'] ?? '') . $parent->getInternalId(), $metric);

                                $subCollectionCounts[$subCollection] = ($subCollectionCounts[$subCollection] ?? 0) + $count; // Project level counts for sub collections like database.documents.count

                                // check if sum calculation is required
                                $total = $subOptions['total'] ?? [];
                                if (empty($total)) {
                                    continue;
                                }

                                $metric = empty($metricPrefix) ? "{$collection}.{$parent->getId()}.{$subCollection}.total" : "{$metricPrefix}.{$collection}.{$parent->getInternalId()}.{$subCollection}.total";
                                $total = $usageDB->sum($projectId, ($subOptions['collectionPrefix'] ?? '') . $parent->getInternalId(), $total['field'], $metric);

                                $subCollectionTotals[$subCollection] = ($subCollectionTotals[$subCollection] ?? 0) + $total; // Project level sum for sub collections like storage.total
                            }
                        });

                        /**
                     * Inserting project level counts for sub collections like database.documents.count
                     */
                        foreach ($subCollectionCounts as $subCollection => $count) {

                            $metric = empty($metricPrefix) ? "{$subCollection}.count" : "{$metricPrefix}.{$subCollection}.count";

                            $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
                            $usageDB->createOrUpdateMetric($projectId, $time, '30m', $metric, $count, 1);

                            $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
                            $usageDB->createOrUpdateMetric($projectId, $time, '1d', $metric, $count, 1);
                        }

                        /**
                     * Inserting project level sums for sub collections like storage.files.total
                     */
                        foreach ($subCollectionTotals as $subCollection => $count) {
                            $metric = empty($metricPrefix) ? "{$subCollection}.total" : "{$metricPrefix}.{$subCollection}.total";

                            $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
                            $usageDB->createOrUpdateMetric($projectId, $time, '30m', $metric, $count, 1);

                            $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
                            $usageDB->createOrUpdateMetric($projectId, $time, '1d', $metric, $count, 1);

                            // aggregate storage.total = storage.files.total + storage.deployments.total
                            if ($metricPrefix === 'storage' && $subCollection === 'files') {
                                $metric = 'storage.total';

                                $time = (int) (floor(time() / 1800) * 1800); // Time rounded to nearest 30 minutes
                                $usageDB->createOrUpdateMetric($projectId, $time, '30m', $metric, $count + $deploymentsTotal, 1);

                                $time = (int) (floor(time() / 86400) * 86400); // Time rounded to nearest day
                                $usageDB->createOrUpdateMetric($projectId, $time, '1d', $metric, $count + $deploymentsTotal, 1);
                            }
                        }
                    } catch (\Exception$e) {
                        Console::warning("Failed: {$e->getMessage()}");
                        Console::warning($e->getTraceAsString());
                    }
                }
            });

            $iterations++;
            $loopTook = microtime(true) - $loopStart;
            $now = date('d-m-Y H:i:s', time());

            Console::info("[{$now}] Aggregation took {$loopTook} seconds");
        }, $interval);
    });
