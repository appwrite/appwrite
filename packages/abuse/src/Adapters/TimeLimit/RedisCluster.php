<?php

namespace Utopia\Abuse\Adapters\TimeLimit;

use Utopia\Abuse\Adapters\TimeLimit;

class RedisCluster extends TimeLimit
{
    public const NAMESPACE = 'abuse';

    /**
     * @var \RedisCluster
     */
    protected \RedisCluster $redis;

    /**
     * @var int
     */
    protected int $ttl;

    public function __construct(string $key, int $limit, int $seconds, \RedisCluster $redis)
    {
        $this->redis = $redis;
        $this->key = $key;
        $this->ttl = $seconds;
        $now = \time();
        $this->timestamp = (int)($now - ($now % $seconds));
        $this->limit = $limit;
    }

    /**
     * Get count for a key at specific timestamp
     *
     * @param string $key
     * @param int $timestamp
     * @return integer
     */
    protected function count(string $key, int $timestamp): int
    {
        if (0 == $this->limit) { // No limit no point for counting
            return 0;
        }

        if (! \is_null($this->count)) { // Get fetched result
            return $this->count;
        }

        /** @var string|false $count */
        $count = $this->redis->get(self::NAMESPACE . '__'. $key .'__'. $timestamp);
        if ($count === false) {
            $this->count = 0;
        } else {
            $this->count = intval($count);
        }

        return $this->count;
    }

    /**
     * Record a hit for a key at specific timestamp
     *
     * @param string $key
     * @param int $timestamp
     * @return void
     */
    protected function hit(string $key, int $timestamp): void
    {
        if (0 == $this->limit) { // No limit no point for counting
            return;
        }

        $key = self::NAMESPACE . '__'. $key .'__'. $timestamp;

        $this->redis->multi();
        $this->redis->incr($key);
        $this->redis->expire($key, $this->ttl);
        $this->redis->exec();

        $this->count = ($this->count ?? 0) + 1;
    }

    /**
     * Set count for a key at specific timestamp
     *
     * @param string $key
     * @param int $timestamp
     * @param int $value
     * @return void
     */
    protected function set(string $key, int $timestamp, int $value): void
    {

        $key = self::NAMESPACE . '__' . $key . '__' . $timestamp;

        $this->redis->multi();
        $this->redis->set($key, (string)$value);
        $this->redis->expire($key, $this->ttl);
        $this->redis->exec();

        $this->count = $value;
    }

    /**
     * Get abuse logs with proper cursor-based pagination
     *
     * @param int|null $offset
     * @param int|null $limit
     * @return array<string, mixed>
     */
    public function getLogs(?int $offset = 0, ?int $limit = 25): array
    {
        $offset = $offset ?? 0;
        $limit = $limit ?? 25;
        $matches = [];
        $pattern = self::NAMESPACE . '__*';

        // Get all keys from each master
        foreach ($this->redis->_masters() as $master) {
            $cursor = null;
            do {
                /** @phpstan-ignore-next-line */
                $keys = $this->redis->scan($cursor, $master, $pattern, 100);
                if ($keys !== false) {
                    $matches = array_merge($matches, $keys);
                }
            } while ($cursor > 0 && count($matches) < $offset + $limit);
        }

        // Sort to ensure consistent ordering
        sort($matches);

        // Apply offset and limit
        $matches = array_slice($matches, $offset, $limit);

        if (empty($matches)) {
            return [];
        }

        // Batch fetch values using mget
        $values = $this->redis->mget($matches);
        return array_combine($matches, $values);
    }

    /**
     * No need for manual cleanup - using Redis TTL
     *
     * @param int $timestamp
     * @return bool
     */
    public function cleanup(int $timestamp): bool
    {
        return true;
    }
}
