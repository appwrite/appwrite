<?php

global $cli;

require_once __DIR__ . '/../init.php';

use Utopia\App;
use Utopia\CLI\Console;

$cli
    ->task('usage')
    ->desc('Schedules syncing data from influxdb to Appwrite console db')
    ->action(function () use ($register, $dbForConsole) {
        Console::title('Usage Sync V1');
        Console::success(APP_NAME . ' usage sync process v1 has started');

        $interval = (int) App::getEnv('_APP_USAGE_SYNC_INTERVAL', '10'); //10 seconds

        // Console::loop(function () use ($interval, $register, $dbForConsole) {
        $time = date('d-m-Y H:i:s', time());
        Console::info("[{$time}] Syncing usage data from influxdb to Appwrite Console DB every {$interval} seconds");

        $client = $register->get('influxdb');

        $client = $register->get('influxdb');

        $requests = [];
        $network = [];
        $functions = [];

        if ($client) {
            // $start30 = DateTime::createFromFormat('U', \strtotime('-30 minutes'))->format(DateTime::RFC3339);
            $start = DateTime::createFromFormat('U', \strtotime('-30 days'))->format(DateTime::RFC3339);
            $end = DateTime::createFromFormat('U', \strtotime('now'))->format(DateTime::RFC3339);
            $database = $client->selectDB('telegraf');

            // Requests
            $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_requests_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' GROUP BY time(' . '1d' . ') FILL(null)');
            $points = $result->getPoints();

            foreach ($points as $point) {
                $requests[] = [
                    'value' => (!empty($point['value'])) ? $point['value'] : 0,
                    'date' => \strtotime($point['time']),
                ];
            }

            // Network
            $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_network_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' GROUP BY time(' . '1d' . ') FILL(null)');
            $points = $result->getPoints();

            foreach ($points as $point) {
                $network[] = [
                    'value' => (!empty($point['value'])) ? $point['value'] : 0,
                    'date' => \strtotime($point['time']),
                ];
            }

            // Functions
            $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_executions_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' GROUP BY time(' . '1d' . ') FILL(null)');
            $points = $result->getPoints();

            foreach ($points as $point) {
                $functions[] = [
                    'value' => (!empty($point['value'])) ? $point['value'] : 0,
                    'date' => \strtotime($point['time']),
                ];
            }

            var_dump($requests, $network, $functions);
        }

        // }, $interval);
    });
