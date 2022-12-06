<?php

require_once __DIR__ . '/../worker.php';

use Swoole\Table;
use Swoole\Timer;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\CLI\Console;

$stats = [];

$periods['1h'] = 'Y-m-d H:00';
$periods['1d'] =  'Y-m-d 00:00';
$periods['inf'] =  'Y-m-d 00:00';

//$stats = new Table(10000, 1);
//$stats->column('namespace', Table::TYPE_STRING, 64);
//$stats->column('key', Table::TYPE_STRING, 64);
//$stats->column('value', Table::TYPE_INT);
//$stats->create();

$server->job()
    ->inject('message')
    ->action(function (Message $message) use (&$stats) {
        $payload = $message->getPayload() ?? [];
        $project = new Document($payload['project'] ?? []);

        foreach ($payload['metrics'] ?? [] as $metric) {
            $uniq = md5($metric['namespace'] . $metric['key']);

//            if ($stats->exists($uniq)) {
//                $stats->incr($uniq, 'value', $metric['value']);
//                continue;
//            }
//
//            $stats->set($uniq, [
//                'projectInternalId' => $project->getInternalId(),
//                'database' => $project->getAttribute('database'),
//                'key' => $metric['key'],
//                'value' => $metric['value'],
//                ]);
//

            if (!isset($stats[$uniq])) {
                $stats[$uniq] = [
                    'projectInternalId' => $project->getInternalId(),
                    'database' => $project->getAttribute('database'),
                    'key' => $metric['namespace'] . '.' . $metric['key'],
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

                    foreach ($slice as $metric) {
                        foreach ($periods as $period => $format) {
                             $time = date($format, time());
                             $id = \md5("{$time}_{$period}_{$metric['key']}");

                             $adapter = new Database(
                                 $pools
                                 ->get($metric['database'])
                                 ->pop()
                                 ->getResource(),
                                 $cache
                             );

                             $adapter->setNamespace('_' . $metric['projectInternalId']);

                            try {
                                $document = Authorization::skip(fn() =>$adapter->getDocument('stats', $id));
                                if ($document->isEmpty()) {
                                    console::log("{$period}, {$time}, {$metric['key']}={$metric['value']}");
                                    Authorization::skip(fn() => $adapter->createDocument('stats', new Document([
                                        '$id' => $id,
                                        'period' => $period,
                                        'time' => $time,
                                        'metric' => $metric['key'],
                                        'value' => $metric['value'],
                                        'type' => 0,
                                        'region' => 'default',
                                    ])));
                                } else {
                                    $value = $document->getAttribute('value') + $metric['value'];
                                    console::info("{$document->getAttribute('period')}, {$document->getAttribute('time')}, {$document->getAttribute('metric')} = {$value}");
                                    Authorization::skip(fn() => $adapter->updateDocument('stats', $document->getId(), $document->setAttribute('value', $value)));
                                }
                            } catch (\Exception $e) {
                                console::error($e->getMessage());
                            }
                            $pools->reclaim();
                        }
                    }
                });
    });

$server->start();
