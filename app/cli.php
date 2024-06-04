<?php

require_once __DIR__ . '/init2.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Hamster;
use Appwrite\Platform\Appwrite;
use Utopia\CLI\Console;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Dependency;
use Utopia\Logger\Log;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Connection;
use Utopia\Registry\Registry;
use Utopia\System\System;
use Swoole\Runtime;
use Utopia\CLI\Adapters\Swoole as SwooleCLI;

global $global, $container;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

/**
 * @var Registry $global
 * @var Container $container
 */
$auth = new Dependency();
$logError = new Dependency();
$queueForDeletes = new Dependency();
$queueForHamster = new Dependency();
$queueForCertificates = new Dependency();

$queueForHamster
    ->setName('queueForHamster')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Hamster($queue);
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
                $log->addExtra('detailedTrace', $error->getTrace());

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


$auth
    ->setName('auth')
    ->setCallback(fn() => new Authorization());

$container->set($auth);
$container->set($logError);
$container->set($queueForHamster);
$container->set($queueForDeletes);
$container->set($queueForCertificates);

$platform = new Appwrite();
$platform->init(Service::TYPE_CLI, ['adapter' => new SwooleCLI(1)]);

$cli = $platform->getCli();

$cli
    ->init()
    ->inject('auth')
    ->action(function (Authorization $auth) {
        $auth->disable();
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
