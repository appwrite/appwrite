<?php

require_once __DIR__ . '/../init.php';

use Ahc\Jwt\JWT;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Queue;
use Utopia\Queue\Message;
use Utopia\Queue\Server;


static $keys;
static $counter;

const SUBMITION_INTERVAL = 20;
const MAX_KEY_COUNT = 10;
const MAX_CURL_SEND_ATTEMPTS = 4;


/**
 * @param string $url
 * @param string $token
 * @param array $keys
 * @return array
 */
function send(string $url, string $token, array $keys): array
{
    $payload = [];
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
function call($database, $regions, $keys): void
{

    $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
    $token = $jwt->encode([]);

    foreach ($regions as $code => $region) {
        var_dump('Sending request to ' . $code . '...............');
        $payload = send($region['domain'] . '/v1/edge/sync', $token, ['keys' => $keys]);
        var_dump($payload);
        if ($payload['status'] !== Response::STATUS_CODE_OK) {
            $database->createDocument('syncs', new Document([
                'region' => App::getEnv('_APP_REGION', 'nyc1'),
                'target' => $code,
                'keys' => $keys,
                'status' => $payload['status'],
                'payload' => $payload['payload'],
            ]));
        }
    }
}

global $register;

$pools = $register->get('pools');
$queue = $pools
    ->get('queue')
    ->pop()
    ->getResource()
;

$connection = new Queue\Connection\Redis(fn() => $queue);
$adapter    = new Queue\Adapter\Swoole($connection, 1, 'syncOut');
$server     = new Queue\Server($adapter);

Server::setResource('dbForConsole', function (Cache $cache) use ($register) {

    $pools = $register->get('pools');
    $dbAdapter = $pools
        ->get('console')
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, $cache);
    $database->setNamespace('console');

    return $database;
}, ['cache']);

Server::setResource('cache', function () use ($register) {

    $pools = $register->get('pools');
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
});

$server->job()
    ->inject('message')
    ->inject('dbForConsole')
    ->action(function (Message $message, Database $dbForConsole) use (&$keys, &$counter) {

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
            call($dbForConsole, $regions, $payload['chunk']);
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
            call($dbForConsole, $regions, array_keys($keys));
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
