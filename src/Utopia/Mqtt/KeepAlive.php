<?php

namespace Utopia\Mqtt;

/**
 * A hashed timing wheel for MQTT keep-alive expiry.
 * Better than a priority queue as it is grouping the fds in slots of their expiry. Getting all expired and non expired fds in one go
 * 
 */
class KeepAlive
{
    /** Reaper tick period in seconds: how often the due buckets are drained. */
    public const INTERVAL = 20;

    /** A silent client is reaped after keepAlive * MULTIPLIER seconds (MQTT §3.1.2.10). */
    public const MULTIPLIER = 1.5;

    /** @var array<int, array<int, true>> deadline second => set of fds */
    private array $buckets = [];

    /** Highest second already drained; buckets at or before it are gone. */
    private int $cursor;

    public function __construct(?int $now = null)
    {
        $this->cursor = $now ?? \time();
    }

    public function schedule(int $fd, float $expiresAt): int
    {
        $slot = \max((int) $expiresAt, $this->cursor + 1);
        $this->buckets[$slot][$fd] = true;

        return $slot;
    }

    public function remove(int $fd, int $slot): void
    {
        unset($this->buckets[$slot][$fd]);

        // Drop the whole bucket once its last connection leaves, so a future second emptied
        // by disconnects doesn't linger as an empty array until the tick reaches it.
        if (($this->buckets[$slot] ?? null) === []) {
            unset($this->buckets[$slot]);
        }
    }

    /**
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
            }

            unset($this->buckets[$second]);
        }

        $this->cursor = \max($this->cursor, $now);

        return $fds;
    }
}
