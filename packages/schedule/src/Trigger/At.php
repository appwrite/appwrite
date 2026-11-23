<?php

declare(strict_types=1);

namespace Utopia\Schedule\Trigger;

use Utopia\Schedule\Trigger;

/**
 * One-shot trigger: a single occurrence at a fixed moment.
 *
 * The moment is absolute from construction onward, so "in 30 seconds"
 * captured through {@see At::in()} means 30 seconds from when it was
 * scheduled — not from whenever the scheduler happens to evaluate it.
 * The scheduler retires it once the occurrence is delivered.
 */
final readonly class At implements Trigger
{
    /** With catch-up enabled, a missed occurrence is due at the start of the next window. */
    public function __construct(private \DateTimeImmutable $time, private bool $catchUp = false)
    {
    }

    /**
     * Delayed semantics: one occurrence $seconds after $from
     * (defaults to the time of the call).
     */
    public static function in(int $seconds, ?\DateTimeImmutable $from = null): self
    {
        $from ??= new \DateTimeImmutable();

        return new self($from->modify("{$seconds} seconds"));
    }

    #[\Override]
    public function occurrencesBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $due = $this->catchUp && $this->time < $start ? $start : $this->time;

        return $due >= $start && $due < $end ? [$due] : [];
    }

    #[\Override]
    public function recurring(): bool
    {
        return false;
    }
}
