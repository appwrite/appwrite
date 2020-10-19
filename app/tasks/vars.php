<?php

global $cli;

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;

$cli
    ->task('vars')
    ->desc('List all the server environment variables')
    ->action(function () {
        $variables = Config::getParam('variables', []);

        foreach ($variables as $key => $value) {
            Console::log('- '.$value['name'].'='.App::getEnv($value['name'], ''));
        }
    });