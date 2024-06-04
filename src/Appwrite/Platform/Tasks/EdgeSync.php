<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Queue\Client;
use Utopia\System\System;

class EdgeSync extends Action
{
    public static function getName(): string
    {
        return 'edge-sync';
    }

    public function __construct()
    {
        $this
            ->desc('Schedules edge sync tasks')
            ->inject('dbForConsole')
            ->inject('queueForSyncOutDelivery')
            ->callback(fn (Database $dbForConsole, Client $queueForSyncOutDelivery) => $this->action($dbForConsole, $queueForSyncOutDelivery));
    }

    public function action(Database $dbForConsole, Client $queueForSyncOutDelivery): void
    {
        Console::title('Edge-sync V1');
        Console::success(APP_NAME . ' Edge-sync v1 has started');

        $interval = (int) App::getEnv('_APP_SYNC_EDGE_INTERVAL', '180');
        Console::loop(function () use ($interval, $dbForConsole, $queueForSyncOutDelivery) {
            $time = DateTime::now();

            Console::success("[{$time}] New task every {$interval} seconds");

                $chunk = 0;
                $limit = 500;
                $sum   = $limit;
                while ($sum === $limit) {
                    $chunk++;
                    $count = 0;
                    $results = $dbForConsole->find('syncs', [
                        Query::equal('sourceRegion', [System::getEnv('_APP_REGION', 'fra')]),
                        Query::limit($limit)
                    ]);

                    $sum = count($results);
                    if ($sum > 0) {
                        foreach ($results as $sync) {
                            try {
                                if ($sync->getAttribute('status') === 200) {
                                    $dbForConsole->deleteDocument('syncs', $sync->getId());
                                    unlink(APP_STORAGE_SYNCS . '/' . $sync->getAttribute('filename') . '.log');
                                    Console::log("[{$time}] Deleting {$sync->getId()}");
                                } else {
                                    Console::log("[{$time}] Enqueueing {$sync->getId()} to {$sync->getAttribute('destRegion')}");

                                    $sync->setAttribute('attempts', $sync->getAttribute('attempts')+1);
                                    $dbForConsole->updateDocument('syncs', $sync->getId(), $sync);

                                    $queueForSyncOutDelivery
                                        ->enqueue([
                                            'syncId' => $sync->getId(),
                                        ]);
                                }

                            } catch(\Throwable $th) {
                                Console::log("[{$time}] Error: {$th->getMessage()}");
                            }

                            $count++;
                        }
                    }

                }

        }, $interval);
    }
}
