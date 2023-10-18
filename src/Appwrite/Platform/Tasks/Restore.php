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
    public const DOWNLOAD_CHUNK_SIZE = 40 * 1024 * 1024; // Must be greater than 5MB;
    public const VERSION = 'v1';
    public const RETRY_DOWNLOAD = 5;
    public const RETRY_PREPARE = 5;
    public const RETRY_DECOMPRESS = 5;
    public const RETRY_TAR = 5;
    protected ?DOSpaces $s3 = null;
    protected string $xtrabackupContainerId;
    protected int $processors = 1;

    public function __construct()
    {
        $this
            ->desc('Restore a DB')
            ->param('id', '', new Text(19), 'The backup identification, We can take it from backups directory (Y_m_d_H_i_s)')
            ->param('cloud', null, new Boolean(true), 'Download backup from cloud or use local directory')
            ->param('database', null, new Text(15), 'The Database name for example db_fra1_01')
            ->callback(fn ($id, $cloud, $project) => $this->action($id, $cloud, $project));
    }

    public static function getName(): string
    {
        return 'restore';
    }

    public function action(string $id, string $cloud, string $database): void
    {
        try {
            $this->checkEnvVariables();
            $this->setContainerId();
            $this->setProcessors();

            $datadir = self::DATADIR;

            try {
                $dsn = new DSN(App::getEnv('_APP_CONNECTIONS_BACKUPS_STORAGE', ''));
                $this->s3 = new DOSpaces('/' . $database . '/' . self::VERSION, $dsn->getUser(), $dsn->getPassword(), $dsn->getPath(), $dsn->getParam('region'));
                $this->s3->setTransferChunkSize(self::DOWNLOAD_CHUNK_SIZE);
            } catch (\Exception $e) {
                throw new Exception($e->getMessage() . 'Invalid DSN.');
            }

            if (!file_exists($datadir)) {
                throw new Exception('Datadir not found: ' . $datadir);
            }

            if (count(scandir($datadir)) > 2) {
                throw new Exception($datadir . ' must be empty!');
            }

            $this->log('--- Restore Start ' . $id . ' --- ');

            $filename = $id . '.tar.gz';
            $start = microtime(true);
            $cloud = $cloud === 'true' || $cloud === '1';

            $local = new Local(self::BACKUPS_PATH . '/restore/' . $id);
            $files = $local->getRoot() . '/files';

            if (file_exists($local->getRoot())) {
                shell_exec('mv ' . $local->getRoot() . ' ' . $local->getRoot() . '/../DELETE_ME/' . time());
            }

            if (!mkdir($files, 0755, true)) {
                throw new Exception('Error creating directory: ' . $files);
            }

            if ($cloud) {
                $this->download($local->getPath($filename), $local); // Fast!
                $this->untar($local->getPath($filename), $files);
            } else {
                $source = self::BACKUPS_PATH . '/' . $database . '/' . self::VERSION . '/' . $id;
                $this->log('Copying... ' . $source);

                if (!file_exists($source)) {
                    throw new Exception('Source not found: ' . $source);
                }

                shell_exec('cp -r ' . $source . ' ' . $local->getRoot() . '/../');
            }

            if (!file_exists($files)) {
                throw new Exception('Files directory not found: ' . $files);
            }

            $this->decompress($files); // Uncompressed is long!
            $this->prepare($files);
            $this->restore($files, $cloud, $datadir);

            $this->log('Restore Finish in ' . (microtime(true) - $start) . ' seconds');
        } catch (Exception $e) {
            //todo: send alerts sentry?
            Console::error(date('Y-m-d H:i:s ') . $e->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    public function download(string $file, Device $local)
    {
        $filename = basename($file);
        $path = $this->s3->getPath($filename);

        if (!$this->s3->exists($path)) {
            throw new Exception('File: ' . $path . ' does not exist on cloud');
        }

        $this->retry(function () use ($path, $file, $local, $filename) {
            if (file_exists($local->getPath('tmp_' . $filename))) {
                Console::warning('Deleting tmp_' . $filename);
                if (!$local->deletePath('tmp_' . $filename)) {
                    throw new Exception('Error deleting tmp download dir');
                }
            }

            $this->log('Download start');

            if (!$this->s3->transfer($path, $file, $local)) {
                throw new Exception('Error Downloading ' . $file);
            }
        }, self::RETRY_DOWNLOAD);
    }

    /**
     * @throws Exception
     */
    public function untar(string $file, string $directory)
    {
        $this->log('Untar Start');

        $this->retry(function () use ($file, $directory) {
            $stdout = '';
            $stderr = '';
            $cmd = 'tar -xzf ' . $file . ' -C ' . $directory;
            Console::execute($cmd, '', $stdout, $stderr);
            if (!empty($stderr)) {
                throw new Exception($stderr);
            }
        }, self::RETRY_TAR);

        if (!file_exists($file)) {
            throw new Exception('Restore file not found: ' . $file);
        }
    }

    /**
     * @throws Exception
     */
    public function decompress(string $target)
    {
        $this->log('Decompress start');

        $logfile = $target . '/../log.txt';

        $args = [
            'xtrabackup',
            '--decompress',
            '--strict',
            '--remove-original', // Time consuming.
            '--compress-threads=' . $this->processors,
            '--parallel=' . $this->processors,
            '--target-dir=' . $target,
            '2> ' . $logfile,
        ];

        $this->retry(function () use ($args, $logfile) {
            shell_exec('docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args));

            $stderr = shell_exec('tail -1 ' . $logfile);
            if (!str_contains($stderr, 'completed OK!')) {
                throw new Exception('Decompress failed');
            }
        }, self::RETRY_DECOMPRESS);
    }

    /**
     * @throws Exception
     */
    public function prepare(string $target)
    {
        $this->log('Prepare start');

        if (!file_exists($target)) {
            throw new Exception('prepare error directory not found: ' . $target);
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

        $this->retry(function () use ($args, $logfile) {
            $cmd = 'docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args);
            shell_exec($cmd);

            $stderr = shell_exec('tail -1 ' . $logfile);
            if (!str_contains($stderr, 'completed OK!')) {
                throw new Exception(' Prepare failed:' . $stderr);
            }
        }, self::RETRY_PREPARE);
    }

    /**
     * @throws Exception
     */
    public function restore(string $target, bool $cloud, string $datadir)
    {
        $this->log('Restore start');

        if (!file_exists($target)) {
            throw new Exception('Error Restoring directory not found: ' . $target);
        }

        $logfile = $target . '/../log.txt';

        $args = [
            'xtrabackup',
            '--move-back',
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
            throw new Exception('Restore failed: ' . $stderr);
        }
    }

    /**
     * @throws Exception
     */
    public function checkEnvVariables(): void
    {
        foreach (
            [
                '_APP_CONNECTIONS_BACKUPS_STORAGE',
                '_APP_CONNECTIONS_DB_REPLICAS',
            ] as $env
        ) {
            if (empty(App::getEnv($env))) {
                throw new Exception('Can\'t read ' . $env);
            }
        }
    }

    public function log(string $message): void
    {
        if (!empty($message)) {
            Console::log(date('Y-m-d H:i:s') . ' ' . $message);
        }
    }

    /**
     * @throws Exception
     */
    public function setContainerId()
    {
        $stdout = '';
        $stderr = '';
        Console::execute('docker ps -f "name=xtrabackup" --format "{{.ID}}"', '', $stdout, $stderr);
        if (!empty($stderr)) {
            throw new Exception('Error setting container Id: ' . $stderr);
        }

        $containerId = str_replace(PHP_EOL, '', $stdout);
        if (empty($containerId)) {
            throw new Exception('Xtrabackup Container ID not found');
        }

        $this->xtrabackupContainerId = $containerId;
    }

    /**
     * @throws Exception
     */
    public function setProcessors()
    {
        $stdout = '';
        $stderr = '';
        Console::execute('docker exec ' . $this->xtrabackupContainerId . ' nproc', '', $stdout, $stderr);
        if (!empty($stderr)) {
            throw new Exception('Error setting processors: ' . $stderr);
        }

        $processors = str_replace(PHP_EOL, '', $stdout);
        $processors = empty($processors) ? 1 : intval($processors);
        $this->processors = $processors;
    }

    /**
     * @throws Exception
     */
    public function retry(callable $action, int $retries, int $sleep = 1)
    {
        try {
            return $action();
        } catch (Exception $e) {
            if ($retries > 0) {
                Console::warning('Retrying (' . $retries . ') ' . $e->getMessage());
                sleep($sleep);
                return $this->retry($action, $retries - 1, $sleep);
            } else {
                throw $e;
            }
        }
    }
}
