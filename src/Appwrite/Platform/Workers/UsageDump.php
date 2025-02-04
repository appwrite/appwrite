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
        $this->inject('message')
            ->inject('getProjectDB')
            ->callback([$this, 'action']);
    }

    /**
     * For each stat key, for each period, if the key is a database storage key then we delegate to handleDatabaseStorage;
     * otherwise we simply add the stat.
     *
     * @param Message $message
     * @param callable(Document): Database $getProjectDB
     * @return void
     * @throws Exception
     * @throws \Throwable
     */
    public function action(Message $message, callable $getProjectDB): void
    {
        $payload = $message->getPayload() ?? [];
        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        try {
            foreach ($payload['stats'] ?? [] as $stats) {
                $project = new Document($stats['project'] ?? []);
                $numberOfKeys = !empty($stats['keys']) ? count($stats['keys']) : 0;
                $receivedAt = $stats['receivedAt'] ?? 'NONE';
                if ($numberOfKeys === 0) {
                    continue;
                }

                $dbForProject = $getProjectDB($project);
                $projectDocuments = [];

                $start = microtime(true);

                foreach ($stats['keys'] ?? [] as $key => $value) {
                    if ($value == 0) {
                        continue;
                    }

                    foreach ($this->periods as $period => $format) {
                        $time = ($period === 'inf') ? null : date($format, time());
                        $id = md5("{$time}_{$period}_{$key}");

                        if (str_contains($key, METRIC_DATABASES_STORAGE)) {
                            static::handleDatabaseStorage(
                                $projectDocuments,
                                $id,
                                $key,
                                $time,
                                $period,
                                $dbForProject
                            );
                            continue;
                        }

                        // For non-database storage keys, simply add/update the stat.
                        static::addStatsDocument(
                            $projectDocuments,
                            $period,
                            $time,
                            $key,
                            $value
                        );
                    }
                }

                $dbForProject->createOrUpdateDocumentsWithIncrease(
                    collection: 'stats',
                    attribute: 'value',
                    documents: array_values($projectDocuments)
                );

                $end = microtime(true);
                // (Optional) Log processing time if desired.
            }
        } catch (\Exception $e) {
            Console::error('[' . DateTime::now() . '] Error processing stats: ' . $e->getMessage());
        }
    }

    /**
     * Handle storage metrics.
     *
     * For a given storage metric key (which might be of the form "20.20.databases.storage"),
     * we need to update three levels: collection-level, database-level, and project-level.
     * For each derived metric, we re-read the previous value (from the in-memory $projectDocuments or from the DB)
     * and compute the diff independently.
     *
     * @param array &$projectDocuments The in-memory accumulator of stats documents.
     * @param string $baseId The base id computed from the original key.
     * @param string $key The original key (e.g. "20.20.databases.storage").
     * @param string|null $time The formatted time (or null for "inf").
     * @param string $period The period (e.g. "1h", "1d", "inf").
     * @param Database $dbForProject The database connection for this project.
     *
     * @return void
     * @throws \Exception
     */
    private static function handleDatabaseStorage(
        array &$projectDocuments,
        string $baseId,
        string $key,
        ?string $time,
        string $period,
        Database $dbForProject
    ): void
    {
        $start = microtime(true);
        $data = explode('.', $key);
        $value = 0;

        // We don’t re-use a single previous value; instead, we’ll compute a unique diff for each derived metric.
        switch (count($data)) {
            // Collection Level: key is in the form "databaseId.collectionId.databases.storage"
            case METRIC_COLLECTION_LEVEL_STORAGE:
                Console::log('[' . DateTime::now() . '] Collection Level Storage Calculation [' . $key . ']');
                $databaseInternalId = $data[0];
                $collectionInternalId = $data[1];
                $collectionId = "database_{$databaseInternalId}_collection_{$collectionInternalId}";

                try {
                    $value = $dbForProject->getSizeOfCollection($collectionId);
                } catch (\Exception $e) {
                    if (!$e instanceof NotFound) {
                        throw $e;
                    }
                }

                // For each sub-metric in this group, fetch its own previous value and compute its diff.
                $derivedKeys = [
                    $key, // Collection-level metric name
                    str_replace(['{databaseInternalId}'], [$databaseInternalId], METRIC_DATABASE_ID_STORAGE), // Database-level metric name
                    METRIC_DATABASES_STORAGE // Project-level metric name
                ];

                Console::log('[PROCESSING COLLECTION KEYS] ' . json_encode($derivedKeys));

                foreach ($derivedKeys as $metric) {
                    // Compute a unique ID for this sub-metric.
                    $unique = md5("{$time}_{$period}_{$metric}");
                    try {
                        // Check if we already have a queued update.
                        $prevVal = isset($projectDocuments[$unique])
                            ? $projectDocuments[$unique]['value']
                            : $dbForProject->getDocument('stats', $unique)->getAttribute('value', 0);
                        Console::log("[SUB-PREVIOUS VALUE] For {$metric}: {$prevVal}");
                    } catch (\Exception $ex) {
                        $prevVal = 0;
                        Console::log("[SUB-PREVIOUS VALUE] Defaulted to 0 for {$metric}");
                    }
                    $subDiff = $value - $prevVal;
                    if ($subDiff <= 0) {
                        Console::log("[SKIPPED] No positive diff for {$metric} (diff: {$subDiff})");
                        continue;
                    }
                    static::addStatsDocument($projectDocuments, $period, $time, $metric, $subDiff);
                }
                break;

            // Database Level: key is something like "databaseId.databases.storage"
            case METRIC_DATABASE_LEVEL_STORAGE:
                Console::log('[' . DateTime::now() . '] Database Level Storage Calculation [' . $key . ']');
                $databaseInternalId = $data[0];
                $databaseId = "database_{$databaseInternalId}";

                $collections = [];
                try {
                    $collections = $dbForProject->find($databaseId);
                } catch (\Exception $e) {
                    if ($e->getMessage() !== 'Collection not found') {
                        throw $e;
                    }
                }

                // Sum the sizes from all collections in the database.
                foreach ($collections as $collection) {
                    $collectionId = "{$databaseId}_collection_{$collection->getInternalId()}";
                    try {
                        $value += $dbForProject->getSizeOfCollection($collectionId);
                    } catch (\Exception $e) {
                        if ($e->getMessage() !== 'Collection not found') {
                            throw $e;
                        }
                    }
                }

                $derivedKeys = [
                    str_replace(['{databaseInternalId}'], [$databaseInternalId], METRIC_DATABASE_ID_STORAGE),
                    METRIC_DATABASES_STORAGE
                ];

                Console::log('[PROCESSING DATABASE KEYS] ' . json_encode($derivedKeys));

                foreach ($derivedKeys as $metric) {
                    $unique = md5("{$time}_{$period}_{$metric}");
                    try {
                        $prevVal = isset($projectDocuments[$unique])
                            ? $projectDocuments[$unique]['value']
                            : $dbForProject->getDocument('stats', $unique)->getAttribute('value', 0);
                        Console::log("[SUB-PREVIOUS VALUE] For {$metric}: {$prevVal}");
                    } catch (\Exception $ex) {
                        $prevVal = 0;
                        Console::log("[SUB-PREVIOUS VALUE] Defaulted to 0 for {$metric}");
                    }
                    $subDiff = $value - $prevVal;
                    if ($subDiff <= 0) {
                        Console::log("[SKIPPED] No positive diff for {$metric} (diff: {$subDiff})");
                        continue;
                    }
                    static::addStatsDocument($projectDocuments, $period, $time, $metric, $subDiff);
                }
                break;

            // Project Level: key might be "databases.storage"
            case METRIC_PROJECT_LEVEL_STORAGE:
                Console::log('[' . DateTime::now() . '] Project Level Storage Calculation [' . $key . ']');
                $databases = [];
                try {
                    $databases = $dbForProject->find('database');
                } catch (\Exception $e) {
                    if ($e->getMessage() !== 'Collection not found') {
                        throw $e;
                    }
                }

                foreach ($databases as $database) {
                    $databaseId = "database_{$database->getInternalId()}";
                    $collections = [];
                    try {
                        $collections = $dbForProject->find($databaseId);
                    } catch (\Exception $e) {
                        if ($e->getMessage() !== 'Collection not found') {
                            throw $e;
                        }
                    }
                    foreach ($collections as $collection) {
                        $collectionId = "{$databaseId}_collection_{$collection->getInternalId()}";
                        try {
                            $value += $dbForProject->getSizeOfCollection($collectionId);
                        } catch (\Exception $e) {
                            if ($e->getMessage() !== 'Collection not found') {
                                throw $e;
                            }
                        }
                    }
                }

                $derivedKeys = [METRIC_DATABASES_STORAGE];
                Console::log('[PROCESSING PROJECT KEYS] ' . json_encode($derivedKeys));
                foreach ($derivedKeys as $metric) {
                    $unique = md5("{$time}_{$period}_{$metric}");
                    try {
                        $prevVal = isset($projectDocuments[$unique])
                            ? $projectDocuments[$unique]['value']
                            : $dbForProject->getDocument('stats', $unique)->getAttribute('value', 0);
                        Console::log("[SUB-PREVIOUS VALUE] For {$metric}: {$prevVal}");
                    } catch (\Exception $ex) {
                        $prevVal = 0;
                        Console::log("[SUB-PREVIOUS VALUE] Defaulted to 0 for {$metric}");
                    }
                    $subDiff = $value - $prevVal;
                    if ($subDiff <= 0) {
                        Console::log("[SKIPPED] No positive diff for {$metric} (diff: {$subDiff})");
                        continue;
                    }
                    static::addStatsDocument($projectDocuments, $period, $time, $metric, $subDiff);
                }
                break;
        }
        $end = microtime(true);
        Console::log('[' . DateTime::now() . '] DB Storage Calculation [' . $key . '] took ' . (($end - $start) * 1000) . ' milliseconds');
    }

    /**
     * Adds or increments a document in the projectDocuments array.
     *
     * @param array  &$projectDocuments
     * @param string $period
     * @param string|null $time
     * @param string $key
     * @param int $diff
     * @return void
     */
    private static function addStatsDocument(
        array &$projectDocuments,
        string $period,
        ?string $time,
        string $key,
        int $diff
    ): void
    {
        $id = md5("{$time}_{$period}_{$key}");

        if (isset($projectDocuments[$id])) {
            Console::log("[DUPLICATE DETECTED] Metric: {$key} (Incrementing by {$diff})");
            Console::log("Previous Value: " . $projectDocuments[$id]['value']);
            // Increment the queued value
            $projectDocuments[$id]['value'] += $diff;
            return;
        }

        Console::log("[ADDING] New metric: {$key} (Value: {$diff})");

        $projectDocuments[$id] = new Document([
            '$id' => $id,
            'period' => $period,
            'time' => $time,
            'metric' => $key,
            'value' => $diff,
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);
    }
}