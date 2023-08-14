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
    public const COMPRESS_ALGORITHM = 'lz4';
    public const CONFIG_PATH = '/etc/my.cnf';
    protected ?DSN $dsn = null;
    protected ?string $database = null;
    protected ?DOSpaces $s3 = null;
    protected string $xtrabackupContainerId;
    protected int $processors = 1;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->checkEnvVariables();

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
    public function action(string $database, Group $pools): void
    {
        $this->database = $database;
        $this->dsn = $this->getDsn($database);
        if (is_null($this->dsn)) {
            Console::error('No DSN match');
            Console::exit();
        }

        try {
            $dsn = new DSN(App::getEnv('_APP_CONNECTIONS_BACKUPS_STORAGE', ''));
            $this->s3 = new DOSpaces('/' . $database . '/full', $dsn->getUser(), $dsn->getPassword(), $dsn->getPath(), $dsn->getParam('region'));
        } catch (\Exception $e) {
            Console::error($e->getMessage());
            Console::exit();
        }

        $attempts = 0;
        $max = 10;
        $sleep = 5;

        do {
            try {
                $attempts++;
                $pools
                    ->get('replica_' . $database)
                    ->pop()
                    ->getResource();

                break; // leave the do-while if successful
            } catch (Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new Exception('Failed to connect to database: ' . $e->getMessage());
                }

                sleep($sleep);
            }
        } while ($attempts < $max);

        $this->setProcessors();
        $this->setContainerId();

        sleep(20);

        Console::loop(function () {
            $this->start();
        }, self::BACKUP_INTERVAL_SECONDS);
    }

    public function start(): void
    {
        $start = microtime(true);
        $time = date('Y_m_d_H_i_s');

        self::log('--- Backup Start ' . $time . ' --- ');

        $filename = $time . '.tar.gz';
        $local = new Local(self::BACKUPS_PATH . '/' . $this->database . '/full/' . $time);
        $local->setTransferChunkSize(5 * 1024 * 1024); // 5MB

        $backups = $local->getRoot() . '/files';
        $tarFile = $local->getPath($filename);

        $this->fullBackup($backups);
        $this->tar($backups, $tarFile);
        $this->upload($tarFile, $local);

        self::log('--- Backup Finish ' . (microtime(true) - $start) . ' seconds --- '   . PHP_EOL . PHP_EOL);
    }

    public function fullBackup(string $target)
    {
        if (!file_exists(self::BACKUPS_PATH)) {
            Console::error('Mount directory does not exist');
            Console::exit();
        }

        if (!file_exists($target) && !mkdir($target, 0755, true)) {
            Console::error('Error creating directory: ' . $target);
            Console::exit();
        }

        $logfile = $target . '/../backup.log';

        $args = [
            'xtrabackup',
            '--user=' . $this->dsn->getUser(),
            '--password=' . $this->dsn->getPassword(),
            '--host=' . $this->dsn->getHost(),
            '--port=' . $this->dsn->getPort(),
            '--backup',
            '--strict',
            '--history=' . $this->database, // logs PERCONA_SCHEMA.xtrabackup_history name attribute
            '--slave-info',
            '--safe-slave-backup',
            '--safe-slave-backup-timeout=300',
            '--check-privileges', // checks if Percona XtraBackup has all the required privileges.
            '--target-dir=' . $target,
            '--parallel=' . $this->processors,
            '--compress=' . self::COMPRESS_ALGORITHM,
            '--compress-threads=' . $this->processors,
            '--rsync', // https://docs.percona.com/percona-xtrabackup/8.0/accelerate-backup-process.html
            '2> ' . $logfile,
        ];

        $cmd = 'docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args);
        self::log('Xtrabackup start');
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);

        if (!str_contains($stderr, 'completed OK!') || !file_exists($target . '/xtrabackup_checkpoints')) {
            Console::error(date('Y-m-d H:i:s') . ' Backup failed:' . $stderr);
            Console::exit();
        }

        if (!unlink($logfile)) {
            Console::error('Error deleting: ' . $logfile);
            Console::exit();
        }
    }

    public function tar(string $directory, string $file)
    {
        self::log('Tar start');

        $stdout = '';
        $stderr = '';
        $cmd = 'cd ' . $directory . ' && tar zcf ' . $file . ' . && cd ' . getcwd();
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            Console::error(date('Y-m-d H:i:s') . ' Tar failed:' . $stderr);
            Console::exit();
        }

        if (!file_exists($file)) {
            Console::error('Can\'t find tar file: ' . $file);
            Console::exit();
        }

        $filesize = \filesize($file);
        if ($filesize < (2 * 1024 * 1024)) {
            Console::error('Tar file size is very small: ' . $file);
            Console::exit();
        }
    }

    public function upload(string $file, Device $local)
    {
        self::log('Upload start');
        $filename = basename($file);

        if (!$this->s3->exists('/')) {
            Console::error('Can\'t read s3 root directory');
            Console::exit();
        }

        try {
            $destination = $this->s3->getRoot() . '/' . $filename;

            if (!$local->transfer($file, $destination, $this->s3)) {
                Console::error('Error uploading to ' . $destination);
                Console::exit();
            }

            if (!$this->s3->exists($destination)) {
                Console::error('File not found in destination: ' . $destination);
                Console::exit();
            }
        } catch (Exception $e) {
            Console::error($e->getMessage());
            Console::exit();
        }

        if (!unlink($file)) {
            Console::error('Error deleting: ' . $file);
            Console::exit();
        }
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

    public function setProcessors()
    {
        $stdout = '';
        $stderr = '';
        Console::execute('nproc', '', $stdout, $stderr);
        if (!empty($stderr)) {
            Console::error('Error setting processors: ' . $stderr);
            Console::exit();
        }

        $processors = str_replace(PHP_EOL, '', $stdout);
        $processors = intval($processors);
        $processors = $processors === 0 ? 1 : $processors;
        $this->processors = $processors;
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
}
