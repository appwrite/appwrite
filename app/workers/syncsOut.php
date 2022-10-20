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
use Utopia\Database\DateTime;
use Utopia\Queue;
use Utopia\Queue\Message;

require_once __DIR__ . '/../init.php';

static $keys;
static $counter;

const DATABASE_PROJECT = 'project';
const DATABASE_CONSOLE = 'console';
const SUBMITION_INTERVAL = 10;
const MAX_KEY_COUNT = 100;

define("CURRENT_REGION", App::getEnv('_APP_REGION', 'nyc1'));
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

    $namespace = '';
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

function send($url, $token, $keys): int
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

    for ($attempts = 0; $attempts < 5; $attempts++) {
        curl_exec($ch);
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($responseStatus === 200) {
            return $responseStatus;
        }

        sleep(2);
    }
    curl_close($ch);
    return $responseStatus;
}

$connection = new Queue\Connection\Redis('redis');
$adapter    = new Queue\Adapter\Swoole($connection, 1, 'syncOut');
$server     = new Queue\Server($adapter);

$server->job()
    ->inject('message')
    ->action(function (Message $message) use (&$keys, &$counter) {

        $payload = $message->getPayload()['value'];
        $regions = Config::getParam('regions', true);

        if (!empty($payload['region'])) {
            $tmp = $regions[$payload['region']];
            $regions = [];
            $regions[$payload['region']] = $tmp;
        }

        $currentRegion = App::getEnv('_APP_REGION', 'nyc1');
        $keys[$payload['key']] = null;

        if (count($keys) > MAX_KEY_COUNT  || ($counter + SUBMITION_INTERVAL) < time()) {

            $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
            $token = $jwt->encode([]);

            foreach ($regions as $code => $region) {
                if (CURRENT_REGION === $code) {
                    continue;
                }

                $status = send($region['domain'] . '/v1/edge', $token, ['keys' => array_keys($keys)]);

//                var_dump([
//                    'keyscount' =>  count($keys),
//                    'keys' =>  array_keys($keys),
//                    'timestamp' => date('m/d/Y H:i:s', $counter)
//                ]);

                if ($status !== Response::STATUS_CODE_OK) {
                    getDB(DATABASE_CONSOLE)->createDocument('syncs', new Document([
                        'requestedAt' => DateTime::now(),
                        'regionOrg'  => $currentRegion,
                        'regionDest' => $code,
                        'keys' => array_keys($keys),
                        'status' => $status,
                    ]));
                }
            }

            $counter = time();
            $keys = [];
            //var_dump('new time set -> ' . $counter);
            //var_dump($keys);
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
        echo "Out region [" . CURRENT_REGION . "] cache purging worker Started" . PHP_EOL;
    })
    ->start();
