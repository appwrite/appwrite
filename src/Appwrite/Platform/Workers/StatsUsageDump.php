<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class StatsUsageDump extends Action
{
    public const METRIC_COLLECTION_LEVEL_STORAGE = 4;
    public const METRIC_DATABASE_LEVEL_STORAGE = 3;
    public const METRIC_PROJECT_LEVEL_STORAGE = 2;
    protected array $stats = [];

    /**
     * Metrics to skip writing to logsDB
     * As these metrics are calculated separately
     * by logs DB
     * @var array
     */
    protected array $skipBaseMetrics = [
        METRIC_DATABASES => true,
        METRIC_BUCKETS => true,
        METRIC_USERS => true,
        METRIC_FUNCTIONS => true,
        METRIC_TEAMS => true,
        METRIC_MESSAGES => true,
        METRIC_MAU => true,
        METRIC_WEBHOOKS => true,
        METRIC_PLATFORMS => true,
        METRIC_PROVIDERS => true,
        METRIC_TOPICS => true,
        METRIC_KEYS => true,
        METRIC_FILES => true,
        METRIC_FILES_STORAGE => true,
        METRIC_DEPLOYMENTS_STORAGE => true,
        METRIC_BUILDS_STORAGE => true,
        METRIC_DEPLOYMENTS => true,
        METRIC_BUILDS => true,
        METRIC_COLLECTIONS => true,
        METRIC_DOCUMENTS => true,
    ];

    /**
     * Skip metrics associated with parent IDs
     * these need to be checked individually with `str_ends_with`
     */
    protected array $skipParentIdMetrics = [
        '.files',
        '.files.storage',
        '.collections',
        '.documents',
        '.deployments',
        '.deployments.storage',
        '.builds',
        '.builds.storage',
    ];

    /**
     * @var callable
     */
    protected mixed $getLogsDB;

    protected array $periods = [
        '1h' => 'Y-m-d H:00',
        '1d' => 'Y-m-d 00:00',
        'inf' => '0000-00-00 00:00'
    ];

    public static function getName(): string
    {
        return 'stats-usage-dump';
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
     * @param callable $getLogsDB
     * @return void
     * @throws Exception
     * @throws \Utopia\Database\Exception
     */
    public function action(Message $message, callable $getProjectDB, callable $getLogsDB): void
    {
        $this->getLogsDB = $getLogsDB;
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

            console::log('['.DateTime::now().'] Id: '.$project->getId(). ' InternalId: '.$project->getInternalId(). ' Db: '.$project->getAttribute('database').' ReceivedAt: '.$receivedAt. ' Keys: '.$numberOfKeys);

            try {
                /** @var \Utopia\Database\Database $dbForProject */
                $dbForProject = $getProjectDB($project);
                foreach ($stats['keys'] ?? [] as $key => $value) {
                    if ($value == 0) {
                        continue;
                    }

                    if (str_contains($key, METRIC_DATABASES_STORAGE)) {
                        try {
                            $this->handleDatabaseStorage($key, $dbForProject, $project);
                        } catch (\Exception $e) {
                            console::error('[' . DateTime::now() . '] failed to calculate database storage for key [' . $key . '] ' . $e->getMessage());
                        }
                        continue;
                    }

                    foreach ($this->periods as $period => $format) {
                        $time = 'inf' === $period ? null : date($format, time());
                        $id = \md5("{$time}_{$period}_{$key}");

                        $document = new Document([
                            '$id' => $id,
                            'period' => $period,
                            'time' => $time,
                            'metric' => $key,
                            'value' => $value,
                            'region' => System::getEnv('_APP_REGION', 'default'),
                        ]);
                        $dbForProject->createOrUpdateDocumentsWithIncrease(
                            'stats',
                            'value',
                            [$document]
                        );

                        $this->writeToLogsDB($project, $document);
                    }
                }
            } catch (\Exception $e) {
                console::error('[' . DateTime::now() . '] project [' . $project->getInternalId() . '] database [' . $project['database'] . '] ' . ' ' . $e->getMessage());
            }
        }
    }

    private function handleDatabaseStorage(string $key, Database $dbForProject, Document $project): void
    {
        $data = explode('.', $key);
        $start = microtime(true);

        $updateMetric = function (Database $dbForProject, Document $project, int $value, string $key, string $period, string|null $time) {
            $id = \md5("{$time}_{$period}_{$key}");

            $document = new Document([
                '$id' => $id,
                'period' => $period,
                'time' => $time,
                'metric' => $key,
                'value' => $value,
                'region' => System::getEnv('_APP_REGION', 'default'),
            ]);
            $dbForProject->createOrUpdateDocumentsWithIncrease(
                'stats',
                'value',
                [$document]
            );
            $this->writeToLogsDB($project, $document);
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
                case self::METRIC_COLLECTION_LEVEL_STORAGE:
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
                    $updateMetric($dbForProject, $project, $diff, $key, $period, $time);

                    // Update Database
                    $databaseKey = str_replace(['{databaseInternalId}'], [$data[0]], METRIC_DATABASE_ID_STORAGE);
                    $updateMetric($dbForProject, $project, $diff, $databaseKey, $period, $time);

                    // Update Project
                    $projectKey = METRIC_DATABASES_STORAGE;
                    $updateMetric($dbForProject, $project, $diff, $projectKey, $period, $time);
                    break;
                    // Database Level
                case self::METRIC_DATABASE_LEVEL_STORAGE:
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
                    $updateMetric($dbForProject, $project, $diff, $databaseKey, $period, $time);

                    // Update Project
                    $projectKey = METRIC_DATABASES_STORAGE;
                    $updateMetric($dbForProject, $project, $diff, $projectKey, $period, $time);
                    break;
                    // Project Level
                case self::METRIC_PROJECT_LEVEL_STORAGE:
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
                    $updateMetric($dbForProject, $project, $diff, $projectKey, $period, $time);
                    break;
            }
        }

        $end = microtime(true);

        console::log('[' . DateTime::now() . '] DB Storage Calculation [' . $key . '] took ' . (($end - $start) * 1000) . ' milliseconds');
    }

    protected function writeToLogsDB(Document $project, Document $document)
    {
        $databasesToDualWrite = explode(',', System::getEnv('_APP_STATS_USAGE_DUAL_WRITING_DBS', ''));

        $db = $project->getAttribute('database');
        if (!in_array($db, $databasesToDualWrite)) {
            return;
        }

        /** @var \Utopia\Database\Database $dbForLogs*/
        $dbForLogs = call_user_func($this->getLogsDB, $project);

        if (array_key_exists($document->getAttribute('metric'), $this->skipBaseMetrics)) {
            return;
        }
        foreach ($this->skipParentIdMetrics as $skipMetric) {
            if (str_ends_with($document->getAttribute('metric'), $skipMetric)) {
                return;
            }
        }

        $dbForLogs->createOrUpdateDocumentsWithIncrease(
            'stats',
            'value',
            [$document]
        );
    }
}
