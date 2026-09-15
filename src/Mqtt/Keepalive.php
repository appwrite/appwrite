<?php

namespace Utopia\Mqtt;

/**
 * A hashed timing wheel for MQTT keep-alive expiry. Connections are grouped into buckets
 * keyed by their deadline second, so a single tick can drain every due connection in one
 * pass rather than scanning a priority queue.
 */
class Keepalive
{
    /** @var array<int, array<int, true>> deadline second => set of fds */
    private array $buckets = [];

    /** Highest second already drained; buckets at or before it are gone. */
    private int $cursor;

    /** @var array<int, int> fd => the bucket it currently occupies, for atomic rescheduling. */
    private array $slots = [];

    /**
     * @param int      $interval   reaper tick period in seconds: how often due buckets are drained.
     * @param float    $multiplier a silent client is reaped after keepAlive * multiplier seconds (MQTT §3.1.2.10).
     * @param int|null $now        starting second; defaults to the current time (a seam for tests).
     */
    public function __construct(
        public readonly int $interval = 20,
        public readonly float $multiplier = 1.5,
        ?int $now = null,
    ) {
        $this->cursor = $now ?? \time();
    }

    /**
     * Schedule a connection at its deadline second, returning the bucket it landed in.
     * Rescheduling is atomic: the fd is first dropped from any previous bucket, so it never
     * sits in two at once and a stale deadline can't reap a client whose deadline moved forward.
     */
    public function schedule(int $fd, float $expiresAt): int
    {
        $this->drop($fd);

        // Round the deadline up: a fractional deadline flooring into the preceding second
        // would let drain() reap an active client up to a second before it actually expires.
        $slot = \max((int) \ceil($expiresAt), $this->cursor + 1);
        $this->buckets[$slot][$fd] = true;
        $this->slots[$fd] = $slot;

        return $slot;
    }

    /** Take a connection off the wheel entirely (e.g. on disconnect). */
    public function remove(int $fd): void
    {
        $this->drop($fd);
    }

    /** Remove an fd from whatever bucket it currently occupies, if any. */
    private function drop(int $fd): void
    {
        $slot = $this->slots[$fd] ?? null;
        if ($slot === null) {
            return;
        }

        unset($this->buckets[$slot][$fd], $this->slots[$fd]);

        // Drop the whole bucket once its last connection leaves, so a future second emptied
        // by disconnects doesn't linger as an empty array until the tick reaches it.
        if (($this->buckets[$slot] ?? null) === []) {
            unset($this->buckets[$slot]);
        }
    }

    /**
     * Drain every bucket due at or before $now, returning their fds. A single coarse tick
     * covers the whole elapsed range, so no per-second bucket is ever skipped between ticks.
     *
     * @return array<int, int>
     */
    public function drain(int $now): array
    {
        $fds = [];

        for ($second = $this->cursor + 1; $second <= $now; $second++) {
            if (!isset($this->buckets[$second])) {
                continue;
            }

            foreach (\array_keys($this->buckets[$second]) as $fd) {
                $fds[] = $fd;
                unset($this->slots[$fd]);
            }

            unset($this->buckets[$second]);
        }

        $this->cursor = \max($this->cursor, $now);

        return $fds;
    }
}
