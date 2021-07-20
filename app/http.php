<?php

require_once __DIR__.'/../vendor/autoload.php';

use Appwrite\Database\Validator\Authorization;
use Appwrite\Utopia\Response;
use Swoole\Process;
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Validator\Authorization as Authorization2;
use Utopia\Audit\Audit;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Database\Document;
use Utopia\Swoole\Files;
use Utopia\Swoole\Request;

$http = new Server("0.0.0.0", App::getEnv('PORT', 80));

$payloadSize = 5 * (1024 * 1024); // 5MB

$http
    ->set([
        'open_http2_protocol' => true,
        // 'document_root' => __DIR__.'/../public',
        // 'enable_static_handler' => true,
        'http_compression' => true,
        'http_compression_level' => 6,
        'package_max_length' => $payloadSize,
        'buffer_output_size' => $payloadSize,
    ])
;

$http->on('WorkerStart', function($serv, $workerId) {
    Console::success('Worker '.++$workerId.' started succefully');
});

$http->on('BeforeReload', function($serv, $workerId) {
    Console::success('Starting reload...');
});

$http->on('AfterReload', function($serv, $workerId) {
    Console::success('Reload completed...');
});

Files::load(__DIR__ . '/../public');

include __DIR__ . '/controllers/general.php';

$http->on('start', function (Server $http) use ($payloadSize, $register) {
    $app = new App('UTC');

    go(function() use ($register, $app) {
        $db = $register->get('dbPool')->get();
        $redis = $register->get('redisPool')->get();

        App::setResource('db', function () use (&$db) {
            return $db;
        });

        App::setResource('cache', function () use (&$redis) {
            return $redis;
        });

        App::setResource('app', function() use (&$app) {
            return $app;
        });

        // wait for database to be ready
        sleep(5);

        $dbForConsole = $app->getResource('dbForConsole'); /** @var Utopia\Database\Database $dbForConsole */

        if(!$dbForConsole->exists()) {
            Console::success('[Setup] - Server database init started...');

            $collections = Config::getParam('collections2', []); /** @var array $collections */

            $redis->flushAll();

            $dbForConsole->create();

            $audit = new Audit($dbForConsole);
            $audit->setup();

            $adapter = new TimeLimit("", 0, 1, $dbForConsole);
            $adapter->setup();

            foreach ($collections as $key => $collection) {
                Console::success('[Setup] - Creating collection: ' . $collection['$id'] . '...');

                $attributes = [];
                $indexes = [];

                foreach ($collection['attributes'] as $attribute) {
                    $attributes[] = new Document([
                        '$id' => $attribute['$id'],
                        'type' => $attribute['type'],
                        'size' => $attribute['size'],
                        'required' => $attribute['required'],
                        'signed' => $attribute['signed'],
                        'array' => $attribute['array'],
                        'filters' => $attribute['filters'],
                    ]);
                }

                foreach ($collection['indexes'] as $index) {
                    $indexes[] = new Document([
                        '$id' => $index['$id'],
                        'type' => $index['type'],
                        'attributes' => $index['attributes'],
                        'lengths' => $index['lengths'],
                        'orders' => $index['orders'],
                    ]);
                }

                $dbForConsole->createCollection($key, $attributes, $indexes);
                
            }

            Console::success('[Setup] - Server database init completed...');
        }
    });

    Console::success('Server started succefully (max payload is '.number_format($payloadSize).' bytes)');

    Console::info("Master pid {$http->master_pid}, manager pid {$http->manager_pid}");

    // listen ctrl + c
    Process::signal(2, function () use ($http) {
        Console::log('Stop by Ctrl+C');
        $http->shutdown();
    });
});

$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) use ($register) {
    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);

    if(Files::isFileLoaded($request->getURI())) {
        $time = (60 * 60 * 24 * 365 * 2); // 45 days cache

        $response
            ->setContentType(Files::getFileMimeType($request->getURI()))
            ->addHeader('Cache-Control', 'public, max-age='.$time)
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time).' GMT') // 45 days cache
            ->send(Files::getFileContents($request->getURI()))
        ;

        return;
    }

    $app = new App('UTC');

    $db = $register->get('dbPool')->get();
    $redis = $register->get('redisPool')->get();

    App::setResource('db', function () use (&$db) {
        return $db;
    });

    App::setResource('cache', function () use (&$redis) {
        return $redis;
    });
    
    try {
        Authorization::cleanRoles();
        Authorization::setRole('role:all');

        Authorization2::cleanRoles();
        Authorization2::setRole('role:all');

        $app->run($request, $response);
    } catch (\Throwable $th) {
        Console::error('[Error] Type: '.get_class($th));
        Console::error('[Error] Message: '.$th->getMessage());
        Console::error('[Error] File: '.$th->getFile());
        Console::error('[Error] Line: '.$th->getLine());

        /**
         * Reset Database connection if PDOException was thrown.
         */
        if ($th instanceof PDOException) {
            $db = null;
        }

        if(App::isDevelopment()) {
            $swooleResponse->end('error: '.$th->getMessage());
        }
        else {
            $swooleResponse->end('500: Server Error');
        }
    } finally {
        /** @var PDOPool $dbPool */
        $dbPool = $register->get('dbPool');
        $dbPool->put($db);

        /** @var RedisPool $redisPool */
        $redisPool = $register->get('redisPool');
        $redisPool->put($redis);
    }
});

$http->start();