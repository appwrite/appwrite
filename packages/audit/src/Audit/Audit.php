<?php

namespace Utopia\Audit;

/**
 * Audit Log Manager
 *
 * This class manages audit logs using pluggable adapters.
 * The default adapter is the Database adapter which stores logs in utopia-php/database.
 * Custom adapters can be created by extending the Adapter abstract class.
 */
class Audit
{
    /**
     * Constructor.
     *
     * @param Adapter $adapter The adapter to use for storing audit logs
     */
    public function __construct(private readonly Adapter $adapter) {}

    /**
     * Get the current adapter.
     */
    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * Setup the audit log storage.
     *
     * @throws \Exception
     */
    public function setup(): void
    {
        $this->adapter->setup();
    }

    /**
     * Add event log.
     *
     * @param array<string, mixed> $data
     *
     * @throws \Exception
     */
    public function log(?string $userId, string $event, string $resource, string $userAgent, string $ip, array $data = []): Log
    {
        /** @var array{userId?: string|null, event: string, resource: string, userAgent: string, ip: string, data?: array<string, mixed>} $log */
        $log = [
            'userId' => $userId,
            'event' => $event,
            'resource' => $resource,
            'userAgent' => $userAgent,
            'ip' => $ip,
            'data' => $data,
        ];

        return $this->adapter->create($log);
    }

    /**
     * Add multiple event logs in batch.
     *
     * @param array<array{userId: string|null, event: string, resource: string, userAgent: string, ip: string, time: string, data?: array<string, mixed>}> $events
     *
     * @throws \Exception
     */
    public function logBatch(array $events): bool
    {
        return $this->adapter->createBatch($events);
    }

    /**
     * Get a single log by its ID.
     *
     * @return Log|null The log entry or null if not found
     * @throws \Exception
     */
    public function getLogById(string $id): ?Log
    {
        return $this->adapter->getById($id);
    }

    /**
     * Get all logs by user ID.
     *
     * @return array<Log>
     * @throws \Exception
     */
    public function getLogsByUser(
        string $userId,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        return $this->adapter->getByUser($userId, $after, $before, $limit, $offset, $ascending);
    }

    /**
     * Count logs by user ID.
     *
     * @throws \Exception
     */
    public function countLogsByUser(
        string $userId,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        ?int $max = null,
    ): int {
        return $this->adapter->countByUser($userId, $after, $before, $max);
    }

    /**
     * Get all logs by resource.
     *
     * @return array<Log>
     * @throws \Exception
     */
    public function getLogsByResource(
        string $resource,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        return $this->adapter->getByResource($resource, $after, $before, $limit, $offset, $ascending);
    }

    /**
     * Count logs by resource.
     *
     *
     * @throws \Exception
     */
    public function countLogsByResource(
        string $resource,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        ?int $max = null,
    ): int {
        return $this->adapter->countByResource($resource, $after, $before, $max);
    }

    /**
     * Get logs by user and events.
     *
     * @param array<int, string> $events
     * @return array<Log>
     * @throws \Exception
     */
    public function getLogsByUserAndEvents(
        string $userId,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        return $this->adapter->getByUserAndEvents($userId, $events, $after, $before, $limit, $offset, $ascending);
    }

    /**
     * Count logs by user and events.
     *
     * @param array<int, string> $events
     *
     * @throws \Exception
     */
    public function countLogsByUserAndEvents(
        string $userId,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        ?int $max = null,
    ): int {
        return $this->adapter->countByUserAndEvents($userId, $events, $after, $before, $max);
    }

    /**
     * Get logs by resource and events.
     *
     * @param array<int, string> $events
     * @return array<Log>
     * @throws \Exception
     */
    public function getLogsByResourceAndEvents(
        string $resource,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        int $limit = 25,
        int $offset = 0,
        bool $ascending = false,
    ): array {
        return $this->adapter->getByResourceAndEvents($resource, $events, $after, $before, $limit, $offset, $ascending);
    }

    /**
     * Count logs by resource and events.
     *
     * @param array<int, string> $events
     *
     * @throws \Exception
     */
    public function countLogsByResourceAndEvents(
        string $resource,
        array $events,
        ?\DateTime $after = null,
        ?\DateTime $before = null,
        ?int $max = null,
    ): int {
        return $this->adapter->countByResourceAndEvents($resource, $events, $after, $before, $max);
    }

    /**
     * Delete all logs older than the specified datetime
     *
     *
     * @throws \Exception
     */
    public function cleanup(\DateTime $datetime): bool
    {
        return $this->adapter->cleanup($datetime);
    }

    /**
     * Find logs using custom queries.
     *
     * @param array<Query> $queries Array of Audit Query objects
     * @return array<Log>
     *
     * @throws \Exception
     */
    public function find(array $queries = []): array
    {
        return $this->adapter->find($queries);
    }

    /**
     * Count logs using custom queries.
     *
     * @param array<Query> $queries Array of Audit Query objects
     *
     * @throws \Exception
     */
    public function count(array $queries = [], ?int $max = null): int
    {
        return $this->adapter->count($queries, $max);
    }

    /**
     * Ping the adapter to check connectivity.
     *
     * Returns false on any connectivity failure rather than throwing.
     *
     * @return bool True when the backing store is reachable, false otherwise.
     */
    public function ping(): bool
    {
        return $this->adapter->ping();
    }
}
