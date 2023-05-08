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

const INFINITY_PERIOD = '_inf_';

/**
 * On Documents that tied by relations like functions>deployments>build || documents>collection>database || buckets>files.
 * When we remove a parent document we need to deduct his children aggregation from the project scope.
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
                    $sessions = count($document->getAttribute(METRIC_SESSIONS, 0));
                    if (!empty($sessions)) {
                        $metrics[] = [
                            'key' => METRIC_SESSIONS,
                            'value' => ($sessions * -1),
                        ];
                    }
                    break;
                case $document->getCollection() === 'databases': // databases
                    $collections = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD . str_replace('{databaseInternalId}', $document->getInternalId(), METRIC_DATABASE_ID_COLLECTIONS)));
                    $documents = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD . str_replace('{databaseInternalId}', $document->getInternalId(), METRIC_DATABASE_ID_DOCUMENTS)));
                    if (!empty($collections['value'])) {
                        $metrics[] = [
                            'key' => METRIC_COLLECTIONS,
                            'value' => ($collections['value'] * -1),
                        ];
                    }

                    if (!empty($documents['value'])) {
                        $metrics[] = [
                            'key' => METRIC_DOCUMENTS,
                            'value' => ($documents['value'] * -1),
                        ];
                    }
                    break;
                case str_starts_with($document->getCollection(), 'database_') && !str_contains($document->getCollection(), 'collection'): //collections
                    $parts = explode('_', $document->getCollection());
                    $databaseInternalId = $parts[1] ?? 0;
                    $documents = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD . str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$databaseInternalId, $document->getInternalId()], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS)));

                    if (!empty($documents['value'])) {
                        $metrics[] = [
                            'key' => METRIC_DOCUMENTS,
                            'value' => ($documents['value'] * -1),
                        ];
                        $metrics[] = [
                            'key' => str_replace('{databaseInternalId}', $databaseInternalId, METRIC_DATABASE_ID_DOCUMENTS),
                            'value' => ($documents['value'] * -1),
                        ];
                    }
                    break;

                case $document->getCollection() === 'buckets':
                    $files = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD . str_replace('{bucketInternalId}', $document->getInternalId(), METRIC_BUCKET_ID_FILES)));
                    $storage = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD . str_replace('{bucketInternalId}', $document->getInternalId(), METRIC_BUCKET_ID_FILES_STORAGE)));

                    if (!empty($files['value'])) {
                        $metrics[] = [
                            'key' => METRIC_FILES,
                            'value' => ($files['value'] * -1),
                        ];
                    }

                    if (!empty($storage['value'])) {
                        $metrics[] = [
                            'key' => METRIC_FILES_STORAGE,
                            'value' => ($storage['value'] * -1),
                        ];
                    }
                    break;

                case $document->getCollection() === 'functions':
                    $deployments = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], ['functions', $document->getInternalId()], METRIC_FUNCTION_ID_DEPLOYMENTS)));
                    $deploymentsStorage = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD . str_replace(['{resourceType}', '{resourceInternalId}'], ['functions', $document->getInternalId()], METRIC_FUNCTION_ID_DEPLOYMENTS_STORAGE)));
                    $builds = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD .  str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS)));
                    $buildsStorage = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS_STORAGE)));
                    $buildsCompute = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_BUILDS_COMPUTE)));
                    $executions = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD .  str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS)));
                    $executionsCompute = $dbForProject->getDocument('stats', md5(INFINITY_PERIOD . str_replace('{functionInternalId}', $document->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_COMPUTE)));

                    if (!empty($deployments['value'])) {
                        $metrics[] = [
                            'key' => METRIC_DEPLOYMENTS,
                            'value' => ($deployments['value'] * -1),
                        ];
                    }

                    if (!empty($deploymentsStorage['value'])) {
                        $metrics[] = [
                            'key' => METRIC_DEPLOYMENTS_STORAGE,
                            'value' => ($deploymentsStorage['value'] * -1),
                        ];
                    }

                    if (!empty($builds['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS,
                            'value' => ($builds['value'] * -1),
                        ];
                    }

                    if (!empty($buildsStorage['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS_STORAGE,
                            'value' => ($buildsStorage['value'] * -1),
                        ];
                    }

                    if (!empty($buildsCompute['value'])) {
                        $metrics[] = [
                            'key' => METRIC_BUILDS_COMPUTE,
                            'value' => ($buildsCompute['value'] * -1),
                        ];
                    }

                    if (!empty($executions['value'])) {
                        $metrics[] = [
                            'key' => METRIC_EXECUTIONS,
                            'value' => ($executions['value'] * -1),
                        ];
                    }

                    if (!empty($executionsCompute['value'])) {
                        $metrics[] = [
                            'key' => METRIC_EXECUTIONS_COMPUTE,
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

                    foreach ($project['keys'] ?? [] as $key => $value) {
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
                    if (!empty($project['keys'])) {
                        $dbForProject->createDocument('statsLogger', new Document([
                            'time' => DateTime::now(),
                            'metrics' => $project['keys'],
                        ]));
                    }
                } catch (\Exception $e) {
                    console::error("[logger] " . " {DateTime::now()} " .  " {$projectInternalId} " . " {$e->getMessage()}");
                } finally {
                    $pools->reclaim();
                }
            }
        });
    });
$server->start();
