<?php

namespace FunctionsProxy;

use Exception;
use Swoole\Database\RedisPool;
use Utopia\App;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;

abstract class Adapter
{
    private RedisPool $redisPool;

    public function __construct(RedisPool $redisPool)
    {
        $this->redisPool = $redisPool;
    }

    private function getConnection(): array
    {
        $redis = $this->redisPool->get();
        $cache = new Cache(new Redis($redis));

        return [$cache, fn () => $this->redisPool->put($redis)];
    }

    protected function getExecutors(): array
    {
        [$cache, $returnCache] = $this->getConnection();

        $responseExecutors = [];

        try {
            $executors = \explode(',', App::getEnv('_APP_EXECUTORS', ''));

            foreach ($executors as $executor) {
                [$id, $hostname] = \explode('=', $executor);

                $data = $cache->load('executors-' . $id, 60 * 60 * 24 * 30 * 3); // 3 months

                if ($data === false || $data['status'] !== 'online') {
                    continue;
                }

                $responseExecutors[] = [
                    'id' => $id,
                    'hostname' => $hostname,
                    'state' => $data
                ];
            }
        } finally {
            call_user_func($returnCache);
        }

        if (\count($responseExecutors) <= 0) {
            throw new Exception("No executor is online.");
        }

        return $responseExecutors;
    }

    abstract public function getNextExecutor(?string $contaierId): array;
}
