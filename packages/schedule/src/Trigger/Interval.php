<?php

declare(strict_types=1);

namespace Utopia\Schedule\Trigger;

use Utopia\Schedule\Trigger;

/**
 * Recurring trigger that fires every fixed number of seconds.
 *
 * Occurrences sit on a deterministic grid — anchor + k × seconds — so the
 * cadence survives restarts instead of re-phasing to whenever the process
 * happened to boot. The anchor defaults to the Unix epoch; pass one to
 * phase the grid (for example to a resource's creation time).
 */
final readonly class Interval implements Trigger
{
    private float $anchor;

    /**
     * @throws \InvalidArgumentException when $seconds is below 1
     */
    public function __construct(private int $seconds, ?\DateTimeImmutable $anchor = null)
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Interval must be at least 1 second');
        }

        $this->anchor = $anchor instanceof \DateTimeImmutable ? (float) $anchor->format('U.u') : 0.0;
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    #[\Override]
    public function occurrencesBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $from = (float) $start->format('U.u');
        $until = (float) $end->format('U.u');

        $occurrences = [];
        $timestamp = $this->anchor + $this->seconds * ceil(($from - $this->anchor) / $this->seconds);

        while ($timestamp < $until) {
            $due = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $timestamp));
            if ($due === false) {
                break;
            }

            $occurrences[] = $due->setTimezone($start->getTimezone());
            $timestamp += $this->seconds;
        }

        return $occurrences;
    }

    #[\Override]
    public function recurring(): bool
    {
        return true;
    }
}
