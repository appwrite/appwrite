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
$periods['inf'] = '0000-00-00 00:00';

$server->job()
    ->inject('message')
    ->action(function (Message $message) use (&$stats) {
        $payload = $message->getPayload() ?? [];
        $project = new Document($payload['project'] ?? []);

        foreach ($payload['metrics'] ?? [] as $metric) {
            $uniq = md5($metric['key']);

            if (!isset($stats[$uniq])) {
                $stats[$uniq] = [
                    'projectInternalId' => $project->getInternalId(),
                    'database' => $project->getAttribute('database'),
                    'key' => $metric['key'],
                    'value' => $metric['value']
                ];

                continue;
            }
            $stats[$uniq]['value'] += $metric['value'];
        }
    });

$server
    ->workerStart()
    ->inject('register')
    ->inject('cache')
    ->inject('pools')
    ->action(function ($register, $cache, $pools) use ($periods, &$stats) {
        Timer::tick(30000, function () use ($register, $cache, $pools, $periods, &$stats) {
            $slice = array_slice($stats, 0, count($stats));
            array_splice($stats, 0, count($stats));
            $log = [];
            foreach ($slice as $metric) {
                $dbForProject = new Database(
                    $pools
                        ->get($metric['database'])
                        ->pop()
                        ->getResource(),
                    $cache
                );
                $dbForProject->setNamespace('_' . $metric['projectInternalId']);
                foreach ($periods as $period => $format) {
                    $time = 'inf' ===  $period ? null : date($format, time());
                    $id = \md5("{$time}_{$period}_{$metric['key']}");
                    try {
                        try {
                            $dbForProject->createDocument('stats', new Document([
                                '$id'    => $id,
                                'period' => $period,
                                'time'   => $time,
                                'metric' => $metric['key'],
                                'value'  => $metric['value'],
                                'region' => App::getEnv('_APP_REGION', 'default'),
                            ]));
                        } catch (Duplicate $th) {
                            $dbForProject->increaseDocumentAttribute(
                                'stats',
                                $id,
                                'value',
                                $metric['value']
                            );
                        }

                        $log[] = [
                            'id'     => $id,
                            'period' => $period,
                            'time'   => $time,
                            'metric' => $metric['key'],
                            'value'  => $metric['value'],
                            'region' => App::getEnv('_APP_REGION', 'default'),
                        ];
                    } catch (\Exception $e) {
                        console::error($e->getMessage());
                    } finally {
                        $pools->reclaim();
                    }
                }

                if (!empty($log)) {
                    $dbForProject->createDocument('statsLogger', new Document([
                        'time'    => DateTime::now(),
                        'metrics' => $log,
                    ]));
                }
            }
        });
    });
$server->start();
