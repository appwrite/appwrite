<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Platform\Action;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

class VolumeSync extends Action
{
    public static function getName(): string
    {
        return 'volume-sync';
    }

    public function __construct()
    {
        $this
            ->desc('Runs rsync to sync certificates between the storage mount and traefik.')
            ->param('source', null, new Text(255), 'Source path to sync from.', false)
            ->param('destination', null, new Text(255), 'Destination path to sync to.', false)
            ->param('interval', null, new Integer(true), 'Interval to run rsync', false)
            ->callback($this->action);
    }

    public function action(string $source, string $destination, int $interval)
    {

        Console::title('RSync V1');
        Console::success(APP_NAME . ' rsync process v1 has started');

        if (!file_exists($source)) {
            Console::error('Source directory does not exist. Exiting ... ');
            Console::exit(0);
        }

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
    }
}
