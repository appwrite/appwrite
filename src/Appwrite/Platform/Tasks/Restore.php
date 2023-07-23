<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use Utopia\App;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Restore extends Action
{
    //  docker compose exec appwrite-backup db-restore --cloud=false --filename=2023-07-19_09:25:11.tar.gz --project=db_fra1_02 --folder=daily
    // todo: Carefully double check this is not a production value!!!!!!!!!!!!!!!
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
     * @throws Exception
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
        $s3 = new DOSpaces('/v1/' . $project . '/' . $folder, App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION'));
        $download = new Local(Backup::$backups . '/downloads');

        Backup::log('Creating directory ' . $original);
        if (!file_exists($original) && !mkdir($original, 0755, true)) {
            Console::error('Error creating directory: ' . $original);
            Console::exit();
        }

        if (!file_exists($download->getRoot()) && !mkdir($download->getRoot(), 0755, true)) {
            Console::error('Error creating directory: ' . $download->getRoot());
            Console::exit();
        }

        if ($cloud) {
            try {
                $path = $s3->getPath($filename);

                if (!$s3->exists($path)) {
                    Console::error('File: ' . $path . ' does not exist on cloud');
                    Console::exit();
                }

                $file = $download->getPath($filename);
                Backup::log('Transferring ' . $path . ' => ' . $file);

                if (!$s3->transfer($path, $file, $download)) {
                    Console::error('Error transferring ' . $file);
                    Console::exit();
                }
            } catch (Exception $e) {
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
        // todo: do we care about original?
        $cmd = 'rm -r ' . Backup::$mysqlDirectory . '/*';
        Backup::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        Backup::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'tar -xzf ' . $file . ' -C ' . Backup::$mysqlDirectory;
        Backup::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            Backup::log($stdout);
            Console::error($stderr);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker start ' . $this->containerName;
        Backup::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            Backup::log($stdout);
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
