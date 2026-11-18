<?php

namespace Appwrite\Logs;

use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * A backend that stores log lines for a project.
 *
 * Schema setup and health belong to the backend, not to callers that read
 * and write logs, so they live here rather than on {@see Logs}.
 */
abstract class Adapter
{
    abstract public function setup(): void;

    /** @return array<string, mixed> */
    abstract public function healthCheck(): array;

    /** @param array<Document> $logs */
    abstract public function upsert(string $projectId, array $logs): void;

    /**
     * Delete a project's logs, optionally narrowed to one resource type or one resource.
     */
    abstract public function delete(string $projectId, ?string $resourceType = null, ?string $resourceInternalId = null): void;

    abstract public function get(string $projectId, string $logId): Document;

    /**
     * @param array<Query> $queries
     * @return array<Document>
     */
    abstract public function find(string $projectId, array $queries): array;

    /** @param array<Query> $queries */
    abstract public function count(string $projectId, array $queries, int $max): int;
}
