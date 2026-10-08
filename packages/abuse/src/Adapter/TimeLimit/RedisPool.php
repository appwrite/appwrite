<?php

namespace Utopia\Abuse\Adapter\TimeLimit;

use Utopia\Pools\Pool;

readonly class RedisPool extends RedisBase
{
    /**
     * @param  Pool<\Redis>|Pool<\RedisCluster>  $pool
     */
    public function __construct(string $key, int $limit, int $seconds, protected Pool $pool)
    {
        parent::__construct($key, $limit, $seconds);
    }

    /**
     * @param  list<string>  $keys
     * @param  list<int|string>  $argv
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

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        $offset ??= 0;
        $limit ??= 25;

        return $this->pool->use(function (\Redis|\RedisCluster $redis) use ($offset, $limit): array {
            if ($redis instanceof \RedisCluster) {
                return $this->clusterLogs($redis, $offset, $limit);
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

            $logs = [];
            foreach ($matches as $key) {
                $logs[$key] = $redis->get($key);
            }

            return $logs;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function clusterLogs(\RedisCluster $redis, int $offset, int $limit): array
    {
        $matches = [];
        $pattern = self::NAMESPACE . '__*';

        foreach ($redis->_masters() as $master) {
            if (!\is_string($master) && !\is_array($master)) {
                continue;
            }

            $cursor = null;
            do {
                $keys = $redis->scan($cursor, $master, $pattern, 100);
                if (\is_array($keys)) {
                    \array_push($matches, ...\array_filter($keys, \is_string(...)));
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
