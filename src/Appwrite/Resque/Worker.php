<?php

namespace Appwrite\Resque;

use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Adapter\MariaDB;

use Exception;
abstract class Worker
{
    /**
     * Callbacks that will be executed when an error occurs
     *
     * @var array
     */
    static protected array $errorCallbacks = [];

    /**
     * Associative array holding all information passed into the worker
     *
     * @return array
     */
    public array $args = [];

    /**
     * Function for identifying the worker needs to be set to unique name
     *
     * @return string
     * @throws Exception
     */
    public function getName(): string
    {
        throw new Exception("Please implement getName method in worker");
    }

    /**
     * Function executed before running first task.
     * Can include any preparations, such as connecting to external services or loading files
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function init() {
        throw new Exception("Please implement getName method in worker");
    }

    /**
     * Function executed when new task requests is received.
     * You can access $args here, it will contain event information
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function run() {
        throw new Exception("Please implement getName method in worker");
    }

    /**
     * Function executed just before shutting down the worker.
     * You can do cleanup here, such as disconnecting from services or removing temp files
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function shutdown() {
        throw new Exception("Please implement getName method in worker");
    }

    const MAX_ATTEMPTS = 10;
    const SLEEP_TIME = 2;

    const DATABASE_PROJECT = 'project';
    const DATABASE_CONSOLE = 'console';

    /**
     * A wrapper around 'init' function with non-worker-specific code
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function setUp(): void
    {
        try {
            $this->init();
        } catch(\Throwable $error) {
            foreach (self::$errorCallbacks as $errorCallback) {
                $errorCallback($error, "init", $this->getName());
            }

            throw $error;
        }
    }

    /**
     * A wrapper around 'run' function with non-worker-specific code
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function perform(): void
    {
        try {
            $this->run();
        } catch(\Throwable $error) {
            foreach (self::$errorCallbacks as $errorCallback) {
                $errorCallback($error, "run", $this->getName(), $this->args);
            }

            throw $error;
        }
    }

    /**
     * A wrapper around 'shutdown' function with non-worker-specific code
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function tearDown(): void
    {
        try {
            $this->shutdown();
        } catch(\Throwable $error) {
            foreach (self::$errorCallbacks as $errorCallback) {
                $errorCallback($error, "shutdown", $this->getName());
            }

            throw $error;
        }
    }


    /**
     * Register callback. Will be executed when error occurs.
     * @param callable $callback
     * @param Throwable $error
     * @return self
     */
    public static function error(callable $callback): void
    {
        \array_push(self::$errorCallbacks, $callback);
    }
    /**
     * Get internal project database
     * @param string $projectId
     * @return Database
     */
    protected function getProjectDB(string $projectId): Database
    {
        return $this->getDB(self::DATABASE_PROJECT, $projectId);
    }

    /**
     * Get console database
     * @return Database
     */
    protected function getConsoleDB(): Database
    {
        return $this->getDB(self::DATABASE_CONSOLE);
    }

    /**
     * Get console database
     * @param string $type One of (internal, external, console)
     * @param string $projectId of internal or external DB
     * @return Database
     */
    private function getDB($type, $projectId = ''): Database
    {
        global $register;

        $namespace = '';
        $sleep = self::SLEEP_TIME; // overwritten when necessary

        switch ($type) {
            case self::DATABASE_PROJECT:
                if (!$projectId) {
                    throw new \Exception('ProjectID not provided - cannot get database');
                }
                $namespace = "_project_{$projectId}";
                break;
            case self::DATABASE_CONSOLE:
                $namespace = "_project_console";
                $sleep = 5; // ConsoleDB needs extra sleep time to ensure tables are created
                break;
            default:
                throw new \Exception('Unknown database type: ' . $type);
                break;
        }

        $attempts = 0;

        do {
            try {
                $attempts++;
                $cache = new Cache(new RedisCache($register->get('cache')));
                $database = new Database(new MariaDB($register->get('db')), $cache);
                $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
                $database->setNamespace($namespace); // Main DB
                if (!empty($projectId) && !$database->getDocument('projects', $projectId)->isEmpty()) {
                    throw new \Exception("Project does not exist: {$projectId}");
                }
                break; // leave loop if successful
            } catch(\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= self::MAX_ATTEMPTS) {
                    throw new \Exception('Failed to connect to database: '. $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < self::MAX_ATTEMPTS);

        return $database;
    }
}
