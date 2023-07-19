<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

//  docker compose exec appwrite-backup db-restore --cloud=false --filename=2023-07-18_12:39:59.tar.gz

class Restore extends Action
{
    // todo: double check this is not a production value!!!!!!!!!!!!!!!
    // todo: it will be erased!!!!
    protected string $containerName = 'appwrite-mariadb';

    public static function getName(): string
    {
        return 'restore';
    }

    public function __construct()
    {
        $this
            ->desc('Restore a DB')
            ->param('filename', '', new Text(100), 'Backup file name')
            ->param('cloud', null, new WhiteList(['true', 'false'], true), 'Take file from cloud?')
            ->param('project', null, new WhiteList(['db_fra1_02'], true), 'From _APP_CONNECTIONS_DB_PROJECT')
            ->param('folder', null, new WhiteList(['hourly', 'daily'], true), 'Sub folder')
            ->callback(fn ($file, $cloud, $project, $folder) => $this->action($file, $cloud, $project, $folder));
    }

    /**
     * @throws \Exception
     */
    public function action(string $filename, string $cloud, string $project, string $folder): void
    {
        $this->checkEnvVariables();

        Backup::log('--- Restore Start ' . $filename . ' --- ');
        $start = microtime(true);

        $cloud = $cloud === 'true';
        $file = Backup::$backups . '/' . $project . '/' . $folder . '/' . $filename;
        $extract = Backup::$backups . '/extract';
        $original = $extract . '/original-' . time();

        Backup::log('Creating directory ' . $original);
        if (!file_exists($original) && !mkdir($original, 0755, true)) {
            Console::error('Error creating directory: ' . $original);
            Console::exit();
        }

//        $tarDirectory = $extract . '/' . str_replace(['.', '-', '_', ':', 'tar', 'gz'], '', $filename);
//        Backup::log('Creating directory ' . $tarDirectory);
//        if (!file_exists($tarDirectory) && !mkdir($tarDirectory, 0755, true)) {
//            Console::error('Error creating directory: ' . $tarDirectory);
//            Console::exit();
//        }

        if ($cloud) {
            Storage::setDevice('s3', new DOSpaces('backups', App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION')));
            Storage::setDevice('mount', new Local(Backup::$backups));

            $device = Storage::getDevice('s3');
            $mount = Storage::getDevice('mount');

            try {
                $folder = App::getEnv('_APP_BACKUP_FOLDER', 'hourly');
                $path = '/' . $project . '/' . $folder . '/' . $filename;

                if (!$device->exists($path)) {
                    Console::error('File: ' . $path . ' does not exist on cloud');
                    Console::exit();
                }

                while ($data = $device->read($path)) {
                    $mount->write('file-' . $file, $data);
                }

                Backup::log('Downloading from s3 ' . $path);
                $mount->write('file-' . $file, $device->read($path));
               // $mount->get('file-' . $file, $device->read($path));
            } catch (\Exception $e) {
                Console::error($e->getMessage());
                Console::exit();
            }
        }

        if (!file_exists($file) || empty($file)) {
            Console::error('Restore file not found: ' . $file);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker stop ' . $this->containerName;
        Backup::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            Backup::log($stdout);
            Console::error($stderr);
            Console::exit();
        }


        $stdout = '';
        $stderr = '';
        $cmd = 'mv ' . Backup::$mysqlDirectory . '/* ' . ' ' . $original . '/';
        Backup::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        Backup::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }


        $stdout = '';
        $stderr = '';
       // $cmd = 'tar -xzf ' . $file . ' -C ' . $tarDirectory;
        $cmd = 'tar -xzf ' . $file . ' -C ' . Backup::$mysqlDirectory;

        Backup::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            Backup::log($stdout);
            Console::error($stderr);
            Console::exit();
        }

//        $stdout = '';
//        $stderr = '';
//        $cmd = 'mv ' . $tarDirectory . ' ' . Backup::$mysqlDirectory;
//        Backup::log($cmd);
//        Console::execute($cmd, '', $stdout, $stderr);
//        Backup::log($stdout);
//        if (!empty($stderr)) {
//            Console::error($stderr);
//            Console::exit();
//        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker start ' . $this->containerName;
        Backup::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        Backup::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        Backup::log("Restore Finish in " . (microtime(true) - $start) . " seconds");
    }

    public function checkEnvVariables(): void
    {
        foreach (
            [
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
