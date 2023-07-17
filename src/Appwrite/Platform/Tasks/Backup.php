<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Storage;
use Utopia\Validator\Hostname;

class Backup extends Action
{
    protected string $directory = '/var/lib/mysql';
    public static string $backups = '/backups'; // Mounted volume
    protected string $containerName = 'appwrite-mariadb';

    public static function getName(): string
    {
        return 'backup';
    }

    public function __construct()
    {
        //docker compose exec appwrite backup
        $this
            ->desc('Backup a DB')
            ->param('domain', App::getEnv('_APP_DOMAIN', ''), new Hostname(), 'Domain.', true)
            ->callback(fn($domain) => $this->action($domain));
    }

    /**
     * @throws \Exception
     */
    public function action(string $domain): void
    {
        $start = microtime(true);
        $time = date('Y-m-d_H:i:s');
        $this->log('--- Backup Start --- ');

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

        Storage::setDevice('files', new DOSpaces('backups', App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION')));
        $device = Storage::getDevice('files');

        // Todo: do we want to have the backup ready on the mounted dir? or to abort?
        if (!$device->exists('/')) {
            Console::error('Can\'t read from DO aborting');
            Console::exit();
        }

        $dsn = explode('=', App::getEnv('_APP_CONNECTIONS_DB_PROJECT'))[0];

        if (!file_exists(self::$backups)) {
            Console::error('Mount directory does not exist');
            Console::exit();
        }

        $backups = self::$backups . '/' . $dsn;

        if (!file_exists($backups) && !mkdir($backups, 0755, true)) {
            Console::error('Error creating directory: ' . $backups);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker stop ' . $this->containerName;
        $this->log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        $this->log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        $filename = $time . '.tar.gz';
        $file = $backups . '/' . $filename;

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
        $this->log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        $this->log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker start ' . $this->containerName;
        $this->log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        $this->log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        try {
            $folder = App::getEnv('_APP_BACKUP_FOLDER', 'hourly');

            $path = '/' . $dsn . '/' . $folder . '/' . $filename;
            $this->log('Uploading ' . $path);
            if (!$device->upload($file, $path)) {
                Console::error('Error uploading to ' . $path);
                Console::exit();
            }

//            $isDaily = true;
//
//            if ($isDaily) {
//                $dailyPath = '/' . $dsn . '/daily/' . $filename;
//                $this->log('Moving ' . $dailyPath);
//                if (!$device->move($hourlyPath, $dailyPath)) {
//                    Console::error('Error moving to hourly to ' . $dailyPath);
//                    Console::exit();
//                }
//            }
        } catch (\Exception $e) {
            Console::error($e->getMessage());
            Console::exit();
        }

        $this->log('--- Backup End ' . (microtime(true) - $start) . ' seconds --- '   . PHP_EOL . PHP_EOL);

        Console::loop(function () {
            Console::log('Hello');
        }, 100);
    }

    public function log(string $message): void
    {
        if (!empty($message)) {
            Console::log(date('Y-m-d H:i:s') . ' ' . $message);
        }
    }
}
