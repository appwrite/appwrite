<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

const METRIC_COLLECTION_LEVEL_STORAGE = 4;
const METRIC_DATABASE_LEVEL_STORAGE = 3;
const METRIC_PROJECT_LEVEL_STORAGE = 2;

class UsageDump extends Action
{
    protected array $stats = [];
    protected array $periods = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00'
    ];

    public static function getName(): string
    {
        return 'usage-dump';
    }

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        $this
            ->inject('message')
            ->inject('getProjectDB')
            ->callback(function (Message $message, callable $getProjectDB) {
                $this->action($message, $getProjectDB);
            });
    }

    /**
     * @param Message $message
     * @param callable $getProjectDB
     * @return void
     * @throws Exception
     * @throws \Utopia\Database\Exception
     */
    public function action(Message $message, callable $getProjectDB): void
    {
        $payload = $message->getPayload() ?? [];
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }


        foreach ($payload['stats'] ?? [] as $stats) {
            //$project = new Document($stats['project'] ?? []);

            /**
             * Start temp bug fallback
             */
            $document = $stats['project'] ?? [];
            if (!empty($document['$uid'])) {
                $document['$id'] = $document['$uid'];
            }

            $project = new Document($document);

            if (empty($project->getAttribute('database'))) {
                continue;
            }

            /**
             * End temp bug fallback
             */
            $numberOfKeys = !empty($stats['keys']) ? count($stats['keys']) : 0;
            $receivedAt = $stats['receivedAt'] ?? 'NONE';
            if ($numberOfKeys === 0) {
                continue;
            }

            console::log('['.DateTime::now().'] Id: '.$project->getId(). ' InternalId: '.$project->getInternalId(). ' Db: '.$project->getAttribute('database').' ReceivedAt: '.$receivedAt. ' Keys: '.$numberOfKeys);

            try {
                $dbForProject = $getProjectDB($project);
                foreach ($stats['keys'] ?? [] as $key => $value) {
                    if ($value == 0) {
                        continue;
                    }

                    if (str_contains($key, METRIC_DATABASES_STORAGE)) {
                        try {
                            $this->handleDatabaseStorage($key, $dbForProject);
                        } catch (\Exception $e) {
                            console::error('[' . DateTime::now() . '] failed to calculate database storage for key [' . $key . '] ' . $e->getMessage());
                        }
                        continue;
                    }

                    foreach ($this->periods as $period => $format) {
                        $time = 'inf' === $period ? null : date($format, time());
                        $id = \md5("{$time}_{$period}_{$key}");

                        try {
                            $dbForProject->createDocument('stats', new Document([
                                '$id' => $id,
                                'period' => $period,
                                'time' => $time,
                                'metric' => $key,
                                'value' => $value,
                                'region' => System::getEnv('_APP_REGION', 'default'),
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
            } catch (\Exception $e) {
                console::error('[' . DateTime::now() . '] project [' . $project->getInternalId() . '] database [' . $project['database'] . '] ' . ' ' . $e->getMessage());
            }
        }
    }

    private function handleDatabaseStorage(string $key, Database $dbForProject): void
    {
        $data = explode('.', $key);
        $start = microtime(true);

        $updateMetric = function (Database $dbForProject, int $value, string $key, string $period, string|null $time) {
            $id = \md5("{$time}_{$period}_{$key}");

            try {
                $dbForProject->createDocument('stats', new Document([
                    '$id' => $id,
                    'period' => $period,
                    'time' => $time,
                    'metric' => $key,
                    'value' => $value,
                    'region' => System::getEnv('_APP_REGION', 'default'),
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
        };

        foreach ($this->periods as $period => $format) {
            $time = 'inf' === $period ? null : date($format, time());
            $id = \md5("{$time}_{$period}_{$key}");

            $value = 0;
            $previousValue = 0;
            try {
                $previousValue = ($dbForProject->getDocument('stats', $id))->getAttribute('value', 0);
            } catch (\Exception $e) {
                // No previous value
            }

            switch (count($data)) {
                // Collection Level
                case METRIC_COLLECTION_LEVEL_STORAGE:
                    Console::log('[' . DateTime::now() . '] Collection Level Storage Calculation [' . $key . ']');
                    $databaseInternalId = $data[0];
                    $collectionInternalId = $data[1];

                    try {
                        $value = $dbForProject->getSizeOfCollection('database_' . $databaseInternalId . '_collection_' . $collectionInternalId);
                    } catch (\Exception $e) {
                        // Collection not found
                        if ($e->getMessage() !== 'Collection not found') {
                            throw $e;
                        }
                    }

                    // Compare with previous value
                    $diff = $value - $previousValue;

                    if ($diff === 0) {
                        break;
                    }

                    // Update Collection
                    $updateMetric($dbForProject, $diff, $key, $period, $time);

                    // Update Database
                    $databaseKey = str_replace(['{databaseInternalId}'], [$data[0]], METRIC_DATABASE_ID_STORAGE);
                    $updateMetric($dbForProject, $diff, $databaseKey, $period, $time);

                    // Update Project
                    $projectKey = METRIC_DATABASES_STORAGE;
                    $updateMetric($dbForProject, $diff, $projectKey, $period, $time);
                    break;
                    // Database Level
                case METRIC_DATABASE_LEVEL_STORAGE:
                    Console::log('[' . DateTime::now() . '] Database Level Storage Calculation [' . $key . ']');
                    $databaseInternalId = $data[0];

                    $collections = [];
                    try {
                        $collections = $dbForProject->find('database_' . $databaseInternalId);
                    } catch (\Exception $e) {
                        // Database not found
                        if ($e->getMessage() !== 'Collection not found') {
                            throw $e;
                        }
                    }

                    foreach ($collections as $collection) {
                        try {
                            $value += $dbForProject->getSizeOfCollection('database_' . $databaseInternalId . '_collection_' . $collection->getInternalId());
                        } catch (\Exception $e) {
                            // Collection not found
                            if ($e->getMessage() !== 'Collection not found') {
                                throw $e;
                            }
                        }
                    }

                    $diff = $value - $previousValue;

                    if ($diff === 0) {
                        break;
                    }

                    // Update Database
                    $databaseKey = str_replace(['{databaseInternalId}'], [$data[0]], METRIC_DATABASE_ID_STORAGE);
                    $updateMetric($dbForProject, $diff, $databaseKey, $period, $time);

                    // Update Project
                    $projectKey = METRIC_DATABASES_STORAGE;
                    $updateMetric($dbForProject, $diff, $projectKey, $period, $time);
                    break;
                    // Project Level
                case METRIC_PROJECT_LEVEL_STORAGE:
                    Console::log('[' . DateTime::now() . '] Project Level Storage Calculation [' . $key . ']');
                    // Get all project databases
                    $databases = $dbForProject->find('database');

                    // Recalculate all databases
                    foreach ($databases as $database) {
                        $collections = $dbForProject->find('database_' . $database->getInternalId());

                        foreach ($collections as $collection) {
                            try {
                                $value += $dbForProject->getSizeOfCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());
                            } catch (\Exception $e) {
                                // Collection not found
                                if ($e->getMessage() !== 'Collection not found') {
                                    throw $e;
                                }
                            }
                        }
                    }

                    $diff = $value - $previousValue;

                    // Update Project
                    $projectKey = METRIC_DATABASES_STORAGE;
                    $updateMetric($dbForProject, $diff, $projectKey, $period, $time);
                    break;
            }
        }

        $end = microtime(true);

        console::log('[' . DateTime::now() . '] DB Storage Calculation [' . $key . '] took ' . (($end - $start) * 1000) . ' milliseconds');
    }
}
