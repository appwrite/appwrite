<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Functions;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;

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
        $clock = new TestClock(new \DateTimeImmutable('2026-10-06T11:20:30Z'));
        $errors = [];
        $scheduler = new Scheduler(
            source: $this->source($database),
            clock: $clock,
            onError: function (\Throwable $error) use (&$errors): void {
                $errors[] = $error;
            },
        );

        $scheduler->reconcile(true);
        $scheduler->reconcile(true);

        $this->assertTrue($database->getDocument('schedules', 'schedule')->isEmpty());
        $this->assertFalse($database->getDocument('schedules', 'live')->isEmpty());
        $this->assertSame([], $errors);
        $scheduler->tick();
        $scheduler->commit();
        $clock->advance(60);
        $this->assertSame(['2'], array_values(array_unique(array_column($scheduler->tick(), 'id'))));
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

        $this->assertInstanceOf(Entry::class, $entry);
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
        $this->assertNull($source->make($row));
        $this->assertTrue($database->getDocument('schedules', 'schedule')->isEmpty());
    }

    public function testDoesNotCacheMissingProject(): void
    {
        $database = new ScheduleDatabase();
        $source = $this->source($database);
        $row = iterator_to_array($source->snapshot())[0];

        $source->make($row);
        $this->assertTrue($database->getDocument('schedules', 'schedule')->isEmpty());

        $database->documents['projects']['project'] = new Document(['$id' => 'project']);
        $database->documents['schedules']['schedule'] = $row->data;
        $entry = $source->make($row);

        $this->assertInstanceOf(Entry::class, $entry);
        $this->assertSame('project', $entry->payload['project']->getId());
        $this->assertFalse($database->getDocument('schedules', 'schedule')->isEmpty());
    }

    private function source(ScheduleDatabase $database): Functions
    {
        return new Functions($database, fn () => $database, fn () => false, fn () => 0);
    }
}
