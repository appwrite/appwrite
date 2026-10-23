<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Feature\Batchable;
use Utopia\Cache\Feature\Leasable;

/**
 * A Redis cache that can be taken down: while down, every call throws as a lost connection does.
 */
final class Outage implements Adapter, Batchable, Leasable
{
    public bool $down = false;

    public function __construct(private readonly Redis $redis)
    {
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return $this->reach()->load($key, $ttl, $hash);
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        return $this->reach()->save($key, $data, $hash, $ttl);
    }

    public function touch(string $key, string $hash = ''): bool
    {
        return $this->reach()->touch($key, $hash);
    }

    public function list(string $key): array
    {
        return $this->reach()->list($key);
    }

    public function purge(string $key, string $hash = ''): bool
    {
        return $this->reach()->purge($key, $hash);
    }

    public function flush(): bool
    {
        return $this->reach()->flush();
    }

    public function ping(): bool
    {
        return $this->reach()->ping();
    }

    public function getSize(): int
    {
        return $this->reach()->getSize();
    }

    public function getName(?string $key = null): string
    {
        return $this->redis->getName($key);
    }

    public function loadMany(string $key, array $fields, int $ttl): array
    {
        return $this->reach()->loadMany($key, $fields, $ttl);
    }

    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        return $this->reach()->saveMany($key, $data, $ttl);
    }

    public function getGeneration(string $key): string
    {
        return $this->reach()->getGeneration($key);
    }

    public function saveWithLease(string $key, array|string $data, string $hash, string $generation): bool|string|array
    {
        return $this->reach()->saveWithLease($key, $data, $hash, $generation);
    }

    private function reach(): Redis
    {
        if ($this->down) {
            throw new \RedisException('Connection lost');
        }

        return $this->redis;
    }
}
