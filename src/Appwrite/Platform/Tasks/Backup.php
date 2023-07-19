<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Storage;

class Backup extends Action
{
    public static string $mysqlDirectory = '/var/lib/mysql';
    public static string $backups = '/backups'; // Mounted volume
    protected string $containerName = 'appwrite-mariadb';

    public static function getName(): string
    {
        return 'backup';
    }

    public function __construct()
    {
        $this
            ->desc('Backup a DB')
            ->callback(fn() => $this->action());
    }

    /**
     * @throws \Exception
     */
    public function action(): void
    {
        $this->checkEnvVariables();

        $folder = App::getEnv('_APP_BACKUP_FOLDER');

        $start = microtime(true);
        $time = date('Y-m-d_H:i:s');
        self::log('--- Backup Start --- ');

        Storage::setDevice('files', new DOSpaces('backups', App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION')));
        $device = Storage::getDevice('files');

        // Todo: do we want to have the backup ready on the mounted dir? or to abort?
        if (!$device->exists('/')) {
            Console::error('Can\'t read from DO ');
            Console::exit();
        }

        $dsn = explode('=', App::getEnv('_APP_CONNECTIONS_DB_PROJECT'))[0];

        if (!file_exists(self::$backups)) {
            Console::error('Mount directory does not exist');
            Console::exit();
        }

        $backups = self::$backups . '/' . $dsn . '/' . $folder;

        if (!file_exists($backups) && !mkdir($backups, 0755, true)) {
            Console::error('Error creating directory: ' . $backups);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker stop ' . $this->containerName;
        self::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            self::log($stdout);
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
        $cmd = 'cd ' . self::$mysqlDirectory . ' && tar zcf ' . $file . ' .';
        self::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        self::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker start ' . $this->containerName;
        self::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            self::log($stdout);
            Console::error($stderr);
            Console::exit();
        }

        try {
            $path = '/' . $dsn . '/' . $folder . '/' . $filename;
            self::log('Uploading ' . $path);
            if (!$device->upload($file, $path)) {
                Console::error('Error uploading to ' . $path);
                Console::exit();
            }
        } catch (\Exception $e) {
            Console::error($e->getMessage());
            Console::exit();
        }

        self::log('--- Backup End ' . (microtime(true) - $start) . ' seconds --- '   . PHP_EOL . PHP_EOL);

        Console::loop(function () {
            self::log('loop');
        }, 100);
    }

    public static function log(string $message): void
    {
        if (!empty($message)) {
            Console::log(date('Y-m-d H:i:s') . ' ' . $message);
        }
    }

    public function checkEnvVariables(): void
    {
        foreach (
            [
                '_APP_BACKUP_FOLDER',
                '_APP_CONNECTIONS_DB_PROJECT',
                '_DO_SPACES_BUCKET_NAME',
                '_DO_SPACES_ACCESS_KEY',
                '_DO_SPACES_SECRET_KEY',
                '_DO_SPACES_REGION'
            ] as $env
        ) {
            if (empty(App::getEnv($env))) {
                Console::error('Can\'t read ' . $env);
                Console::exit();
            }
        }
    }
    
}
