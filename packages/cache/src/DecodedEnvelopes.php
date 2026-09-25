<?php

declare(strict_types=1);

namespace Utopia\Cache;

use InvalidArgumentException;

/**
 * Per-process memo of decoded envelopes, reused only while the stored string is byte-for-byte unchanged.
 */
final class DecodedEnvelopes
{
    /**
     * @var array<string, array{string, int, mixed}> cache key => [raw envelope, envelope time, data]
     */
    private array $entries = [];

    public function __construct(
        private readonly Envelope $envelope,
        private readonly int $capacity = 128,
        private readonly int $minimumBytes = 1024,
    ) {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Capacity must be at least 1.');
        }
    }

    /**
     * Decode $value the way {@see Envelope::decode()} does, reusing the entry for $key when the stored string has not changed.
     */
    public function decode(string $key, string $value, int $ttl, int $now): mixed
    {
        if (\strlen($value) < $this->minimumBytes) {
            return $this->envelope->decode($value, $ttl, $now);
        }

        $entry = $this->entries[$key] ?? null;
        if ($entry !== null && $entry[0] === $value) {
            return $entry[1] + $ttl > $now ? $entry[2] : false;
        }

        $envelope = $this->envelope->unwrap($value);
        if ($envelope === false) {
            return false;
        }

        unset($this->entries[$key]);
        $oldest = array_key_first($this->entries);
        if ($oldest !== null && \count($this->entries) >= $this->capacity) {
            unset($this->entries[$oldest]);
        }
        $this->entries[$key] = [$value, $envelope['time'], $envelope['data']];

        return $envelope['time'] + $ttl > $now ? $envelope['data'] : false;
    }

    public function count(): int
    {
        return \count($this->entries);
    }
}
