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
    //public const BACKUP_INTERVAL_SECONDS = 60 * 60 * 4; // 4 hours;
    public const BACKUP_INTERVAL_SECONDS = 100;
    public const COMPRESS_ALGORITHM = 'zstd'; // https://www.percona.com/blog/get-your-backup-to-half-of-its-size-introducing-zstd-support-in-percona-xtrabackup/
    public const CLEANUP_LOCAL_FILES_SECONDS = 60 * 60 * 24 * 1; // 2 days?
    public const CLEANUP_CLOUD_FILES_SECONDS = 60 * 60 * 24 * 1; // 14 days?;
    public const UPLOAD_CHUNK_SIZE = 5 * 1024 * 1024; // Must be greater than 5MB;
    protected string $filename;
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
        $this->checkEnvVariables();

        $this->database = $database;
        $this->dsn = $this->getDsn($database);
        if (is_null($this->dsn)) {
            throw new Exception('No DSN match');
        }

        $dsn = new DSN(App::getEnv('_APP_CONNECTIONS_BACKUPS_STORAGE', ''));
        $this->s3 = new DOSpaces('/' . $database . '/full', $dsn->getUser(), $dsn->getPassword(), $dsn->getPath(), $dsn->getParam('region'));

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

        $this->setContainerId();
        $this->setProcessors();

        Console::loop(function () {
            try {
                $this->start();
            } catch (Exception $e) {
                //todo: send alerts to admin!
                // todo: Do we want to terminate the script? or wait for next backup iteration?
                Console::error($e->getMessage());
            }
        }, self::BACKUP_INTERVAL_SECONDS);
    }

    /**
     * @throws Exception
     */
    public function start(): void
    {
        $start = microtime(true);
        $this->filename = date('Y_m_d_H_i_s') . '.xbstream';
        //$this->filename = '2023_09_06_08_19_24.xbstream';

        self::log('--- Backup Start ' . $this->filename . ' --- ');

        $local = new Local(self::BACKUPS_PATH . '/' . $this->database . '/full');
        $local->setTransferChunkSize(self::UPLOAD_CHUNK_SIZE);

        $this->fullBackup($local);

        $max = 1;
        $i = 0;
        while (true) {
            $i++;
            try {
                $this->upload($local);
                break;
            } catch (Exception $e) {
                if ($i <= $max) {
                    Console::warning($e->getMessage() . ' (' . $i . ' attempt)');
                } else {
                    throw new Exception($e->getMessage());
                }
            }
        }

        $this->cleanLocalFiles($local);
        $this->cleanCloudFiles();

        self::log('--- Backup Finish ' . (microtime(true) - $start) . ' seconds --- '   . PHP_EOL . PHP_EOL);
    }

    /**
     * @throws Exception
     */
    public function fullBackup(Device $device)
    {
        self::log('Backup start');

        $target = $device->getRoot();
        $log = $device->getPath($this->filename . '.log');
        $file = $device->getPath($this->filename);

        if (!file_exists(self::BACKUPS_PATH)) {
            throw new Exception('Mount directory does not exist');
        }

        if (!file_exists($target) && !mkdir($target, 0755, true)) {
            throw new Exception('Error creating directory: ' . $target);
        }

        $args = [
            'xtrabackup',
            '--user=' . $this->dsn->getUser(),
            '--password=' . $this->dsn->getPassword(),
            '--host=' . $this->dsn->getHost(),
            '--port=' . $this->dsn->getPort(),
            '--backup',
            '--stream=xbstream',
            '--strict',
            '--history="' . $this->database . '|' . pathinfo($this->filename, PATHINFO_FILENAME) . '"', // PERCONA_SCHEMA.xtrabackup_history
            '--slave-info',
            '--safe-slave-backup',
            '--safe-slave-backup-timeout=300',
            '--check-privileges', // checks if Percona XtraBackup has all the required privileges.
            '--target-dir=' . self::BACKUPS_PATH,
            '--compress=' . self::COMPRESS_ALGORITHM,
            '--compress-threads=' . intval($this->processors / 2),
            '--parallel=' . $this->processors,
            '> ' . $file,
            '2> ' . $log,
        ];

        $cmd = 'docker exec ' . $this->xtrabackupContainerId . ' ' . implode(' ', $args);
        shell_exec($cmd);
        Console::success($cmd);
        $stderr = shell_exec('tail -1 ' . $log);

        if (!str_contains($stderr, 'completed OK!')) {
            throw new Exception('Backup failed: ' . $stderr);
        }

        if (!unlink($log)) {
            throw new Exception('Error deleting: ' . $log);
        }
    }

    /**
     * @throws Exception
     */
    public function upload(Device $local)
    {
        self::log('Upload start');

        $file = $local->getPath($this->filename);

        if (!$this->s3->exists('/')) {
            throw new Exception('Can\'t read s3 root directory');
        }

        $destination = $this->s3->getRoot() . '/' . $this->filename;

        if (!$local->transfer($file, $destination, $this->s3)) {
            throw new Exception('Error uploading to ' . $destination);
        }

        if (!$this->s3->exists($destination)) {
            throw new Exception('File not found in destination: ' . $destination);
        }
    }

    public function cleanLocalFiles(Device $local)
    {
        self::log('cleanLocalFiles start');

        $folder = scandir($local->getRoot());
        if ($folder !== false) {
            foreach ($folder as $item) {
                if ($this->isDelete($item, self::CLEANUP_LOCAL_FILES_SECONDS)) {
                    if (str_ends_with($item, '.xbstream.log')) {
                        $delete = true;
                    } else {
                        // Check if file exist on cloud before delete
                        if ($this->s3->exists($this->s3->getRoot() . '/' . $item)) {
                            $delete = true;
                        } else {
                            if ($this->isDelete($item, self::CLEANUP_CLOUD_FILES_SECONDS)) {
                                $delete = true;
                            } else {
                                Console::warning('Skipping delete not found on cloud: ' . $local->getPath($item) . ' ');
                                $delete = false;
                            }
                        }
                    }

                    if ($delete === true) {
                        if (unlink($local->getPath($item))) {
                            Console::success($local->getPath($item) . ' Deleted!');
                        }
                    }
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    public function cleanCloudFiles(): void
    {
        self::log('cleanCloudFiles start');

        $files = $this->s3->getFiles($this->s3->getRoot());

        if ($files['KeyCount'] > 0) {
            foreach ($files['Contents'] as $file) {
                if ($this->isDelete(basename($file['Key']), self::CLEANUP_CLOUD_FILES_SECONDS)) {
                    if ($this->s3->delete($file['Key'])) {
                        Console::success($file['Key'] . ' Deleted!');
                    }
                }
            }
        }
    }

    /**
     * @param string $item
     * @param int $seconds
     * @return bool
     */
    public function isDelete(string $item, int $seconds): bool
    {
        if (str_ends_with($item, '.xbstream')) {
            $item = substr($item, 0, -9);
        } elseif (str_ends_with($item, '.xbstream.log')) {
            $item = substr($item, 0, -13);
        } else {
            return false;
        }

        [$year, $month, $day, $hour, $minute, $second] = explode('_', $item);
        $date = $year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $minute . ':' . $second;

        try {
            $now = new \DateTime();
            $backupDate = new \DateTime($date);
            if (($now->getTimestamp() - $backupDate->getTimestamp()) > $seconds) {
                return true;
            }
        } catch (Exception $e) {
        }

        return false;
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
        $processors = intval($processors);

        if ($processors === 0) {
            throw new Exception('Set Processors Error');
        }

        $this->processors = $processors;
    }
}
