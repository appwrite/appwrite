<?php

namespace Utopia\Abuse\Adapters\SlidingWindow;

class Redis extends RedisBase
{
    /**
     * @param  string  $key         Abuse key pattern, e.g. "ip:{ip}"; params are substituted via setParam()
     * @param  int  $limit          Max allowed hits per window; 0 means unlimited
     * @param  int  $windowSize     Length of the rate-limit window in seconds
     * @param  int  $ttl            Lifetime of a bucket key in seconds; must be >= $windowSize so the
     *                              previous window's bucket survives long enough to be weighted
     * @param  \Redis  $redis       Redis connection used for storage
     */
    public function __construct(protected string $key, protected int $limit, int $windowSize, int $ttl, protected \Redis $redis)
    {
        $this->initWindow($windowSize, $ttl);
    }

    /**
     * @param  string  $script
     * @param  list<string>  $keys
     * @param  list<int|float>  $argv
     * @return mixed
     *
     * @throws \RedisException
     */
    protected function eval(string $script, array $keys, array $argv): mixed
    {
        return $this->redis->eval($script, [...$keys, ...$argv], \count($keys));
    }

    /**
     * @param  string  $key
     * @return mixed
     *
     * @throws \RedisException
     */
    protected function get(string $key): mixed
    {
        return $this->redis->get($key);
    }

    /**
     * @param  string  ...$keys
     * @return void
     *
     * @throws \RedisException
     */
    protected function delete(string ...$keys): void
    {
        $this->redis->del(...$keys);
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

        $cursor = null;
        $matches = [];
        $pattern = self::NAMESPACE . '__*';

        do {
            $keys = $this->redis->scan($cursor, $pattern, 100);
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
            $logs[$key] = $this->redis->get($key);
        }

        return $logs;
    }
}
