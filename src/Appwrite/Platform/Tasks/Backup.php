<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use Utopia\DSN\DSN;
use Utopia\Platform\Action;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Pools\Group;
use Utopia\Storage\Device;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;
use Utopia\Validator\Text;

class Backup extends Action
{
    public const BACKUPS_PATH = '/backups';
    public const BACKUP_INTERVAL_SECONDS = 60 * 60 * 4; // 4 hours;
    public const COMPRESS_ALGORITHM = 'zstd'; // https://www.percona.com/blog/get-your-backup-to-half-of-its-size-introducing-zstd-support-in-percona-xtrabackup/
    public const CLEANUP_LOCAL_FILES_SECONDS = 60 * 60 * 24 * 7; // 2 days?
    public const CLEANUP_CLOUD_FILES_SECONDS = 60 * 60 * 24 * 14; // 14 days?;
    public const UPLOAD_CHUNK_SIZE = 5 * 1024 * 1024; // Must be greater than 5MB;
    public const RETRY_BACKUP = 1;
    public const RETRY_TAR = 1;
    public const RETRY_UPLOAD = 2;
    protected ?DSN $dsn = null;
    protected ?string $database = null;
    protected ?DOSpaces $s3 = null;
    protected string $xtrabackupContainerId;
    protected int $processors;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Backup a database')
            ->param('database', null, new Text(20), 'Database name, for example db_fra1_01')
            ->inject('pools')
            ->callback(fn(string $database, Group $pools) => $this->action($database, $pools));
    }

    public static function getName(): string
    {
        return 'backup';
    }

    /**
     * @throws Exception
     */
    public function hello(string $str): void
    {
        Console::success($str);
        //throw new Exception('kaka');
    }

    /**
     * @throws Exception
     */
    public function action(string $database, Group $pools): void
    {

//        $this->retry(function () {
//            $this->hello('David123');
//        }, 1, 2);
//
//        exit;


        $this->checkEnvVariables();

        $this->database = $database;
        $this->dsn = $this->getDsn($database);
        if (is_null($this->dsn)) {
            throw new Exception('No DSN match');
        }

        //todo: remove this:
        console::info('Trying to connect to ' . $this->dsn->getHost() . ' : ' . $this->dsn->getPort() . ' user: ' . $this->dsn->getUser() . ' password: ' . $this->dsn->getPassword());

        $dsn = new DSN(App::getEnv('_APP_CONNECTIONS_BACKUPS_STORAGE', ''));
        $this->s3 = new DOSpaces('/' . $database . '/full', $dsn->getUser(), $dsn->getPassword(), $dsn->getPath(), $dsn->getParam('region'));

        try {
            $this->retry(function () use ($pools, $database) {
                $pools
                ->get('replica_' . $database)
                ->pop()
                ->getResource();
            }, 10, 5);
        } catch (Exception $e) {
            throw new Exception('Failed to connect to database: ' . $e->getMessage());
        }

        $this->setContainerId();
        $this->setProcessors();

        Console::loop(function () {
            try {
                $this->start();
            } catch (Exception $e) {
                //todo: send alerts sentry?
                Console::error(date('Y-m-d H:i:s') . ' Error: ' . $e->getMessage());
            }
        }, self::BACKUP_INTERVAL_SECONDS);
    }

    /**
     * @throws Exception
     */
    public function start(): void
    {
        $start = microtime(true);
        $time = date('Y_m_d_H_i_s');

        self::log('--- Backup Start ' . $time . ' --- ');

        $local = new Local(self::BACKUPS_PATH . '/' . $this->database . '/full/' . $time);
        $local->setTransferChunkSize(self::UPLOAD_CHUNK_SIZE);

        $tarFile = $local->getPath($time . '.tar.gz');
        $backups = $local->getRoot() . '/files';

        $this->backup($backups);
        $this->tar($backups, $tarFile);
        $this->upload($tarFile, $local);

        if (!unlink($tarFile)) {
            throw new Exception('Error deleting: ' . $tarFile);
        }

        self::log('--- Backup Finish ' . (microtime(true) - $start) . ' seconds --- '   . PHP_EOL . PHP_EOL);
    }

    /**
     * @throws Exception
     */
    public function backup(string $target)
    {
        $start = microtime(true);
        self::log('Xtrabackup start');

        if (!file_exists(self::BACKUPS_PATH)) {
            throw new Exception('Mount directory does not exist');
        }

        if (!file_exists($target) && !mkdir($target, 0755, true)) {
            throw new Exception('Error creating directory: ' . $target);
        }

        $filename = basename($target);
        $logfile = $target . '/../backup.log';

        $args = [
            'xtrabackup',
            '--user=' . $this->dsn->getUser(),
            '--password=' . $this->dsn->getPassword(),
            '--host=' . $this->dsn->getHost(),
            '--port=' . $this->dsn->getPort(),
            '--backup',
            '--strict',
            '--history="' . $this->database . '|' . pathinfo($filename, PATHINFO_FILENAME) . '"', // PERCONA_SCHEMA.xtrabackup_history
            '--slave-info',
            '--safe-slave-backup',
            '--safe-slave-backup-timeout=300',
            '--check-privileges', // checks if Percona XtraBackup has all the required privileges.
            '--target-dir=' . $target,
            '--compress=' . self::COMPRESS_ALGORITHM,
            '--compress-threads=' . $this->processors,
            '--parallel=' . $this->processors,
            '--rsync', // https://docs.percona.com/percona-xtrabackup/8.0/accelerate-backup-process.html
            '2> ' . $logfile,
        ];

        $this->retry(function () use ($args, $logfile, $target) {
            shell_exec('docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args));
            $stderr = shell_exec('tail -1 ' . $logfile);
            if (!str_contains($stderr, 'completed OK!')) {
                shell_exec('rm -rf ' . $target . '/*');
                throw new Exception(' Backup failed: ' . $stderr);
            }
        }, self::RETRY_BACKUP);

        if (!unlink($logfile)) {
            throw new Exception('Error deleting: ' . $logfile);
        }

        self::log('Xtrabackup end ' . (microtime(true) - $start) . ' seconds');
    }

    /**
     * @throws Exception
     */
    public function tar(string $directory, string $file)
    {
        self::log('Tar start');
        $start = microtime(true);

        $this->retry(function () use ($directory, $file) {
            $stdout = '';
            $stderr = '';
            $cmd = 'cd ' . $directory . ' && tar zcf ' . $file . ' . && cd ' . getcwd();
            Console::execute($cmd, '', $stdout, $stderr);
            if (!empty($stderr)) {
                throw new Exception('Tar failed:' . $stderr);
            }
        }, self::RETRY_TAR);

        if (!file_exists($file)) {
            throw new Exception('Tar file not found: ' . $file);
        }

        self::log('Tar took ' . (microtime(true) - $start) . ' seconds');
    }

    /**
     * @throws Exception
     */
    public function upload(string $file, Device $local)
    {
        $start = microtime(true);
        self::log('Upload start');
        $filename = basename($file);

        if (!$this->s3->exists('/')) {
            throw new Exception('Can\'t read s3 root directory');
        }

        $destination = $this->s3->getRoot() . '/' . $filename;

        $this->retry(function () use ($local, $file, $destination) {
            if (!$local->transfer($file, $destination, $this->s3)) {
                throw new Exception('Error uploading to ' . $destination);
            }
        }, self::RETRY_UPLOAD);

        if (!$this->s3->exists($destination)) {
            throw new Exception('File not found in destination: ' . $destination);
        }

        self::log('Upload took ' . (microtime(true) - $start) . ' seconds');
    }

    public static function log(string $message): void
    {
        if (!empty($message)) {
            Console::log(date('Y-m-d H:i:s') . ' ' . $message);
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

    public function getDsn(string $database): ?DSN
    {
        foreach (explode(',', App::getEnv('_APP_CONNECTIONS_DB_REPLICAS', '')) as $project) {
            [$db, $dsn] = explode('=', $project);
            if ($db === $database) {
                return new DSN($dsn);
            }
        }
        return null;
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

    /**
     * @throws Exception
     */
    public function retry(callable $f, int $retries, int $sleep = 1)
    {
        try {
            return $f();
        } catch (Exception $e) {
            if ($retries > 0) {
                Console::warning('Retrying (' . $retries . ') ' . $e->getMessage());
                sleep($sleep);
                return $this->retry($f, $retries - 1, $sleep);
            } else {
                throw $e;
            }
        }
    }
}
