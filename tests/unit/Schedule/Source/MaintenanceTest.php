<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Maintenance;
use PHPUnit\Framework\TestCase;

final class MaintenanceTest extends TestCase
{
    public function testSnapshotListsOneActiveScheduleThatChangesWithTheInterval(): void
    {
        $five = \iterator_to_array((new Maintenance(300))->snapshot(), false);
        $ten = \iterator_to_array((new Maintenance(600))->snapshot(), false);

        $this->assertCount(1, $five);
        $this->assertCount(1, $ten);
        $this->assertTrue($five[0]->active);
        $this->assertSame($five[0]->id, $ten[0]->id);
        $this->assertNotSame($five[0]->version, $ten[0]->version);
    }

    public function testTriggerFallsOnTheEpochGridWhateverTheWindowStart(): void
    {
        $source = new Maintenance(300);
        $row = \iterator_to_array($source->snapshot(), false)[0];
        $start = new \DateTimeImmutable('2026-03-02 00:02:30 UTC');

        $dues = $source->make($row)->trigger->occurrencesBetween($start, $start->modify('+1 hour'));

        $this->assertSame(
            \range(\strtotime('2026-03-02 00:05:00 UTC'), \strtotime('2026-03-02 01:00:00 UTC'), 300),
            \array_map(fn (\DateTimeImmutable $due): int => $due->getTimestamp(), $dues),
        );
    }
}
