<?php

namespace Appwrite\Platform\Tasks;

use Utopia\App;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

//  docker compose exec appwrite-backup db-restore --file=2023-07-17_11:36:12.tar.gz

class Restore extends Action
{
    protected string $extract = '/extracts';
    protected string $containerName = 'appwrite-mariadb';

    public static function getName(): string
    {
        return 'restore';
    }

    public function __construct()
    {
        $this
            ->desc('Restore a DB')
            ->param('file', '', new Text(100), 'Backup file name')
            ->param('cloud', 'false', new Boolean(true), 'Take file from cloud?')
            ->callback(fn ($file, $cloud) => $this->action($file, $cloud));
    }

    /**
     * @throws \Exception
     */
    public function action(string $file, bool $cloud): void
    {

        var_dump($cloud);

        if ($cloud) {
            Storage::setDevice('files', new DOSpaces('backups', App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION')));
            Storage::setDevice('mount', new Local(Backup::$backups));

            $device = Storage::getDevice('files');
            $mount = Storage::getDevice('mount');

            try {
                $folder = App::getEnv('_APP_BACKUP_FOLDER', 'hourly');
                $dsn = explode('=', App::getEnv('_APP_CONNECTIONS_DB_PROJECT'))[0];
                $path = '/' . $dsn . '/' . $folder . '/' . $file;

                $this->log('Downloading from Space ' . $path);

                $x = $device->read($path);

                $mount->write('/bla-' . $file, $device->read($path));

            } catch (\Exception $e) {
                Console::error($e->getMessage());
                Console::exit();
            }
        }


        exit;

        $file = Backup::$backups . '/' . $file;
        $start = microtime(true);

        $this->log('--- Restore Start ' . $file . ' --- ');

        if (!file_exists($file) || empty($file)) {
            Console::error('Restore file not found: ' . $file);
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

        $extract = $this->extract . '/' . time();
        $this->log('Creating extract directory ' . $extract);
        if (!file_exists($extract) && !mkdir($extract, 0755, true)) {
            Console::error('Error creating directory: ' . $extract);
            Console::exit();
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'tar -xzf ' . $file . ' -C ' . $this->extract;
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

        $this->log("Restore Finish in " . (microtime(true) - $start) . " seconds");
    }

    public function log(string $message): void
    {
        if (!empty($message)) {
            Console::log(date('Y-m-d H:i:s') . ' ' . $message);
        }
    }
}
