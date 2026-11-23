<?php

namespace Utopia\Abuse\Challenges\Nonce;

use Utopia\Abuse\Challenges\Nonce;
use Utopia\Pools\Pool;

final readonly class RedisPool extends Nonce
{
    /**
     * @param  Pool<\Redis>|Pool<\RedisCluster>  $pool
     * @param  array<string, string>  $params
     */
    public function __construct(string $key, private Pool $pool, array $params = [])
    {
        parent::__construct($key, $params);
    }

    #[\Override]
    protected function claim(string $key, int $ttl): bool
    {
        return $this->pool->use(fn (\Redis|\RedisCluster $redis): bool => $redis->set($key, '1', ['nx', 'ex' => $ttl]) === true);
    }
}
