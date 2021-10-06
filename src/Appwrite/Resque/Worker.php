<?php

namespace Appwrite\Resque;

use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;

abstract class Worker
{
    public $args = [];

    abstract public function init(): void;

    abstract public function run(): void;

    abstract public function shutdown(): void;

    const MAX_ATTEMPTS = 10;
    const SLEEP_TIME = 2;

    const DATABASE_INTERNAL = 'internal';
    const DATABASE_EXTERNAL = 'external';
    const DATABASE_CONSOLE = 'console';

    public function setUp(): void
    {
        $this->init();
    }

    public function perform()
    {
        $this->run();
    }

    public function tearDown(): void
    {
        $this->shutdown();
    }

    /**
     * Get internal project database.
     */
    protected function getInternalDB(string $projectId): Database
    {
        return $this->getDB(self::DATABASE_INTERNAL, $projectId);
    }

    /**
     * Get external project database.
     */
    protected function getExternalDB(string $projectId): Database
    {
        return $this->getDB(self::DATABASE_EXTERNAL, $projectId);
    }

    /**
     * Get console database.
     */
    protected function getConsoleDB(): Database
    {
        return $this->getDB(self::DATABASE_CONSOLE);
    }

    /**
     * Get console database.
     *
     * @param string $type      One of (internal, external, console)
     * @param string $projectId of internal or external DB
     */
    private function getDB($type, $projectId = ''): Database
    {
        global $register;

        $namespace = '';
        $sleep = self::SLEEP_TIME; // overwritten when necessary

        switch ($type) {
            case self::DATABASE_INTERNAL:
                if (!$projectId) {
                    throw new \Exception('ProjectID not provided - cannot get database');
                }
                $namespace = "project_{$projectId}_internal";
                break;
            case self::DATABASE_EXTERNAL:
                if (!$projectId) {
                    throw new \Exception('ProjectID not provided - cannot get database');
                }
                $namespace = "project_{$projectId}_external";
                break;
            case self::DATABASE_CONSOLE:
                $namespace = 'project_console_internal';
                $sleep = 5; // ConsoleDB needs extra sleep time to ensure tables are created
                break;
            default:
                throw new \Exception('Unknown database type: '.$type);
                break;
        }

        $attempts = 0;

        do {
            try {
                ++$attempts;
                $cache = new Cache(new RedisCache($register->get('cache')));
                $database = new Database(new MariaDB($register->get('db')), $cache);
                $database->setNamespace($namespace); // Main DB
                if (!$database->exists()) {
                    throw new \Exception("Table does not exist: {$database->getNamespace()}");
                }
                break; // leave loop if successful
            } catch (\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= self::MAX_ATTEMPTS) {
                    throw new \Exception('Failed to connect to database: '.$e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < self::MAX_ATTEMPTS);

        return $database;
    }
}
