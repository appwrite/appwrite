<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * Where the scheduler's {@see Claim} lives. Back it with shared storage
 * — {@see Store\Redis}, or a database row — and replicas elect one
 * dispatcher, a replacement resumes coverage where its predecessor
 * stopped, and a deposed leader's late commit is rejected instead of
 * rewinding the watermark.
 *
 * Implementations must make {@see Store::swap()} atomic: on Redis a Lua
 * script or WATCH/MULTI, on a database `UPDATE … WHERE token = ?`.
 */
interface Store
{
    public function load(): ?Claim;

    /**
     * Store $next only if the current record's token equals $expected
     * (null means no record exists yet).
     *
     * @return bool true when the swap happened
     */
    public function swap(?string $expected, Claim $next): bool;
}
