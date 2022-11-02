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
    ->task('sync-edge')
    ->desc('Schedules edge sync tasks')
    ->action(function () use ($register) {
        Console::title('Syncs edges V1');
        Console::success(APP_NAME . ' Sync failed cache purge process v1 has started');

        $fallbackForRedis = AppwriteURL::unparse([
            'scheme' => 'redis',
            'host' => App::getEnv('_APP_REDIS_HOST', 'redis'),
            'port' => App::getEnv('_APP_REDIS_PORT', '6379'),
            'user' => App::getEnv('_APP_REDIS_USER', ''),
            'pass' => App::getEnv('_APP_REDIS_PASS', ''),
        ]);

        $connection = App::getEnv('_APP_CONNECTIONS_QUEUE', $fallbackForRedis);
        $dsns = explode(',', $connection ?? '');

        if (empty($dsns)) {
            Console::error("No Dsn found");
        }

        $dsn = explode('=', $dsns[0]);
        $dsn = $dsn[1] ?? '';
        $dsn = new DSN($dsn);
        $redisConnection = new Queue\Connection\Redis($dsn->getHost(), $dsn->getPort());
        $client = new SyncOut('syncOut', $redisConnection);


        // Todo fix pdo  PDOException
        // Table 'appwrite.console__metadata' doesn't exist
        sleep(4);

        $interval = (int) App::getEnv('_APP_SYNC_EDGE_INTERVAL', '180');
          Console::loop(function () use ($interval, $register, $client) {

            $database = getConsoleDB();
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
