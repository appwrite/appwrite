<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
use Utopia\Config\Config;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Database\Database;
use Utopia\Pools\Group;
use Utopia\Queue\Client;

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
            ->inject('pools')
            ->inject('dbForConsole')
            ->inject('queueForCacheSyncOut')
            ->callback(fn (Group $pools, Database $dbForConsole, Client $queueForCacheSyncOut) => $this->action($pools, $dbForConsole, $queueForCacheSyncOut));
    }

    public function action(Group $pools, Database $dbForConsole, Client $queueForCacheSyncOut): void
    {
        Console::title('Edge-sync V1');
        Console::success(APP_NAME . ' Edge-sync v1 has started');

        $regions = array_filter(
            Config::getParam('regions', []),
            fn ($region) => App::getEnv('_APP_REGION') !== $region
                && $region !== 'default',
            ARRAY_FILTER_USE_KEY
        );

        $interval = (int) App::getEnv('_APP_SYNC_EDGE_INTERVAL', '180');
        Console::loop(function () use ($interval, $dbForConsole, $queueForCacheSyncOut, $regions) {
            $time = DateTime::now();

            Console::success("[{$time}] New task every {$interval} seconds");

            foreach ($regions as $target) {
                $count = 0;
                $chunk = 0;
                $limit = 50;
                $sum = $limit;
                $keys = [];
                while ($sum === $limit) {
                    $chunk++;

                    $results = $dbForConsole->find('syncs', [
                        Query::equal('region', [App::getEnv('_APP_REGION')]),
                        Query::equal('target', [$target]),
                        Query::limit($limit)
                    ]);

                    $sum = count($results);
                    if ($sum > 0) {
                        foreach ($results as $document) {
                            $keys[] = [
                                        'type' => $document->getAttribute('type'),
                                        'key' => $document->getAttribute('key')
                                ];
                            $dbForConsole->deleteDocument('syncs', $document->getId());
                            $count++;
                        }
                    }
                }

                if (!empty($keys)) {
                    Console::info("[{$time}] Enqueueing  keys chunk {$count} to {$target}");
                    $queueForCacheSyncOut
                        ->enqueue([
                            'region' => $target,
                            'keys' => $keys
                        ]);
                } else {
                        Console::info("[{$time}] No cache keys where found.");
                }
            }
        }, $interval);
    }
}
