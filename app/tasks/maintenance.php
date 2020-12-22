<?php

global $cli;

require_once __DIR__.'/../init.php';

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;

// TODO: Think of a better way to access consoleDB
function getConsoleDB() {
    global $register;
    $consoleDB = new Database();
    $consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
    $consoleDB->setNamespace('app_console'); // Main DB
    $consoleDB->setMocks(Config::getParam('collections', []));
    return $consoleDB;
}

function notifyDeleteExecutionLogs()
{
    Resque::enqueue(DELETE_QUEUE_NAME, DELETE_CLASS_NAME, [
        'type' => DELETE_TYPE_EXECUTION_LOGS,
        'document' => new Document([
            '$collection' => Database::SYSTEM_COLLECTION_EXECUTIONS
        ])
    ]);
}

function notifyDeleteAbuseLogs(int $interval) 
{
    Resque::enqueue(DELETE_QUEUE_NAME, DELETE_CLASS_NAME, [
        'type' =>  DELETE_TYPE_ABUSE,
        'timestamp' => time() - $interval
    ]);
}

function notifyDeleteAuditLogs(int $interval) 
{
    Resque::enqueue(DELETE_QUEUE_NAME, DELETE_CLASS_NAME, [
        'type' => DELETE_TYPE_AUDIT,
        'timestamp' => time() - $interval
    ]);
}

$cli
    ->task('maintenance')
    ->desc('Schedules maintenance tasks and publishes them to resque')
    ->action(function () {
        // # of days in seconds (1 day = 86400s)
        $interval = App::getEnv('_APP_MAINTENANCE_INTERVAL', '') + 0;
        //Convert Seconds to microseconds
        $intervalMicroseconds = $interval * 1000000;

        $consoleDB = getConsoleDB();

        Console::loop(function() use ($consoleDB, $interval){

            Console::info("[ MAINTENANCE TASK ] Notifying deletes workers every {$interval} seconds");

            notifyDeleteExecutionLogs();
            notifyDeleteAbuseLogs($interval);
            notifyDeleteAuditLogs($interval);
            
        }, $intervalMicroseconds);

    });