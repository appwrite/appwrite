<?php

namespace Utopia\Abuse\Adapter\TimeLimit;

final readonly class RedisCluster extends RedisBase
{
    public function __construct(string $key, int $limit, int $seconds, private \RedisCluster $redis)
    {
        parent::__construct($key, $limit, $seconds);
    }

    /**
     * @param  list<string>  $keys
     * @param  list<int|string>  $argv
     *
     * @throws \RedisClusterException
     */
    #[\Override]
    protected function eval(string $script, array $keys, array $argv): mixed
    {
        return $this->redis->eval($script, [...$keys, ...$argv], \count($keys));
    }

    /**
     * @throws \RedisClusterException
     */
    #[\Override]
    protected function get(string $key): mixed
    {
        return $this->redis->get($key);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RedisClusterException
     */
    #[\Override]
    public function getLogs(?int $offset = 0, ?int $limit = 25): array
    {
        $offset = $offset ?? 0;
        $limit = $limit ?? 25;
        $matches = [];
        $pattern = self::NAMESPACE . '__*';

        foreach ($this->redis->_masters() as $master) {
            if (!\is_string($master) && !\is_array($master)) {
                continue;
            }

            $cursor = null;
            do {
                $keys = $this->redis->scan($cursor, $master, $pattern, 100);
                if (\is_array($keys)) {
                    \array_push($matches, ...\array_filter($keys, \is_string(...)));
                }
            } while ($cursor > 0 && \count($matches) < $offset + $limit);
        }

        \sort($matches);
        $matches = \array_slice($matches, $offset, $limit);

        if ($matches === []) {
            return [];
        }

        return \array_combine($matches, $this->redis->mget($matches));
    }
}
