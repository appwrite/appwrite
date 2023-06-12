<?php

namespace Appwrite\Resque;

use Exception;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Cache\Adapter\Sharding;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\S3;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Storage\Storage;

abstract class Worker
{
    /**
     * Callbacks that will be executed when an error occurs
     *
     * @var array
     */
    protected static array $errorCallbacks = [];

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
    public function init()
    {
        throw new Exception("Please implement init method in worker");
    }

    /**
     * Function executed when new task requests is received.
     * You can access $args here, it will contain event information
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function run()
    {
        throw new Exception("Please implement run method in worker");
    }

    /**
     * Function executed just before shutting down the worker.
     * You can do cleanup here, such as disconnecting from services or removing temp files
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function shutdown()
    {
        throw new Exception("Please implement shutdown method in worker");
    }

    public const DATABASE_PROJECT = 'project';
    public const DATABASE_CONSOLE = 'console';

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
        } catch (\Throwable $error) {
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
            /**
             * Disabling global authorization in workers.
             */
            Authorization::disable();
            Authorization::setDefaultStatus(false);
            $this->run();
        } catch (\Throwable $error) {
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
        global $register;

        try {
            $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */
            $pools->reclaim();

            $this->shutdown();
        } catch (\Throwable $error) {
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
     * @param Document $project
     * @return Database
     */
    protected static $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools
    protected function getProjectDB(Document $project): Database
    {
        global $register;

        $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

        Console::log("Getting project DB for {$project->getId()}");

        if ($project->isEmpty() || $project->getId() === 'console') {
            return $this->getConsoleDB();
        }

        $databaseName = $project->getAttribute('database');

        var_dump(array_keys(self::$databases));

        if (isset(self::$databases[$databaseName])) {
            $database = self::$databases[$databaseName];
            $database->setNamespace('_' . $project->getInternalId());
            return $database;
        }

        $dbAdapter = $pools
            ->get($project->getAttribute('database'))
            ->pop()
            ->getResource()
        ;

        $database = new Database($dbAdapter, $this->getCache());

        self::$databases[$databaseName] = $database;

        $database->setNamespace('_' . $project->getInternalId());

        return $database;
    }

    /**
     * Get console database
     * @return Database
     */
    protected function getConsoleDB(): Database
    {
        global $register;

        $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

        $dbAdapter = $pools
            ->get('console')
            ->pop()
            ->getResource()
        ;

        $database = new Database($dbAdapter, $this->getCache());

        $database->setNamespace('console');

        return $database;
    }


    /**
     * Get Cache
     * @return Cache
     */
    protected function getCache(): Cache
    {
        global $register;

        $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

        $list = Config::getParam('pools-cache', []);
        $adapters = [];

        foreach ($list as $value) {
            $adapters[] = $pools
                ->get($value)
                ->pop()
                ->getResource()
            ;
        }

        return new Cache(new Sharding($adapters));
    }

    /**
     * Get Functions Storage Device
     * @param string $projectId of the project
     * @return Device
     */
    protected function getFunctionsDevice($projectId): Device
    {
        return $this->getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $projectId);
    }

    /**
     * Get Files Storage Device
     * @param string $projectId of the project
     * @return Device
     */
    protected function getFilesDevice($projectId): Device
    {
        return $this->getDevice(APP_STORAGE_UPLOADS . '/app-' . $projectId);
    }

    /**
     * Get Builds Storage Device
     * @param string $projectId of the project
     * @return Device
     */
    protected function getBuildsDevice($projectId): Device
    {
        return $this->getDevice(APP_STORAGE_BUILDS . '/app-' . $projectId);
    }

    /**
     * Get Device based on selected storage environment
     * @param string $root path of the device
     * @return Device
     */
    public function getDevice($root): Device
    {
        $connection = App::getEnv('_APP_CONNECTIONS_STORAGE', '');

        $acl = 'private';
        $device = Storage::DEVICE_LOCAL;
        $accessKey = '';
        $accessSecret = '';
        $bucket = '';
        $region = '';

        try {
            $dsn = new DSN($connection);
            $device = $dsn->getScheme();
            $accessKey = $dsn->getUser();
            $accessSecret = $dsn->getPassword();
            $bucket = $dsn->getPath();
            $region = $dsn->getParam('region');
        } catch (\Exception $e) {
            Console::error($e->getMessage() . 'Invalid DSN. Defaulting to Local device.');
        }

        switch ($device) {
            case Storage::DEVICE_S3:
                return new S3($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case STORAGE::DEVICE_DO_SPACES:
                return new DOSpaces($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_BACKBLAZE:
                return new Backblaze($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LINODE:
                return new Linode($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_WASABI:
                return new Wasabi($root, $accessKey, $accessSecret, $bucket, $region, $acl);
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
        }
    }
}
