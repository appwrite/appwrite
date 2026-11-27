<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Schedules;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Distribution\Fakes\MemoryDatabase;
use Utopia\Database\Document;

final class SchedulesTest extends TestCase
{
    private const string STALE = '2026-01-01 00:00:00.000';

    private MemoryDatabase $dbForPlatform;

    private Schedules $schedules;

    private Document $project;

    protected function setUp(): void
    {
        $this->dbForPlatform = new MemoryDatabase();
        $this->schedules = new Schedules($this->dbForPlatform);
        $this->project = new Document(['$id' => 'project1', '$sequence' => '7', 'region' => 'fra']);
    }

    public function testWriteCreatesAnActiveRow(): void
    {
        $this->schedules->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $this->artifact(), 1);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame([
            'region' => 'fra',
            'resourceType' => SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD,
            'resourceId' => 'artifact1',
            'resourceInternalId' => '42',
            'projectId' => 'project1',
            'projectInternalId' => '7',
            'schedule' => '',
            'interval' => 1,
            'active' => true,
        ], \array_intersect_key($rows[0]->getArrayCopy(), \array_flip([
            'region', 'resourceType', 'resourceId', 'resourceInternalId', 'projectId', 'projectInternalId', 'schedule', 'interval', 'active',
        ])));
        $this->assertNotEmpty($rows[0]->getAttribute('resourceUpdatedAt'));
    }

    public function testWriteUpdatesTheExistingRow(): void
    {
        $this->schedules->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $this->artifact(), 1);
        $this->stale();
        $this->schedules->stop($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'artifact1');

        $this->schedules->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $this->artifact(), 15);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(15, $rows[0]->getAttribute('interval'));
        $this->assertTrue($rows[0]->getAttribute('active'));
        $this->assertNotSame(self::STALE, $rows[0]->getAttribute('resourceUpdatedAt'));
    }

    public function testRowsAreKeyedByTypeProjectAndRegion(): void
    {
        $this->schedules->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $this->artifact(), 1);
        $this->schedules->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, $this->artifact(), 2);
        $this->schedules->write(new Document(['$id' => 'project2', '$sequence' => '8', 'region' => 'fra']), SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $this->artifact(), 1);
        $this->schedules->write(new Document(['$id' => 'project1', '$sequence' => '7', 'region' => 'nyc']), SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $this->artifact(), 1);

        $this->assertCount(4, $this->rows());
    }

    public function testStopDeactivatesTheRowAndMovesItsMarker(): void
    {
        $this->schedules->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $this->artifact(), 1);
        $this->stale();

        $this->schedules->stop($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'artifact1');

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]->getAttribute('active'));
        $this->assertNotSame(self::STALE, $rows[0]->getAttribute('resourceUpdatedAt'));
    }

    public function testStopLeavesOtherRowsAlone(): void
    {
        $this->schedules->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, $this->artifact(), 1);

        $this->schedules->stop($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'artifact1');
        $this->schedules->stop($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'artifact2');

        $this->assertTrue($this->rows()[0]->getAttribute('active'));
        $this->assertCount(1, $this->rows());
    }

    private function artifact(): Document
    {
        return new Document(['$id' => 'artifact1', '$sequence' => '42']);
    }

    /**
     * @return list<Document>
     */
    private function rows(): array
    {
        return \array_values($this->dbForPlatform->documents['schedules'] ?? []);
    }

    private function stale(): void
    {
        foreach ($this->rows() as $row) {
            $this->dbForPlatform->updateDocument('schedules', $row->getId(), new Document(['resourceUpdatedAt' => self::STALE]));
        }
    }
}
