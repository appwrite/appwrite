<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
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

    /**
     * @throws \Exception
     */
    public function action(): void
    {
        Console::title('Backup V1');
        Console::success(APP_NAME . ' backup process v1 has started');

        $jobInitTime = '13:10'; // (hour:minutes)
        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $next = new \DateTime($now->format("Y-m-d $jobInitTime"));
        $next->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $delay = $next->getTimestamp() - $now->getTimestamp();

        /**
         * If time passed for the target day.
         */
        if ($delay <= 0) {
            $next->add(\DateInterval::createFromDateString('1 days'));
            $delay = $next->getTimestamp() - $now->getTimestamp();
        }

        $folders = [
            'cert' => APP_STORAGE_CERTIFICATES,
            'config' => APP_STORAGE_CONFIG,
        ];

        $sleep = 86400; //24 hours
        $sleep = 60;
        $remote = getDevice('/');

        Console::log('[' . $now->format("Y-m-d H:i:s.v") . '] Delaying for ' . $delay . ' setting loop to [' . $next->format("Y-m-d H:i:s.v") . ']');
        Console::loop(function () use ($delay, $sleep, $folders, $remote) {
            $success = 0;
            foreach ($folders as $key => $folder) {
                $local = new Local($folder);
                $filename = $key . '-' . date("Y-m-d") . '.tar.gz';
                $source = $local->getRoot() . '/' . $filename;
                $destination = '/' . $key . '/' . $filename;

//                for ($i = 0; $i < 1000; $i++) {
//                    file_put_contents($local->getRoot() . '/' . $i . '.txt', '');
//                }

                $stdout = '';
                $stderr = '';

                Console::execute(
                    'cd ' . $folder . ' && tar --exclude ' . $filename . ' -zcf ' . $source . ' *',
                    '',
                    $stdout,
                    $stderr
                );


                try {
                    if (!$local->exists($source)) {
                        continue;
                    }

                    $local->transfer($source, $destination, $remote);

                    if ($remote->exists($destination)) {
                        $success++;
                    }

                    Console::info("Backing up local $source to {$remote->getName()} $destination");
                    /**
                     * Backup folder, long tail cleanup.
                     */
                    $now = new \DateTime();
                    $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                    for ($i = 0; $i < 20; $i++) {
                        $now->sub(\DateInterval::createFromDateString('1 days'));
                        if ($i >= 10) {
                            $destination = '/' . $key . '/' . $key . '-' . $now->format("Y-m-d") . '.tar.gz';
                            $remote->delete($destination);
                        }
                    }
                } catch (\Exception $e) {
                    Console::error($e->getMessage());
                }
            }

            /**
             * heartbeat url
             */
            $url = App::getEnv('_APP_BACKUP_HEARTBEAT', '');
            if ($success === count($folders) && $url !== '') {
                file_get_contents($url);
            }
        }, $sleep, $delay);
    }
}
