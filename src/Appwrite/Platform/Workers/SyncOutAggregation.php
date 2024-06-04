<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use JsonException;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Platform\Action;
use Utopia\Queue\Client;
use Utopia\Queue\Message;
use Utopia\System\System;

class SyncOutAggregation extends Action
{
    private int $lastTriggeredTime = 0;
    private const KEYS_THRESHOLD = 10000;
    private array $keys = [];
    private const AGGREGATION_INTERVAL = 5;

    public static function getName(): string
    {
        return 'sync-out-aggregation';
    }

    /**
     * @throws Exception|\Exception
     */
    public function __construct()
    {

        $this
            ->desc('Sync out aggregation worker')
            ->inject('message')
            ->inject('dbForConsole')
            ->inject('queueForSyncOutDelivery')
            ->callback(fn (Message $message, Database $dbForConsole, Client $queueForSyncOutDelivery) => $this->action($message, $dbForConsole, $queueForSyncOutDelivery));
    }


    /**
     * @param Message $message
     * @param Database $dbForConsole
     * @param Client $queueForSyncOutDelivery
     * @throws Exception
     * @throws JsonException
     * @throws \Utopia\Database\Exception
     * @throws Authorization
     * @throws Structure
     */
    public function action(Message $message, Database $dbForConsole, Client $queueForSyncOutDelivery): void
    {

        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        if (!empty($payload['key'])) {
            $this->keys[] = [
                'time' => time(),
                'type' => $payload['type'],
                'key'  => $payload['key'],
            ];
        }

        $destRegions = [];
        $currentRegion = System::getEnv('_APP_REGION', 'fra');
        foreach(Config::getParam('regions', []) as  $destRegion) {
            if($currentRegion  !== $destRegion['$id'] && $destRegion['disabled'] === false) {
                $destRegions[] = $destRegion['$id'];
            }
        }

        if (
            count($this->keys) >= self::KEYS_THRESHOLD ||
            (time() - $this->lastTriggeredTime > self::AGGREGATION_INTERVAL)
        ) {
            $chunk = array_slice($this->keys, 0, self::KEYS_THRESHOLD, true);
            array_splice($this->keys, 0, self::KEYS_THRESHOLD);

            $filename = (string)time();
            $chunk = json_encode($chunk, flags: JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            $path = APP_STORAGE_SYNCS . '/' . $filename . '.log';

            Console::log('Writing log '. $path);

            if (\file_put_contents($path, $chunk)) {
                foreach($destRegions as  $destRegion) {
                    Console::log('Creating documents to '. $destRegion);
                    $sync = $dbForConsole->createDocument('syncs', new Document([
                        'sourceRegion' => $currentRegion,
                        'destRegion' => $destRegion,
                        'filename' => $filename,
                        'logCreatedAt' => DateTime::now()
                    ]));

                    $queueForSyncOutDelivery
                        ->enqueue([
                            'syncId' =>  $sync->getId(),
                        ]);

                    $this->lastTriggeredTime = time();
                }
            } else {
                Console::error('Failed to save log : ' . $path);
            }
        }
    }
}
