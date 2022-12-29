<?php

require_once __DIR__ . '/../worker.php';

use Swoole\Timer;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\CLI\Console;
use Utopia\Queue\Server;
use Utopia\Registry\Registry;

Authorization::disable();
Authorization::setDefaultStatus(false);

$stats = [];

$periods['1h']  = 'Y-m-d H:00';
$periods['1d']  = 'Y-m-d 00:00';
//$periods['1m']  = 'Y-m-1 00:00';
$periods['inf'] = '0000-00-00 00:00';

/**
 * On Documents that tied by relations like functions>deployments>build || documents>collection>database || buckets>files
 * When we remove a parent document we need to deduct his children aggregation from the project scope
 */
Server::setResource('reduce', function (Cache $cache, Registry $register, $pools) {
    return function ($database, $projectInternalId, Document $document, array &$metrics) use ($pools, $cache, $register): void {
        try {
            $dbForProject = new Database(
                $pools
                    ->get($database)
                    ->pop()
                    ->getResource(),
                $cache
            );

            $dbForProject->setNamespace('_' . $projectInternalId);

            switch (true) {
                case $document->getCollection() === 'users': // users
                    $sessions = count($document->getAttribute('sessions', 0));
                    if (!empty($sessions)) {
                        $metrics[] = [
                            'key' => 'sessions',
                            'value' => ($sessions * -1),
                        ];
                    }
                    break;
                case $document->getCollection() === 'databases': // databases
                    $collections = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getInternalId()}" . ".collections"));
                    $documents = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getInternalId()}" . ".documents"));
                    if (!empty($collections['value'])) {
                        $metrics[] = [
                            'key' => 'collections',
                            'value' => ($collections['value'] * -1),
                        ];
                    }
                    if (!empty($documents['value'])) {
                        $metrics[] = [
                            'key' => 'documents',
                            'value' => ($documents['value'] * -1),
                        ];
                    }
                    break;
                case str_starts_with($document->getCollection(), 'database_') && !str_contains($document->getCollection(), 'collection'): //collections
                    $parts = explode('_', $document->getCollection());
                    $databaseId = $parts[1] ?? 0;
                    $documents = $dbForProject->getDocument('stats', md5("_inf_" . "{$databaseId}" . "." . "{$document->getInternalId()}" . ".documents"));

                    if (!empty($documents['value'])) {
                        $metrics[] = [
                            'key' => 'documents',
                            'value' => ($documents['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => "{$databaseId}" . ".documents",
                            'value' => ($documents['value'] * -1),
                        ];
                    }
                    break;

                case $document->getCollection() === 'buckets':
                    $files = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getInternalId()}" . ".files"));
                    $storage = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getInternalId()}" . ".files.storage"));

                    if (!empty($files['value'])) {
                        $metrics[] = [
                            'key' => 'files',
                            'value' => ($files['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => 'files.storage',
                            'value' => ($storage['value'] * -1),
                        ];
                    }
                    break;

                case $document->getCollection() === 'functions':
                    $deployments = $dbForProject->getDocument('stats', md5("_inf_function." . "{$document->getInternalId()}" . ".deployments"));
                    $deploymentsStorage = $dbForProject->getDocument('stats', md5("_inf_function." . "{$document->getInternalId()}" . ".deployments.storage"));
                    $builds = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getInternalId()}" . ".builds"));
                    $buildsStorage = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getInternalId()}" . ".builds.storage"));
                    $buildsCompute = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getInternalId()}" . ".builds.compute"));
                    $executions = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getInternalId()}" . ".executions"));
                    $executionsCompute = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getInternalId()}" . ".executions.compute"));

                    if (!empty($deployments['value'])) {
                        $metrics[] = [
                            'key' => 'deployments',
                            'value' => ($deployments['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => 'deployments.storage',
                            'value' => ($deploymentsStorage['value'] * -1),
                        ];
                    }

                    if (!empty($builds['value'])) {
                        $metrics[] = [
                            'key' => 'builds',
                            'value' => ($builds['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => 'builds.storage',
                            'value' => ($buildsStorage['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => 'builds.compute',
                            'value' => ($buildsCompute['value'] * -1),
                        ];
                    }

                    if (!empty($executions['value'])) {
                        $metrics[] = [
                            'key' => 'executions',
                            'value' => ($executions['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => 'executions.compute',
                            'value' => ($executionsCompute['value'] * -1),
                        ];
                    }
                    break;
                default:
                    break;
            }
        } catch (\Exception $e) {
            console::error("[reducer] " . " {DateTime::now()} " .  " {$projectInternalId} " . " {$e->getMessage()}");
        } finally {
            $pools->reclaim();
        }
    };
}, ['cache', 'register', 'pools']);


$server->job()
    ->inject('message')
    ->inject('reduce')

    ->action(function (Message $message, callable $reduce) use (&$stats) {

        $payload = $message->getPayload() ?? [];
        $project = new Document($payload['project'] ?? []);
        $projectId = $project->getInternalId();

        foreach ($payload['reduce'] ?? [] as $document) {
            if (empty($document)) {
                continue;
            }

             $reduce(
                 database: $project->getAttribute('database'),
                 projectInternalId: $project->getInternalId(),
                 document: new Document($document),
                 metrics:  $payload['metrics'],
             );
        }

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
                    console::error("[logger] " . " {DateTime::now()} " .  " {$projectInternalId} " . " {$e->getMessage()}");
                } finally {
                    $pools->reclaim();
                }
            }
        });
    });
$server->start();
