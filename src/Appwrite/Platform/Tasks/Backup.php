<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Platform\Action;
use Utopia\Storage\Device\Local;

class Backup extends Action
{
    public static function getName(): string
    {
        return 'backup';
    }

    public function __construct()
    {
        $this
            ->desc('Backup process')
            ->callback(fn() => $this->action());
    }

    public function action(): void
    {
        Console::title('Backup V1');
        Console::success(APP_NAME . ' backup process v1 has started');

        gc_enable();
        $time = 0;
        while (!connection_aborted() || PHP_SAPI == "cli") {
            $now = new \DateTime();
            $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $next = new \DateTime($now->format("Y-m-d 11:30.0"));
            $next->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $sleep = $next->getTimestamp() - $now->getTimestamp();

            /**
             * If time passed the target time
             */
            if ($sleep <= 0) {
                $next->add(\DateInterval::createFromDateString('1 days'));
                $sleep = $next->getTimestamp() - $now->getTimestamp();
            }

            Console::log('[' . $now->format("Y-m-d H:i:s.v") . '] Sleeping for ' . $sleep . ' seconds next run will be at [' . $next->format("Y-m-d H:i:s.v") . ']');

            sleep($sleep);

            $total = 0;
            $start = \microtime(true);

            $folders = [
                'cert' => APP_STORAGE_CERTIFICATES,
                'config' => APP_STORAGE_CONFIG,
            ];

            $remote = getDevice('/');

            foreach ($folders as $key => $folder) {
                $local = new Local($folder);
                $filename = $key . '-' . date("Y-m-d") . '.tar.gz';
                $source = $local->getRoot() . '/' . $filename;
                $destination = '/' . $key . '/' . $filename;

                for ($i = 0; $i < 1000; $i++) {
                    file_put_contents($local->getRoot() . '/' . $i . '.txt', '');
                }

                $stdout = '';
                $stderr = '';

                Console::execute(
                    'cd ' . $folder . ' && tar --exclude ' . $filename . ' -zcf ' . $source . '*',
                    '',
                    $stdout,
                    $stderr
                );

                try {
                    if (!$local->exists($source)) {
                        continue;
                    }

                    $local->transfer($source, $destination, $remote);
                    Console::info("Backing up local $source to {$remote->getName()} $destination");

                    /**
                     * Remote storage old backup files clean-up
                     */
                    $now = new \DateTime();
                    $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                    for ($i = 0; $i < 20; $i++) {
                        $now->sub(\DateInterval::createFromDateString('1 days'));
                        if ($i >= 10) {
                            $destination = '/' . $key . '/' . $key . '-' . $now->format("Y-m-d") . '.tar.gz';
                            Console::info("Trying to delete from {$remote->getName()} $destination");
                            $remote->delete($destination);
                        }
                    }
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
            $total = (microtime(true) - $start);
        }
    }
}
