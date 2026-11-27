<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\DistributionBuilds;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Schedule\Source\Row;

final class DistributionBuildsTest extends TestCase
{
    public function testMakeLoadsNeitherProjectNorResource(): void
    {
        $database = $this->database(['interval' => 2]);
        $database->readError = new \RuntimeException('The project was looked up');

        $entry = new DistributionBuilds($database)->make($this->row($database));

        $this->assertSame('artifact', $entry->payload['id']);
        $this->assertInstanceOf(Document::class, $entry->payload['project']);
        $this->assertSame('project', $entry->payload['project']->getId());
        $this->assertSame('7', $entry->payload['project']->getSequence());
        $this->assertArrayHasKey('schedule', $database->documents['schedules'], 'An unknown project or resource must not delete the schedule as an orphan');
    }

    public function testIntervalIsInMinutesAtStablePhase(): void
    {
        $database = $this->database(['interval' => 2]);
        $start = new \DateTimeImmutable('2026-10-07 00:00:00 UTC');

        $entry = new DistributionBuilds($database)->make($this->row($database));
        $occurrences = $entry->trigger->occurrencesBetween($start, $start->modify('+6 minutes'));

        $phase = \abs(\crc32('artifact')) % 120;
        $this->assertSame([
            $start->getTimestamp() + $phase,
            $start->getTimestamp() + $phase + 120,
            $start->getTimestamp() + $phase + 240,
        ], \array_map(fn (\DateTimeImmutable $due): int => $due->getTimestamp(), $occurrences));
    }

    public function testRowWithoutIntervalIsInactive(): void
    {
        $database = $this->database(['interval' => 0]);

        $this->assertFalse($this->row($database)->active);
    }

    private function row(ScheduleDatabase $database): Row
    {
        return \iterator_to_array(new DistributionBuilds($database)->snapshot())[0];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function database(array $attributes): ScheduleDatabase
    {
        $database = new ScheduleDatabase();
        $database->documents['schedules']['schedule'] = new Document([
            '$id' => 'schedule',
            '$sequence' => '1',
            'projectId' => 'project',
            'projectInternalId' => '7',
            'resourceId' => 'artifact',
            'resourceType' => SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD,
            'schedule' => '',
            'active' => true,
            'resourceUpdatedAt' => '2026-09-09 00:00:00.000',
            ...$attributes,
        ]);

        return $database;
    }
}
