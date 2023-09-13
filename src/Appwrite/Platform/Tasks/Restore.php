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
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Restore extends Action
{
    public const BACKUPS_PATH = '/backups';
    public const DATADIR = '/var/lib/mysql';
    protected ?DOSpaces $s3 = null;
    protected string $xtrabackupContainerId;
    protected int $processors = 1;

    public function __construct()
    {
        $this
            ->desc('Restore a DB')
            ->param('id', '', new Text(19), 'The backup identification')
            ->param('cloud', null, new Boolean(true), 'Download backup from cloud or use local directory')
            ->param('database', null, new Text(10), 'The Database name for example db_fra1_01')
            ->callback(fn ($id, $cloud, $project) => $this->action($id, $cloud, $project));
    }

    public static function getName(): string
    {
        return 'restore';
    }

    public function action(string $id, string $cloud, string $database): void
    {
        $this->checkEnvVariables();
        $this->setContainerId();
        $this->setProcessors();

        $datadir = self::DATADIR;

        try {
            $dsn = new DSN(App::getEnv('_APP_CONNECTIONS_BACKUPS_STORAGE', ''));
            $this->s3 = new DOSpaces('/' . $database . '/full', $dsn->getUser(), $dsn->getPassword(), $dsn->getPath(), $dsn->getParam('region'));
            $this->s3->setTransferChunkSize(40 * 1024 * 1024); // 5MB
        } catch (\Exception $e) {
            Console::error($e->getMessage() . 'Invalid DSN.');
            Console::exit();
        }

        if (!file_exists($datadir)) {
            Console::error('Datadir not found: ' . $datadir);
            Console::exit();
        }

        if (file_exists($datadir . '/sys') || file_exists($datadir . '/appwrite')) {
            Console::error('Datadir ' . $datadir . ' must be empty!');
            //Console::exit();
        }

        $this->log('--- Restore Start ' . $id . ' --- ');

        $filename = $id . '.tar.gz';
        $start = microtime(true);
        $cloud = $cloud === 'true' || $cloud === '1';

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
       // $this->restore($files, $cloud, $datadir);

        $this->log('Restore Finish in ' . (microtime(true) - $start) . ' seconds');
    }

    public function download(string $file, Device $local)
    {
        $this->log('Download start');

        $filename = basename($file);
        try {
            $path = $this->s3->getPath($filename);

            if (!$this->s3->exists($path)) {
                Console::error('File: ' . $path . ' does not exist on cloud');
                Console::exit();
            }

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
        $this->log('Untar Start');

        $stdout = '';
        $stderr = '';
        $cmd = 'tar -xzf ' . $file . ' -C ' . $directory;
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
        $this->log('Decompress start');

        $logfile = $target . '/../log.txt';

        $args = [
            'xtrabackup',
            '--decompress',
            '--strict',
            '--remove-original',
            '--compress-threads=' . $this->processors / 2,
            '--parallel=' . $this->processors,
            '--target-dir=' . $target,
            '2> ' . $logfile,
        ];

        $cmd = 'docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);

        if (!str_contains($stderr, 'completed OK!')) {
            Console::error('Decompress failed');
            Console::exit();
        }
    }

    public function prepare(string $target)
    {
        $this->log('Prepare start');

        if (!file_exists($target)) {
            Console::error('prepare error directory not found: ' . $target);
            Console::exit();
        }

        $logfile = $target . '/../log.txt';

        $args = [
            'xtrabackup',
            '--prepare',
            '--parallel=' . $this->processors,
            '--strict',
            '--target-dir=' . $target,
            '2> ' . $logfile,
        ];

        $cmd = 'docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);

        if (!str_contains($stderr, 'completed OK!')) {
            Console::error(date('Y-m-d H:i:s') . ' Prepare failed:' . $stderr);
            Console::exit();
        }
    }

    public function restore(string $target, bool $cloud, string $datadir)
    {
        $this->log('Restore start');

        if (!file_exists($target)) {
            Console::error('restore error directory not found: ' . $target);
            Console::exit();
        }

        $logfile = $target . '/../log.txt';

        $args = [
            'xtrabackup',
            $cloud ? '--move-back' : '--copy-back',
            '--strict',
            '--parallel=' . $this->processors,
            '--target-dir=' . $target,
            '--datadir=' . $datadir,
            '2> ' . $logfile,
        ];

        $cmd = 'docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);

        if (!str_contains($stderr, 'completed OK!')) {
            Console::error(date('Y-m-d H:i:s') . ' Restore failed: ' . $stderr);
            Console::exit();
        }
    }

    public function checkEnvVariables(): void
    {
        foreach (
            [
                '_APP_CONNECTIONS_BACKUPS_STORAGE',
                '_APP_CONNECTIONS_DB_REPLICAS',
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

    public function setContainerId()
    {
        $stdout = '';
        $stderr = '';
        Console::execute('docker ps -f "name=xtrabackup" --format "{{.ID}}"', '', $stdout, $stderr);
        if (!empty($stderr)) {
            Console::error('Error setting container Id: ' . $stderr);
            Console::exit();
        }

        $containerId = str_replace(PHP_EOL, '', $stdout);
        if (empty($containerId)) {
            Console::error('Xtrabackup Container ID not found');
            Console::exit();
        }

        $this->xtrabackupContainerId = $containerId;
    }

    public function setProcessors()
    {
        $stdout = '';
        $stderr = '';
        Console::execute('docker exec ' . $this->xtrabackupContainerId . ' nproc', '', $stdout, $stderr);
        if (!empty($stderr)) {
            Console::error('Error setting processors: ' . $stderr);
            Console::exit();
        }

        $processors = str_replace(PHP_EOL, '', $stdout);
        $processors = intval($processors);

        if ($processors === 0) {
            Console::error('Set Processors Error');
            Console::exit();
        }

        $this->processors = \max(1, $processors - 2);
    }
}
