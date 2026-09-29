<?php

namespace Utopia\Abuse\Adapters\SlidingWindow;

class RedisCluster extends RedisBase
{
    /**
     * @param  string  $key         Abuse key pattern, e.g. "ip:{ip}"; params are substituted via setParam()
     * @param  int  $limit          Max allowed hits per window; 0 means unlimited
     * @param  int  $windowSize     Length of the rate-limit window in seconds
     * @param  int  $ttl            Lifetime of a bucket key in seconds; must be >= $windowSize so the
     *                              previous window's bucket survives long enough to be weighted
     * @param  \RedisCluster  $redis Redis Cluster connection used for storage
     */
    public function __construct(protected string $key, protected int $limit, int $windowSize, int $ttl, protected \RedisCluster $redis)
    {
        $this->initWindow($windowSize, $ttl);
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
     * @param  string  $key
     * @return mixed
     *
     * @throws \RedisClusterException
     */
    protected function get(string $key): mixed
    {
        return $this->redis->get($key);
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
            } while ($cursor > 0 && count($matches) < $offset + $limit);
        }

        sort($matches);
        $matches = array_slice($matches, $offset, $limit);

        if (empty($matches)) {
            return [];
        }

        $values = $this->redis->mget($matches);

        return array_combine($matches, $values);
    }
}
