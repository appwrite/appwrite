<?php

namespace Appwrite\Resque;

use Exception;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Adapter\MariaDB;

abstract class Worker
{
    public $args = [];

    abstract public function init(): void;

    abstract public function run(): void;

    abstract public function shutdown(): void;

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
     * Get internal project database
     * @param string $projectId
     * @return Database
     */
    protected function getInternalDB(string $projectId): Database
    {
        global $register;

        $attempts = 0;
        $max = 10;
        $sleep = 2;

        do {
            try {
                $attempts++;
                $cache = new Cache(new RedisCache($register->get('cache')));
                $dbForInternal = new Database(new MariaDB($register->get('db')), $cache);
                $dbForInternal->setNamespace("project_{$projectId}_internal"); // Main DB
                if (!$dbForInternal->exists()) {
                    throw new Exception("Table does not exist: {$dbForInternal->getNamespace()}");
                }
                break; // leave loop if successful
            } catch(\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('Failed to connect to database: '. $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < $max);

        return $dbForInternal;
    }

    /**
     * Get external project database
     * @param string $projectId
     * @return Database
     */
    protected function getExternalDB(string $projectId): Database
    {
        global $register;

        $attempts = 0;
        $max = 10;
        $sleep = 2;

        do {
            try {
                $attempts++;
                $cache = new Cache(new RedisCache($register->get('cache')));
                $dbForExternal = new Database(new MariaDB($register->get('db')), $cache);
                $dbForExternal->setNamespace("project_{$projectId}_external"); // Main DB
                if (!$dbForExternal->exists()) {
                    throw new Exception("Table does not exist: {$dbForExternal->getNamespace()}");
                }
                break; // leave loop if successful
            } catch(\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('Failed to connect to database: '. $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < $max);

        return $dbForExternal;
    }

    /**
     * Get console database
     * @return Database
     */
    protected function getConsoleDB(): Database
    {
        global $register;

        $attempts = 0;
        $max = 5;
        $sleep = 5;

        do {
            try {
                $attempts++;
                $cache = new Cache(new RedisCache($register->get('cache')));
                $dbForConsole = new Database(new MariaDB($register->get('db')), $cache);
                $dbForConsole->setNamespace('project_console_internal'); // Main DB
                if (!$dbForConsole->exists()) {
                    throw new Exception("Table does not exist: {$dbForConsole->getNamespace()}");
                }
                break; // leave loop if successful
            } catch(\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('Failed to connect to database: '. $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < $max);

        return $dbForConsole;
    }
}