<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\DSN\DSN;
use Utopia\Platform\Action;
use Utopia\Storage\Device\DOSpaces;
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
            ->callback(fn () => $this->action());
    }

    public function action(): void
    {
        Console::title('Folder backup V1');
        Console::success(APP_NAME . ' folder backup process v1 has started');

        $interval = (int) App::getEnv('_APP_MAINTENANCE_INTERVAL', '86400');
        Console::loop(function () use ($interval) {
            $folders = [
                'cert' => APP_STORAGE_CERTIFICATES,
            ];

            foreach ($folders as $key => $folder) {
                $local = new Local($folder);
                $filename = $key . '-' . date("Y-m-d") . '.tar.gz';
                $source   = $local->getRoot() . '/' . $filename;
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
                    $connection = App::getEnv('_APP_CONNECTIONS_STORAGE', '');
                    $dsn = new DSN($connection);
                    $remote = new DOSpaces(
                        '/',
                        $dsn->getUser(),
                        $dsn->getPassword(),
                        $dsn->getPath(),
                        $dsn->getParam('region')
                    );
                    $result = $local->transfer($source, $destination, $remote);
                    var_dump($result);
                    Console::log('done');
                } catch (\Exception $e) {
                    Console::error($e->getMessage());
                }
            }
        }, $interval);
    }
}
