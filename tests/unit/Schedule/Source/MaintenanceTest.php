<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Maintenance;
use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Source\Row;

final class MaintenanceTest extends TestCase
{
    public function testSnapshotListsOneScheduleVersionedByItsInterval(): void
    {
        $rows = \iterator_to_array((new Maintenance(300))->snapshot(), false);

        $this->assertCount(1, $rows);
        $this->assertInstanceOf(Row::class, $rows[0]);
        $this->assertSame('maintenance.inactive', $rows[0]->id);
        $this->assertSame(Maintenance::ID, $rows[0]->id);
        $this->assertTrue($rows[0]->active);
        $this->assertSame('300', $rows[0]->version);

        $rows = \iterator_to_array((new Maintenance(600))->snapshot(), false);

        $this->assertCount(1, $rows);
        $this->assertSame('600', $rows[0]->version);
    }

    public function testTriggerFallsOnTheEpochGridWhateverTheWindowStart(): void
    {
        $source = new Maintenance(300);
        $row = \iterator_to_array($source->snapshot(), false)[0];
        $start = new \DateTimeImmutable('2026-03-02 00:02:30 UTC');

        $dues = $source->make($row)->trigger->occurrencesBetween($start, $start->modify('+1 hour'));

        $timestamps = \array_map(fn (\DateTimeImmutable $due): int => $due->getTimestamp(), $dues);
        $this->assertCount(12, $timestamps);
        $this->assertSame(\strtotime('2026-03-02 00:05:00 UTC'), $timestamps[0]);
        $this->assertSame(\strtotime('2026-03-02 01:00:00 UTC'), $timestamps[11]);
        foreach ($timestamps as $timestamp) {
            $this->assertSame(0, $timestamp % 300);
        }
        $this->assertSame(\range($timestamps[0], $timestamps[11], 300), $timestamps);
    }
}
