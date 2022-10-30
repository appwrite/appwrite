<?php

require_once __DIR__ . '/../worker.php';

use Ahc\Jwt\JWT;
use Appwrite\Utopia\Response;
use Swoole\Runtime;
use Swoole\Timer;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Structure;
use Utopia\Queue;
use Utopia\Queue\Message;

$regions = array_filter(
    Config::getParam('regions', []),
    fn ($region) => App::getEnv('_APP_REGION', 'nyc1') !== $region
        && $region !== 'default',
    ARRAY_FILTER_USE_KEY
);

$stack = [
    'regions' => $regions,
    'keys' => [],
];
$failures = [];

const CHUNK_MAX_KEYS = 2;
const MAX_CURL_SEND_ATTEMPTS = 4;

/**
 * @param string $url
 * @param string $token
 * @param array $stack
 * @return array
 */
function send(string $url, string $token, array $stack): array
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
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stack));

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

        sleep(1);
    }

    curl_close($ch);

    return $payload;
}

/**
 * @throws Authorization
 * @throws Structure
 * @throws Exception
 */
function call($regions, $stack): void
{

    global $register;

    $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
    $token = $jwt->encode([]);

    foreach ($regions as $code => $region) {
        $time = DateTime::now();
        $response = send($region['domain'] . '/v1/edge/sync', $token, ['keys' => $stack]);
        if ($response['status'] !== Response::STATUS_CODE_OK) {
            Console::error("[{$time}] Request to  {$code} has failed");
            try {
                $database = getConsoleDB();
                $database->createDocument('syncs', new Document([
                    'region' => App::getEnv('_APP_REGION', 'nyc1'),
                    'target' => $code,
                    'keys' => $stack,
                    'status' => $response['status'],
                    'payload' => $response['payload'],
                ]));
            } catch (\Throwable $th) {
                $register->get('pools')->reclaim();
            }
        }
    }
}

$connection = new Queue\Connection\Redis(App::getEnv('_APP_REDIS_HOST', 'redis'), App::getEnv('_APP_REDIS_PORT', '6379'));
$adapter    = new Queue\Adapter\Swoole($connection, 1, 'syncOut');
$server     = new Queue\Server($adapter);

$server->job()
    ->inject('message')
    ->inject('dbForConsole')
    ->action(function (Message $message) use (&$stack, &$failures) {

        $payload = $message->getPayload()['value'] ?? [];

        if (!empty($payload['keys'])) {
            $regions = array_filter(
                Config::getParam('regions', []),
                fn ($region) => $payload['region']  === $region,
                ARRAY_FILTER_USE_KEY
            );

            $failures[] = [
                'regions' => $regions,
                'keys' => $payload['keys']
            ];
        }

        if (!empty($payload['key'])) {
            if (!in_array($payload['key'], $stack['keys'] ?? [])) {
                $stack['keys'][] = $payload['key'];
            }
        }
    });

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$server
    ->error()
    ->inject('error')
    ->action(function ($error) {
        echo $error->getMessage() . PHP_EOL;
        echo $error->getLine() . PHP_EOL;
    });

$server
    ->workerStart(function () use (&$stack, &$failures) {
        Timer::tick(20000, function () use (&$stack, &$failures) {
            $time = DateTime::now();
            if (empty($stack['keys']) && count($failures) === 0) {
                Console::info("[{$time}] Stack is empty");
                return;
            }

            if (count($failures) > 0) {
                $i = 0;
                while ($i < count($failures)) {
                    $failure = array_shift($failures);
                    Console::info("[{$time}] ReSending " . count($failure['keys']) . " to " . key($failure['regions']));
                    call($failure['regions'], $failure['keys']);
                    $i++;
                }
                return;
            }

            $chunk = array_slice($stack['keys'], 0, CHUNK_MAX_KEYS);
            array_splice($stack['keys'], 0, CHUNK_MAX_KEYS);
            Console::info("[{$time}] Sending " . count($chunk) . " remains " . count($stack['keys']));
            call($stack['regions'], $chunk);
            //var_dump($stack['keys']);
            $chunk = [];
        });
        echo "Out  [" . App::getEnv('_APP_REGION', 'nyc1') . "] edge cache purging worker Started" . PHP_EOL;
    })
    ->start();
