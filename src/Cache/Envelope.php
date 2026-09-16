<?php

declare(strict_types=1);

namespace Utopia\Cache;

use Throwable;

/**
 * The `['time' => <unix>, 'data' => <value>]` record the hash-based adapters
 * store, run through a Codec. Encoding adds the timestamp; decoding enforces
 * TTL relative to a caller-supplied `now`.
 */
final readonly class Envelope
{
    public function __construct(private Codec $codec) {}

    /**
     * @param  array<int|string, mixed>|string  $data
     *
     * @throws Throwable when the codec cannot encode the value
     */
    public function encode(array|string $data, int $time): string
    {
        return $this->codec->encode([
            'time' => $time,
            'data' => $data,
        ]);
    }

    /**
     * Decode a stored envelope. Returns the wrapped data if the envelope is
     * well-formed and not yet expired (`time + ttl > now`); returns false
     * otherwise. Never throws — an undecodable payload or wrong shape is a miss.
     */
    public function decode(string $value, int $ttl, int $now): mixed
    {
        $cache = $this->unwrap($value);
        if ($cache === false || ! isset($cache['time']) || ! \is_int($cache['time'])) {
            return false;
        }

        if ($cache['time'] + $ttl > $now) {
            return $cache['data'];
        }

        return false;
    }

    /**
     * Re-stamp an existing envelope with a new timestamp, preserving its data.
     * Returns the rewritten payload, or false if the input is not a valid envelope.
     */
    public function touch(string $value, int $newTime): string|false
    {
        $cache = $this->unwrap($value);
        if ($cache === false) {
            return false;
        }

        $cache['time'] = $newTime;

        try {
            return $this->codec->encode($cache);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|false the decoded record when it carries a
     *                                    non-null `data` field, false otherwise
     */
    private function unwrap(string $value): array|false
    {
        try {
            $cache = $this->codec->decode($value);
        } catch (Throwable) {
            return false;
        }

        if (! \is_array($cache) || ! isset($cache['data'])) {
            return false;
        }

        return $cache;
    }
}
