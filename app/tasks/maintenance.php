<?php

global $cli;

use Utopia\App;
use Utopia\CLI\Console;

$cli
    ->task('maintenance')
    ->desc('Schedules maintenance tasks and publishes them to resque')
    ->action(function () {
        $interval = App::getEnv('_APP_MAINTENANCE_INTERVAL', '');

        for($i = 0; $i <= 10; ++$i){
            Console::log('Starting the maintenance worker every '.$interval.' seconds');
            sleep($interval);
        }
        
    });