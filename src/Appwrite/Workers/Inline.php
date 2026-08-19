<?php

declare(strict_types=1);

namespace Appwrite\Workers;

use Appwrite\Certificates\LetsEncrypt;
use Appwrite\Platform\Appwrite;
use Throwable;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Platform\Service;
use Utopia\Queue\Adapter\Inline as InlineAdapter;
use Utopia\Queue\Server;
use Utopia\Span\Span;
use Utopia\System\System;

/**
 * Boots every worker job on an {@see InlineAdapter} so enqueue() runs the
 * handler in this process. Used when `_APP_QUEUE_ADAPTER=inline` — HTTP and
 * CLI publish without a worker container.
 */
final class Inline
{
    private static ?InlineAdapter $adapter = null;

    /**
     * @param callable(string, mixed=): mixed $env
     */
    public static function enabled(?callable $env = null): bool
    {
        $env ??= System::getEnv(...);

        return $env('_APP_QUEUE_ADAPTER', 'redis') === 'inline';
    }

    public static function publisher(Container $container): InlineAdapter
    {
        if (self::$adapter instanceof InlineAdapter) {
            return self::$adapter;
        }

        $adapter = new InlineAdapter(resources: $container);
        self::$adapter = $adapter;
        self::boot($container, $adapter);

        return $adapter;
    }

    private static function boot(Container $container, InlineAdapter $adapter): void
    {
        if (!$container->has('certificates')) {
            $container->set('certificates', function () {
                $email = System::getEnv('_APP_EMAIL_CERTIFICATES', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS'));
                if (empty($email)) {
                    throw new \Exception('You must set a valid security email address (_APP_EMAIL_CERTIFICATES) to issue a LetsEncrypt SSL certificate.');
                }

                return new LetsEncrypt($email);
            }, []);
        }

        $registerWorkerMessageResources = require __DIR__ . '/../../../app/init/worker/message.php';
        $worker = new Server($adapter);

        $worker->init()->action(function () use ($worker, $registerWorkerMessageResources, $container) {
            $registerWorkerMessageResources($worker->context());
            $message = $worker->context()->get('message');
            if ($message instanceof \Utopia\Queue\Message) {
                Span::add('worker.queue', $message->getQueue());
            }

            $worker->context()->set('bus', function () use ($container, $worker) {
                $bus = clone $container->get('bus');

                return $bus->setResolver(
                    fn (string $name) => $worker->context()->get($name)
                );
            });
        });

        $jobs = Jobs::resolve(
            \array_keys(Config::getParam('workers', [])),
            Config::getParam('workers', []),
            System::getEnv(...),
        );

        $platform = new Appwrite();
        $platform->setWorker($worker);
        $platform->init(Service::TYPE_WORKER, [
            'workerName' => 'all',
            'workers' => ['all'],
            'jobs' => $jobs,
        ]);

        $worker
            ->error()
            ->inject('error')
            ->inject('logger')
            ->inject('log')
            ->inject('project')
            ->inject('authorization')
            ->action(function (Throwable $error, ?Logger $logger, Log $log, Document $project, Authorization $authorization) {
                $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

                if ($logger) {
                    $log->setNamespace('appwrite-worker');
                    $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
                    $log->setVersion($version);
                    $log->setType(Log::TYPE_ERROR);
                    $log->setMessage($error->getMessage());
                    $log->setAction('appwrite-queue-inline');
                    $log->addTag('verboseType', get_class($error));
                    $log->addTag('code', $error->getCode());
                    $log->addTag('projectId', $project->getId());
                    $log->addExtra('file', $error->getFile());
                    $log->addExtra('line', $error->getLine());
                    $log->addExtra('trace', $error->getTraceAsString());
                    $log->addExtra('roles', $authorization->getRoles());

                    $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
                    $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

                    try {
                        $logger->addLog($log);
                    } catch (Throwable $th) {
                        Console::error('Error pushing log: ' . $th->getMessage());
                    }
                }

                Console::error('[Inline] Type: ' . get_class($error));
                Console::error('[Inline] Message: ' . $error->getMessage());
                Console::error('[Inline] File: ' . $error->getFile());
                Console::error('[Inline] Line: ' . $error->getLine());
            });

        $worker->start();
    }
}
