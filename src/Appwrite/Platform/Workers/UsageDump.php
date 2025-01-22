<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\NotFound;
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
            $project = new Document($stats['project'] ?? []);

            /**
             * End temp bug fallback
             */
            $numberOfKeys = !empty($stats['keys']) ? count($stats['keys']) : 0;
            $receivedAt = $stats['receivedAt'] ?? 'NONE';
            if ($numberOfKeys === 0) {
                continue;
            }

            Console::log('['.DateTime::now().'] Id: '.$project->getId(). ' InternalId: '.$project->getInternalId(). ' Db: '.$project->getAttribute('database').' ReceivedAt: '.$receivedAt. ' Keys: '.$numberOfKeys);

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
                            Console::error('[' . DateTime::now() . '] failed to calculate database storage for key [' . $key . '] ' . $e->getMessage());
                        }
                        continue;
                    }

                    $documents = [];

                    foreach ($this->periods as $period => $format) {
                        $time = 'inf' === $period ? null : date($format, time());
                        $id = \md5("{$time}_{$period}_{$key}");

                        $documents[] = new Document([
                            '$id' => $id,
                            'period' => $period,
                            'time' => $time,
                            'metric' => $key,
                            'value' => $value,
                            'region' => System::getEnv('_APP_REGION', 'default'),
                        ]);
                    }

                    $dbForProject->createOrUpdateDocumentsWithInplaceIncrease(
                        collection: 'stats',
                        attribute: 'value',
                        documents: $documents
                    );
                }
            } catch (\Exception $e) {
                Console::error('[' . DateTime::now() . '] project [' . $project->getInternalId() . '] database [' . $project['database'] . '] ' . ' ' . $e->getMessage());
            }
        }
    }

    private function handleDatabaseStorage(string $key, Database $dbForProject): void
    {
        $data = \explode('.', $key);
        $start = \microtime(true);

        $documents = [];

        foreach ($this->periods as $period => $format) {
            $time = 'inf' === $period ? null : \date($format, \time());
            $id = \md5("{$time}_{$period}_{$key}");

            $value = 0;
            $previousValue = 0;

            try {
                $previousValue = $dbForProject
                    ->getDocument('stats', $id)
                    ->getAttribute('value', 0);
            } catch (\Exception) {
                // No previous value
            }

            switch (\count($data)) {
                // Collection Level
                case METRIC_COLLECTION_LEVEL_STORAGE:
                    Console::log('[' . DateTime::now() . '] Collection Level Storage Calculation [' . $key . ']');

                    $databaseInternalId = $data[0];
                    $collectionInternalId = $data[1];

                    try {
                        $value = $dbForProject->getSizeOfCollection('database_' . $databaseInternalId . '_collection_' . $collectionInternalId);
                    } catch (\Exception $e) {
                        if (!$e instanceof NotFound) {
                            throw $e;
                        }
                    }

                    // Compare with previous value
                    $diff = $value - $previousValue;

                    if ($diff === 0) {
                        break;
                    }

                    $databaseKey = \str_replace(
                        ['{databaseInternalId}'],
                        [$data[0]],
                        METRIC_DATABASE_ID_STORAGE
                    );

                    // Database
                    $documents[] = new Document([
                        '$id' => $id,
                        'period' => $period,
                        'time' => $time,
                        'metric' => $databaseKey,
                        'value' => $diff,
                        'region' => System::getEnv('_APP_REGION', 'default'),
                    ]);

                    // Collection
                    $documents[] = new Document([
                        '$id' => $id,
                        'period' => $period,
                        'time' => $time,
                        'metric' => $key,
                        'value' => $diff,
                        'region' => System::getEnv('_APP_REGION', 'default'),
                    ]);

                    // Project
                    $documents[] = new Document([
                        '$id' => $id,
                        'period' => $period,
                        'time' => $time,
                        'metric' => METRIC_DATABASES_STORAGE,
                        'value' => $diff,
                        'region' => System::getEnv('_APP_REGION', 'default'),
                    ]);
                    break;
                // Database Level
                case METRIC_DATABASE_LEVEL_STORAGE:
                    Console::log('[' . DateTime::now() . '] Database Level Storage Calculation [' . $key . ']');
                    $databaseInternalId = $data[0];

                    $collections = [];
                    try {
                        $collections = $dbForProject->find('database_' . $databaseInternalId);
                    } catch (\Exception $e) {
                        if (!$e instanceof NotFound) {
                            throw $e;
                        }
                    }

                    foreach ($collections as $collection) {
                        try {
                            $value += $dbForProject->getSizeOfCollection('database_' . $databaseInternalId . '_collection_' . $collection->getInternalId());
                        } catch (\Exception $e) {
                            if (!$e instanceof NotFound) {
                                throw $e;
                            }
                        }
                    }

                    $diff = $value - $previousValue;

                    if ($diff === 0) {
                        break;
                    }

                    // Database
                    $databaseKey = str_replace(
                        ['{databaseInternalId}'],
                        [$data[0]],
                        METRIC_DATABASE_ID_STORAGE
                    );

                    $documents[] = new Document([
                        '$id' => $id,
                        'period' => $period,
                        'time' => $time,
                        'metric' => $databaseKey,
                        'value' => $diff,
                        'region' => System::getEnv('_APP_REGION', 'default'),
                    ]);

                    // Project
                    $documents[] = new Document([
                        '$id' => $id,
                        'period' => $period,
                        'time' => $time,
                        'metric' => METRIC_DATABASES_STORAGE,
                        'value' => $diff,
                        'region' => System::getEnv('_APP_REGION', 'default'),
                    ]);
                    break;
                // Project Level
                case METRIC_PROJECT_LEVEL_STORAGE:
                    Console::log('[' . DateTime::now() . '] Project Level Storage Calculation [' . $key . ']');

                    $databases = $dbForProject->find('databases');

                    // Recalculate all databases
                    foreach ($databases as $database) {
                        $collections = $dbForProject->find('database_' . $database->getInternalId());

                        foreach ($collections as $collection) {
                            try {
                                $value += $dbForProject->getSizeOfCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());
                            } catch (\Exception $e) {
                                if (!$e instanceof NotFound) {
                                    throw $e;
                                }
                            }
                        }
                    }

                    $diff = $value - $previousValue;

                    // Project
                    $documents[] = new Document([
                        '$id' => $id,
                        'period' => $period,
                        'time' => $time,
                        'metric' => METRIC_DATABASES_STORAGE,
                        'value' => $diff,
                        'region' => System::getEnv('_APP_REGION', 'default'),
                    ]);
                    break;
            }
        }

        $dbForProject->createOrUpdateDocumentsWithInplaceIncrease(
            collection: 'stats',
            attribute: 'value',
            documents: $documents
        );

        $end = microtime(true);

        Console::log('[' . DateTime::now() . '] DB Storage Calculation [' . $key . '] took ' . (($end - $start) * 1000) . ' milliseconds');
    }
}
