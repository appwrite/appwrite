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
    public function __construct(private \DateTimeImmutable $time) {}

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
        return $this->time >= $start && $this->time < $end ? [$this->time] : [];
    }

    #[\Override]
    public function recurring(): bool
    {
        return false;
    }
}
