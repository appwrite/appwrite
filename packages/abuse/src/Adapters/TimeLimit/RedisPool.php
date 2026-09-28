<?php

namespace Utopia\Abuse\Adapters\TimeLimit;

use RuntimeException;
use Throwable;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Pools\Pool as UtopiaPool;

class RedisPool extends TimeLimit
{
    /**
     * @var int
     */
    protected int $ttl;

    /**
     * @param UtopiaPool<\Redis>|UtopiaPool<\RedisCluster> $pool
     */
    public function __construct(
        string $key,
        int $limit,
        int $seconds,
        protected UtopiaPool $pool
    ) {
        $this->key = $key;
        $this->ttl = $seconds;
        $now = \time();
        $this->timestamp = (int) ($now - ($now % $seconds));
        $this->limit = $limit;
    }

    protected function count(string $key, int $timestamp): int
    {
        if (0 == $this->limit) {
            return 0;
        }

        if (!\is_null($this->count)) {
            return $this->count;
        }

        /** @var int $count */
        $count = $this->pool->use(function (\Redis|\RedisCluster $redis) use ($key, $timestamp): int {
            $count = $redis->get(Redis::NAMESPACE . '__' . $key . '__' . $timestamp);

            return \is_numeric($count) ? (int) $count : 0;
        });

        $this->count = $count;

        return $this->count;
    }

    protected function hit(string $key, int $timestamp): void
    {
        if (0 == $this->limit) {
            return;
        }

        $ttl = $this->ttl;
        $key = Redis::NAMESPACE . '__' . $key . '__' . $timestamp;

        $this->pool->use(function (\Redis|\RedisCluster $redis) use ($key, $ttl): void {
            $redis->multi();
            try {
                $redis->incr($key);
                $redis->expire($key, $ttl);
                $result = $redis->exec();
            } catch (Throwable $th) {
                $this->discard($redis);
                throw $th;
            }

            if (!\is_array($result) || \in_array(false, $result, true)) {
                $this->discard($redis);
                throw new RuntimeException('Redis transaction failed.');
            }
        });

        $this->count = ($this->count ?? 0) + 1;
    }

    protected function set(string $key, int $timestamp, int $value): void
    {
        $ttl = $this->ttl;
        $key = Redis::NAMESPACE . '__' . $key . '__' . $timestamp;

        $this->pool->use(function (\Redis|\RedisCluster $redis) use ($key, $ttl, $value): void {
            $redis->multi();
            try {
                $redis->set($key, (string) $value);
                $redis->expire($key, $ttl);
                $result = $redis->exec();
            } catch (Throwable $th) {
                $this->discard($redis);
                throw $th;
            }

            if (!\is_array($result) || \in_array(false, $result, true)) {
                $this->discard($redis);
                throw new RuntimeException('Redis transaction failed.');
            }
        });

        $this->count = $value;
    }

    /**
     * Get abuse logs
     *
     * Return logs with an offset and limit
     *
     * @param int|null $offset
     * @param int|null $limit
     * @return array<string, mixed>
     */
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        $offset = $offset ?? 0;
        $limit = $limit ?? 25;

        /** @var array<string, mixed> $result */
        $result = $this->pool->use(function (\Redis|\RedisCluster $redis) use ($offset, $limit): array {
            if ($redis instanceof \RedisCluster) {
                return $this->getRedisClusterLogs($redis, $offset, $limit);
            }

            $cursor = null;
            $matches = [];
            $pattern = Redis::NAMESPACE . '__*';

            do {
                $keys = $redis->scan($cursor, $pattern, 100);
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
                $logs[$key] = $redis->get($key);
            }

            return $logs;
        });

        return $result;
    }

    /**
     * Delete all logs older than $timestamp
     *
     * @param int $timestamp
     * @return bool
     */
    public function cleanup(int $timestamp): bool
    {
        return true;
    }

    private function discard(\Redis|\RedisCluster $redis): void
    {
        try {
            $redis->discard();
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getRedisClusterLogs(\RedisCluster $redis, int $offset, int $limit): array
    {
        $matches = [];
        $pattern = Redis::NAMESPACE . '__*';

        foreach ($redis->_masters() as $master) {
            $cursor = null;
            do {
                /** @phpstan-ignore-next-line */
                $keys = $redis->scan($cursor, $master, $pattern, 100);
                if ($keys !== false) {
                    \array_push($matches, ...$keys);
                }
            } while ($cursor > 0);
        }

        \sort($matches);
        $matches = \array_slice($matches, $offset, $limit);

        if (empty($matches)) {
            return [];
        }

        $values = $redis->mget($matches);
        if (!\is_array($values)) {
            return [];
        }

        $logs = \array_combine($matches, $values);
        if (!\is_array($logs)) {
            return [];
        }

        return $logs;
    }
}
