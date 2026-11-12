<?php

declare(strict_types=1);

namespace Tests\Unit\Logs;

use Appwrite\Logs\Adapter;
use Appwrite\Logs\Logs;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class LogsTest extends TestCase
{
    public function testUpsertWritesOneLog(): void
    {
        $adapter = new MemoryAdapter();
        $logs = new Logs($adapter);

        $logs->upsert('project', new Document(['$id' => 'log1']));

        $this->assertSame(['log1'], $adapter->ids('project'));
    }

    public function testUpsertManySkipsEmptyBatch(): void
    {
        $adapter = new MemoryAdapter();
        $logs = new Logs($adapter);

        $logs->upsertMany('project', []);

        $this->assertSame(0, $adapter->writes);
    }

    public function testDeleteNarrowsToResource(): void
    {
        $adapter = new MemoryAdapter();
        $logs = new Logs($adapter);
        $logs->upsertMany('project', [
            new Document(['$id' => 'fn', 'resourceType' => 'functions', 'resourceInternalId' => '1']),
            new Document(['$id' => 'site', 'resourceType' => 'sites', 'resourceInternalId' => '1']),
        ]);

        $logs->delete('project', 'functions', '1');

        $this->assertSame(['site'], $adapter->ids('project'));
    }

    public function testExposesAdapterForSetup(): void
    {
        $adapter = new MemoryAdapter();

        (new Logs($adapter))->getAdapter()->setup();

        $this->assertTrue($adapter->healthCheck()['schemaReady']);
    }
}

final class MemoryAdapter extends Adapter
{
    public int $writes = 0;

    private bool $ready = false;

    /** @var array<string, list<Document>> */
    private array $logs = [];

    public function setup(): void
    {
        $this->ready = true;
    }

    public function healthCheck(): array
    {
        return ['healthy' => true, 'schemaReady' => $this->ready];
    }

    public function upsert(string $projectId, array $logs): void
    {
        $this->writes++;
        foreach ($logs as $log) {
            $this->logs[$projectId][] = $log;
        }
    }

    public function delete(string $projectId, ?string $resourceType = null, ?string $resourceInternalId = null): void
    {
        $this->logs[$projectId] = \array_values(\array_filter(
            $this->logs[$projectId] ?? [],
            fn (Document $log) => !(
                ($resourceType === null || $log->getAttribute('resourceType') === $resourceType)
                && ($resourceInternalId === null || $log->getAttribute('resourceInternalId') === $resourceInternalId)
            ),
        ));
    }

    public function get(string $projectId, string $logId): Document
    {
        foreach ($this->logs[$projectId] ?? [] as $log) {
            if ($log->getId() === $logId) {
                return $log;
            }
        }

        return new Document();
    }

    public function find(string $projectId, array $queries): array
    {
        return $this->logs[$projectId] ?? [];
    }

    public function count(string $projectId, array $queries, int $max): int
    {
        return \min($max, \count($this->logs[$projectId] ?? []));
    }

    /** @return list<string> */
    public function ids(string $projectId): array
    {
        return \array_map(fn (Document $log) => $log->getId(), $this->logs[$projectId] ?? []);
    }
}
