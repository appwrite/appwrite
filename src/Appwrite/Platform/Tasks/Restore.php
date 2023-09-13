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
        try {
            $this->checkEnvVariables();
            $this->setContainerId();
            $this->setProcessors();

            $datadir = self::DATADIR;

            try {
                $dsn = new DSN(App::getEnv('_APP_CONNECTIONS_BACKUPS_STORAGE', ''));
                $this->s3 = new DOSpaces('/' . $database . '/full', $dsn->getUser(), $dsn->getPassword(), $dsn->getPath(), $dsn->getParam('region'));
                $this->s3->setTransferChunkSize(40 * 1024 * 1024); // 5MB
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

            if ($cloud) {
                $local = new Local(self::BACKUPS_PATH . '/downloads/' . $id);

                $files = $local->getRoot() . '/files';

                if (!file_exists($files) && !mkdir($files, 0755, true)) {
                    throw new Exception('Error creating directory: ' . $files);
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
                throw new Exception('Directory not found: ' . $files);
            }

            $this->decompress($files);
            $this->prepare($files);
            $this->restore($files, $cloud, $datadir);

            $this->log('Restore Finish in ' . (microtime(true) - $start) . ' seconds');
        } catch (Exception $e) {
            //todo: send alerts sentry?
            Console::error(date('Y-m-d H:i:s') . ' Error: ' . $e->getMessage());
        }
    }

    /**
     * @throws Exception
     */
    public function download(string $file, Device $local)
    {
        $this->log('Download start');

        $filename = basename($file);
        $path = $this->s3->getPath($filename);

        if (!$this->s3->exists($path)) {
            throw new Exception('File: ' . $path . ' does not exist on cloud');
        }

        if (!$this->s3->transfer($path, $file, $local)) {
            throw new Exception('Error Downloading ' . $file);
        }
    }

    /**
     * @throws Exception
     */
    public function untar(string $file, string $directory)
    {
        $this->log('Untar Start');

        $stdout = '';
        $stderr = '';
        $cmd = 'tar -xzf ' . $file . ' -C ' . $directory;
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            throw new Exception($stderr);
        }

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
            '--remove-original',
            '--compress-threads=' . $this->processors,
            '--parallel=' . $this->processors,
            '--target-dir=' . $target,
            '2> ' . $logfile,
        ];

        $cmd = 'docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);

        if (!str_contains($stderr, 'completed OK!')) {
            throw new Exception('Decompress failed');
        }
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

        $cmd = 'docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);

        if (!str_contains($stderr, 'completed OK!')) {
            throw new Exception(' Prepare failed:' . $stderr);
        }
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

        $this->processors = \max(1, $processors - 2);
    }
}
