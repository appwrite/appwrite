<?php

namespace Appwrite\Resque;

use Exception;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Database\Database;
use Utopia\Storage\Device;
use Utopia\Storage\Storage;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\S3;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

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
    protected function getProjectDB(Document $project): Database
    {
        global $register;

        $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

        if ($project->isEmpty() || $project->getId() === 'console') {
            return $this->getConsoleDB();
        }

        $dbAdapter = $pools
            ->get($project->getAttribute('database'))
            ->pop()
            ->getResource()
        ;

        $database = new Database($dbAdapter, $this->getCache());
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
        switch (App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL)) {
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
            case Storage::DEVICE_S3:
                $s3AccessKey = App::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
                $s3SecretKey = App::getEnv('_APP_STORAGE_S3_SECRET', '');
                $s3Region = App::getEnv('_APP_STORAGE_S3_REGION', '');
                $s3Bucket = App::getEnv('_APP_STORAGE_S3_BUCKET', '');
                $s3Acl = 'private';
                return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = App::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
                $doSpacesSecretKey = App::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
                $doSpacesRegion = App::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
                $doSpacesBucket = App::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
                $doSpacesAcl = 'private';
                return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
            case Storage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = App::getEnv('_APP_STORAGE_BACKBLAZE_ACCESS_KEY', '');
                $backblazeSecretKey = App::getEnv('_APP_STORAGE_BACKBLAZE_SECRET', '');
                $backblazeRegion = App::getEnv('_APP_STORAGE_BACKBLAZE_REGION', '');
                $backblazeBucket = App::getEnv('_APP_STORAGE_BACKBLAZE_BUCKET', '');
                $backblazeAcl = 'private';
                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
            case Storage::DEVICE_LINODE:
                $linodeAccessKey = App::getEnv('_APP_STORAGE_LINODE_ACCESS_KEY', '');
                $linodeSecretKey = App::getEnv('_APP_STORAGE_LINODE_SECRET', '');
                $linodeRegion = App::getEnv('_APP_STORAGE_LINODE_REGION', '');
                $linodeBucket = App::getEnv('_APP_STORAGE_LINODE_BUCKET', '');
                $linodeAcl = 'private';
                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
            case Storage::DEVICE_WASABI:
                $wasabiAccessKey = App::getEnv('_APP_STORAGE_WASABI_ACCESS_KEY', '');
                $wasabiSecretKey = App::getEnv('_APP_STORAGE_WASABI_SECRET', '');
                $wasabiRegion = App::getEnv('_APP_STORAGE_WASABI_REGION', '');
                $wasabiBucket = App::getEnv('_APP_STORAGE_WASABI_BUCKET', '');
                $wasabiAcl = 'private';
                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
        }
    }
}
