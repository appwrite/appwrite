<?php

declare(strict_types=1);

namespace Utopia\Schedule\Trigger;

use Utopia\Schedule\Trigger;

/**
 * Another trigger, every occurrence moved by a fixed number of seconds.
 *
 * What this is for: a fleet of schedules that share an expression share
 * their due second, so `* * * * *` across three thousand rows is three
 * thousand publishes at :00 and nothing for the rest of the minute. Give
 * each row an offset derived from its id and the burst spreads across the
 * minute while every row keeps its slot across deploys.
 *
 * The shift belongs to the schedule rather than to delivery, so the
 * occurrence's `due` *is* the moment it runs: the window that covers it
 * covers the shifted time, the watermark commits the shifted time, and a
 * restart neither repeats nor loses it. Shifting at hand-over instead
 * would leave selection and delivery disagreeing about when a run belongs.
 *
 * The window is shifted back, the results forward, so occurrences still
 * land in exactly one window — the property the whole design rests on.
 */
final readonly class Shifted implements Trigger
{
    public function __construct(
        private Trigger $trigger,
        private int $seconds,
    ) {}

    #[\Override]
    public function occurrencesBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if ($this->seconds === 0) {
            return $this->trigger->occurrencesBetween($start, $end);
        }

        return array_map(
            fn(\DateTimeImmutable $due): \DateTimeImmutable => $due->modify("{$this->seconds} seconds"),
            $this->trigger->occurrencesBetween(
                $start->modify(\sprintf('%d seconds', -$this->seconds)),
                $end->modify(\sprintf('%d seconds', -$this->seconds)),
            ),
        );
    }

    #[\Override]
    public function recurring(): bool
    {
        return $this->trigger->recurring();
    }
}
