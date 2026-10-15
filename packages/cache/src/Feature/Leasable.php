<?php

declare(strict_types=1);

namespace Utopia\Cache\Feature;

/**
 * Optional capability for adapters that can guard against the cache-aside
 * read-after-write race: a reader that fetched a row from the database just
 * before a concurrent purge must not be allowed to re-populate the cache with
 * that now-stale row after the purge has run.
 *
 * Each key carries a monotonic generation that advances on every purge. A
 * reader captures the generation before its database read and presents it back
 * when saving; the save only lands if the generation is unchanged, otherwise a
 * purge happened in between and the stale write is rejected.
 */
interface Leasable
{
    /**
     * Current generation token for $key. Advances whenever the key is purged.
     * Returns '0' when no generation has been recorded yet.
     */
    public function getGeneration(string $key): string;

    /**
     * Save $data only if the key's generation still equals $generation (no purge
     * since the caller captured it). Returns $data on success, or false when the
     * lease was invalidated by a concurrent purge and the write was skipped.
     *
     * @param  array<int|string, mixed>|string  $data
     * @return bool|string|array<int|string, mixed>
     */
    public function saveWithLease(string $key, array|string $data, string $hash, string $generation): bool|string|array;
}
