<?php

global $cli;
global $register;

use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Database\Query;


function getConsoleDatabase(): Database
{
    global $register;

    $attempts = 0;

    do {
        try {
            $attempts++;
            $cache = new Cache(new RedisCache($register->get('cache')));
            $database = new Database(new MariaDB($register->get('db')), $cache);
            $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
            $database->setNamespace('_console'); // Main DB

            if (!$database->exists($database->getDefaultDatabase(), 'certificates')) {
                throw new \Exception('Console project not ready');
            }

            break; // leave loop if successful
        } catch (\Exception $e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                throw new \Exception('Failed to connect to database: ' . $e->getMessage());
            }
            sleep(DATABASE_RECONNECT_SLEEP);
        }
    } while ($attempts < DATABASE_RECONNECT_MAX_ATTEMPTS);

    return $database;
}

function syncRegionalCache($dbForConsole, $regionOrg): void
{
    global $register;

    $time = DateTime::now();
    $chunks = $dbForConsole->find('syncs', [
        Query::equal('regionOrg', [$regionOrg]),
        Query::limit(500)
    ]);

    if (count($chunks) > 0) {
        Console::info("[{$time}] Found " . \count($chunks) . " cache key chunks to purge.");
        foreach ($chunks as $chunk) {
                $register
                    ->get('workerSyncOut')
                    ->resetStats();

                $register
                    ->get('workerSyncOut')
                    ->enqueue([
                        'type' => 'from cloud maintenance',
                        'value' => [
                            'region' => $chunk->getAttribute('regionDest'),
                            'chunk' => $chunk->getAttribute('keys')
                        ]
                    ]);

            $dbForConsole->deleteDocument('syncs', $chunk->getId());
        }
    } else {
        Console::info("[{$time}] No cache key chunks where found.");
    }
}

$cli
    ->task('syncsCloud')
    ->desc('Schedules cloud sync tasks')
    ->action(function () {
        Console::title('Syncs cloud V1');
        Console::success(APP_NAME . ' Syncs cloud process v1 has started');

        $interval = (int) App::getEnv('_APP_SYNCS_CLOUD_INTERVAL', '180');

        Console::loop(function () use ($interval) {
            $database = getConsoleDatabase();
            $time = DateTime::now();
            $currentRegion = App::getEnv('_APP_REGION', 'nyc1');
            Console::info("[{$time}] Notifying workers with cloud tasks every {$interval} seconds");
            syncRegionalCache($database, $currentRegion);
        }, $interval);
    });
