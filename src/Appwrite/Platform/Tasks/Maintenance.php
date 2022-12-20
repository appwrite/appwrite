<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Auth\Auth;
use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Func;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Queue\Connection;
use Utopia\Registry\Registry;

class Maintenance extends Action
{
    public static function getName(): string
    {
        return 'maintenance';
    }

    public function __construct()
    {
        $this
            ->desc('Schedules maintenance tasks and publishes them to queues')
            ->inject('dbForConsole')
            ->inject('certificates')
            ->inject('deletes')
            ->callback(fn (Database $dbForConsole, Certificate $certificates, Delete $deletes) => $this->action($dbForConsole, $certificates, $deletes));
    }

    public function action(Database $dbForConsole, Certificate $certificates, Delete $deletes): void
    {
        Console::title('Maintenance V1');
        Console::success(APP_NAME . ' maintenance process v1 has started');

        function notifyDeleteExecutionLogs(int $interval, Delete $deletes)
        {
            ($deletes)
                ->setType(DELETE_TYPE_EXECUTIONS)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
                ->trigger();
        }

        function notifyDeleteAbuseLogs(int $interval, Delete $deletes)
        {
            ($deletes)
                ->setType(DELETE_TYPE_ABUSE)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
                ->trigger();
        }

        function notifyDeleteAuditLogs(int $interval, Delete $deletes)
        {
            ($deletes)
                ->setType(DELETE_TYPE_AUDIT)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
                ->trigger();
        }

        function notifyDeleteUsageStats(int $usageStatsRetentionHourly, Delete $deletes)
        {
            ($deletes)
                ->setType(DELETE_TYPE_USAGE)
                ->setUsageRetentionHourlyDateTime(DateTime::addSeconds(new \DateTime(), -1 * $usageStatsRetentionHourly))
                ->trigger();
        }

        function notifyDeleteConnections(Delete $deletes)
        {
            ($deletes)
                ->setType(DELETE_TYPE_REALTIME)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -60))
                ->trigger();
        }

        function notifyDeleteExpiredSessions(Delete $deletes)
        {
            ($deletes)
                ->setType(DELETE_TYPE_SESSIONS)
                ->trigger();
        }

        function renewCertificates(Database $dbForConsole, Certificate $queueForCertificate)
        {
            $time = DateTime::now();

            $certificates = $dbForConsole->find('certificates', [
               Query::lessThan('attempts', 5), // Maximum 5 attempts
               Query::lessThanEqual('renewDate', $time), // includes 60 days cooldown (we have 30 days to renew)
               Query::limit(200), // Limit 200 comes from LetsEncrypt (300 orders per 3 hours, keeping some for new domains)
            ]);


            if (\count($certificates) > 0) {
                Console::info("[{$time}] Found " . \count($certificates) . " certificates for renewal, scheduling jobs.");

                foreach ($certificates as $certificate) {
                    $queueForCertificate
                        ->setDomain(new Document([
                            'domain' => $certificate->getAttribute('domain')
                        ]))
                        ->trigger();
                }
            } else {
                Console::info("[{$time}] No certificates for renewal.");
            }
        }

        function notifyDeleteCache($interval, Delete $deletes)
        {

            ($deletes)
                ->setType(DELETE_TYPE_CACHE_BY_TIMESTAMP)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
                ->trigger();
        }

        function notifyDeleteSchedules($interval, Delete $deletes)
        {

            ($deletes)
                ->setType(DELETE_TYPE_SCHEDULES)
                ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
                ->trigger();
        }

        // # of days in seconds (1 day = 86400s)
        $interval = (int) App::getEnv('_APP_MAINTENANCE_INTERVAL', '86400');
        $executionLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', '1209600');
        $auditLogRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', '1209600');
        $abuseLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', '86400');
        $usageStatsRetentionHourly = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_HOURLY', '8640000'); //100 days

        $cacheRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_CACHE', '2592000'); // 30 days
        $schedulesDeletionRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_SCHEDULES', '86400'); // 1 Day

        Console::loop(function () use ($interval, $executionLogsRetention, $abuseLogsRetention, $auditLogRetention, $cacheRetention, $schedulesDeletionRetention, $usageStatsRetentionHourly, $dbForConsole, $deletes, $certificates) {
            $time = DateTime::now();

            Console::info("[{$time}] Notifying workers with maintenance tasks every {$interval} seconds");
            notifyDeleteExecutionLogs($executionLogsRetention, $deletes);
            notifyDeleteAbuseLogs($abuseLogsRetention, $deletes);
            notifyDeleteAuditLogs($auditLogRetention, $deletes);
            notifyDeleteUsageStats($usageStatsRetentionHourly, $deletes);
            notifyDeleteConnections($deletes);
            notifyDeleteExpiredSessions($deletes);
            renewCertificates($dbForConsole, $certificates);
            notifyDeleteCache($cacheRetention, $deletes);
            notifyDeleteSchedules($schedulesDeletionRetention, $deletes);
        }, $interval);
    }
}
