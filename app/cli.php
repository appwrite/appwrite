<?php

require_once __DIR__ . '/init.php';

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Func;
use Appwrite\Platform\Appwrite;
use Appwrite\Runtimes\Runtimes;
use Swoole\Runtime;
use Utopia\CLI\Adapters\Swoole as SwooleCLI;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Dependency;
use Utopia\Logger\Log;
use Utopia\Platform\Service;
use Utopia\Queue\Connection;
use Utopia\Registry\Registry;
use Utopia\System\System;

global $registry, $container;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

// overwriting runtimes to be architectur agnostic for CLI
Config::setParam('runtimes', (new Runtimes('v4'))->getAll(supported: false));

// require controllers after overwriting runtimes
require_once __DIR__ . '/controllers/general.php';

/**
 * @var Registry $registry
 * @var Container $container
 */
$context = new Dependency();
$register = new Dependency();
$logError = new Dependency();
$queueForDeletes = new Dependency();
$queueForFunctions = new Dependency();
$queueForCertificates = new Dependency();

$context
    ->setName('context')
    ->setCallback(fn () => $container);

$register
    ->setName('register')
    ->setCallback(function () use (&$registry): Registry {
        return $registry;
    });

$queueForFunctions
    ->setName('queueForFunctions')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Func($queue);
    });


$queueForDeletes
    ->setName('queueForDeletes')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Delete($queue);
    });

$queueForCertificates
    ->setName('queueForCertificates')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Certificate($queue);
    });

$logError
    ->setName('logError')
    ->inject('register')
    ->setCallback(function (Registry $register) {
        return function (Throwable $error, string $namespace, string $action) use ($register) {
            $logger = $register->get('logger');

            if ($logger) {
                $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

                $log = new Log();
                $log->setNamespace($namespace);
                $log->setServer(\gethostname());
                $log->setVersion($version);
                $log->setType(Log::TYPE_ERROR);
                $log->setMessage($error->getMessage());

                $log->addTag('code', $error->getCode());
                $log->addTag('verboseType', get_class($error));

                $log->addExtra('file', $error->getFile());
                $log->addExtra('line', $error->getLine());
                $log->addExtra('trace', $error->getTraceAsString());
                $log->addExtra('trace', $error->getTraceAsString());

                $log->setAction($action);

                $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';

                $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

                try {
                    $responseCode = $logger->addLog($log);
                    Console::info('Error log pushed with status code: ' . $responseCode);
                } catch (Throwable $th) {
                    Console::error('Error pushing log: ' . $th->getMessage());
                }
            }

            Console::warning("Failed: {$error->getMessage()}");
            Console::warning($error->getTraceAsString());
        };
    });

$container->set($context);
$container->set($logError);
$container->set($register);
$container->set($queueForDeletes);
$container->set($queueForFunctions);
$container->set($queueForCertificates);

$platform = new Appwrite();
$platform->init(Service::TYPE_TASK, ['adapter' => new SwooleCLI(1)]);

$cli = $platform->getCli();

$cli
    ->init()
    ->inject('authorization')
    ->action(function (Authorization $authorization) {
        $authorization->disable();
    });

$cli
    ->error()
    ->inject('error')
    ->action(function (Throwable $error) {
        Console::error($error->getMessage());
    });

$cli
    ->setContainer($container)
    ->run();
