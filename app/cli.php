<?php

require_once __DIR__ . '/init2.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Func;
use Appwrite\Platform\Appwrite;
use Swoole\Runtime;
use Utopia\CLI\Adapters\Swoole as SwooleCLI;
use Utopia\CLI\Console;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Dependency;
use Utopia\Logger\Log;
use Utopia\Platform\Service;
use Utopia\Queue\Connection;
use Utopia\Registry\Registry;
use Utopia\System\System;

global $global, $container;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

/**
 * @var Registry $global
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
    ->setCallback(function () use (&$global): Registry {
        return $global;
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

                $responseCode = $logger->addLog($log);
                Console::info('Usage stats log pushed with status code: ' . $responseCode);
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
