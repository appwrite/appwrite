<?php

namespace Appwrite\Resque;

abstract class Worker
{
    /**
     * Callbacks that will be executed when an error occurs
     *
     * @var array
     */
    protected $errorCallbacks = [];

    /**
     * Named array holding all information passed into worker alongside a new task.
     *
     * @return array
     */
    public array $args = [];

    /**
     * Function for identifying the worker needs to be set to unique name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Function executed before running first task.
     * Can include any preparations, such as connecting to external services or loading files
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    abstract public function init(): void;

    /**
     * Function executed when new task requests is received.
     * You can access $args here, it will contain event information
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    abstract public function run(): void;

    /**
     * Function executed just before shutting down the worker.
     * You can do cleanup here, such as disconnecting from services or removing temp files
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    abstract public function shutdown(): void;

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
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, "init");
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
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, "run");
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
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, "shutdown");
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
    public function error(callable $callback): self
    {
        \array_push($this->errorCallbacks, $callback);
        return $this;
    }

    // TODO: Implement this on init file using Worker->error(function() {  HERE })
    //global $register;
    //$logger = $register->get('logger');
    //
    //if($logger) {
    //$version = App::getEnv('_APP_VERSION', 'UNKNOWN');
    //$workerType = $this->getName();
    //
    //$log = new Log();
    //
    //$log->setNamespace("worker-" . $workerType);
    //$log->setServer(\gethostname());
    //$log->setVersion($version);
    //$log->setType(Log::TYPE_ERROR);
    //$log->setMessage($error->getMessage());
    //
    //$log->addTag('worker_type', $workerType);
    //$log->addTag('code', $error->getCode());
    //$log->addTag('verbose_type', \get_class($error));
    //
    //$log->addExtra('file', $error->getFile());
    //$log->addExtra('line', $error->getLine());
    //$log->addExtra('trace', $error->getTraceAsString());
    //$log->addExtra('args', $this->args);
    //
    //$action = 'worker.' . $workerType . '.setUp';
    //$log->setAction($action);
    //
    //$isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
    //$log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);
    //
    //$responseCode = $logger->addLog($log);
    //Console::info('Setup log pushed with status code: '.$responseCode);
    //}
}
