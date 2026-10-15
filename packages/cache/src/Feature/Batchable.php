<?php

declare(strict_types=1);

namespace Utopia\Cache\Feature;

/**
 * Optional capability for adapters that store many fields under one key (a hash)
 * and can read or write several of them in a single round trip. The Cache facade
 * exposes loadMany()/saveMany() and routes to this when the adapter implements
 * it, falling back for adapters that store a single value per key — mirroring how
 * Leasable backs saveWithLease().
 */
interface Batchable
{
    /**
     * Load several fields of $key at once. An empty $fields loads every field.
     * Returns a field => value map with missing/expired fields omitted.
     *
     * @param  string[]  $fields
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>
     */
    public function loadMany(string $key, array $fields, int $ttl): array;

    /**
     * Write every field => value pair of $data in one call. When $ttl > 0 the key
     * is given a key-level expiry. Returns $data on success or false on failure.
     *
     * @param  array<string, mixed>  $data field => value
     * @param  int  $ttl time in seconds
     * @return array<string, mixed>|false
     */
    public function saveMany(string $key, array $data, int $ttl = 0): array|false;
}
