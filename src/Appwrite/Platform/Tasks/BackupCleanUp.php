<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use Utopia\DSN\DSN;
use Utopia\Platform\Action;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;
use Utopia\Validator\Text;

class BackupCleanUp extends Action
{
    public const BACKUPS_PATH = '/backups';
    public const CLEANUP_INTERVAL_SECONDS = 60 * 60 * 4; // 4 hours;
    public const CLEANUP_LOCAL_FILES_SECONDS = 60 * 60 * 24 * 7; // 7 days;
    protected string $filename;
    protected ?string $database = null;
    protected ?DOSpaces $s3 = null;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Clean old backups')
            ->param('database', null, new Text(20), 'Database name, for example db_fra1_01')
            ->callback(fn(string $database) => $this->action($database));
    }

    public static function getName(): string
    {
        return 'backup-cleanup';
    }

    /**
     * @throws Exception
     */
    public function action(string $database): void
    {
        var_dump(App::getEnv('_APP_CONNECTIONS_BACKUPS_STORAGE'));
        if (empty(App::getEnv('_APP_CONNECTIONS_BACKUPS_STORAGE'))) {
            Console::error('Can\'t read ' . '_APP_CONNECTIONS_BACKUPS_STORAGE');
            Console::exit();
        }

        $this->database = $database;

        try {
            $dsn = new DSN(App::getEnv('_APP_CONNECTIONS_BACKUPS_STORAGE', ''));
            $this->s3 = new DOSpaces('/' . $database . '/full', $dsn->getUser(), $dsn->getPassword(), $dsn->getPath(), $dsn->getParam('region'));
        } catch (\Exception $e) {
            Console::error($e->getMessage());
            Console::exit();
        }

        Console::loop(function () {
            $this->start();
        }, self::CLEANUP_INTERVAL_SECONDS);
    }

    public function start(): void
    {
        $start = microtime(true);
        self::log('--- Cleanup Start ' . date('Y-m-d H:i:s') . ' --- ');
        $this->cleanLocalFiles();
        $this->cleanCloudFiles();
        self::log('--- Cleanup Finish ' . (microtime(true) - $start) . ' seconds --- '   . PHP_EOL . PHP_EOL);
    }

    public function cleanLocalFiles()
    {
        self::log('cleanLocalFiles start');

        $local = new Local(self::BACKUPS_PATH . '/' . $this->database . '/full');
        $folder = scandir($local->getRoot());
        $now = new \DateTime();
        if ($folder !== false) {
            foreach ($folder as $item) {
                if (str_ends_with($item, '.xbstream')) {
                    [$year, $month, $day, $hour, $minute, $second] = explode('_', basename($item, '.xbstream'));
                    $date = $year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $minute . ':' . $second;
                    try {
                        $backupDate = new \DateTime($date);
                        $difference = $now->getTimestamp() - $backupDate->getTimestamp();
                        if ($difference > self::CLEANUP_LOCAL_FILES_SECONDS) {
                            // todo do we want to check if file exist on cloud before delete locally?
                            if ($this->s3->exists($this->s3->getRoot() . '/' . $item)) {
                                self::log('Deleting ' . $local->getPath($item));
                                //unlink($local->getPath($item));
                            } else {
                                Console::warning('Skipping delete file: ' . $local->getPath($item) . ' not found on cloud');
                            }
                        }
                    } catch (Exception $e) {
                        Console::error('DateTime error: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    public function cleanCloudFiles()
    {
        self::log('cleanCloudFiles start');

        // todo: how to scan a folder on cloud?

        if (!$this->s3->exists($this->s3->getRoot())) {
            Console::error('Can\'t read s3 ' . $this->s3->getRoot());
            Console::exit();
        }
    }

    public static function log(string $message): void
    {
        if (!empty($message)) {
            Console::log(date('Y-m-d H:i:s') . ' ' . $message);
        }
    }
}
