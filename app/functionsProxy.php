<?php

use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;
use Swoole\Timer;
use Utopia\App;
use Utopia\CLI\Console;

// Redis setup
$redisHost = App::getEnv('_APP_REDIS_HOST', '');
$redisPort = App::getEnv('_APP_REDIS_PORT', '');
$redisUser = App::getEnv('_APP_REDIS_USER', '');
$redisPass = App::getEnv('_APP_REDIS_PASS', '');
$redisAuth = '';

if ($redisUser && $redisPass) {
    $redisAuth = $redisUser . ':' . $redisPass;
}

$redisPool = new RedisPool(
    (new RedisConfig())
    ->withHost($redisHost)
    ->withPort($redisPort)
    ->withAuth($redisAuth)
    ->withDbIndex(0),
    64
);

// Fetch info about executors
function fetchExecutorsState(?int $timerId, array $params) {
    /** @var RedisPool $redisPool */
    $redisPool = $params[0];

    $executors = \explode(',', App::getEnv('_APP_EXECUTORS', ''));

    foreach ($executors as $executor) {
        go(function () use ($redisPool, $executor) {
            $redis = $redisPool->get();

            try {
                [ $id, $hostname ] = \explode('=', $executor);

                $endpoint = $hostname . '/health';
        
                $ch = \curl_init();
        
                \curl_setopt($ch, CURLOPT_URL, $endpoint);
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'x-appwrite-executor-key: ' . App::getEnv('_APP_EXECUTOR_SECRET', '')
                ]);
        
                $executorResponse = \curl_exec($ch);
                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = \curl_error($ch);
        
                \curl_close($ch);
        
                if($statusCode === 200) {
                    // TODO: Upsert, log if it was down
                    Console::success('Executor "' . $id . '" went online.');
                } else {
                    \var_dump($redis);
                    // TODO: Mark asdown, log if it was up
                    Console::warning('Executor "' . $id . '" went down! Message:');
                    Console::warning($error);
                }
            } catch(\Exception $err) {
                throw $err;
            } finally {
                $redisPool->put($redis);
            }
        });
    }
};

Timer::tick(5000, "fetchExecutorsState", [ $redisPool ]);
fetchExecutorsState(null, [ $redisPool ]);

Console::success("Functions proxy is ready.");