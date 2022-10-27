<?php

global $cli;
global $register;

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Queue\Client as SyncOut;
use Utopia\Queue\Connection\Redis as QueueRedis;

$cli
    ->task('sync-edge')
    ->desc('Schedules edge sync tasks')
    ->action(function () use ($register) {
        Console::title('Syncs edges V1');
        Console::success(APP_NAME . ' Syncs Edge process v1 has started');

        $interval = (int) App::getEnv('_APP_SYNC_EDGE_INTERVAL', '180');
          Console::loop(function () use ($interval, $register) {
            $database = getConsoleDB();
            $time = DateTime::now();
            $region = App::getEnv('_APP_REGION', 'nyc1');
            Console::info("[{$time}] Notifying workers with edges tasks every {$interval} seconds");

            $time = DateTime::now();
            $chunks = $database->find('syncs', [
                Query::equal('region', [$region]),
                Query::limit(500)
            ]);

            if (count($chunks) > 0) {
                $client = new SyncOut('syncOut', new QueueRedis(App::getEnv('_APP_REDIS_HOST', 'redis'), App::getEnv('_APP_REDIS_PORT', '6379')));
                foreach ($chunks as $counter => $chunk) {
                    Console::info("[{$time}] Sending  chunk .($counter+1). ot of  " . count($chunks) . "  to  {$chunk->getAttribute('target')}");
                    $client
                        ->enqueue([
                            'value' => [
                                'region' => $chunk->getAttribute('target'),
                                'keys'  => $chunk->getAttribute('keys')
                            ]
                        ]);

                    $database->deleteDocument('syncs', $chunk->getId());
                }
            } else {
                Console::info("[{$time}] No cache key chunks where found.");
            }
          }, $interval);
    });
