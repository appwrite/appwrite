<?php

namespace Utopia\Abuse\Adapter\TimeLimit;

final readonly class Redis extends RedisBase
{
    public function __construct(string $key, int $limit, int $seconds, private \Redis $redis)
    {
        parent::__construct($key, $limit, $seconds);
    }

    /**
     * @param  list<string>  $keys
     * @param  list<int|string>  $argv
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
     * @return array<string, mixed>
     *
     * @throws \RedisException
     */
    #[\Override]
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        $cursor = null;

        $keys = $this->redis->scan($cursor, self::NAMESPACE . '__*', $limit ?? 0);
        if (!$keys) {
            return [];
        }

        $logs = [];
        foreach ($keys as $key) {
            $logs[$key] = $this->redis->get($key);
        }

        return $logs;
    }
}
