<?php

global $cli;
global $register;

use Appwrite\DSN\DSN;
use Appwrite\URL\URL as AppwriteURL;
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
        Console::success(APP_NAME . ' Sync Edge process v1 has started');

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

        // Todo fix pdo  PDOException
        //Table 'appwrite.console__metadata' doesn't exist
        sleep(4);
        $interval = (int) App::getEnv('_APP_SYNC_EDGE_INTERVAL', '180');
          Console::loop(function () use ($interval, $register, $dsn) {
            $database = getConsoleDB();
            $time = DateTime::now();
            $region = App::getEnv('_APP_REGION', 'default');
            if (App::getEnv('_APP_REGION', 'default') === 'default') {
                return;
            }

            Console::info("[{$time}] Notifying workers with edges tasks every {$interval} seconds");

            $time = DateTime::now();
            $chunks = $database->find('syncs', [
                Query::equal('region', [$region]),
                Query::limit(500)
            ]);

            if (count($chunks) > 0) {
                $client = new SyncOut('syncOut', new QueueRedis($dsn->getHost(), $dsn->getPort()));
                foreach ($chunks as $counter => $chunk) {
                    Console::info("[{$time}] Sending  chunk .$counter. ot of  " . count($chunks) . "  to  {$chunk->getAttribute('target')}");
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
