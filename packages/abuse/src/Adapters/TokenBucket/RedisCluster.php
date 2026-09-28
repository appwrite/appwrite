<?php

namespace Utopia\Abuse\Adapters\TokenBucket;

class RedisCluster extends RedisBase
{
    /**
     * @param  string  $key         Abuse key pattern, e.g. "ip:{ip}"; params are substituted via setParam()
     * @param  int  $tokens         Bucket capacity (max tokens); 0 means unlimited
     * @param  float  $refillRate    Tokens refilled per second
     * @param  \RedisCluster  $redis Redis Cluster connection used for storage
     */
    public function __construct(protected string $key, protected int $tokens, float $refillRate, protected \RedisCluster $redis)
    {
        $this->initBucket($refillRate);
    }

    /**
     * @param  string  $script
     * @param  list<string>  $keys
     * @param  list<int|float>  $argv
     * @return mixed
     *
     * @throws \RedisClusterException
     */
    protected function eval(string $script, array $keys, array $argv): mixed
    {
        return $this->redis->eval($script, [...$keys, ...$argv], \count($keys));
    }

    /**
     * @param  string  ...$keys
     * @return void
     *
     * @throws \RedisClusterException
     */
    protected function delete(string ...$keys): void
    {
        $this->redis->del(...$keys);
    }

    /**
     * Get abuse logs with cursor-based pagination across masters
     *
     * @param  int|null  $offset
     * @param  int|null  $limit
     * @return array<string, mixed>
     */
    public function getLogs(?int $offset = 0, ?int $limit = 25): array
    {
        $offset = $offset ?? 0;
        $limit = $limit ?? 25;
        $matches = [];
        $pattern = self::NAMESPACE . '__*';

        foreach ($this->redis->_masters() as $master) {
            $cursor = null;
            do {
                /** @phpstan-ignore-next-line */
                $keys = $this->redis->scan($cursor, $master, $pattern, 100);
                if ($keys !== false) {
                    $matches = array_merge($matches, $keys);
                }
            } while ($cursor > 0);
        }

        sort($matches);
        $matches = array_slice($matches, $offset, $limit);

        if (empty($matches)) {
            return [];
        }

        $logs = [];
        foreach ($matches as $key) {
            $logs[$key] = $this->redis->hGetAll($key);
        }

        return $logs;
    }
}
