<?php

namespace Appwrite\Schedule\Source;

use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Trigger\Interval;

/**
 * One schedule firing every $interval seconds on the epoch grid, so every replica agrees on which slot a moment is.
 */
final readonly class Maintenance implements Source
{
    public const string ID = 'maintenance.inactive';

    public function __construct(private int $interval)
    {
    }

    #[\Override]
    public function snapshot(): iterable
    {
        yield new Row(id: self::ID, version: (string) $this->interval);
    }

    #[\Override]
    public function make(Row $row): Entry
    {
        return new Entry(new Interval($this->interval));
    }
}
