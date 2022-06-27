<?php

use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;
use Swoole\Timer;
use Utopia\App;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
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

function markDown(Cache $cache, string $executorId, string $error) {
    $data = $cache->load('executors-' . $executorId, 60 * 60 * 24 * 30 * 3); // 3 months

    $cache->save('executors-' . $executorId, [ 'state' => 'down' ]);

    if(!$data || $data['state'] === 'up') {
        Console::warning('Executor "' . $executorId . '" went down! Message:');
        Console::warning($error);
    }
}

function markUp(cache $cache, string $executorId) {
    $data = $cache->load('executors-' . $executorId, 60 * 60 * 24 * 30 * 3); // 3 months

    $cache->save('executors-' . $executorId, [ 'state' => 'up' ]);

    if(!$data || $data['state'] === 'down') {
        Console::success('Executor "' . $executorId . '" went online.');
    }
}

// Fetch info about executors
function fetchExecutorsState(RedisPool $redisPool)
{
    $executors = \explode(',', App::getEnv('_APP_EXECUTORS', ''));

    foreach ($executors as $executor) {
        go(function () use ($redisPool, $executor) {
            $redis = $redisPool->get();
            $cache = new Cache(new Redis($redis));

            try {
                [$id, $hostname] = \explode('=', $executor);

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

                $executorResponse = \curl_exec($ch); // TODO: Use to save usage stats
                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = \curl_error($ch);

                \curl_close($ch);

                if ($statusCode === 200) {
                    markUp($cache, $id);
                } else {
                    markDown($cache, $id, $error);
                }
            } catch (\Exception $err) {
                throw $err;
            } finally {
                $redisPool->put($redis);
            }
        });
    }
};

Timer::tick(30000, fn (int $timerId, array $params) => fetchExecutorsState($params[0]), [$redisPool]);
fetchExecutorsState($redisPool);

Console::success("Functions proxy is ready.");
