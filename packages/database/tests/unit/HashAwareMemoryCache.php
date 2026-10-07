<?php

namespace Tests\Unit;

use Utopia\Cache\Adapter\Memory;

/**
 * The bundled Memory adapter ignores the $hash argument, so a document cached
 * under one hash is served for every other. Only the Redis adapters honour it
 * in production; this mirrors their key/field semantics in memory so tests can
 * observe hash-scoped hits and misses.
 */
class HashAwareMemoryCache extends Memory
{
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return parent::load($this->field($key, $hash), $ttl);
    }

    /**
     * @param  array<int|string, mixed>|string  $data
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = ''): bool|string|array
    {
        return parent::save($this->field($key, $hash), $data);
    }

    public function touch(string $key, string $hash = ''): bool
    {
        return parent::touch($this->field($key, $hash));
    }

    public function purge(string $key, string $hash = ''): bool
    {
        if ($hash !== '') {
            return parent::purge($this->field($key, $hash));
        }

        $purged = false;

        foreach (\array_keys($this->store) as $stored) {
            if ($stored === $key || \str_starts_with($stored, $key . "\0")) {
                unset($this->store[$stored]);
                $purged = true;
            }
        }

        return $purged;
    }

    private function field(string $key, string $hash): string
    {
        return $hash === '' ? $key : $key . "\0" . $hash;
    }
}
