<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Functions;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Schedule\Source\Entry;

final class FunctionsTest extends TestCase
{
    public function testIntervalRunsOncePerPeriodAtStablePhase(): void
    {
        $entry = $this->entry(['schedule' => '', 'interval' => 60]);
        $start = new \DateTimeImmutable('2026-10-07 00:00:00 UTC');

        $occurrences = $entry->trigger->occurrencesBetween($start, $start->modify('+3 hours'));

        $phase = \abs(\crc32('function')) % 3600;
        $this->assertSame([
            $start->getTimestamp() + $phase,
            $start->getTimestamp() + $phase + 3600,
            $start->getTimestamp() + $phase + 7200,
        ], \array_map(fn (\DateTimeImmutable $due): int => $due->getTimestamp(), $occurrences));
    }

    public function testSkipsUnsupportedInterval(): void
    {
        $database = $this->database(['schedule' => '', 'interval' => -5]);
        $source = new Functions($database, fn () => $database, fn () => false, fn () => 0);

        $row = \iterator_to_array($source->snapshot())[0];

        $this->assertFalse($row->active);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function entry(array $attributes): Entry
    {
        $database = $this->database($attributes);
        $source = new Functions($database, fn () => $database, fn () => false, fn () => 0);
        $row = \iterator_to_array($source->snapshot())[0];

        return $source->make($row);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function database(array $attributes): ScheduleDatabase
    {
        $database = new ScheduleDatabase();
        $database->documents['projects']['project'] = new Document(['$id' => 'project']);
        $database->documents['schedules']['schedule'] = new Document(\array_merge(
            $database->documents['schedules']['schedule']->getArrayCopy(),
            $attributes,
        ));

        return $database;
    }
}
