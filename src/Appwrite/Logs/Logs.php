<?php

namespace Appwrite\Logs;

use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * Log lines of a project's resources, such as function and site runtimes or
 * dedicated databases, stored by an {@see Adapter}.
 */
class Logs
{
    public function __construct(private readonly Adapter $adapter)
    {
    }

    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }

    public function upsert(string $projectId, Document $log): void
    {
        $this->adapter->upsert($projectId, [$log]);
    }

    /** @param array<Document> $logs */
    public function upsertMany(string $projectId, array $logs): void
    {
        if ($logs === []) {
            return;
        }

        $this->adapter->upsert($projectId, $logs);
    }

    public function delete(string $projectId, ?string $resourceType = null, ?string $resourceInternalId = null): void
    {
        $this->adapter->delete($projectId, $resourceType, $resourceInternalId);
    }

    public function get(string $projectId, string $logId): Document
    {
        return $this->adapter->get($projectId, $logId);
    }

    /**
     * @param array<Query> $queries
     * @return array<Document>
     */
    public function find(string $projectId, array $queries): array
    {
        return $this->adapter->find($projectId, $queries);
    }

    /** @param array<Query> $queries */
    public function count(string $projectId, array $queries, int $max): int
    {
        return $this->adapter->count($projectId, $queries, $max);
    }
}
