<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Alerts;
use Appwrite\Schedule\Source\Functions;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Schedule\Scheduler;

final class DatabaseTest extends TestCase
{
    public function testRemovesMissingProjectScheduleOnce(): void
    {
        $database = new ScheduleDatabase();
        $database->documents['projects']['live'] = new Document(['$id' => 'live']);
        $database->documents['schedules']['live'] = new Document(array_merge(
            $database->documents['schedules']['schedule']->getArrayCopy(),
            ['$id' => 'live', '$sequence' => '2', 'projectId' => 'live'],
        ));
        $errors = [];
        $scheduler = new Scheduler(
            source: $this->source($database),
            onError: function (\Throwable $error) use (&$errors): void {
                $errors[] = $error;
            },
        );

        $scheduler->reconcile(true);
        $scheduler->reconcile(true);

        $this->assertTrue($database->getDocument('schedules', 'schedule')->isEmpty());
        $this->assertFalse($database->getDocument('schedules', 'live')->isEmpty());
        $this->assertCount(1, $errors);
        $this->assertInstanceOf(\InvalidArgumentException::class, $errors[0]);
    }

    public function testRetriesFailedProjectRead(): void
    {
        $database = new ScheduleDatabase();
        $database->readError = new \RuntimeException('Database unavailable');
        $source = $this->source($database);
        $row = iterator_to_array($source->snapshot())[0];

        try {
            $source->make($row);
            $this->fail('The database failure must propagate.');
        } catch (\RuntimeException) {
        }
        $this->assertFalse($database->getDocument('schedules', 'schedule')->isEmpty());

        $database->readError = null;
        $database->documents['projects']['project'] = new Document(['$id' => 'project']);
        $entry = $source->make($row);

        $this->assertSame('project', $entry->payload['project']->getId());
        $this->assertFalse($database->getDocument('schedules', 'schedule')->isEmpty());
    }

    public function testRetriesFailedDeletion(): void
    {
        $database = new ScheduleDatabase();
        $database->deleteError = new \RuntimeException('Delete unavailable');
        $source = $this->source($database);
        $row = iterator_to_array($source->snapshot())[0];

        try {
            $source->make($row);
            $this->fail('The deletion failure must propagate.');
        } catch (\RuntimeException) {
        }
        $this->assertFalse($database->getDocument('schedules', 'schedule')->isEmpty());

        $database->deleteError = null;
        try {
            $source->make($row);
            $this->fail('The missing project must still be reported.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue($database->getDocument('schedules', 'schedule')->isEmpty());
        }
    }

    public function testDoesNotCacheMissingProject(): void
    {
        $database = new ScheduleDatabase();
        $source = $this->source($database);
        $row = iterator_to_array($source->snapshot())[0];

        try {
            $source->make($row);
            $this->fail('The missing project must be reported.');
        } catch (\InvalidArgumentException) {
        }

        $database->documents['projects']['project'] = new Document(['$id' => 'project']);
        $database->documents['schedules']['schedule'] = $row->data;
        $entry = $source->make($row);

        $this->assertSame('project', $entry->payload['project']->getId());
        $this->assertFalse($database->getDocument('schedules', 'schedule')->isEmpty());
    }

    public function testAlertScheduleNeedsNoProject(): void
    {
        $database = $this->alertSchedules();
        $source = new Alerts($database, fn (array $schedules): array => \array_fill_keys(\array_map(fn (Document $schedule) => $schedule->getId(), $schedules), 60));

        $entries = [];
        foreach ($source->snapshot() as $row) {
            $this->assertTrue($row->active);
            $entries[(string) $row->data->getAttribute('resourceId')] = $source->make($row);
        }

        // A monitor without a project, such as an organization's; unlike a function's row, its row is not an orphan.
        $this->assertSame('spend', $entries['spend']->payload['monitorId']);
        $this->assertTrue($entries['spend']->payload['project']->isEmpty());
        $this->assertFalse($database->getDocument('schedules', 'organization')->isEmpty());

        $this->assertSame('egress', $entries['egress']->payload['monitorId']);
        $this->assertSame('project', $entries['egress']->payload['project']->getId());
        $this->assertSame('5', $entries['egress']->payload['project']->getSequence());
    }

    public function testAlertIntervalChangeReschedulesTheMonitor(): void
    {
        $database = $this->alertSchedules();
        $interval = 60;
        $source = new Alerts($database, function (array $schedules) use (&$interval): array {
            // The organization's monitor is left out, as for an organization that is gone.
            return ['rule' => $interval];
        });

        $before = \iterator_to_array($source->snapshot());
        $interval = 900;
        $after = \iterator_to_array($source->snapshot());

        // A new version makes the scheduler rebuild the entry on the new interval,
        // although the row itself did not change.
        $this->assertNotSame($before[0]->version, $after[0]->version);
        $this->assertSame(900, $after[0]->data->getAttribute('interval'));
        $this->assertFalse($after[1]->active);
    }

    private function alertSchedules(): ScheduleDatabase
    {
        $database = new ScheduleDatabase();
        $database->documents['schedules'] = [
            'rule' => new Document([
                '$id' => 'rule',
                '$sequence' => '10',
                'projectId' => 'project',
                'projectInternalId' => '5',
                'resourceId' => 'egress',
                'resourceType' => SCHEDULE_RESOURCE_TYPE_MONITOR,
                'active' => true,
                'resourceUpdatedAt' => '2026-10-29 00:00:00.000',
            ]),
            'organization' => new Document([
                '$id' => 'organization',
                '$sequence' => '11',
                'projectId' => '',
                'projectInternalId' => '',
                'resourceId' => 'spend',
                'resourceType' => SCHEDULE_RESOURCE_TYPE_MONITOR,
                'active' => true,
                'resourceUpdatedAt' => '2026-10-29 00:00:00.000',
            ]),
        ];

        return $database;
    }

    private function source(ScheduleDatabase $database): Functions
    {
        return new Functions($database, fn () => $database, fn () => false, fn () => 0);
    }
}
