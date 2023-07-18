<?php

global $cli;

use Utopia\Http\Http;
use Utopia\CLI\Console;
use Utopia\Config\Config;

$cli
    ->task('vars')
    ->desc('List all the server environment variables')
    ->action(function () {
        $config = Config::getParam('variables', []);
        $vars = [];

        foreach ($config as $category) {
            foreach ($category['variables'] ?? [] as $var) {
                $vars[] = $var;
            }
        }

        foreach ($vars as $key => $value) {
            Console::log('- ' . $value['name'] . '=' . Http::getEnv($value['name'], ''));
        }
    });
