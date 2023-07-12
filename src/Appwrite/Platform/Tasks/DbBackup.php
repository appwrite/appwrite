<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Validator\Hostname;

class DbBackup extends Action
{
    protected string $directory = '/var/lib/mysql';
    public static string $backups = '/backups';
    protected string $containerName = 'appwrite-mariadb';

    public static function getName(): string
    {
        return 'db-backup';
    }

    public function __construct()
    {
        //docker compose exec appwrite backup
        $this
            ->desc('Backup a DB')
            ->param('domain', App::getEnv('_APP_DOMAIN', ''), new Hostname(), 'Domain.', true)
            ->callback(fn ($domain) => $this->action($domain));
    }

    /**
     * @throws \Exception
     */
    public function action(string $domain): void
    {

        if (empty(App::getEnv('_APP_CONNECTIONS_DB_PROJECT', ''))) {
            Console::error('Can\'t read .env variables');
            Console::exit();
        }

        $time = date('Y-m-d_H:i:s');
        Console::log('Backup started:' . $time);

//        Console::log('creating directory:' . $this->backups);
//        if (!file_exists($this->backups) && !mkdir($this->backups, 0755, true)) {
//            Console::error('Error creating directory: ' . $this->backups);
//            Console::exit();
//        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker stop ' . $this->containerName;
        Console::log($cmd);
        $code = Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        Console::log($stdout);

        $filename = $time . '.tar.gz';
        $file = self::$backups . '/' . $filename;

        //        $cmd = 'tar czf ' . $file . ' --absolute-names ' . $this->directory;
        //
        //        $cmd = '
        //        cd /var/lib/mysql
        //        tar czf ' . $file . ' --absolute-names *';
        //          Extract:::
        //           tar --strip-components=4 -xf 2023-07-11_12:40:47.tar.gz -C ./a2

        $stdout = '';
        $stderr = '';
        $cmd = 'cd ' . $this->directory . ' && tar zcf ' . $file . ' .';
        $code = Console::execute($cmd, '', $stdout, $stderr);
        Console::log($cmd);
        Console::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker start ' . $this->containerName;

        $code = Console::execute($cmd, '', $stdout, $stderr);

        Console::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        Console::loop(function () {
            Console::log('Hello');
        }, 1);

    }
}
