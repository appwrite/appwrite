<?php

namespace Utopia\Abuse\Adapter\SlidingWindow;

use Utopia\Pools\Pool;

readonly class RedisPool extends RedisBase
{
    /**
     * @param  Pool<\Redis>|Pool<\RedisCluster>  $pool
     */
    public function __construct(
        string $key,
        int $limit,
        int $windowSize,
        int $ttl,
        protected Pool $pool,
    ) {
        parent::__construct($key, $limit, $windowSize, $ttl);
    }

    /**
     * @param  list<string>  $keys
     * @param  list<int|float>  $argv
     */
    #[\Override]
    protected function eval(string $script, array $keys, array $argv): mixed
    {
        return $this->pool->use(fn (\Redis|\RedisCluster $redis): mixed => $redis->eval($script, [...$keys, ...$argv], \count($keys)));
    }

    #[\Override]
    protected function get(string $key): mixed
    {
        return $this->pool->use(fn (\Redis|\RedisCluster $redis): mixed => $redis->get($key));
    }

    #[\Override]
    protected function delete(string ...$keys): void
    {
        $this->pool->use(function (\Redis|\RedisCluster $redis) use ($keys): void {
            $redis->del(...$keys);
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        $offset ??= 0;
        $limit ??= 25;

        return $this->pool->use(fn (\Redis|\RedisCluster $redis): array => $redis instanceof \RedisCluster
            ? $this->clusterLogs($redis, $offset, $limit)
            : $this->logs($redis, $offset, $limit));
    }

    /**
     * @return array<string, mixed>
     */
    private function logs(\Redis $redis, int $offset, int $limit): array
    {
        $cursor = null;
        $matches = [];

        do {
            $keys = $redis->scan($cursor, self::NAMESPACE . '__*', 100);
            if (\is_array($keys)) {
                \array_push($matches, ...$keys);
            }
        } while ($cursor > 0);

        \sort($matches);
        $matches = \array_slice($matches, $offset, $limit);

        $logs = [];
        foreach ($matches as $key) {
            $logs[$key] = $redis->get($key);
        }

        return $logs;
    }

    /**
     * @return array<string, mixed>
     */
    private function clusterLogs(\RedisCluster $redis, int $offset, int $limit): array
    {
        $matches = [];

        foreach ($redis->_masters() as $master) {
            if (!\is_string($master) && !\is_array($master)) {
                continue;
            }

            $cursor = null;
            do {
                $keys = $redis->scan($cursor, $master, self::NAMESPACE . '__*', 100);
                if (\is_array($keys)) {
                    \array_push($matches, ...\array_values(\array_filter($keys, \is_string(...))));
                }
            } while ($cursor > 0);
        }

        \sort($matches);
        $matches = \array_slice($matches, $offset, $limit);

        if ($matches === []) {
            return [];
        }

        return \array_combine($matches, $redis->mget($matches));
    }
}
