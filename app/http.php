<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Process;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Http;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Audit\Audit;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Database\Database;
use Utopia\Database\Document;

$server = new Server("0.0.0.0", Http::getEnv('PORT', 80));

$payloadSize = 6 * (1024 * 1024); // 6MB
$workerNumber = swoole_cpu_num() * intval(Http::getEnv('_APP_WORKER_PER_CORE', 6));

$server
    ->setConfig([
        'worker_num' => $workerNumber,
        'open_http2_protocol' => true,
        // 'document_root' => __DIR__.'/../public',
        // 'enable_static_handler' => true,
        'http_compression' => true,
        'http_compression_level' => 6,
        'package_max_length' => $payloadSize,
        'buffer_output_size' => $payloadSize,
    ]);


$server->onBeforeReload(function ($server, $workerId) {
    Console::success('Starting reload...');
});

$server->onAfterReload(function ($server, $workerId) {
    Console::success('Reload completed...');
});
    
Http::onWorkerStart()
    ->inject('workerId')
    ->action(function($workerId) {
    Console::success('Worker ' . ++$workerId . ' started successfully');
});


include __DIR__ . '/controllers/general.php';

Http::onStart()
    ->inject('register')
    ->inject('utopia')
    ->inject('server')
    ->action(function($register, $app,  $http) use ($payloadSize) {

    go(function () use ($register, $app) {
        // wait for database to be ready
        $attempts = 0;
        $max = 10;
        $sleep = 1;

        do {
            try {
                $attempts++;
                $db = $register->get('dbPool')->get();
                $redis = $register->get('redisPool')->get();
                break; // leave the do-while if successful
            } catch (\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('Failed to connect to database: ' . $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < $max);

        Http::setResource('db', fn () => $db);
        Http::setResource('cache', fn () => $redis);

        /** @var Utopia\Database\Database $dbForConsole */
        $dbForConsole = $app->getResource('dbForConsole');

        Console::success('[Setup] - Server database init started...');

        /** @var array $collections */
        $collections = Config::getParam('collections', []);

        try {
            Console::success('[Setup] - Creating database: appwrite...');
            $dbForConsole->create();
        } catch (\Exception $e) {
            Console::success('[Setup] - Skip: metadata table already exists');
        }

        if ($dbForConsole->getCollection(Audit::COLLECTION)->isEmpty()) {
            $audit = new Audit($dbForConsole);
            $audit->setup();
        }

        if ($dbForConsole->getCollection(TimeLimit::COLLECTION)->isEmpty()) {
            $adapter = new TimeLimit("", 0, 1, $dbForConsole);
            $adapter->setup();
        }

        foreach ($collections as $key => $collection) {
            if (($collection['$collection'] ?? '') !== Database::METADATA) {
                continue;
            }
            if (!$dbForConsole->getCollection($key)->isEmpty()) {
                continue;
            }

            Console::success('[Setup] - Creating collection: ' . $collection['$id'] . '...');

            $attributes = [];
            $indexes = [];

            foreach ($collection['attributes'] as $attribute) {
                $attributes[] = new Document([
                    '$id' => ID::custom($attribute['$id']),
                    'type' => $attribute['type'],
                    'size' => $attribute['size'],
                    'required' => $attribute['required'],
                    'signed' => $attribute['signed'],
                    'array' => $attribute['array'],
                    'filters' => $attribute['filters'],
                    'default' => $attribute['default'] ?? null,
                    'format' => $attribute['format'] ?? ''
                ]);
            }

            foreach ($collection['indexes'] as $index) {
                $indexes[] = new Document([
                    '$id' => ID::custom($index['$id']),
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]);
            }

            $dbForConsole->createCollection($key, $attributes, $indexes);
        }

        if ($dbForConsole->getDocument('buckets', 'default')->isEmpty() && !$dbForConsole->exists(Http::getEnv('_APP_DB_SCHEMA', 'appwrite'), 'bucket_1')) {
            Console::success('[Setup] - Creating default bucket...');
            $dbForConsole->createDocument('buckets', new Document([
                '$id' => ID::custom('default'),
                '$collection' => ID::custom('buckets'),
                'name' => 'Default',
                'maximumFileSize' => (int) Http::getEnv('_APP_STORAGE_LIMIT', 0), // 10MB
                'allowedFileExtensions' => [],
                'enabled' => true,
                'compression' => 'gzip',
                'encryption' => true,
                'antivirus' => true,
                'fileSecurity' => true,
                '$permissions' => [
                    Permission::create(Role::any()),
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'search' => 'buckets Default',
            ]));

            $bucket = $dbForConsole->getDocument('buckets', 'default');

            Console::success('[Setup] - Creating files collection for default bucket...');
            $files = $collections['files'] ?? [];
            if (empty($files)) {
                throw new Exception('Files collection is not configured.');
            }

            $attributes = [];
            $indexes = [];

            foreach ($files['attributes'] as $attribute) {
                $attributes[] = new Document([
                    '$id' => ID::custom($attribute['$id']),
                    'type' => $attribute['type'],
                    'size' => $attribute['size'],
                    'required' => $attribute['required'],
                    'signed' => $attribute['signed'],
                    'array' => $attribute['array'],
                    'filters' => $attribute['filters'],
                    'default' => $attribute['default'] ?? null,
                    'format' => $attribute['format'] ?? ''
                ]);
            }

            foreach ($files['indexes'] as $index) {
                $indexes[] = new Document([
                    '$id' => ID::custom($index['$id']),
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]);
            }

            $dbForConsole->createCollection('bucket_' . $bucket->getInternalId(), $attributes, $indexes);
        }

        Console::success('[Setup] - Server database init completed...');
    });

    Console::success('Server started successfully (max payload is ' . number_format($payloadSize) . ' bytes)');
    Console::info("Master pid {$http->master_pid}, manager pid {$http->manager_pid}");

    // listen ctrl + c
    Process::signal(2, function () use ($http) {
        Console::log('Stop by Ctrl+C');
        $http->shutdown();
    });
});

Http::onRequest()
    ->inject('register')
    ->inject('request')
    ->inject('response')
    ->action(function ($register, $request, $response,) {
        var_dump($request->getURI());
        $request = new Request($request);
        $response = new Response($response);
        Http::setResource('request', fn()=>$request);
        Http::setResource('response', fn()=>$response);

        var_dump($request->getURI());
        
        $db = $register->get('dbPool')->get();
        $redis = $register->get('redisPool')->get();

        Http::setResource('db', fn () => $db);
        Http::setResource('cache', fn () => $redis);
        Authorization::cleanRoles();
        Authorization::setRole(Role::any()->toString());
    });

$app = new Http($server, 'UTC');
$app->loadFiles(__DIR__ . '/../console');

$app->start();
