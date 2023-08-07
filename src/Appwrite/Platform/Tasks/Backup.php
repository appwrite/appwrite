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
    protected ?DSN $dsn = null;
    protected ?string $database = null;
    public const BACKUPS = '/backups';
    public const BACKUP_INTERVAL = 60 * 60 * 4; // 4 hours;
    public const COMPRESS_ALGORITHM = 'lz4';
    public const CNF = '/etc/my.cnf';
    public const PROCESSORS = 4;

    public static function getName(): string
    {
        return 'backup';
    }

    public function __construct()
    {
        $this->checkEnvVariables();

        $this
            ->desc('Backup a DB')
            ->param('database', null, new Text(20), 'passed from command')
            ->inject('pools')
            ->callback(fn(string $database, Group $pools) => $this->action($database, $pools));
    }

    /**
     * @throws Exception
     */
    public function action($database, Group $pools): void
    {
        $this->database = $database;
        $this->dsn = self::getDsn($database);

        if (!$this->dsn instanceof DSN) {
            Console::error('No DSN match');
            Console::exit();
        }

        $attempts = 0;
        $max = 10;
        $sleep = 5;

        do {
            try {
                $attempts++;
                $pools
                    ->get('database_' . $database)
                    ->pop()
                    ->getResource()
                ;

                break; // leave the do-while if successful
            } catch (Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new Exception('Failed to connect to database: ' . $e->getMessage());
                }

                sleep($sleep);
            }
        } while ($attempts < $max);

        Console::loop(function () {
            $this->start();
        }, self::BACKUP_INTERVAL);
    }

    public function start(): void
    {
        $start = microtime(true);
        $time = date('Y_m_d_H_i_s');

        self::log('--- Backup Start --- ');
        self::log('--- Creating backup ' . $time . '  --- ');

        $filename = $time . '.tar.gz';
        $local = new Local(self::BACKUPS . '/' . $this->database . '/full/' . $time);
        $local->setTransferChunkSize(5 * 1024 * 1024); // 5MB

        $backups = $local->getRoot() . '/files';
        $tarFile = $local->getPath($filename);

        $this->fullBackup($backups);
        $this->tar($backups, $tarFile);
        $this->upload($tarFile, $local);
        // todo: Do we want to delete the tar file? and remain with the folder?
        // todo: Do we want to delete the backup.log?

        self::log('--- Backup End ' . (microtime(true) - $start) . ' seconds --- '   . PHP_EOL . PHP_EOL);
    }

    public function fullBackup(string $target)
    {
        if (!file_exists(self::BACKUPS)) {
            Console::error('Mount directory does not exist');
            Console::exit();
        }

        if (!file_exists($target) && !mkdir($target, 0755, true)) {
            Console::error('Error creating directory: ' . $target);
            Console::exit();
        }

        $logfile = $target . '/../backup.log';

        $args = [
            //'--defaults-file=' . $this->cnf, // [ERROR] Failed to open required defaults file: /etc/my.cnf
            '--user=' . $this->dsn->getUser(),
            '--password=' . $this->dsn->getPassword(),
            '--host=' . $this->dsn->getHost(),
            '--backup',
            '--strict',
            '--history=' . $this->database, // logs PERCONA_SCHEMA.xtrabackup_history name attribute
            '--slave-info',
            '--safe-slave-backup',
            '--safe-slave-backup-timeout=300',
            '--check-privileges', // checks if Percona XtraBackup has all the required privileges.
            '--target-dir=' . $target,
            '--parallel=' . self::PROCESSORS,
            '--compress=' . self::COMPRESS_ALGORITHM,
            '--compress-threads=' . self::PROCESSORS,
            '--rsync', // https://docs.percona.com/percona-xtrabackup/8.0/accelerate-backup-process.html
            //'--encrypt-threads=' . $this->processors,
            //'--encrypt=AES256',
            //'--encrypt-key-file=' . '/encryption_key_file',
            //'--no-lock', // https://docs.percona.com/percona-xtrabackup/8.0/xtrabackup-option-reference.html#-no-lock
            '2> ' . $logfile,
        ];

        $cmd = 'docker exec appwrite-xtrabackup xtrabackup ' . implode(' ', $args);
        self::log($cmd);
        shell_exec($cmd);

        $stderr = shell_exec('tail -1 ' . $logfile);
        Backup::log($stderr);

        if (!str_contains($stderr, 'completed OK!') || !file_exists($target . '/xtrabackup_checkpoints')) {
            Console::error('Backup failed');
            Console::exit();
        }

        // todo: remove logfile?
    }

    public function tar(string $directory, string $file)
    {
        $stdout = '';
        $stderr = '';
        // Tar from inside the directory for not using --strip-components
        $cmd = 'cd ' . $directory . ' && tar zcf ' . $file . ' .';
        self::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        self::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        if (!file_exists($file)) {
            Console::error("Can't find tar file: " . $file);
            Console::exit();
        }

        $filesize = \filesize($file);
        self::log("Tar file size is: " . ceil($filesize / 1024 / 1024) . 'MB');
        if ($filesize < (2 * 1024 * 1024)) {
            Console::error("File size is very small: " . $file);
            Console::exit();
        }
    }

    public function upload(string $file, Device $local)
    {
        $filename = basename($file);
        $s3 = new DOSpaces('/' . $this->database . '/full', App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION'));

        if (!$s3->exists('/')) {
            Console::error('Can\'t read s3 root directory');
            Console::exit();
        }

        try {
            self::log('Uploading: ' . $file);
            $destination = $s3->getRoot() . '/' . $filename;

            if (!$local->transfer($file, $destination, $s3)) {
                Console::error('Error uploading to ' . $destination);
                Console::exit();
            }

            if (!$s3->exists($destination)) {
                Console::error('File not found on s3 ' . $destination);
                Console::exit();
            }
        } catch (Exception $e) {
            Console::error($e->getMessage());
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
                '_APP_CONNECTIONS_DB_PROJECT',
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

    public static function getDsn(string $database): ?DSN
    {
        foreach (explode(',', App::getEnv('_APP_CONNECTIONS_DB_PROJECT')) as $project) {
           [$db, $dsn] = explode('=', $project);
            if ($db === $database) {
                return new DSN($dsn);
            }
        }
        return null;
    }
}
