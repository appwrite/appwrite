<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Process;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Audit\Audit;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Adapter\Swoole\Request as SwooleRequest;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Http;
use Utopia\Pools\Group;

$payloadSize = 6 * (1024 * 1024); // 6MB
$workerNumber = swoole_cpu_num() * intval(Http::getEnv('_APP_WORKER_PER_CORE', 6));

include __DIR__ . '/controllers/general.php';

$http = new Http(new Server('0.0.0.0', Http::getEnv('PORT', 80), [
    'open_http2_protocol' => true,
    'http_compression' => true,
    'http_compression_level' => 6,
    'package_max_length' => $payloadSize,
    'buffer_output_size' => $payloadSize,
]), 'UTC');

$http->setRequestClass(Request::class);
$http->setResponseClass(Response::class);

$http->loadFiles(__DIR__ . '/../console');

go(function () use ($register, $http, $payloadSize) {
    $pools = $register->get('pools');
    /** @var Group $pools */
    Http::setResource('pools', fn () => $pools);
    $auth = new Authorization();

    // wait for database to be ready
    $attempts = 0;
    $max = 10;
    $sleep = 1;

    do {
        try {
            $attempts++;
            $dbForConsole = $http->getResource('dbForConsole');
            $dbForConsole->ping();
            /** @var Utopia\Database\Database $dbForConsole */
            break; // leave the do-while if successful
        } catch (\Throwable $e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= $max) {
                throw new \Exception('Failed to connect to database: ' . $e->getMessage());
            }
            sleep($sleep);
        }
    } while ($attempts < $max);

    Console::success('[Setup] - Server database init started...');

    try {
        Console::success('[Setup] - Creating database: appwrite...');
        $dbForConsole->create();
    } catch (\Throwable $e) {
        Console::success('[Setup] - Skip: metadata table already exists');
    }

    if ($dbForConsole->getCollection(Audit::COLLECTION)->isEmpty()) {
        $audit = new Audit($dbForConsole, $auth);
        $audit->setup();
    }

    if ($dbForConsole->getCollection(TimeLimit::COLLECTION)->isEmpty()) {
        $adapter = new TimeLimit("", 0, 1, $dbForConsole, $auth);
        $adapter->setup();
    }

    /** @var array $collections */
    $collections = Config::getParam('collections', []);
    $consoleCollections = $collections['console'];
    foreach ($consoleCollections as $key => $collection) {
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

    if ($dbForConsole->getDocument('buckets', 'default')->isEmpty() && !$dbForConsole->exists($dbForConsole->getDatabase(), 'bucket_1')) {
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
        $files = $collections['buckets']['files'] ?? [];
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

    $pools->reclaim();

    Console::success('[Setup] - Server database init completed...');

    Console::success('Server started successfully (max payload is ' . number_format($payloadSize) . ' bytes)');

    // listen ctrl + c
    Process::signal(2, function () use ($http) {
        Console::log('Stop by Ctrl+C');
        $http->shutdown();
    });

    Http::init()
        ->inject('auth')
        ->action(function (Authorization $auth) {
            $auth->cleanRoles();
            $auth->addRole(Role::any()->toString());
        });

    $http->start();
});

