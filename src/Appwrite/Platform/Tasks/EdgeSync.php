<?php

global $cli;
global $register;

use Appwrite\DSN\DSN;
use Appwrite\URL\URL as AppwriteURL;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Queue;
use Utopia\Queue\Client as SyncOut;

$cli
    ->task('edge-sync')
    ->desc('Schedules edge sync tasks')
    ->action(function () use ($register) {
        Console::title('Syncs edges V1');
        Console::success(APP_NAME . ' Sync failed cache purge process v1 has started');

        $pools = $register->get('pools');
        $client = new SyncOut('syncOut', $pools->get('queue')->pop()->getResource());
        $database = getConsoleDB();

        $interval = (int) App::getEnv('_APP_SYNC_EDGE_INTERVAL', '180');
          Console::loop(function () use ($interval, $database, $register, $client) {

            $time = DateTime::now();
            $count = 0;
            $chunk = 0;
            $limit = 50;
            $sum = $limit;

            Console::success("[{$time}] New task every {$interval} seconds");

            while ($sum === $limit) {
                $chunk++;

                $results = $database->find('syncs', [
                    Query::equal('region', [App::getEnv('_APP_REGION')]),
                    Query::limit($limit)
                ]);

                $sum = count($results);
                if ($sum > 0) {
                    foreach ($results as $document) {
                        Console::info("[{$time}] Enqueueing  keys chunk {$count} to {$document->getAttribute('target')}");
                        $client
                            ->enqueue([
                                'value' => [
                                    'region' => $document->getAttribute('target'),
                                    'keys' => $document->getAttribute('keys')
                                ]
                            ]);

                        $database->deleteDocument('syncs', $document->getId());
                        $count++;
                    }
                } else {
                    Console::info("[{$time}] No cache keys where found.");
                }
            }
          }, $interval);
    });
