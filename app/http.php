<?php
ini_set('memory_limit', '512M');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('default_socket_timeout', -1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Utopia\Queue\Connections;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
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
use Utopia\DI\Container;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Http;
use Utopia\System\System;

// Unlimited memory limit to handle as many coroutines/requests as possible
ini_set('memory_limit', '-1');

$container = new Container();
$workerNumber = swoole_cpu_num() * intval(System::getEnv('_APP_WORKER_PER_CORE', 6));
$payloadSize = 6 * (1024 * 1024); // 6MB

$server = new Server('0.0.0.0', '80', [
    'open_http2_protocol' => true,
    // 'http_compression' => true,
    // 'http_compression_level' => 6,

    // Server
    // 'log_level' => 0,
    'dispatch_mode' => 2,
    'worker_num' => $workerNumber,
    'reactor_num' => swoole_cpu_num() * 2,
    // 'task_worker_num' => $workerNumber,
    'open_cpu_affinity' => true,

    // Coroutine
    'enable_coroutine' => true,
    'max_coroutine' => 10000,
]);

$http = new Http($server, $container, 'UTC');

// $http->loadFiles(__DIR__ . '/../console');
$http->setRequestClass(Request::class);
$http->setResponseClass(Response::class);

//require_once __DIR__ . '/init.php';
require_once __DIR__ . '/init/constants.php';
require_once __DIR__ . '/init/config.php';
require_once __DIR__ . '/init/locale.php';
require_once __DIR__ . '/init/database/filters.php';
require_once __DIR__ . '/init/database/formats.php';
require_once __DIR__ . '/init2.php';
require_once __DIR__ . '/controllers/general.php';

global $global;

http::onStart()
    ->inject('authorization')
    ->inject('dbForConsole')
    ->inject('connections')
    ->action(function (Authorization $authorization, Database $dbForConsole, Connections $connections) {
        // wait for database to be ready
        $attempts = 0;
        $max = 10;
        $sleep = 1;

        do {
            try {
                $attempts++;
                $dbForConsole->ping();
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
            return true;
        }

        if ($dbForConsole->getCollection(Audit::COLLECTION)->isEmpty()) {
            $audit = new Audit($dbForConsole, $authorization);
            $audit->setup();
        }

        if ($dbForConsole->getCollection(TimeLimit::COLLECTION)->isEmpty()) {
            $abuse = new TimeLimit("", 0, 1, $dbForConsole, $authorization);
            $abuse->setup();
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
                'maximumFileSize' => (int) System::getEnv('_APP_STORAGE_LIMIT', 0), // 10MB
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

        $connections->reclaim();

        Console::success('[Setup] - Server database init completed...');
        Console::success('Server started successfully');
    });

Http::init()
    ->inject('authorization')
    ->action(function (Authorization $authorization) {
        $authorization->cleanRoles();
        $authorization->addRole(Role::any()->toString());
    });

$http->start();