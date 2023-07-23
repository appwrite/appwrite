<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;
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
        self::log('--- Backup Start --- ');
        $this->checkEnvVariables();

        $start = microtime(true);
        $filename = date('Ymd_His') . '.tar.gz';
        $folder = App::getEnv('_APP_BACKUP_FOLDER');
        $project = explode('=', App::getEnv('_APP_CONNECTIONS_DB_PROJECT'))[0];
        $s3 = new DOSpaces('/v1/' . $project . '/' . $folder, App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION'));
        $local = new Local(self::$backups . '/' . $project . '/' . $folder);
        $source = $local->getRoot() . '/' . $filename;

        $local->setTransferChunkSize(10  * 1024 * 1024); // > 5MB

//        $source = '/backups/shmuel.tar.gz'; // 1452521974
//        $destination = '/shmuel/' . $time . '.tar.gz';
//
//        $local->transfer($source, $destination, $s3);
//
//        if ($s3->exists($destination)) {
//            Console::success('Uploaded successfully !!! ' . $destination);
//            Console::exit();
//        }

        if (!$s3->exists('/')) {
            Console::error('Can\'t read from DO ');
            Console::exit();
        }

        if (!file_exists(self::$backups)) {
            Console::error('Mount directory does not exist');
            Console::exit();
        }

        if (!file_exists($local->getRoot()) && !mkdir($local->getRoot(), 0755, true)) {
            Console::error('Error creating directory: ' . $local->getRoot());
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

        //        $cmd = 'tar czf ' . $file . ' --absolute-names ' . $this->directory;
        //
        //        $cmd = '
        //        cd /var/lib/mysql
        //        tar czf ' . $file . ' --absolute-names *';
        //          Extract:::
        //           tar --strip-components=4 -xf 2023-07-11_12:40:47.tar.gz -C ./a2

        $stdout = '';
        $stderr = '';
        $cmd = 'cd ' . self::$mysqlDirectory . ' && tar zcf ' . $source . ' .';
        self::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        self::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }


        if (!file_exists($source)) {
            Console::error("Can't find tar file: " . $source);
            Console::exit();
        }

        $filesize = \filesize($source);
        self::log("Tar file size is :" . ceil($filesize / 1024 / 1024) . 'MB');
        if ($filesize < (2 * 1024)) {
            Console::error("File size is very small: " . $source);
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
            self::log('Start transferring ' . $source);

            $destination = $s3->getRoot() . '/' . $filename;

            if (!$local->transfer($source, $destination, $s3)) {
                Console::error('Error transferring to ' . $destination);
                Console::exit();
            }

            if (!$s3->exists($destination)) {
                Console::error('File not found on s3 ' . $destination);
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
