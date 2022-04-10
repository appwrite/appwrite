<?php

global $cli;
global $register;

use Appwrite\Event\Event;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Registry\Registry;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Database\Query;

function getDatabase(Registry &$register)
{
    $attempts = 0;

    do {
        try {
            $attempts++;

            $db = $register->get('dbPool')->get();
            $redis = $register->get('redisPool')->get();

            $cache = new Cache(new RedisCache($redis));
            $database = new Database(new MariaDB($db), $cache);
            $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
            $database->setNamespace('_console');

            break; // leave loop if successful
        } catch(\Exception $e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                throw new \Exception('Failed to connect to database: '. $e->getMessage());
            }
            sleep(DATABASE_RECONNECT_SLEEP);
        }
    } while ($attempts < DATABASE_RECONNECT_MAX_ATTEMPTS);

    return [
        $database,
        function () use ($register, $db, $redis) {
            $register->get('dbPool')->put($db);
            $register->get('redisPool')->put($redis);
        }
    ];

};

$cli
    ->task('maintenance')
    ->desc('Schedules maintenance tasks and publishes them to resque')
    ->action(function () use ($register) {
        Console::title('Maintenance V1');
        Console::success(APP_NAME.' maintenance process v1 has started');

        function notifyDeleteExecutionLogs(int $interval)
        {
            Resque::enqueue(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME, [
                'type' => DELETE_TYPE_EXECUTIONS,
                'timestamp' => time() - $interval
            ]);
        }

        function notifyDeleteAbuseLogs(int $interval) 
        {
            Resque::enqueue(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME, [
                'type' =>  DELETE_TYPE_ABUSE,
                'timestamp' => time() - $interval
            ]);
        }

        function notifyDeleteAuditLogs(int $interval) 
        {
            Resque::enqueue(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME, [
                'type' => DELETE_TYPE_AUDIT,
                'timestamp' => time() - $interval
            ]);
        }

        function notifyDeleteUsageStats(int $interval30m, int $interval1d) 
        {
            Resque::enqueue(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME, [
                'type' => DELETE_TYPE_USAGE,
                'timestamp1d' => time() - $interval1d,
                'timestamp30m' => time() - $interval30m,
            ]);
        }

        function notifyDeleteConnections() 
        {
            Resque::enqueue(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME, [
                'type' => DELETE_TYPE_REALTIME,
                'timestamp' => time() - 60
            ]);
        }

        function renewCertificates($dbForConsole)
        {
            $time = date('d-m-Y H:i:s', time());
            /** @var Utopia\Database\Database $dbForConsole */

            $certificates = $dbForConsole->find('certificates', [
                new Query('attempts', Query::TYPE_LESSER, [5]), // Maximum 5 attempts
                new Query('renewDate', Query::TYPE_LESSEREQUAL, [\time()]) // includes 60 days cooldown (we have 30 days to renew)
            ], 300); // Limit 300 comes from LetsEncrypt (orders per 3 hours)

            if(\count($certificates) > 0) {
                Console::info("[{$time}] Found " . \count($certificates) . " certificates for renewal, scheduling jobs.");

                foreach ($certificates as $certificate) {
                    Resque::enqueue(Event::CERTIFICATES_QUEUE_NAME, Event::CERTIFICATES_CLASS_NAME, [
                        'domain' => $certificate->getAttribute('domain'),
                    ]);
                }
            } else {
                Console::info("[{$time}] No certificates for renewal.");
            }
        }

        // # of days in seconds (1 day = 86400s)
        $interval = (int) App::getEnv('_APP_MAINTENANCE_INTERVAL', '86400');
        $executionLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', '1209600');
        $auditLogRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', '1209600');
        $abuseLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', '86400');
        $usageStatsRetention30m = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_30M', '129600');//36 hours
        $usageStatsRetention1d = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_1D', '8640000'); // 100 days

        Console::loop(function() use ($register, $interval, $executionLogsRetention, $abuseLogsRetention, $auditLogRetention, $usageStatsRetention30m, $usageStatsRetention1d) { 
            go(function () use ($register, $interval, $executionLogsRetention, $abuseLogsRetention, $auditLogRetention, $usageStatsRetention30m, $usageStatsRetention1d) {
                try {
                    [$database, $returnDatabase] = getDatabase($register, '_console');
    
                    $time = date('d-m-Y H:i:s', time());
                    Console::info("[{$time}] Notifying deletes workers every {$interval} seconds");
                    notifyDeleteExecutionLogs($executionLogsRetention);
                    notifyDeleteAbuseLogs($abuseLogsRetention);
                    notifyDeleteAuditLogs($auditLogRetention);
                    notifyDeleteUsageStats($usageStatsRetention30m, $usageStatsRetention1d);
                    notifyDeleteConnections();
    
                    renewCertificates($database);
                } catch (\Throwable $th) {
                    throw $th;
                } finally {
                    call_user_func($returnDatabase);
                }
            });
        }, $interval);
    });