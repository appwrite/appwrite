<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
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
            ->inject('getLogsDB')
            ->callback([$this, 'action']);
    }

    /**
     * @param Message $message
     * @param callable $getProjectDB
     * @return void
     * @throws Exception
     * @throws \Utopia\Database\Exception
     */
    public function action(Message $message, callable $getProjectDB, callable $getLogsDB): void
    {
        $payload = $message->getPayload() ?? [];
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        try {
            foreach ($payload['stats'] ?? [] as $stats) {
                $project = new Document($stats['project'] ?? []);
                $numberOfKeys = !empty($stats['keys']) ? \count($stats['keys']) : 0;
                $receivedAt = $stats['receivedAt'] ?? 'NONE';
                if ($numberOfKeys === 0) {
                    continue;
                }

                $dbForProject = $getProjectDB($project);
                $dbForLogs = $getLogsDB($dbForProject);
                $projectDocuments = [];
                $databaseCache = [];
                $collectionSizeCache = [];

                Console::log('['.DateTime::now().'] Id: '.$project->getId(). ' InternalId: '.$project->getInternalId(). ' Db: '.$project->getAttribute('database').' ReceivedAt: '.$receivedAt. ' Keys: '.$numberOfKeys . ' Started');
                $start = \microtime(true);

                foreach ($stats['keys'] ?? [] as $key => $value) {
                    if ($value == 0) {
                        continue;
                    }

                    foreach ($this->periods as $period => $format) {
                        $time = 'inf' === $period ? null : \date($format, \time());
                        $id = \md5("{$time}_{$period}_{$key}");

                        if (\str_contains($key, METRIC_DATABASES_STORAGE)) {
                            $this->handleDatabaseStorage(
                                $id,
                                $key,
                                $time,
                                $period,
                                $dbForProject,
                                $projectDocuments,
                                $databaseCache,
                                $collectionSizeCache
                            );
                            continue;
                        }

                        $projectDocuments[] = new Document([
                            '$id' => $id,
                            'period' => $period,
                            'time' => $time,
                            'metric' => $key,
                            'value' => $value,
                            'region' => System::getEnv('_APP_REGION', 'default'),
                        ]);
                    }
                }

                $dbForProject->createOrUpdateDocumentsWithIncrease(
                    collection: 'stats',
                    attribute: 'value',
                    documents: $projectDocuments
                );

                $dbForLogs->createOrUpdateDocumentsWithIncrease(
                    collection: 'usage',
                    attribute: 'value',
                    documents: $projectDocuments
                );
                $end = \microtime(true);
                Console::log('['.DateTime::now().'] Id: '.$project->getId(). ' InternalId: '.$project->getInternalId(). ' Db: '.$project->getAttribute('database').' ReceivedAt: '.$receivedAt. ' Keys: '.$numberOfKeys. ' Time: '.($end - $start).'s');
            }
        } catch (\Exception $e) {
            Console::error('[' . DateTime::now() . '] Error processing stats: ' . $e->getMessage());
        }
    }

    private function handleDatabaseStorage(
        string $id,
        string $key,
        ?string $time,
        string $period,
        Database $dbForProject,
        array &$projectDocuments,
        array &$databaseCache,
        array &$collectionSizeCache,
    ): void {
        $data = \explode('.', $key);
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
            case METRIC_COLLECTION_LEVEL_STORAGE:
                $databaseInternalId = $data[0];
                $collectionInternalId = $data[1];
                $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";

                if (!isset($collectionSizeCache[$collectionId])) {
                    try {
                        $collectionSizeCache[$collectionId] = $dbForProject->getSizeOfCollection($collectionId);
                    } catch (\Exception $e) {
                        if (!$e instanceof NotFound) {
                            throw $e;
                        }
                        $collectionSizeCache[$collectionId] = 0;
                    }
                }

                $value = $collectionSizeCache[$collectionId];

                $diff = $value - $previousValue;
                if ($diff === 0) {
                    break;
                }

                $keys = [
                    $key,
                    \str_replace(['{databaseInternalId}'], [$data[0]], METRIC_DATABASE_ID_STORAGE),
                    METRIC_DATABASES_STORAGE
                ];

                foreach ($keys as $metric) {
                    $projectDocuments[] = $this->createStatsDocument($id, $period, $time, $metric, $diff);
                }
                break;

            case METRIC_DATABASE_LEVEL_STORAGE:
                $databaseInternalId = $data[0];
                $databaseId = "database_{$databaseInternalId}";

                if (!isset($databaseCache[$databaseId])) {
                    try {
                        $databaseCache[$databaseId] = $dbForProject->find($databaseId);
                    } catch (\Exception $e) {
                        if (!$e instanceof NotFound) {
                            throw $e;
                        }
                        $databaseCache[$databaseId] = [];
                    }
                }

                foreach ($databaseCache[$databaseId] as $collection) {
                    $collectionId = "{$databaseId}_collection_{$collection->getInternalId()}";

                    if (!isset($collectionSizeCache[$collectionId])) {
                        try {
                            $collectionSizeCache[$collectionId] = $dbForProject->getSizeOfCollection($collectionId);
                        } catch (\Exception $e) {
                            if (!$e instanceof NotFound) {
                                throw $e;
                            }
                            $collectionSizeCache[$collectionId] = 0;
                        }
                    }
                    $value += $collectionSizeCache[$collectionId];
                }

                $diff = $value - $previousValue;
                if ($diff === 0) {
                    break;
                }

                $keys = [
                    \str_replace(['{databaseInternalId}'], [$data[0]], METRIC_DATABASE_ID_STORAGE),
                    METRIC_DATABASES_STORAGE
                ];

                foreach ($keys as $metric) {
                    $projectDocuments[] = $this->createStatsDocument($id, $period, $time, $metric, $diff);
                }
                break;

            case METRIC_PROJECT_LEVEL_STORAGE:
                if (!isset($databaseCache['*'])) {
                    try {
                        $databaseCache['*'] = $dbForProject->find('databases');
                    } catch (\Exception $e) {
                        if (!$e instanceof NotFound) {
                            throw $e;
                        }
                        $databaseCache['*'] = [];
                    }
                }

                foreach ($databaseCache['*'] as $database) {
                    $databaseId = "database_{$database->getInternalId()}";
                    if (!isset($databaseCache[$databaseId])) {
                        try {
                            $databaseCache[$databaseId] = $dbForProject->find($databaseId);
                        } catch (\Exception $e) {
                            if (!$e instanceof NotFound) {
                                throw $e;
                            }
                            $databaseCache[$databaseId] = [];
                        }
                    }

                    foreach ($databaseCache[$databaseId] as $collection) {
                        $collectionId = "{$databaseId}_collection_{$collection->getInternalId()}";

                        if (!isset($collectionSizeCache[$collectionId])) {
                            try {
                                $collectionSizeCache[$collectionId] = $dbForProject->getSizeOfCollection($collectionId);
                            } catch (\Exception $e) {
                                if (!$e instanceof NotFound) {
                                    throw $e;
                                }
                                $collectionSizeCache[$collectionId] = 0;
                            }
                        }
                        $value += $collectionSizeCache[$collectionId];
                    }
                }

                $diff = $value - $previousValue;
                if ($diff === 0) {
                    break;
                }

                $keys = [
                    METRIC_DATABASES_STORAGE
                ];

                foreach ($keys as $metric) {
                    $projectDocuments[] = $this->createStatsDocument($id, $period, $time, $metric, $diff);
                }

                break;
        }
    }

    private function createStatsDocument(
        string $id,
        string $period,
        ?string $time,
        string $key,
        int $diff,
    ): Document {
        return new Document([
            '$id' => $id,
            'period' => $period,
            'time' => $time,
            'metric' => $key,
            'value' => $diff,
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);
    }
}
