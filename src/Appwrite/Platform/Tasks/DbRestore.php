<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Validator\Text;

//  docker compose exec appwrite-backup db-restore --file=2023-07-12_12:10:22.tar.gz

class DbRestore extends Action
{
    protected string $extract = '/extracts';

    public static function getName(): string
    {
        return 'db-restore';
    }

    public function __construct()
    {
        $this
            ->desc('Restore a DB')
            ->param('file', '', new Text(100), 'Backup file name')
            ->callback(fn ($file) => $this->action($file));
    }

    /**
     * @throws \Exception
     */
    public function action(string $file): void
    {
        // $file = '2023-07-12_09:37:47.tar.gz';

        Console::log('Restoring backup' . $file);

        $file = DbBackup::$backups . '/' . $file;

        if (!file_exists($file) || empty($file)) {
            Console::error('File not found: ' . $file);
            Console::exit();
        }

        // Todo: shut down target container

        $extract = $this->extract . '/' . time();
        if (!file_exists($extract) && !mkdir($extract, 0755, true)) {
            Console::error('Error creating directory: ' . $extract);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';

//        $start = microtime(true);
//        Console::log($start);

        $cmd = 'tar -xzf ' . $file . ' -C ' . $this->extract;
        Console::log($cmd);
        $code = Console::execute($cmd, '', $stdout, $stderr);

//        Console::log('time end ' . (microtime(true) - $start));

        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        Console::log($stdout);
//        // Todo: start down target container

        Console::log("Restore Finish");
    }
}
