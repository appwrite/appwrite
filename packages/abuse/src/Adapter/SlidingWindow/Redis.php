<?php

namespace Utopia\Abuse\Adapter\SlidingWindow;

final readonly class Redis extends RedisBase
{
    public function __construct(
        string $key,
        int $limit,
        int $windowSize,
        int $ttl,
        private \Redis $redis,
    ) {
        parent::__construct($key, $limit, $windowSize, $ttl);
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
    protected function get(string $key): mixed
    {
        return $this->redis->get($key);
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
     * @return array<string, mixed>
     *
     * @throws \RedisException
     */
    #[\Override]
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        $cursor = null;
        $matches = [];

        do {
            $keys = $this->redis->scan($cursor, self::NAMESPACE . '__*', 100);
            if (\is_array($keys)) {
                \array_push($matches, ...$keys);
            }
        } while ($cursor > 0);

        \sort($matches);
        $matches = \array_slice($matches, $offset ?? 0, $limit ?? 25);

        $logs = [];
        foreach ($matches as $key) {
            $logs[$key] = $this->redis->get($key);
        }

        return $logs;
    }
}
