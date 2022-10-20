<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\App;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Queue;
use Utopia\Queue\Message;

require_once __DIR__ . '/../init.php';

define("CURRENT_REGION", App::getEnv('_APP_REGION', 'nyc1'));

/**
 * @return RedisCache
 */
function getCache(): RedisCache
{
    global $register;
    return new RedisCache($register->get('cache'));
}

$connection = new Queue\Connection\Redis('redis');
$adapter = new Queue\Adapter\Swoole($connection, 1, 'syncIn');
$server = new Queue\Server($adapter);

$server->job()
    ->inject('message')
    ->action(function (Message $message) use (&$keys, &$counter) {

        $payload = $message->getPayload()['value'];
        if (!empty($payload['key'])) {
            var_dump('purging ' . $payload['key']);
            getCache()->purge($payload['key']);
        }
    });

$server
    ->error()
    ->inject('error')
    ->action(function ($error) {
        echo $error->getMessage() . PHP_EOL;
    });

$server
    ->workerStart(function () {
        echo "In region [" . CURRENT_REGION . "] cache purging worker Started" . PHP_EOL;
    })
    ->start();
