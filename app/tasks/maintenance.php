<?php

global $cli;

use Appwrite\Event\Delete;
use Utopia\App;
use Utopia\CLI\Console;

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
                ->setTimestamp(time() - $interval)
                ->trigger();
        }

        function notifyDeleteAbuseLogs(int $interval)
        {
            (new Delete())
                ->setType(DELETE_TYPE_ABUSE)
                ->setTimestamp(time() - $interval)
                ->trigger();
        }

        function notifyDeleteAuditLogs(int $interval)
        {
            (new Delete())
                ->setType(DELETE_TYPE_AUDIT)
                ->setTimestamp(time() - $interval)
                ->trigger();
        }

        function notifyDeleteUsageStats(int $interval30m, int $interval1d)
        {
            (new Delete())
                ->setType(DELETE_TYPE_USAGE)
                ->setTimestamp1d(time() - $interval1d)
                ->setTimestamp30m(time() - $interval30m)
                ->trigger();
        }

        function notifyDeleteConnections()
        {
            (new Delete())
                ->setType(DELETE_TYPE_REALTIME)
                ->setTimestamp(time() - 60)
                ->trigger();
        }

        // # of days in seconds (1 day = 86400s)
        $interval = (int) App::getEnv('_APP_MAINTENANCE_INTERVAL', '86400');
        $executionLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', '1209600');
        $auditLogRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', '1209600');
        $abuseLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', '86400');
        $usageStatsRetention30m = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_30M', '129600'); //36 hours
        $usageStatsRetention1d = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_1D', '8640000'); // 100 days

        Console::loop(function () use ($interval, $executionLogsRetention, $abuseLogsRetention, $auditLogRetention, $usageStatsRetention30m, $usageStatsRetention1d) {
            $time = date('d-m-Y H:i:s', time());
            Console::info("[{$time}] Notifying deletes workers every {$interval} seconds");
            notifyDeleteExecutionLogs($executionLogsRetention);
            notifyDeleteAbuseLogs($abuseLogsRetention);
            notifyDeleteAuditLogs($auditLogRetention);
            notifyDeleteUsageStats($usageStatsRetention30m, $usageStatsRetention1d);
            notifyDeleteConnections();
        }, $interval);
    });
