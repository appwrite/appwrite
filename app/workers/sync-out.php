<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Ahc\Jwt\JWT;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Queue;
use Utopia\Queue\Message;

require_once __DIR__ . '/../init.php';

static $keys;
static $counter;

const DATABASE_PROJECT = 'project';
const DATABASE_CONSOLE = 'console';
const SUBMITION_INTERVAL = 20;
const MAX_KEY_COUNT = 10;
const MAX_CURL_SEND_ATTEMPTS = 4;

/**
 * Get console database
 * @param string $type One of (internal, external, console)
 * @param string $projectId of internal or external DB
 * @param string $projectInternalId
 * @return Database
 * @throws Exception
 */
function getDB(string $type, string $projectId = '', string $projectInternalId = ''): Database
{
    global $register;

    $sleep = DATABASE_RECONNECT_SLEEP; // overwritten when necessary

    switch ($type) {
        case DATABASE_PROJECT:
            if (!$projectId) {
                throw new \Exception('ProjectID not provided - cannot get database');
            }
            $namespace = "_{$projectInternalId}";
            break;
        case DATABASE_CONSOLE:
            $namespace = "_console";
            $sleep = 5; // ConsoleDB needs extra sleep time to ensure tables are created
            break;
        default:
            throw new \Exception('Unknown database type: ' . $type);
            break;
    }

    $attempts = 0;

    do {
        try {
            $attempts++;
            $cache = new Cache(new RedisCache($register->get('cache')));
            $database = new Database(new MariaDB($register->get('db')), $cache);
            $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
            $database->setNamespace($namespace); // Main DB

            if (!empty($projectId) && !$database->getDocument('projects', $projectId)->isEmpty()) {
                throw new \Exception("Project does not exist: {$projectId}");
            }

            if ($type === DATABASE_CONSOLE && !$database->exists($database->getDefaultDatabase(), Database::METADATA)) {
                throw new \Exception('Console project not ready');
            }

            break; // leave loop if successful
        } catch (\Exception $e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                throw new \Exception('Failed to connect to database: ' . $e->getMessage());
            }
            sleep($sleep);
        }
    } while ($attempts < DATABASE_RECONNECT_MAX_ATTEMPTS);

    return $database;
}

/**
 * @param string $url
 * @param string $token
 * @param array $keys
 * @return array\
 */
function send(string $url, string $token, array $keys): array
{

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($keys));

    for ($attempts = 0; $attempts < MAX_CURL_SEND_ATTEMPTS; $attempts++) {
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $payload = [
            'status' => $status,
            'payload' => json_decode($response, true)
            ];

        if ($status === 200) {
            return $payload;
        }

        sleep(2);
    }

    curl_close($ch);

    return $payload;
}

/**
 * @throws Authorization
 * @throws Structure
 * @throws Exception
 */
function call($regions, $keys): void
{

    $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
    $token = $jwt->encode([]);

    foreach ($regions as $code => $region) {
        var_dump('Sending request to ' . $code . '...............');
        $payload = send($region['domain'] . '/v1/edge/sync', $token, ['keys' => $keys]);
        var_dump($payload);
        if ($payload['status'] !== Response::STATUS_CODE_OK) {
            getDB(DATABASE_CONSOLE)->createDocument('syncs', new Document([
                'region' => App::getEnv('_APP_REGION', 'nyc1'),
                'target' => $code,
                'keys' => $keys,
                'status' => $payload['status'],
                'payload' => $payload['payload'],
            ]));
        }
    }
}

$connection = new Queue\Connection\Redis('redis');
$adapter    = new Queue\Adapter\Swoole($connection, 1, 'syncOut');
$server     = new Queue\Server($adapter);

$server->job()
    ->inject('message')
    ->action(function (Message $message) use (&$keys, &$counter) {

        $payload = $message->getPayload()['value'];
        $regions = Config::getParam('regions', true);
        $regions = array_filter(
            $regions,
            fn ($region) => App::getEnv('_APP_REGION', 'nyc1') !== $region,
            ARRAY_FILTER_USE_KEY
        );

        if (!empty($payload['region'])) {
            $regions = array_filter(
                $regions,
                fn ($region) => $payload['region'] === $region,
                ARRAY_FILTER_USE_KEY
            );
        }

        if (!empty($payload['chunk'])) {
            var_dump('from chunk');
            call($regions, $payload['chunk']);
            return;
        }

         $keys[$payload['key']] = null;
        if (count($keys) >= MAX_KEY_COUNT  || ($counter + SUBMITION_INTERVAL) < time()) {
            var_dump('From key');
            var_dump([
                'regions' =>  array_keys($regions),
                'because_time' => ($counter + SUBMITION_INTERVAL) < time(),
                'because_count' => count($keys) >= MAX_KEY_COUNT,
                'count' => count($keys),
                'counter' => $counter + SUBMITION_INTERVAL,
                'time' => time(),
                'keys' => array_keys($keys),
            ]);
            call($regions, array_keys($keys));
            $counter = time();
            $keys = [];
        }
    });

$server
    ->error()
    ->inject('error')
    ->action(function ($error) {
        echo $error->getMessage() . PHP_EOL;
        echo $error->getLine() . PHP_EOL;
    });

$server
    ->workerStart(function () {
        echo "Out region [" . App::getEnv('_APP_REGION', 'nyc1') . "] cache purging worker Started" . PHP_EOL;
    })
    ->start();
