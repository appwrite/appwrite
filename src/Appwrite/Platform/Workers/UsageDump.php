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

        // TODO: rename both usage workers @shimonewman
        foreach ($payload['stats'] ?? [] as $stats) {
            $project = new Document($stats['project'] ?? []);
            $numberOfKeys = !empty($stats['keys']) ? count($stats['keys']) : 0;
            $receivedAt = $stats['receivedAt'] ?? 'NONE';
            if ($numberOfKeys === 0) {
                continue;
            }

            console::log('[' . DateTime::now() . '] ProjectId [' . $project->getInternalId()  . '] ReceivedAt [' . $receivedAt . '] ' . $numberOfKeys . ' keys');

            try {
                $dbForProject = $getProjectDB($project);
                foreach ($stats['keys'] ?? [] as $key => $value) {
                    if ($value == 0) {
                        continue;
                    }

                    if (str_ends_with($key, '.db_storage') && $value === 1) {
                        $this->handleDBStorageCalculation($key, $dbForProject);
                        return;
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

    private function handleDBStorageCalculation(string $key, Database $dbForProject): void
    {
        $data = explode('.', $key);
        $start = microtime(true);

        var_dump('Calculating DB Storage for ' . $key);

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
                case 3:
                    $databaseInternalId = $data[0];
                    $collectionInternalId = $data[1];

                    $value = $dbForProject->getSizeOfCollection('database_'.$databaseInternalId.'_collection_'.$collectionInternalId);

                    // Compare with previous value
                    $diff = $value - $previousValue;

                    if ($diff === 0) {
                        break;
                    }

                    var_dump('Calculated collection level, diff was ' . $diff . ' for ' . $key);

                    // Update Collection
                    $updateMetric($dbForProject, $diff, $key, $period, $time);

                    // Update Database
                    $databaseKey = $data[0] . '.db_storage';
                    $updateMetric($dbForProject, $diff, $databaseKey, $period, $time);

                    // Update Project
                    $projectKey = 'db_storage';
                    $updateMetric($dbForProject, $diff, $projectKey, $period, $time);
                    break;
                // Database Level
                case 2:
                    $databaseInternalId = $data[0];
                    $collections = $dbForProject->find('database_' . $databaseInternalId);

                    foreach ($collections as $collection) {
                        $value += $dbForProject->getSizeOfCollection('database_'.$databaseInternalId.'_collection_'.$collection->getInternalId());
                    }

                    $diff = $value - $previousValue;

                    if ($diff === 0) {
                        break;
                    }

                    var_dump('Calculated database level, diff was ' . $diff . ' for ' . $key);

                    // Update Database
                    $databaseKey = $data[0] . '.db_storage';
                    $updateMetric($dbForProject, $diff, $databaseKey, $period, $time);

                    // Update Project
                    $projectKey = 'db_storage';
                    $updateMetric($dbForProject, $diff, $projectKey, $period, $time);
                    break;
                    // Project Level
                case 1:
                    // Get all project databases
                    $databases = $dbForProject->find('database');

                    // Recalculate all databases
                    foreach ($databases as $database) {
                        $collections = $dbForProject->find('database_' . $database->getInternalId());

                        foreach ($collections as $collection) {
                            $value += $dbForProject->getSizeOfCollection('database_'.$database->getInternalId().'_collection_'.$collection->getInternalId());
                        }
                    }

                    $diff = $value - $previousValue;

                    var_dump('Calculated project level, diff was ' . $diff . ' for ' . $key);

                    // Update Project
                    $projectKey = 'db_storage';
                    $updateMetric($dbForProject, $diff, $projectKey, $period, $time);
                    break;
            }
        }

        $end = microtime(true);

        console::log('[' . DateTime::now() . '] DB Storage Calculation [' . $key . '] took ' . (($end - $start) * 1000) . ' milliseconds');
    }
}
