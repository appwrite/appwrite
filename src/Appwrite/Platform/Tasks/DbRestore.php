<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Validator\Hostname;

class DbRestore extends Action
{
    public string $extract = '/extracts';

    public static function getName(): string
    {
        return 'db-restore';
    }

    public function __construct()
    {
        //docker compose exec appwrite backup
        $this
            ->desc('Restore a DB')
            ->param('domain', App::getEnv('_APP_DOMAIN', ''), new Hostname(), 'Domain.', true)
            ->callback(fn ($domain) => $this->action($domain));
    }

    /**
     * @throws \Exception
     */
    public function action(string $domain): void
    {
        $filename = '2023-07-12_09:37:47.tar.gz';

        Console::log('Restoring backup' . $filename);

        $file = DbBackup::$backups . '/' . $filename;

        if (!file_exists($file)) {
            Console::error('File not found: ' . $file);
            Console::exit();
        }

        // Todo: shut down target container

        if (!file_exists($this->extract) && !mkdir($this->extract, 0755, true)) {
            Console::error('Error creating directory: ' . $this->extract);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';

        $start = microtime(true);
        Console::log($start);

        $cmd = 'tar -xzf ' . $file . ' -C ' . $this->extract;
        Console::log($cmd);
        $code = Console::execute($cmd, '', $stdout, $stderr);

        Console::log('time end ' . (microtime(true) - $start));

        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        Console::log($stdout);
//        // Todo: start down target container

        Console::log("Restore Finish");
    }
}
