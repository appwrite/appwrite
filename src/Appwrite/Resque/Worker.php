<?php

namespace Appwrite\Resque;

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Logger\Log;

abstract class Worker
{
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
            global $register;
            $logger = $register->get('logger');

            if($logger) {
                $version = App::getEnv('_APP_VERSION', 'UNKNOWN');
                $workerType = $this->getName();

                $log = new Log();

                $log->setNamespace("worker-" . $workerType);
                $log->setServer(\gethostname());
                $log->setVersion($version);
                $log->setType(Log::TYPE_ERROR);
                $log->setMessage($error->getMessage());

                $log->addTag('worker_type', $workerType);
                $log->addTag('code', $error->getCode());
                $log->addTag('verbose_type', \get_class($error));

                $log->addExtra('file', $error->getFile());
                $log->addExtra('line', $error->getLine());
                $log->addExtra('trace', $error->getTraceAsString());
                $log->addExtra('args', $this->args);

                $action = 'worker.' . $workerType . '.setUp';
                $log->setAction($action);

                $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
                $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

                $responseCode = $logger->addLog($log);
                Console::info('Setup log pushed with status code: '.$responseCode);
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
            global $register;
            $logger = $register->get('logger');

            if($logger) {
                $version = App::getEnv('_APP_VERSION', 'UNKNOWN');
                $workerType = $this->getName();

                $log = new Log();

                $log->setNamespace("worker-" . $workerType);
                $log->setServer(\gethostname());
                $log->setVersion($version);
                $log->setType(Log::TYPE_ERROR);
                $log->setMessage($error->getMessage());

                $log->setTags([
                    'worker_type' => $workerType,
                    'code' => $error->getCode(),
                    'verbose_type' => \get_class($error),
                ]);

                $log->addExtra('file', $error->getFile());
                $log->addExtra('line', $error->getLine());
                $log->addExtra('trace', $error->getTraceAsString());

                $action = 'worker.' . $workerType . '.perform';
                $log->setAction($action);

                $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
                $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

                $responseCode = $logger->addLog($log);
                Console::info('Perform log pushed with status code: '.$responseCode);
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
            global $register;
            $logger = $register->get('logger');

            if($logger) {
                $version = App::getEnv('_APP_VERSION', 'UNKNOWN');
                $workerType = $this->getName();

                $log = new Log();

                $log->setNamespace("worker-" . $workerType);
                $log->setServer(\gethostname());
                $log->setVersion($version);
                $log->setType(Log::TYPE_ERROR);
                $log->setMessage($error->getMessage());

                $log->setTags([
                    'worker_type' => $workerType,
                    'code' => $error->getCode(),
                    'verbose_type' => \get_class($error),
                ]);

                $log->addExtra('file', $error->getFile());
                $log->addExtra('line', $error->getLine());
                $log->addExtra('trace', $error->getTraceAsString());

                $action = 'worker.' . $workerType . '.tearDown';
                $log->setAction($action);

                $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
                $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

                $responseCode = $logger->addLog($log);
                Console::info('Teardown log pushed with status code: '.$responseCode);
            }

            throw $error;
        }
    }
}
