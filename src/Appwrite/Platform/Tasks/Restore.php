<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use Utopia\App;
use Utopia\DSN\DSN;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Storage\Device;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Restore extends Action
{
    public const BACKUPS_PATH = '/backups';
    public const DATADIR = '/var/lib/mysql';
    public const PROCESSORS = 4;
    protected ?DSN $dsn = null;
    protected string $database;
    protected ?DOSpaces $s3 = null;

    public function __construct()
    {
        $this->checkEnvVariables();

        $this
            ->desc('Restore a DB')
            ->param('id', '', new Text(20), 'The backup identification')
            ->param('cloud', null, new WhiteList(['true', 'false'], true), 'Download backup from cloud or use local directory')
            ->param('database', null, new Text(10), 'The Database name for example db_fra1_01')
            ->callback(fn ($id, $cloud, $project) => $this->action($id, $cloud, $project));
    }

    public static function getName(): string
    {
        return 'restore';
    }

    public function action(string $id, string $cloud, string $database): void
    {
        $this->database = $database;
        $this->dsn = $this->getDsn($database);
        $this->s3 = new DOSpaces('/' . $database . '/full', App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION'));
        $datadir = self::DATADIR;

        if (is_null($this->dsn)) {
            Console::error('No DSN match');
            Console::exit();
        }

        if (!file_exists($datadir)) {
            Console::error('Datadir not found: ' . $datadir);
            Console::exit();
        }

        if (file_exists($datadir . '/sys') || file_exists($datadir . '/appwrite')) {
            Console::error('Datadir ' . $datadir . ' must be empty!');
            Console::exit();
        }

        $this->log('--- Restore Start ' . $id . ' --- ');

        $filename = $id . '.tar.gz';
        $start = microtime(true);
        $cloud = $cloud === 'true';

        if ($cloud) {
            $local = new Local(self::BACKUPS_PATH . '/downloads/' . $id);

            $files = $local->getRoot() . '/files';

            if (!file_exists($files) && !mkdir($files, 0755, true)) {
                Console::error('Error creating directory: ' . $files);
                Console::exit();
            }

            $file = $local->getPath($filename);

            if (!file_exists($file)) {
                $this->download($file, $local);
            }

            $this->untar($file, $files);
        } else {
            $local = new Local(self::BACKUPS_PATH . '/' . $database . '/full/' . $id);
            $files = $local->getRoot() . '/files';
        }

        if (!file_exists($files)) {
            Console::error('Directory not found: ' . $files);
            Console::exit();
        }

        $this->decompress($files);
        $this->prepare($files);
        $this->restore($files, $cloud, $datadir);

        $this->log('Restore Finish in ' . (microtime(true) - $start) . ' seconds');
    }

    public function download(string $file, Device $local)
    {
        $filename = basename($file);
        try {
            $path = $this->s3->getPath($filename);

            if (!$this->s3->exists($path)) {
                Console::error('File: ' . $path . ' does not exist on cloud');
                Console::exit();
            }

            $this->log('Downloading: ' . $file);

            if (!$this->s3->transfer($path, $file, $local)) {
                Console::error('Error Downloading ' . $file);
                Console::exit();
            }
        } catch (Exception $e) {
            Console::error($e->getMessage());
            Console::exit();
        }
    }

    public function untar(string $file, string $directory)
    {
        $stdout = '';
        $stderr = '';
        $cmd = 'tar -xzf ' . $file . ' -C ' . $directory;
        $this->log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        if (!file_exists($file)) {
            Console::error('Restore file not found: ' . $file);
            Console::exit();
        }
    }

    public function decompress(string $target)
    {
        $logfile = $target . '/../log.txt';

        $args = [
            '--user=' . $this->dsn->getUser(),
            '--password=' . $this->dsn->getPassword(),
            '--host=' . $this->dsn->getHost(),
            '--decompress',
            '--strict',
            '--remove-original', // Removes *.lz4 compressed files
            '--parallel=' . self::PROCESSORS,
            '--compress-threads=' . self::PROCESSORS,
            '--target-dir=' . $target,
            '2> ' . $logfile,
        ];

        $cmd = 'docker exec appwrite-xtrabackup xtrabackup ' . implode(' ', $args);
        $this->log($cmd);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);
        $this->log($stderr);

        if (!str_contains($stderr, 'completed OK!') || !file_exists($target . '/xtrabackup_checkpoints')) {
            Console::error('Decompress failed');
            Console::exit();
        }
    }

    public function prepare(string $target)
    {
        if (!file_exists($target)) {
            Console::error('prepare error directory not found: ' . $target);
            Console::exit();
        }

        $logfile = $target . '/../log.txt';

        $args = [
            '--user=' . $this->dsn->getUser(),
            '--password=' . $this->dsn->getPassword(),
            '--host=' . $this->dsn->getHost(),
            '--prepare',
            '--strict',
            '--target-dir=' . $target,
            '2> ' . $logfile,
        ];

        $cmd = 'docker exec appwrite-xtrabackup xtrabackup ' . implode(' ', $args);
        $this->log($cmd);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);
        $this->log($stderr);

        if (!str_contains($stderr, 'completed OK!') || !file_exists($target . '/xtrabackup_checkpoints')) {
            Console::error('Prepare failed');
            Console::exit();
        }
    }

    public function restore(string $target, bool $cloud, string $datadir)
    {
        if (!file_exists($target)) {
            Console::error('restore error directory not found: ' . $target);
            Console::exit();
        }

        $logfile = $target . '/../log.txt';

        $args = [
            '--user=' . $this->dsn->getUser(),
            '--password=' . $this->dsn->getPassword(),
            '--host=' . $this->dsn->getHost(),
            $cloud ? '--move-back' : '--copy-back',
            '--strict',
            '--target-dir=' . $target,
            '--datadir=' . $datadir,
            '--parallel=' . self::PROCESSORS,
            '2> ' . $logfile,
        ];

        $cmd = 'docker exec appwrite-xtrabackup xtrabackup ' . implode(' ', $args);
        $this->log($cmd);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);
        $this->log($stderr);

        if (!str_contains($stderr, 'completed OK!') || !file_exists($target . '/xtrabackup_checkpoints')) {
            Console::error('Restore failed');
            Console::exit();
        }
    }

    public function checkEnvVariables(): void
    {
        foreach (
            [
                '_APP_CONNECTIONS_DB_REPLICAS',
                '_DO_SPACES_BUCKET_NAME',
                '_DO_SPACES_ACCESS_KEY',
                '_DO_SPACES_SECRET_KEY',
                '_DO_SPACES_REGION',
            ] as $env
        ) {
            if (empty(App::getEnv($env))) {
                Console::error('Can\'t read ' . $env);
                Console::exit();
            }
        }
    }

    public function log(string $message): void
    {
        if (!empty($message)) {
            Console::log(date('Y-m-d H:i:s') . ' ' . $message);
        }
    }

    public function getDsn(string $database): ?DSN
    {
        foreach (explode(',', App::getEnv('_APP_CONNECTIONS_DB_REPLICAS')) as $project) {
            [$db, $dsn] = explode('=', $project);
            if ($db === $database) {
                return new DSN($dsn);
            }
        }
        return null;
    }
}
