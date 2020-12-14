<?php

global $cli;

require_once __DIR__.'/../init.php';

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Utopia\App;
use Utopia\CLI\Console;


$cli
    ->task('maintenance')
    ->desc('Schedules maintenance tasks and publishes them to resque')
    ->action(function () {
        // Convert string to integer
        $interval = App::getEnv('_APP_MAINTENANCE_INTERVAL', '') + 0;
        //Convert Seconds to microseconds
        $interval = $interval * 1000000;

        Console::loop(function() {
            $projects = $consoleDB->getCollection([
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_PROJECTS,
                ],
            ]);
            var_dump("*************** MAINTENANCE WORKER *****************");
            print_r($projects);

            Resque::enqueue('v1-deletes', 'DeletesV1', [
                'document' => new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_EXECUTIONS,
                    'projectIds' => $this->projects 
                ]),
            ]);
        }, $interval);

    });