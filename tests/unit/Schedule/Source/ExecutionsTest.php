<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Executions;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;

final class ExecutionsTest extends TestCase
{
    public function testDoesNotReadTheExecutionsCollection(): void
    {
        $database = new ExecutionScheduleDatabase();
        $database->documents['executions']['execution'] = new Document([
            '$id' => 'execution',
            'resourceId' => 'stale-mysql',
        ]);
        $blocked = [];
        $source = new Executions(
            $database,
            fn () => $database,
            function (Document $project, string $type, string $id) use (&$blocked): bool {
                $blocked[] = [$type, $id];

                return false;
            },
        );

        $entry = $source->make(iterator_to_array($source->snapshot())[0]);

        $this->assertSame('execution', $entry->payload['resource']->getId());
        $this->assertSame('function', $entry->payload['resource']->getAttribute('resourceId'));
        $this->assertNotEmpty($blocked);
        foreach ($blocked as $call) {
            $this->assertSame([RESOURCE_TYPE_FUNCTIONS, 'function'], $call);
        }
        $this->assertNotContains('executions', array_column($database->reads, 0));
    }

    public function testBlocksOnTheFunctionNotTheExecution(): void
    {
        $database = new ExecutionScheduleDatabase();
        $source = new Executions(
            $database,
            fn () => $database,
            fn (Document $project, string $type, string $id): bool => $type === RESOURCE_TYPE_FUNCTIONS && $id === 'function',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Resource blocked: function');

        $source->make(iterator_to_array($source->snapshot())[0]);
    }

    public function testYieldsBlockedFunctionScheduleInactive(): void
    {
        $database = new ExecutionScheduleDatabase();
        $source = new Executions(
            $database,
            fn () => $database,
            fn (Document $project, string $type, string $id): bool => $type === RESOURCE_TYPE_FUNCTIONS && $id === 'function',
        );

        $row = iterator_to_array($source->snapshot())[0];

        $this->assertFalse($row->active);
    }
}

final class ExecutionScheduleDatabase extends Database
{
    /** @var array<string, array<string, Document>> */
    public array $documents;

    /** @var list<array{0: string, 1: string}> */
    public array $reads = [];

    public function __construct()
    {
        $this->documents = [
            'projects' => ['project' => new Document(['$id' => 'project'])],
            'schedules' => ['schedule' => new Document([
                '$id' => 'schedule',
                '$sequence' => '1',
                'projectId' => 'project',
                'resourceId' => 'execution',
                'resourceType' => SCHEDULE_RESOURCE_TYPE_EXECUTION,
                'schedule' => '2026-09-14 20:00:00.000',
                'active' => true,
                'resourceUpdatedAt' => '2026-09-14 19:00:00.000',
                'data' => ['functionId' => 'function'],
            ])],
        ];
    }

    public function skipFilters(callable $callback, ?array $filters = null): mixed
    {
        return $callback();
    }

    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        $this->reads[] = [$collection, $id];

        return $this->documents[$collection][$id] ?? new Document();
    }

    public function find(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): array
    {
        return array_values($this->documents[$collection] ?? []);
    }
}
