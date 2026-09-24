<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;

/**
 * A source built from closures, for tests that need to vary what a
 * snapshot reports or make a row fail to build.
 *
 * Deliberately not a `Changes` source: a scheduler given one of these
 * takes a full snapshot on every sync, which is what most tests want to
 * assert against.
 */
class SnapshotSource implements Source
{
    /**
     * @param \Closure(): iterable<Row> $snapshot
     * @param \Closure(Row): Entry $make
     */
    public function __construct(
        private readonly \Closure $snapshot,
        private readonly \Closure $make,
    ) {}

    #[\Override]
    public function snapshot(): iterable
    {
        return ($this->snapshot)();
    }

    #[\Override]
    public function make(Row $row): Entry
    {
        return ($this->make)($row);
    }
}
