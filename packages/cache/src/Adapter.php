<?php

declare(strict_types=1);

namespace Utopia\Cache;

interface Adapter
{
    /**
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed;

    /**
     * Save $data under $hash. When $ttl > 0 the key is also given a key-level
     * expiry (adapters that support it arm it; others ignore it and keep their
     * timestamp TTL). $ttl = 0 preserves the prior behaviour.
     *
     * @param  string|array<int|string, mixed>  $data
     * @param  string  $hash optional
     * @param  int  $ttl time in seconds
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array;

    /**
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool;

    /**
     * @return string[]
     */
    public function list(string $key): array;

    /**
     * @param  string  $hash optional
     */
    public function purge(string $key, string $hash = ''): bool;

    public function flush(): bool;

    public function ping(): bool;

    public function getSize(): int;

    public function getName(?string $key = null): string;
}
