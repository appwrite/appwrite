<?php

global $cli, $register;

require_once __DIR__ . '/../init.php';

use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

$cli
    ->task('usage')
    ->desc('Schedules syncing data from influxdb to Appwrite console db')
    ->action(function () use ($register) {
        Console::title('Usage Sync V1');
        Console::success(APP_NAME . ' usage sync process v1 has started');

        $interval = (int) App::getEnv('_APP_USAGE_SYNC_INTERVAL', '30'); //30 seconds
        $attempts = 0;
        $max = 10;
        $sleep = 1;
        do {
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

        $cacheAdapter = new Cache(new None());
        $dbForConsole = new Database(new MariaDB($db), $cacheAdapter);
        $dbForConsole->setNamespace('project_console_internal');
        $dbForProject = new Database(new MariaDB($db), $cacheAdapter);

        Authorization::disable();
        $projectIds = [];
        $latestProject = null;
        $latestData = [];
        do {
            $projects = $dbForConsole->find('projects', [], 100, orderAfter:$latestProject);
            if (!empty($projects)) {
                $latestProject = $projects[array_key_last($projects)];
                $latestData = getLatestData($projects, $latestData, $dbForProject, $projectIds);
            }
        } while (!empty($projects));

        $projects = null;

        $firstRun = true;
        Console::loop(function () use ($interval, $register, &$projectIds, &$latestData, $dbForProject, $dbForConsole, &$firstRun, &$latestProject) {
            $time = date('d-m-Y H:i:s', time());
            Console::info("[{$time}] Syncing usage data from influxdb to Appwrite Console DB every {$interval} seconds");

            if (!$firstRun) {
                $projects = $dbForConsole->find('projects', limit:100, orderAfter:$latestProject);
                if (!empty($projects)) {
                    $latestProject = $projects[array_key_last($projects)];
                    $latestData = getLatestData($projects, $latestData, $dbForProject, $projectIds);
                }
            }

            $client = $register->get('influxdb');
            if ($client) {
                foreach ($projectIds as $id => $value) {
                    syncData($client, $id, $latestData, $dbForProject);
                }
            }
            $firstRun = false;
        }, $interval);
    });

function getLatestData(&$projects, &$latestData, $dbForProject, &$projectIds)
{
    foreach ($projects as $project) {
        $id = $project->getId();
        $projectIds[$id] = true;
        $dbForProject->setNamespace("project_{$id}_internal");
        foreach (['requests', 'network', 'executions', 'database.reads', 'database.creates', 'database.updates', 'database.deletes'] as $metric) {
            $doc = $dbForProject->findOne('stats', [new Query("period", Query::TYPE_EQUAL, ["1d"]), new Query("metric", Query::TYPE_EQUAL, [$metric])], 0, ['time'], [Database::ORDER_DESC]);
            $latestData[$id][$metric]["1d"] = $doc ? $doc->getAttribute('time') : null;
            $doc = $dbForProject->findOne('stats', [new Query("period", Query::TYPE_EQUAL, ["30m"])], 0, ['time'], [Database::ORDER_DESC]);
            $latestData[$id][$metric]["30m"] = $doc ? $doc->getAttribute('time') : null;
        }
    }
    $projects = null;
    return $latestData;
}

function syncData($client, $projectId, &$latestData, $dbForProject)
{
    foreach (['30m', '1d'] as $period) {
        $start = DateTime::createFromFormat('U', \strtotime($period == '1d' ? '-90 days' : '-24 hours'))->format(DateTime::RFC3339);
        $end = DateTime::createFromFormat('U', \strtotime('now'))->format(DateTime::RFC3339);
        $database = $client->selectDB('telegraf');
        $dbForProject->setNamespace("project_{$projectId}_internal");
        foreach (['requests', 'network', 'executions'] as $metric) {
            if (!empty($latestData[$projectId][$metric][$period])) {
                $start = DateTime::createFromFormat('U', $latestData[$projectId][$metric][$period])->format(DateTime::RFC3339);
            }
            syncMetric($database, $projectId, $period, 'requests', $start, $end, $dbForProject);
        }
        // syncMetric($database, $projectId, $period, 'network', $start, $end, $dbForProject);
        // syncMetric($database, $projectId, $period, 'executions', $start, $end, $dbForProject);
        syncMetricPaths($database, $projectId, $period, $start, $end, $dbForProject);
    }

}

function syncMetricPaths($database, $projectId, $period, $start, $end, $dbForProject)
{
    $start = DateTime::createFromFormat('U', \strtotime($period == '1d' ? '-7 days' : '-24 hours'))->format(DateTime::RFC3339);
    if (!empty($latestData[$projectId]['database'][$period])) {
        $start = DateTime::createFromFormat('U', $latestData[$projectId]['database'][$period])->format(DateTime::RFC3339);
    }
    $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_requests_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' AND "project"=\'' . $projectId . '\'GROUP BY time(' . $period . '), "path", "method" FILL(null)');
    $points = $result->getPoints();

    $databaseMetrics = [
        'creates' => [
            'method' => 'post',
            'paths' => [
                '/v1/database/collections' => true,
                '/v1/database/collections/*collectionId/attributes/string' => true,
                '/v1/database/collections/*collectionId/attributes/email' => true,
                '/v1/database/collections/*collectionId/attributes/ip' => true,
                '/v1/database/collections/*collectionId/attributes/url' => true,
                '/v1/database/collections/*collectionId/attributes/integer' => true,
                '/v1/database/collections/*collectionId/attributes/float' => true,
                '/v1/database/collections/*collectionId/attributes/boolean' => true,
                '/v1/database/collections/*collectionId/indexes' => true,
                '/v1/database/collections/*collectionId/documents' => true,
            ],
        ],
        'reads' => [
            'method' => 'get',
            'paths' => [
                '/v1/database/collections' => true,
                '/v1/database/collections/*collectionId' => true,
                '/v1/database/collections/*collectionId/attributes' => true,
                '/v1/database/collections/*collectionId/attributes/*attributeId' => true,
                '/v1/database/collections/*collectionId/indexes' => true,
                '/v1/database/collections/*collectionId/indexes/*indexId' => true,
                '/v1/database/collections/*collectionId/documents' => true,
                '/v1/database/collections/*collectionId/documents/*documentId' => true,
            ],
        ],
        'updates' => [
            'method' => 'put',
            'paths' => [
                '/v1/database/collections/*collectionId' => true,
                '/v1/database/collections/*collectionId/documents/*documentId' => true,
            ],
        ],
        'deletes' => [
            'method' => 'delete',
            'paths' => [
                '/v1/database/collections/*collectionId' => true,
                '/v1/database/collections/*collectionId/attributes/*attributeId' => true,
                '/v1/database/collections/*collectionId/indexes/*indexId' => true,
                '/v1/database/collections/*collectionId/documents/*documentId' => true,
            ],
        ],
    ];

    $dbStats = [];
    foreach ($points as $point) {
        $time = \strtotime($point['time']);
        $value = (!empty($point['value'])) ? $point['value'] : 0;
        $path = $point['path'] ?? '';
        $method = $point['method'] ?? '';

        foreach (['creates', 'reads', 'updates', 'deletes'] as $operation) {
            if ($method == $databaseMetrics[$operation]['method']
                && array_key_exists($path, $databaseMetrics[$operation]['paths'])) {
                if (empty($dbStats["database.{$operation}"][$period][$time])) {
                    $dbStats["database.{$operation}"][$period][$time] = 0;
                }
                $dbStats["database.{$operation}"][$period][$time] += $value;
            }
        }
    }

    $time = \strtotime($start);
    foreach ($dbStats as $metric => $stats) {
        foreach ($stats as $period => $times) {
            foreach ($times as $time => $value) {
                $id = \md5($time . '_' . $period . '_' . $metric);
                $document = $dbForProject->getDocument('stats', $id);
                if ($document->isEmpty()) {
                    $dbForProject->createDocument('stats', new Document([
                        '$id' => $id,
                        'period' => $period,
                        'time' => $time,
                        'metric' => $metric,
                        'value' => $value,
                        'type' => 0,
                    ]));
                } else {
                    $dbForProject->updateDocument('stats', $document->getId(),
                        $document->setAttribute('value', $value));
                }
            }
        }
    }
    $latestData[$projectId]['database'][$period] = $time;
}

function syncMetric($database, $projectId, $period, $metric, $start, $end, $dbForProject)
{
    $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_' . $metric . '_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' AND "project"=\'' . $projectId . '\'GROUP BY time(' . $period . ') FILL(null)');
    $points = $result->getPoints();

    foreach ($points as $point) {
        $time = \strtotime($point['time']);
        $id = \md5($time . '_' . $period . '_' . $metric);
        $value = (!empty($point['value'])) ? $point['value'] : 0;
        $document = $dbForProject->getDocument('stats', $id);
        if ($document->isEmpty()) {
            $dbForProject->createDocument('stats', new Document([
                '$id' => $id,
                'period' => $period,
                'time' => $time,
                'metric' => $metric,
                'value' => $value,
                'type' => 0,
            ]));
        } else {
            $dbForProject->updateDocument('stats', $document->getId(),
                $document->setAttribute('value', $value));
        }
        $latestData[$projectId][$metric][$period] = $time;
    }
}
