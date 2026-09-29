<?php

namespace Utopia\Abuse\Adapters\TokenBucket;

class Redis extends RedisBase
{
    /**
     * @param  string  $key         Abuse key pattern, e.g. "ip:{ip}"; params are substituted via setParam()
     * @param  int  $tokens         Bucket capacity (max tokens); 0 means unlimited
     * @param  float  $refillRate    Tokens refilled per second
     * @param  \Redis  $redis       Redis connection used for storage
     */
    public function __construct(protected string $key, protected int $tokens, float $refillRate, protected \Redis $redis)
    {
        $this->initBucket($refillRate);
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
            $logs[$key] = $this->redis->hGetAll($key);
        }

        return $logs;
    }
}
