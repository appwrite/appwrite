<?php

namespace Appwrite\Resque;

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Logger\Log;
use function get_class;

abstract class Worker
{
    public array $args = [];

    abstract public function getWorkerName(): string;

    abstract public function init(): void;

    abstract public function run(): void;

    abstract public function shutdown(): void;

    public function setUp(): void
    {
        try {
            $this->init();
        } catch(\Throwable $error) {
            global $register;
            $logger = $register->get('logger');

            $version = App::getEnv('_APP_VERSION', 'UNKNOWN');
            $workerType = $this->getWorkerName();

            $log = new Log();

            $log->setNamespace("worker-" . $workerType);
            $log->setServer(App::getEnv("_APP_LOGGING_SERVERNAME", "selfhosted-001"));
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());

            $log->setTags([
                'worker_type' => $workerType,
                'code' => $error->getCode(),
                'verbose_type' => get_class($error),
            ]);

            $log->setExtra([
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
                'args' => $this->args
            ]);

            $action = 'worker.' . $workerType . '.setUp';
            $log->setAction($action);

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $log->setBreadcrumbs([]);

            $responseCode = $logger->addLog($log);
            Console::info('Setup log pushed with status code: '.$responseCode);

            throw $error;
        }
    }

    public function perform(): void
    {
        try {
            $this->run();
        } catch(\Throwable $error) {
            global $register;
            $logger = $register->get('logger');
            $version = App::getEnv('_APP_VERSION', 'UNKNOWN');
            $workerType = $this->getWorkerName();

            $log = new Log();

            $log->setNamespace("worker-" . $workerType);
            $log->setServer(App::getEnv("_APP_LOGGING_SERVERNAME", "selfhosted-001"));
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());

            $log->setTags([
                'worker_type' => $workerType,
                'code' => $error->getCode(),
                'verbose_type' => get_class($error),
            ]);

            $log->setExtra([
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString()
            ]);

            $action = 'worker.' . $workerType . '.perform';
            $log->setAction($action);

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $log->setBreadcrumbs([]);

            $responseCode = $logger->addLog($log);
            Console::info('Perform log pushed with status code: '.$responseCode);

            throw $error;
        }
    }

    public function tearDown(): void
    {
        try {
            $this->shutdown();
        } catch(\Throwable $error) {
            global $register;
            $logger = $register->get('logger');
            $version = App::getEnv('_APP_VERSION', 'UNKNOWN');
            $workerType = $this->getWorkerName();

            $log = new Log();

            $log->setNamespace("worker-" . $workerType);
            $log->setServer(App::getEnv("_APP_LOGGING_SERVERNAME", "selfhosted-001"));
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());

            $log->setTags([
                'worker_type' => $workerType,
                'code' => $error->getCode(),
                'verbose_type' => get_class($error),
            ]);

            $log->setExtra([
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString()
            ]);

            $action = 'worker.' . $workerType . '.tearDown';
            $log->setAction($action);

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $log->setBreadcrumbs([]);

            $responseCode = $logger->addLog($log);
            Console::info('Teardown log pushed with status code: '.$responseCode);

            throw $error;
        }
    }
}
