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
    protected ?DSN $dsn = null;
    protected string $database;
    public const PROCESSORS = 4;

    public static function getName(): string
    {
        return 'restore';
    }

    public function __construct()
    {
        $this->checkEnvVariables();

        $this
            ->desc('Restore a DB')
            ->param('id', '', new Text(20), 'Folder Identifier')
            ->param('cloud', null, new WhiteList(['true', 'false'], true), 'Take file from cloud?')
            ->param('database', null, new Text(10), 'example: db_fra1_01..')
            ->param('datadir', null, new Text(100), 'mysql datadir path')
            ->callback(fn ($id, $cloud, $project, $datadir) => $this->action($id, $cloud, $project, $datadir));
    }

    public function action(string $id, string $cloud, string $database, string $datadir): void
    {
        $this->database = $database;
        $this->dsn = Backup::getDsn($database);

        if (!$this->dsn instanceof DSN) {
            Console::error('No dsn match');
            Console::exit();
        }

        // todo? why do we need to pass it as variable?
        //$datadir = '/varlibmysql';

        if (!file_exists($datadir)) {
            Console::error('Datadir not found: ' . $datadir);
            Console::exit();
        }

        if (file_exists($datadir . '/sys') || file_exists($datadir . '/appwrite')) {
            Console::error('Datadir ' . $datadir . ' must be empty!');
            Console::exit();
        }

        //todo: check if the container is stopped
        //$this->checkContainerIsStopped();

        $filename = $id . '.tar.gz';
        Backup::log('--- Restore Start ' . $filename . ' --- ');
        $start = microtime(true);
        $cloud = $cloud === 'true';

        if ($cloud) {
            $local = new Local(Backup::BACKUPS . '/downloads/' . $id);

            if (file_exists($local->getRoot())) {
                $stdout = '';
                $stderr = '';
                $cmd = 'rm -rf ' . $local->getRoot();
                Backup::log($cmd);
                Console::execute($cmd, '', $stdout, $stderr);
                if (!empty($stderr)) {
                    Console::error($stderr);
                    Console::exit();
                }
            }

            $files = $local->getRoot() . '/files';

            Backup::log('Creating Directory: ' . $files);
            if (!mkdir($files, 0755, true)) {
                Console::error('Error creating directory: ' . $files);
                Console::exit();
            }

            $this->download($filename, $local);
            $this->untar($local->getPath($filename), $files);
        } else {
            $local = new Local(Backup::BACKUPS . '/' . $database . '/full/' . $id);
            $files = $local->getRoot() . '/files';
        }

        $this->decompress($files);
        $this->prepare($files);
        $this->restore($files, $cloud, $datadir);

        Backup::log("Restore Finish in " . (microtime(true) - $start) . " seconds");
    }

    public function download(string $filename, Device $local)
    {
        $s3 = new DOSpaces($this->database . '/full', App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION'));

        try {
            $path = $s3->getPath($filename);

            if (!$s3->exists($path)) {
                Console::error('File: ' . $path . ' does not exist on cloud');
                Console::exit();
            }

            $file = $local->getPath($filename);
            Backup::log('Downloading: ' . $file);

            if (!$s3->transfer($path, $file, $local)) {
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
        Backup::log($cmd);
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
        if (!file_exists($target)) {
            Console::error('decompress error directory not found: ' . $target);
            Console::exit();
        }

        $logfile = $target . '/../decompress.log';

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
        Backup::log($cmd);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);
        Backup::log($stderr);

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

        $logfile = $target . '/../prepare.log';

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
        Backup::log($cmd);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);
        Backup::log($stderr);

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

        $logfile = $target . '/../restore.log';

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
        Backup::log($cmd);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);
        Backup::log($stderr);

        if (!str_contains($stderr, 'completed OK!') || !file_exists($target . '/xtrabackup_checkpoints')) {
            Console::error('Restore failed');
            Console::exit();
        }

        // todo: Do we need to chown -R mysql:mysql /var/lib/mysql?
    }

    public function checkEnvVariables(): void
    {
        foreach (
            [
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

    public function checkContainerIsStopped(): void
    {
        $host = $this->dsn->getHost();
        $cmd = 'docker ps --filter "status=running" --filter "name=' . $host . '"';
        Backup::log($cmd);
        $stderr = '';
        $stdout = '';
        Console::execute($cmd, '', $stdout, $stderr);

        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        $stdout = explode(PHP_EOL, $stdout);
        array_shift($stdout);
        $info = array_shift($stdout);

        if (!empty($info)) {
            Console::error('Please stop container: ' . $host);
            //Console::exit();
        }
    }
}
