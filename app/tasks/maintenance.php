<?php

global $cli;

require_once __DIR__.'/../init.php';

use Appwrite\Event\Event;
use Utopia\App;
use Utopia\CLI\Console;

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

$cli
    ->task('maintenance')
    ->desc('Schedules maintenance tasks and publishes them to resque')
    ->action(function () {
        // # of days in seconds (1 day = 86400s)
        $interval = (int) App::getEnv('_APP_MAINTENANCE_INTERVAL', '86400');
        $executionLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', '1209600');
        $auditLogRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', '1209600');
        $abuseLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', '86400');

        Console::loop(function() use ($interval, $executionLogsRetention, $abuseLogsRetention, $auditLogRetention){
            $time = date('d-m-Y H:i:s', time());
            Console::info("[{$time}] Notifying deletes workers every {$interval} seconds");
            notifyDeleteExecutionLogs($executionLogsRetention);
            notifyDeleteAbuseLogs($abuseLogsRetention);
            notifyDeleteAuditLogs($auditLogRetention);
        }, $interval);
    });