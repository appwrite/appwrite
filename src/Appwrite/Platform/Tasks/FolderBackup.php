<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Platform\Action;
use Utopia\Storage\Device\Local;

class FolderBackup extends Action
{
    public static function getName(): string
    {
        return 'folder-backup';
    }

    public function __construct()
    {
        $this
            ->desc('Folder backup and restore process')
            ->callback(fn() => $this->action());
    }

    public function action(): void
    {
        Console::title('Folder backup V1');
        Console::success(APP_NAME . ' folder backup process v1 has started');

        gc_enable();
        $time = 0;
        while (!connection_aborted() || PHP_SAPI == "cli") {
            $now  = DateTime::now();


            $now = new \DateTime();
            $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $next = new \DateTime($now->format("Y-m-d 12:10:0.0"));
            $next->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $sleep  = $next->getTimestamp() - $now->getTimestamp();

            /**
             * If time passed the target time
             */
            if ($sleep <= 0) {
                $next->add(\DateInterval::createFromDateString('1 days'));
                $sleep  = $next->getTimestamp() - $now->getTimestamp();
            }

            console::log('[' . $now->format("Y-m-d H:i:s.v") . '] Sleeping for ' . $sleep . ' seconds next run will be at [' . $next->format("Y-m-d H:i:s.v") . ']');

            sleep($sleep);

            $folders = [
                'cert' => APP_STORAGE_CERTIFICATES,
            ];

             $remote = getDevice('/');

            foreach ($folders as $key => $folder) {
                $local = new Local($folder);
                $filename = $key . '-' . date("Y-m-d") . '.tar.gz';
                $source = $local->getRoot() . '/' . $filename;
                $destination = '/' . $key . '/' . $filename;
                $content = $local->getRoot() . '/*';

//            for ($i = 0; $i < 1000; $i++) {
//                file_put_contents($root->getRoot() . '/' . $i . '.txt', '');
//            }

                $stdout = '';
                $stderr = '';
                Console::execute(
                    'tar --exclude ' . $filename . ' -zcf ' . $source . ' ' . $content,
                    '',
                    $stdout,
                    $stderr
                );

                try {
                    $local->transfer($source, $destination, $remote);
                    console::info("backing up local $source to $remote->getName() $destination");
                } catch (\Exception $e) {
                    Console::error($e->getMessage());
                }

                if (PHP_SAPI == "cli") {
                    if ($time >= 60 * 5) {
                        $time = 0;
                        gc_collect_cycles();
                    }
                }
            }
        }
    }
}
