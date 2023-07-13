<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Extend\Exception;
use Utopia\Platform\Action;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Storage;
use Utopia\Validator\Hostname;

class DbBackup extends Action
{
    protected string $directory = '/var/lib/mysql';
    public static string $backups = '/backups'; // This is the mounted volume
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
        if (empty(App::getEnv('_APP_CONNECTIONS_DB_PROJECT'))) {
            Console::error('Can\'t read _APP_CONNECTIONS_DB_PROJECT');
            Console::exit();
        }

        if (empty(App::getEnv('_DO_SPACES_BUCKET_NAME'))) {
            Console::error('Can\'t read _DO_SPACES_BUCKET_NAME');
            Console::exit();
        }

        if (empty(App::getEnv('_DO_SPACES_ACCESS_KEY'))) {
            Console::error('Can\'t read _DO_SPACES_ACCESS_KEY');
            Console::exit();
        }

        if (empty(App::getEnv('_DO_SPACES_SECRET_KEY'))) {
            Console::error('Can\'t read _DO_SPACES_SECRET_KEY');
            Console::exit();
        }

        if (empty(App::getEnv('_DO_SPACES_REGION'))) {
            Console::error('Can\'t read _DO_SPACES_REGION');
            Console::exit();
        }

        $time = date('Y-m-d_H:i:s');
        Console::log('--- Backup Start --- ' . $time);

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
        Console::execute($cmd, '', $stdout, $stderr);
        Console::log($cmd);
        Console::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker start ' . $this->containerName;
        Console::execute($cmd, '', $stdout, $stderr);
        Console::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        $dsn = explode('=', App::getEnv('_APP_CONNECTIONS_DB_PROJECT'))[0];

        Storage::setDevice('files', new DOSpaces('backups', App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION')));
        $device = Storage::getDevice('files');
        try {
            $result = $device->upload($file, '/' . $dsn . '/daily/' . $filename);
        } catch (\Exception $e) {
            Console::error($e->getMessage());
            Console::exit();
        }

        Console::log('-- Backup End -- ');

//        Console::loop(function () {
//            Console::log('Hello');
//        }, 100);
    }
}
