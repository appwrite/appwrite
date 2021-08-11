<?php

global $cli, $register;

require_once __DIR__ . '/../workers.php';

use Utopia\App;
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

        $cacheAdapter = new Cache(new Redis($register->get('cache')));
        $dbForConsole = new Database(new MariaDB($register->get('db')), $cacheAdapter);
        $dbForConsole->setNamespace('project_console_internal');

        Authorization::disable();
        $projects = $dbForConsole->find('projects');
        $projectIds = [];
        foreach ($projects as $project) {
            $projectIds[] = $project['$id'];
        }
        $projects = null;

        $latestData = [];
        $dbForProject = new Database(new MariaDB($register->get('db')), $cacheAdapter);
        foreach ($projectIds as $id) {
            $dbForProject->setNamespace("project_{$id}_internal");
            $doc = $dbForProject->find('stats', [new Query("period", Query::TYPE_EQUAL, ["1d"])], 1, 0, ['time'], [Database::ORDER_DESC]);
            $doc = reset($doc);
            $latestData[$id]["1d"] = $doc ? $doc->getAttribute('time') : null;
            $doc = $dbForProject->find('stats', [new Query("period", Query::TYPE_EQUAL, ["30m"])], 1, 0, ['time'], [Database::ORDER_DESC]);
            $doc = reset($doc);
            $latestData[$id]["30m"] = $doc ? $doc->getAttribute('time') : null;
        }

        $firstRun = true;
        Console::loop(function () use ($interval, $register, $projectIds, $latestData, $dbForProject, &$firstRun) {
            $time = date('d-m-Y H:i:s', time());
            Console::info("[{$time}] Syncing usage data from influxdb to Appwrite Console DB every {$interval} seconds");

            $client = $register->get('influxdb');

            $requests = [];
            $network = [];
            $functions = [];

            if ($client) {
                foreach ($projectIds as $id) {
                    if ($firstRun) {
                        $start = DateTime::createFromFormat('U', \strtotime('-1 days'))->format(DateTime::RFC3339);
                        if (!empty($latestData[$id]["30m"])) {
                            $start = DateTime::createFromFormat('U', $latestData[$id]['30m'])->format(DateTime::RFC3339);
                        }
                    } else {
                        $start = DateTime::createFromFormat('U', \strtotime("-{$interval} seconds"))->format(DateTime::RFC3339);
                    }
                    $end = DateTime::createFromFormat('U', \strtotime('now'))->format(DateTime::RFC3339);
                    $database = $client->selectDB('telegraf');

                    // Requests
                    $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_requests_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' AND "project"=\'' . $id . '\' GROUP BY time(' . '30m' . ') FILL(null)');
                    $points = $result->getPoints();

                    $dbForProject->setNamespace("project_{$id}_internal");
                    foreach ($points as $point) {
                        $requests[] = [
                            'value' => (!empty($point['value'])) ? $point['value'] : 0,
                            'date' => \strtotime($point['time']),
                            'dateStr' => $point['time'],
                            'point' => $point,
                        ];
                        $time = \strtotime($point['time']);
                        $id = \md5($time . '_' . '30m' . '_requests');
                        $value = (!empty($point['value'])) ? $point['value'] : 0;
                        $document = $dbForProject->getDocument('stats', $id);
                        if ($document->isEmpty()) {
                            $dbForProject->createDocument('stats', new Document([
                                '$id' => $id,
                                'period' => '30m',
                                'time' => $time,
                                'metric' => 'requests',
                                'value' => $value,
                                'type' => 0,
                            ]));
                        } else {
                            $dbForProject->updateDocument('stats', $document->getId(),
                                $document->setAttribute('value', $firstRun ? $value : $document->getAttribute('value') + $value));
                        }

                    }

                    // Network
                    $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_network_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' AND "project"=\'' . $id . '\'GROUP BY time(' . '30m' . ') FILL(null)');
                    $points = $result->getPoints();

                    foreach ($points as $point) {
                        $network[] = [
                            'value' => (!empty($point['value'])) ? $point['value'] : 0,
                            'date' => \strtotime($point['time']),
                            'dateStr' => $point['time'],
                            'point' => $point,
                        ];
                        $time = \strtotime($point['time']);
                        $id = \md5($time . '_' . '30m' . '_network');
                        $value = (!empty($point['value'])) ? $point['value'] : 0;
                        $document = $dbForProject->getDocument('stats', $id);
                        if ($document->isEmpty()) {
                            $dbForProject->createDocument('stats', new Document([
                                '$id' => $id,
                                'period' => '30m',
                                'time' => $time,
                                'metric' => 'network',
                                'value' => $value,
                                'type' => 0,
                            ]));
                        } else {
                            $dbForProject->updateDocument('stats', $document->getId(),
                            $document->setAttribute('value', $firstRun ? $value : $document->getAttribute('value') + $value));
                        }
                    }

                    // Functions
                    $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_executions_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' AND "project"=\'' . $id . '\'GROUP BY time(' . '30m' . ') FILL(null)');
                    $points = $result->getPoints();

                    foreach ($points as $point) {
                        $functions[] = [
                            'value' => (!empty($point['value'])) ? $point['value'] : 0,
                            'date' => \strtotime($point['time']),
                            'dateStr' => $point['time'],
                            'point' => $point,
                        ];
                        $time = \strtotime($point['time']);
                        $id = \md5($time . '_' . '30m' . '_functions');
                        $value = (!empty($point['value'])) ? $point['value'] : 0;
                        $document = $dbForProject->getDocument('stats', $id);
                        if ($document->isEmpty()) {
                            $dbForProject->createDocument('stats', new Document([
                                '$id' => $id,
                                'period' => '30m',
                                'time' => $time,
                                'metric' => 'functions',
                                'value' => $value,
                                'type' => 0,
                            ]));
                        } else {
                            $dbForProject->updateDocument('stats', $document->getId(),
                            $document->setAttribute('value', $firstRun ? $value : $document->getAttribute('value') + $value));
                        }
                    }

                }
            }
            $firstRun = false;
        }, $interval);
    });
