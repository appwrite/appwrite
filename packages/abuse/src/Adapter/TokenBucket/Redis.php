<?php

namespace Utopia\Abuse\Adapter\TokenBucket;

final readonly class Redis extends RedisBase
{
    public function __construct(
        string $key,
        int $tokens,
        float $refillRate,
        private \Redis $redis,
    ) {
        parent::__construct($key, $tokens, $refillRate);
    }

    /**
     * @param  list<string>  $keys
     * @param  list<int|float>  $argv
     *
     * @throws \RedisException
     */
    #[\Override]
    protected function eval(string $script, array $keys, array $argv): mixed
    {
        return $this->redis->eval($script, [...$keys, ...$argv], \count($keys));
    }

    /**
     * @throws \RedisException
     */
    #[\Override]
    protected function delete(string ...$keys): void
    {
        $this->redis->del(...$keys);
    }

    /**
     * @return array<string, array<string, string>>
     *
     * @throws \RedisException
     */
    #[\Override]
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        return $this->logs($this->redis, $offset, $limit);
    }
}
