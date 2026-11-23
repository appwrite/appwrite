<?php

namespace Utopia\Abuse\Challenges\Nonce;

use Utopia\Abuse\Challenges\Nonce;

final readonly class RedisCluster extends Nonce
{
    /**
     * @param  array<string, string>  $params
     */
    public function __construct(string $key, private \RedisCluster $redis, array $params = [])
    {
        parent::__construct($key, $params);
    }

    /**
     * @throws \RedisClusterException
     */
    #[\Override]
    protected function claim(string $key, int $ttl): bool
    {
        return $this->redis->set($key, '1', ['nx', 'ex' => $ttl]) === true;
    }
}
