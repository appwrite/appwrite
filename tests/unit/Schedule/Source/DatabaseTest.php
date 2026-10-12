<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

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

    private function source(ScheduleDatabase $database): Functions
    {
        return new Functions($database, fn () => $database, fn () => false, fn () => 0);
    }
}
