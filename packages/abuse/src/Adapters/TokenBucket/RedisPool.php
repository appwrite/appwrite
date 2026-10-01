<?php

namespace Utopia\Abuse\Adapters\TokenBucket;

use Utopia\Pools\Pool as UtopiaPool;

class RedisPool extends RedisBase
{
    /**
     * @param  string  $key         Abuse key pattern, e.g. "ip:{ip}"; params are substituted via setParam()
     * @param  int  $tokens         Bucket capacity (max tokens); 0 means unlimited
     * @param  float  $refillRate    Tokens refilled per second
     * @param  UtopiaPool<\Redis>|UtopiaPool<\RedisCluster>  $pool  Pool yielding a Redis or RedisCluster connection
     */
    public function __construct(
        protected string $key,
        protected int $tokens,
        float $refillRate,
        protected UtopiaPool $pool
    ) {
        $this->initBucket($refillRate);
    }

    /**
     * @param  string  $script
     * @param  list<string>  $keys
     * @param  list<int|float>  $argv
     * @return mixed
     */
    protected function eval(string $script, array $keys, array $argv): mixed
    {
        return $this->pool->use(fn (\Redis|\RedisCluster $redis): mixed => $redis->eval($script, [...$keys, ...$argv], \count($keys)));
    }

    /**
     * @param  string  ...$keys
     * @return void
     */
    protected function delete(string ...$keys): void
    {
        $this->pool->use(function (\Redis|\RedisCluster $redis) use ($keys): void {
            $redis->del(...$keys);
        });
    }

    /**
     * Get abuse logs
     *
     * Return logs with an offset and limit
     *
     * @param  int|null  $offset
     * @param  int|null  $limit
     * @return array<string, mixed>
     */
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        $offset = $offset ?? 0;
        $limit = $limit ?? 25;

        /** @var array<string, mixed> $result */
        $result = $this->pool->use(function (\Redis|\RedisCluster $redis) use ($offset, $limit): array {
            if ($redis instanceof \RedisCluster) {
                return $this->getRedisClusterLogs($redis, $offset, $limit);
            }

            $cursor = null;
            $matches = [];
            $pattern = self::NAMESPACE . '__*';

            do {
                $keys = $redis->scan($cursor, $pattern, 100);
                if ($keys !== false) {
                    \array_push($matches, ...$keys);
                }
            } while ($cursor > 0);

            \sort($matches);
            $matches = \array_slice($matches, $offset, $limit);

            if (empty($matches)) {
                return [];
            }

            $logs = [];
            foreach ($matches as $key) {
                $logs[$key] = $redis->hGetAll($key);
            }

            return $logs;
        });

        return $result;
    }

    /**
     * @param  \RedisCluster  $redis
     * @param  int  $offset
     * @param  int  $limit
     * @return array<string, mixed>
     */
    private function getRedisClusterLogs(\RedisCluster $redis, int $offset, int $limit): array
    {
        $matches = [];
        $pattern = self::NAMESPACE . '__*';

        foreach ($redis->_masters() as $master) {
            $cursor = null;
            do {
                /** @phpstan-ignore-next-line */
                $keys = $redis->scan($cursor, $master, $pattern, 100);
                if ($keys !== false) {
                    \array_push($matches, ...$keys);
                }
            } while ($cursor > 0);
        }

        \sort($matches);
        $matches = \array_slice($matches, $offset, $limit);

        if (empty($matches)) {
            return [];
        }

        $logs = [];
        foreach ($matches as $key) {
            $logs[$key] = $redis->hGetAll($key);
        }

        return $logs;
    }
}
