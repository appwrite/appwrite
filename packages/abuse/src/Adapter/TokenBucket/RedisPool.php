<?php

namespace Utopia\Abuse\Adapter\TokenBucket;

use Utopia\Pools\Pool;

readonly class RedisPool extends RedisBase
{
    /**
     * @param  Pool<\Redis>|Pool<\RedisCluster>  $pool
     */
    public function __construct(
        string $key,
        int $tokens,
        float $refillRate,
        protected Pool $pool,
    ) {
        parent::__construct($key, $tokens, $refillRate);
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
    protected function delete(string ...$keys): void
    {
        $this->pool->use(function (\Redis|\RedisCluster $redis) use ($keys): void {
            $redis->del(...$keys);
        });
    }

    /**
     * @return array<string, array<string, string>>
     */
    #[\Override]
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        return $this->pool->use(fn (\Redis|\RedisCluster $redis): array => $this->logs($redis, $offset, $limit));
    }
}
