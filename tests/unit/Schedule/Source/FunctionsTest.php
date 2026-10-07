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
        $entry = $this->entry(['schedule' => '', 'interval' => 3600]);
        $start = new \DateTimeImmutable('2026-10-07 00:00:00 UTC');

        $occurrences = $entry->trigger->occurrencesBetween($start, $start->modify('+3 hours'));

        $phase = \abs(\crc32('function')) % 3600;
        $this->assertSame([
            $start->getTimestamp() + $phase,
            $start->getTimestamp() + $phase + 3600,
            $start->getTimestamp() + $phase + 7200,
        ], \array_map(fn (\DateTimeImmutable $due): int => $due->getTimestamp(), $occurrences));
    }

    public function testIntervalPhaseDiffersPerFunction(): void
    {
        $start = new \DateTimeImmutable('2026-10-07 00:00:00 UTC');
        $first = $this->entry(['schedule' => '', 'interval' => 3600], 'function');
        $second = $this->entry(['schedule' => '', 'interval' => 3600], 'report');

        $firstDue = $first->trigger->occurrencesBetween($start, $start->modify('+1 hour'))[0];
        $secondDue = $second->trigger->occurrencesBetween($start, $start->modify('+1 hour'))[0];

        $this->assertNotSame($firstDue->getTimestamp(), $secondDue->getTimestamp());
    }

    public function testCronIsUsedWithoutInterval(): void
    {
        $entry = $this->entry(['schedule' => '0 * * * *', 'interval' => 0]);
        $start = new \DateTimeImmutable('2026-10-07 00:30:00 UTC');

        $occurrences = $entry->trigger->occurrencesBetween($start, $start->modify('+2 hours'));

        $this->assertSame(
            ['01:00:00', '02:00:00'],
            \array_map(fn (\DateTimeImmutable $due): string => $due->format('H:i:s'), $occurrences),
        );
    }

    public function testRejectsUnsupportedInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->entry(['schedule' => '', 'interval' => 1]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function entry(array $attributes, string $functionId = 'function'): Entry
    {
        $database = new ScheduleDatabase();
        $database->documents['projects']['project'] = new Document(['$id' => 'project']);
        $database->documents['functions'][$functionId] = new Document(['$id' => $functionId]);
        $database->documents['schedules']['schedule'] = new Document(\array_merge(
            $database->documents['schedules']['schedule']->getArrayCopy(),
            ['resourceId' => $functionId],
            $attributes,
        ));

        $source = new Functions($database, fn () => $database, fn () => false, fn () => 0);
        $row = \iterator_to_array($source->snapshot())[0];

        return $source->make($row);
    }
}
