<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
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

                    if (str_ends_with($key, '.db_storage')) {
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

        foreach ($this->periods as $period => $format) {
            $time = 'inf' === $period ? null : date($format, time());
            $id = \md5("{$time}_{$period}_{$key}");

            $value = 0;
            $previousValue = 0;
            try {
                $previousValue = ($dbForProject->getDocument('stats', $id))->getAttribute('value');
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
                    $value = $value - $previousValue;

                    // Update Collection

                    // Update Database

                    // Update Project
                    break;
                // Database Level
                case 2:
                    $databaseInternalId = $data[0];
                    $collections = $dbForProject->find('database_' . $databaseInternalId);

                    foreach ($collections as $collection) {
                        $value += $dbForProject->getSizeOfCollection($collection->getInternalId());
                    }
                    break;
                // Project Level
                case 1:
                    break;
            }
        }
    }
}
