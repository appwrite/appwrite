<?php

require_once __DIR__ . '/../worker.php';

use Swoole\Timer;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\CLI\Console;

Authorization::disable();
Authorization::setDefaultStatus(false);

$stats = [];

$periods['1h']  = 'Y-m-d H:00';
$periods['1d']  = 'Y-m-d 00:00';
//$periods['1m']  = 'Y-m-1 00:00';
$periods['inf'] = '0000-00-00 00:00';

$server->job()
    ->inject('message')
    ->action(function (Message $message) use (&$stats) {

        $payload = $message->getPayload() ?? [];
        $project = new Document($payload['project'] ?? []);
        $projectId = $project->getInternalId();
        $stats[$projectId]['database'] = $project->getAttribute('database');

        foreach ($payload['metrics'] ?? [] as $metric) {
            if (!isset($stats[$projectId]['keys'][$metric['key']])) {
                $stats[$projectId]['keys'][$metric['key']] = $metric['value'];
                continue;
            }
            $stats[$projectId]['keys'][$metric['key']] += $metric['value'];
        }
    });

$server
    ->workerStart()
    ->inject('register')
    ->inject('cache')
    ->inject('pools')
    ->action(function ($register, $cache, $pools) use ($periods, &$stats) {
        Timer::tick(30000, function () use ($register, $cache, $pools, $periods, &$stats) {

            $offset = count($stats);
            $projects = array_slice($stats, 0, $offset, true);
            array_splice($stats, 0, $offset);

            foreach ($projects as $projectInternalId => $project) {
                try {
                    $dbForProject = new Database(
                        $pools
                            ->get($project['database'])
                            ->pop()
                            ->getResource(),
                        $cache
                    );

                    $dbForProject->setNamespace('_' . $projectInternalId);

                    foreach ($project['keys'] as $key => $value) {
                        if ($value == 0) {
                            continue;
                        }

                        foreach ($periods as $period => $format) {
                            $time = 'inf' === $period ? null : date($format, time());
                            $id = \md5("{$time}_{$period}_{$key}");

                            try {
                                $dbForProject->createDocument('stats', new Document([
                                    '$id' => $id,
                                    'period' => $period,
                                    'time' => $time,
                                    'metric' => $key,
                                    'value' => $value,
                                    'region' => App::getEnv('_APP_REGION', 'default'),
                                ]));
                            } catch (Duplicate $th) {
                                if ($value < 0) {
                                    $dbForProject->decreaseDocumentAttribute(
                                        'stats',
                                        $id,
                                        'value',
                                        abs($value)
                                    );
                                } else {
                                    $dbForProject->increaseDocumentAttribute(
                                        'stats',
                                        $id,
                                        'value',
                                        $value
                                    );
                                }
                            }
                        }
                    }
                    $dbForProject->createDocument('statsLogger', new Document([
                        'time'    => DateTime::now(),
                        'metrics' => $project['keys'],
                    ]));
                } catch (\Exception $e) {
                    console::error($e->getMessage());
                } finally {
                    $pools->reclaim();
                }
            }
        });
    });
$server->start();
