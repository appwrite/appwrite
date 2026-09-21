<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Functions;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Schedule\Scheduler;

final class DatabaseTest extends TestCase
{
    public function testRemovesMissingProjectScheduleOnce(): void
    {
        $database = new ScheduleDatabase();
        $database->documents['projects']['live'] = new Document(['$id' => 'live', 'region' => 'default', 'status' => PROJECT_STATUS_ACTIVE]);
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
        $database->documents['projects']['project'] = new Document(['$id' => 'project', 'region' => 'default', 'status' => PROJECT_STATUS_ACTIVE]);
        $row = new \Utopia\Schedule\Source\Row(
            id: '1',
            version: '2026-09-09 00:00:00.000',
            data: $database->documents['schedules']['schedule'],
            active: true,
        );

        $database->readError = new \RuntimeException('Database unavailable');
        $source = $this->source($database);

        try {
            $source->make($row);
            $this->fail('The database failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Database unavailable', $error->getMessage());
        }
        $this->assertFalse($database->getDocument('schedules', 'schedule')->isEmpty());

        $database->readError = null;
        $entry = $source->make($row);

        $this->assertSame('project', $entry->payload['project']->getId());
        $this->assertFalse($database->getDocument('schedules', 'schedule')->isEmpty());
    }

    public function testRetriesFailedDeletion(): void
    {
        $database = new ScheduleDatabase();
        $source = $this->source($database);
        $row = new \Utopia\Schedule\Source\Row(
            id: '1',
            version: '2026-09-09 00:00:00.000',
            data: $database->documents['schedules']['schedule'],
            active: true,
        );

        $database->deleteError = new \RuntimeException('Delete unavailable');

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
        $row = new \Utopia\Schedule\Source\Row(
            id: '1',
            version: '2026-09-09 00:00:00.000',
            data: $database->documents['schedules']['schedule'],
            active: true,
        );

        try {
            $source->make($row);
            $this->fail('The missing project must be reported.');
        } catch (\InvalidArgumentException) {
        }

        $database->documents['projects']['project'] = new Document(['$id' => 'project', 'region' => 'default', 'status' => PROJECT_STATUS_ACTIVE]);
        $database->documents['schedules']['schedule'] = $row->data;
        $entry = $source->make($row);

        $this->assertSame('project', $entry->payload['project']->getId());
        $this->assertFalse($database->getDocument('schedules', 'schedule')->isEmpty());
    }

    public function testDropsSchedulesForInactiveProjects(): void
    {
        $database = new ScheduleDatabase();
        $database->documents['projects']['project'] = new Document(['$id' => 'project', 'region' => 'default', 'status' => PROJECT_STATUS_ACTIVE]);
        $source = $this->source($database);
        $scheduler = new Scheduler(source: $source, onError: function (): void {
        });

        $scheduler->reconcile(true);
        $this->assertSame(1, $scheduler->count());

        $database->documents['projects']['project'] = new Document(['$id' => 'project', 'region' => 'default', 'status' => 'blocked']);
        $scheduler->reconcile(true);

        $this->assertSame(0, $scheduler->count());
        $this->assertFalse($database->getDocument('schedules', 'schedule')->isEmpty());
    }

    private function source(ScheduleDatabase $database): Functions
    {
        return new Functions($database, fn () => $database, fn () => false, fn () => 0);
    }
}

final class ScheduleDatabase extends Database
{
    /** @var array<string, array<string, Document>> */
    public array $documents;

    public ?\RuntimeException $readError = null;

    public ?\RuntimeException $deleteError = null;

    public function __construct()
    {
        $this->documents = [
            'projects' => [],
            'functions' => ['function' => new Document(['$id' => 'function'])],
            'schedules' => ['schedule' => new Document([
                '$id' => 'schedule',
                '$sequence' => '1',
                'region' => 'default',
                'projectId' => 'project',
                'resourceId' => 'function',
                'resourceType' => SCHEDULE_RESOURCE_TYPE_FUNCTION,
                'schedule' => '* * * * *',
                'active' => true,
                'resourceUpdatedAt' => '2026-09-09 00:00:00.000',
            ])],
        ];
    }

    public function skipFilters(callable $callback, ?array $filters = null): mixed
    {
        return $callback();
    }

    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        if ($collection === 'projects' && $this->readError !== null) {
            throw $this->readError;
        }

        return $this->documents[$collection][$id] ?? new Document();
    }

    public function deleteDocument(string $collection, string $id): bool
    {
        if ($this->deleteError !== null) {
            throw $this->deleteError;
        }
        unset($this->documents[$collection][$id]);

        return true;
    }

    public function find(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): array
    {
        $documents = \array_values($this->documents[$collection] ?? []);

        foreach ($queries as $query) {
            if (!$query instanceof Query || $query->getMethod() !== Query::TYPE_EQUAL) {
                continue;
            }

            $attribute = $query->getAttribute();
            $values = $query->getValues();
            $documents = \array_values(\array_filter(
                $documents,
                fn (Document $document): bool => \in_array($document->getAttribute($attribute), $values, true),
            ));
        }

        return $documents;
    }
}
