<?php

namespace Utopia\Abuse\Challenge\Nonce;

use Utopia\Abuse\Challenge\Nonce;

final readonly class Redis extends Nonce
{
    /**
     * @param  array<string, string>  $params
     */
    public function __construct(string $key, private \Redis $redis, array $params = [])
    {
        parent::__construct($key, $params);
    }

    /**
     * @throws \RedisException
     */
    #[\Override]
    protected function claim(string $key, int $ttl): bool
    {
        return $this->redis->set($key, '1', ['nx', 'ex' => $ttl]) === true;
    }
}
