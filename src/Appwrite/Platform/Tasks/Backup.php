<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Pools\Group;
use Utopia\Storage\Device;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;

class Backup extends Action
{
    protected string $host = 'mariadb';
    protected string $project;
    public const BACKUPS = '/backups';
    //public const BACKUP_INTERVAL = 60 * 60 * 4; // 4 hours;
    public const BACKUP_INTERVAL = 300; // 4 hours;
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
        $this->project = explode('=', App::getEnv('_APP_CONNECTIONS_DB_PROJECT'))[0];

        $this
            ->desc('Backup a DB')
            ->inject('pools')
            ->callback(fn(Group $pools) => $this->action($pools));
    }

    /**
     * @throws \Exception
     */
    public function action($pools): void
    {
        $attempts = 0;
        $max = 10;
        $sleep = 5;

        do {
            try {
                $attempts++;
                $pools
                    ->get('database_' . $this->project)
                    ->pop()
                    ->getResource()
                ;

                break; // leave the do-while if successful
            } catch (\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('Failed to connect to database: ' . $e->getMessage());
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
        self::log('--- Backup Start --- ');
        $start = microtime(true);
        //$type = 'inc';
        $type = 'full';

        switch ($type) {
            case 'inc':
                $this->incrementalBackup();
                break;
            case 'full':
                $time = date('Y_m_d_H_i_s');
                self::log('--- Creating backup ' . $time . '  --- ');
                $filename = $time . '.tar.gz';
                $local = new Local(self::BACKUPS . '/' . $this->project . '/full/' . $time);
                $local->setTransferChunkSize(5  * 1024 * 1024); // > 5MB

                $backups = $local->getRoot() . '/files';
                $tarFile = $local->getPath($filename);

                $this->fullBackup($backups);
                $this->tar($backups, $tarFile);
                $this->upload($tarFile, $local);
                // todo: Do we want to delete the tar file? and remain with the folder?
                // todo: Do we want to delete the tar log.file?
                break;

            default:
                Console::error('No type detected');
                Console::exit();
        }

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

        $logfile = $target . '/../log.txt';

        $args = [
            //'--defaults-file=' . $this->cnf, // [ERROR] Failed to open required defaults file: /etc/my.cnf
            '--user=root',
            '--password=' . App::getEnv('_APP_DB_ROOT_PASS'),
            '--host=' . $this->host,
            '--backup',
            '--strict',
            '--history=' . $this->project, // logs PERCONA_SCHEMA.xtrabackup_history
            '--slave-info',
            '--safe-slave-backup',
            '--safe-slave-backup-timeout=300',
            '--check-privileges', // checks if Percona XtraBackup has all the required privileges.
            '--target-dir=' . $target,
            '--parallel=' . self::PROCESSORS,
            '--compress=' . self::COMPRESS_ALGORITHM,
            '--compress-threads=' . self::PROCESSORS,
            '--rsync', // https://docs.percona.com/percona-xtrabackup/8.0/accelerate-backup-process.htm
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
        $s3 = new DOSpaces('/' . $this->project . '/full', App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION'));

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
        } catch (\Exception $e) {
            Console::error($e->getMessage());
            Console::exit();
        }
    }

    public function incrementalBackup()
    {
        $project = $this->project;
        //$folder = ceil(time() / 60 * 60);
        //$folder = date('Y_m_d');
        //$folder = ceil(date('z') / 7); // day of the year 0-365
        $folder = date('W'); // week of the year  0 - 51

        $folder = 'v1_' . date('Y') . '_' . $folder;
        $local = new Local(self::BACKUPS . '/' . $project . '/inc/' . $folder);
        $position = 1;
        $target = $local->getRoot() . '/' . $position;
        $base = '';

        if (file_exists($local->getRoot() . '/position')) {
            $position = intval(file_get_contents($local->getRoot() . '/position'));

            if (!file_exists($local->getRoot() . '/' . $position . '/xtrabackup_checkpoints')) {
                Console::error('Backup ' . $folder . ' is garbage!!!');
                Console::exit();
            }

            $base = $local->getRoot() . '/' . $position;
            $position += 1;
            $target = $local->getRoot() . '/' . $position;
        }

        Console::success($base);
        Console::success($target);

        if (!file_exists($target) && !mkdir($target, 0755, true)) {
            Console::error('Error creating backup directory: ' . $target);
            Console::exit();
        }

        file_put_contents($local->getRoot() . '/position', $position);

        $args = [
            '--user=root',
            '--password=rootsecretpassword',
            '--backup=1',
            '--strict',
            '--host=' . $this->host,
            '--safe-slave-backup',
            '--safe-slave-backup-timeout=300',
            '--check-privileges',
            //'--no-lock',  // https://docs.percona.com/percona-xtrabackup/8.0/xtrabackup-option-reference.html#-no-lock
            //'--compress=lz4',
            //'--compress-threads=1',
            '--target-dir=' . $target
        ];

        if (!empty($base)) {
            $args[] = '--incremental-basedir=' . $base;
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker exec appwrite-xtrabackup xtrabackup ' . implode(' ', $args);
        self::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
           // Console::error($stderr);
            //Console::exit();
        }

        // For some reason they write everything as $stderr
        if (!str_contains($stderr, 'completed OK!')) {
            /// Todo We need to destroy this directory and all the data inside or move it somewhere
            Console::error($stderr);
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
                '_APP_DB_ROOT_PASS'
            ] as $env
        ) {
            if (empty(App::getEnv($env))) {
                Console::error('Can\'t read ' . $env);
                Console::exit();
            }
        }
    }
}
