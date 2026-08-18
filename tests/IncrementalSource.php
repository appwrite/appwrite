<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Utopia\Schedule\Changes;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;

/**
 * A source that also reports changes, so a scheduler given one syncs
 * incrementally between full snapshots.
 */
final class IncrementalSource extends SnapshotSource implements Changes
{
    /**
     * @param \Closure(): iterable<Row> $snapshot
     * @param \Closure(Row): Entry $make
     * @param \Closure(\DateTimeImmutable): iterable<Row> $since
     */
    public function __construct(
        \Closure $snapshot,
        \Closure $make,
        private readonly \Closure $since,
    ) {
        parent::__construct($snapshot, $make);
    }

    #[\Override]
    public function since(\DateTimeImmutable $moment): iterable
    {
        return ($this->since)($moment);
    }
}
