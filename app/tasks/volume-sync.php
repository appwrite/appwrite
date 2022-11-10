<?php

global $cli;

use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

$cli
    ->task('volume-sync')
    ->desc('Runs rsync to sync certificates between the storage mount and traefik.')
    ->param('source', null, new Text(255), 'Source path to sync from.', false)
    ->param('destination', null, new Text(255), 'Destination path to sync to.', false)
    ->param('interval', null, new Integer(true), 'Interval to run rsync', false)
    ->action(function ($source, $destination, $interval) {

        Console::title('RSync V1');
        Console::success(APP_NAME . ' rsync process v1 has started');

        Console::loop(function () use ($interval, $source, $destination) {
            $time = DateTime::now();

            Console::info("[{$time}] Executing rsync every {$interval} seconds");
            Console::info("Syncing between $source and $destination");

            if (!file_exists($source)) {
                Console::error('Source directory does not exist. Skipping ... ');
                return;
            }

            $stdin = "";
            $stdout = "";
            $stderr = "";

            Console::execute("rsync -av $source $destination", $stdin, $stdout, $stderr);
            Console::success($stdout);
            Console::error($stderr);
        }, $interval);
    });
