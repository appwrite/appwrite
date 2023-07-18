<?php

global $cli;
global $register;

use Appwrite\Auth\Auth;
use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Utopia\Http\Http;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Database\Document;
use Utopia\Database\Query;

function getConsoleDB(): Database
{
    global $register;

    $attempts = 0;

    do {
        try {
            $attempts++;
            $cache = new Cache(new RedisCache($register->get('cache')));
            $database = new Database(new MariaDB($register->get('db')), $cache);
            $database->setDefaultDatabase(Http::getEnv('_APP_DB_SCHEMA', 'appwrite'));
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

$cli
    ->task('maintenance')
    ->desc('Schedules maintenance tasks and publishes them to resque')
    ->action(function () {
        Console::title('Maintenance V1');
        Console::success(APP_NAME . ' maintenance process v1 has started');

        function notifyDeleteExecutionLogs(int $interval)
        {
            (new Delete())
                ->setType(DELETE_TYPE_EXECUTIONS)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
                ->trigger();
        }

        function notifyDeleteAbuseLogs(int $interval)
        {
            (new Delete())
                ->setType(DELETE_TYPE_ABUSE)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
                ->trigger();
        }

        function notifyDeleteAuditLogs(int $interval)
        {
            (new Delete())
                ->setType(DELETE_TYPE_AUDIT)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
                ->trigger();
        }

        function notifyDeleteUsageStats(int $usageStatsRetentionHourly)
        {
            (new Delete())
                ->setType(DELETE_TYPE_USAGE)
                ->setUsageRetentionHourlyDateTime(DateTime::addSeconds(new \DateTime(), -1 * $usageStatsRetentionHourly))
                ->trigger();
        }

        function notifyDeleteConnections()
        {
            (new Delete())
                ->setType(DELETE_TYPE_REALTIME)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -60))
                ->trigger();
        }

        function notifyDeleteExpiredSessions()
        {
            (new Delete())
                ->setType(DELETE_TYPE_SESSIONS)
                ->trigger();
        }

        function renewCertificates($dbForConsole)
        {
            $time = DateTime::now();

            $certificates = $dbForConsole->find('certificates', [
               Query::lessThan('attempts', 5), // Maximum 5 attempts
               Query::lessThanEqual('renewDate', $time), // includes 60 days cooldown (we have 30 days to renew)
               Query::limit(200), // Limit 200 comes from LetsEncrypt (300 orders per 3 hours, keeping some for new domains)
            ]);


            if (\count($certificates) > 0) {
                Console::info("[{$time}] Found " . \count($certificates) . " certificates for renewal, scheduling jobs.");

                $event = new Certificate();
                foreach ($certificates as $certificate) {
                    $event
                        ->setDomain(new Document([
                            'domain' => $certificate->getAttribute('domain')
                        ]))
                        ->trigger();
                }
            } else {
                Console::info("[{$time}] No certificates for renewal.");
            }
        }

        function notifyDeleteCache($interval)
        {

            (new Delete())
                ->setType(DELETE_TYPE_CACHE_BY_TIMESTAMP)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
                ->trigger();
        }

        // # of days in seconds (1 day = 86400s)
        $interval = (int) Http::getEnv('_APP_MAINTENANCE_INTERVAL', '86400');
        $executionLogsRetention = (int) Http::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', '1209600');
        $auditLogRetention = (int) Http::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', '1209600');
        $abuseLogsRetention = (int) Http::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', '86400');
        $usageStatsRetentionHourly = (int) Http::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_HOURLY', '8640000'); //100 days

        $cacheRetention = (int) Http::getEnv('_APP_MAINTENANCE_RETENTION_CACHE', '2592000'); // 30 days

        Console::loop(function () use ($interval, $executionLogsRetention, $abuseLogsRetention, $auditLogRetention, $usageStatsRetentionHourly, $cacheRetention) {
            $database = getConsoleDB();

            $time = DateTime::now();

            Console::info("[{$time}] Notifying workers with maintenance tasks every {$interval} seconds");
            notifyDeleteExecutionLogs($executionLogsRetention);
            notifyDeleteAbuseLogs($abuseLogsRetention);
            notifyDeleteAuditLogs($auditLogRetention);
            notifyDeleteUsageStats($usageStatsRetentionHourly);
            notifyDeleteConnections();
            notifyDeleteExpiredSessions();
            renewCertificates($database);
            notifyDeleteCache($cacheRetention);
        }, $interval);
    });
