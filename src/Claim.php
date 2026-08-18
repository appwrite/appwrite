<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * The scheduler's shared record: who leads, until when, and where
 * committed coverage stops. Leadership and the watermark share one
 * lifecycle — the leader advances both on every commit — so they share
 * one record, and one compare-and-swap makes the commit fenced: a
 * deposed leader's late write no longer matches the stored token and
 * cannot rewind coverage.
 */
final readonly class Claim
{
    /**
     * @param string $token the holder's identity; empty when the claim was released
     * @param float $expiresAt Unix seconds; at or past this moment any instance may take over
     * @param string|null $windowEnd where committed coverage stops, as a `U.u` timestamp;
     *                               null before the first ever commit
     */
    public function __construct(
        public string $token,
        public float $expiresAt,
        public ?string $windowEnd,
    ) {}
}
