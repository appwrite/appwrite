<?php

global $cli;

require_once __DIR__.'/../init.php';

use Appwrite\Event\Event;
use Utopia\App;
use Utopia\CLI\Console;

Console::title('Maintenance V1');

Console::success(APP_NAME.' maintenance process v1 has started');

function notifyDeleteExecutionLogs(int $retention)
{
    Resque::enqueue(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME, [
        'type' => DELETE_TYPE_EXECUTIONS,
        'timestamp' => time() - $retention
    ]);
}

function notifyDeleteAbuseLogs(int $retention) 
{
    Resque::enqueue(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME, [
        'type' =>  DELETE_TYPE_ABUSE,
        'timestamp' => time() - $retention
    ]);
}

function notifyDeleteAuditLogs(int $retention) 
{
    Resque::enqueue(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME, [
        'type' => DELETE_TYPE_AUDIT,
        'timestamp' => time() - $retention
    ]);
}

$cli
    ->task('maintenance')
    ->desc('Schedules maintenance tasks and publishes them to resque')
    ->action(function () {
        // # of days in seconds (1 day = 86400s)
        // $executionLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_EXECUTION_LOG_RETENTION', '60');
        $executionLogsRetention = 120;
        $abuseLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_ABUSE_LOG_RETENTION', '60');
        $auditLogRetention = (int) App::getEnv('_APP_MAINTENANCE_AUDIT_LOG_RETENTION', '60');

        // Schedule delete execution logs
        Console::loop(function() use ($executionLogsRetention){
            $time = date('d-m-Y H:i:s', time());
            Console::info("[{$time}] Notifying deletes workers every {$executionLogsRetention} seconds");
            notifyDeleteExecutionLogs($executionLogsRetention);
        }, $executionLogsRetention);

        // // Schedule delete abuse logs
        // Console::loop(function() use ($abuseLogsRetention){
        //     $time = date('d-m-Y H:i:s', time());
        //     Console::info("[{$time}] Notifying deletes workers every {$abuseLogsRetention} seconds");
        //     notifyDeleteAbuseLogs($abuseLogsRetention);
        // }, $abuseLogsRetention);

        // // Schedule delete audit logs
        // Console::loop(function() use ($auditLogRetention){
        //     $time = date('d-m-Y H:i:s', time());
        //     Console::info("[{$time}] Notifying deletes workers every {$auditLogRetention} seconds");
        //     notifyDeleteAuditLogs($auditLogRetention);
        // }, $auditLogRetention);

    });