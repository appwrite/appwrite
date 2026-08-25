<?php

declare(strict_types=1);

namespace Appwrite\Schedule\Source;

use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Trigger\Interval;

/**
 * A fixed set of named chores sharing one cadence.
 *
 * The set is static -- it comes from code, not a database -- so there is no
 * change feed and every reconcile is a handful of string comparisons. What
 * the scheduler adds over a single loop is that each chore is its own
 * schedule: one failing does not skip the others, and each reports its own
 * dispatch telemetry.
 */
final readonly class Chores implements Source
{
    /**
     * @param list<string> $ids
     * @param \DateTimeImmutable|null $anchor phases the grid, e.g. to a
     *        configured start-of-day, and holds it there without the drift
     *        a sleep-based loop accumulates
     */
    public function __construct(
        private array $ids,
        private int $seconds,
        private ?\DateTimeImmutable $anchor = null,
    ) {
    }

    /**
     * @return iterable<Row>
     */
    #[\Override]
    public function snapshot(): iterable
    {
        foreach ($this->ids as $id) {
            yield new Row(id: $id, version: (string) $this->seconds);
        }
    }

    #[\Override]
    public function make(Row $row): Entry
    {
        return new Entry(new Interval($this->seconds, $this->anchor));
    }
}
