<?php

// Utopia\Usage\Engine is registered by the utopia PHP extension (crates/extension in
// appwrite/appwrite) when _APP_EXTENSION_LIBRARIES names `usage`. This file only declares it
// for static analysis and editors; it is never loaded.

namespace Utopia\Usage;

use Exception;

/**
 * The ClickHouse adapter's statements and response handling, in Rust. Adapter\ClickHouse
 * delegates to it when it exists and sends its requests: the engine never opens a socket.
 *
 * @internal
 */
final class Engine
{
    /**
     * @param  array<string, mixed>  $config  Adapter\ClickHouse's constructor arguments, by name.
     * @param  callable(string, array<string, string>, string): array{int, string}  $send  Sends
     *   one POST (URL, headers, body) and answers with the status and body, or with a negative
     *   status and the reason the request could not be answered.
     *
     * @throws Exception
     */
    public function __construct(array $config, callable $send)
    {
    }

    public function setNextQueryId(?string $queryId): void
    {
    }

    /**
     * @return array<int, array{operation: string, metric: ?string, route: string, start: ?string, end: ?string, dimensions: array<int, string>, interval: ?string}>
     */
    public function getRouteLog(): array
    {
    }

    public function clearRouteLog(): void
    {
    }

    public function getRequestCount(): int
    {
    }

    /**
     * @return array{healthy: bool, host: string, port: int, database: string, secure: bool, version?: string, uptime?: int, error?: string, response_time?: float}
     */
    public function healthCheck(): array
    {
    }

    /** @throws Exception */
    public function setup(): void
    {
    }

    /** @throws Exception */
    public function migrate(): void
    {
    }

    /** @throws Exception */
    public function backfillDaily(string $from, string $to, bool $force): void
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $metrics
     *
     * @throws Exception
     */
    public function addBatch(array $metrics, string $type, int $batchSize): bool
    {
    }

    /**
     * @param  array<string>  $metrics
     * @param  array<string>  $queries  Queries as Query::toString() writes them.
     * @return array<string, array{total: int|float, data: array<array{value: float, date: string}>}>
     *
     * @throws Exception
     */
    public function getTimeSeries(string $tenant, array $metrics, string $interval, string $startDate, string $endDate, array $queries, bool $zeroFill, ?string $type): array
    {
    }

    /**
     * @param  array<string>  $queries
     *
     * @throws Exception
     */
    public function getTotal(string $tenant, string $metric, array $queries, ?string $type): int
    {
    }

    /**
     * @param  array<string>  $metrics
     * @param  array<string>  $queries
     * @return array<string, int>
     *
     * @throws Exception
     */
    public function getTotalBatch(string $tenant, array $metrics, array $queries, ?string $type): array
    {
    }

    /**
     * @param  array<string>  $queries
     *
     * @throws Exception
     */
    public function purge(string $tenant, array $queries, ?string $type): bool
    {
    }

    /**
     * @param  array<string>  $queries
     * @return array<array<string, mixed>>
     *
     * @throws Exception
     */
    public function find(string $tenant, array $queries, ?string $type): array
    {
    }

    /**
     * @param  array<string>  $queries
     * @return array<array<string, mixed>>
     *
     * @throws Exception
     */
    public function findAcrossTenants(array $queries, ?string $type): array
    {
    }

    /**
     * @param  array<string>  $queries
     *
     * @throws Exception
     */
    public function count(string $tenant, array $queries, ?string $type, ?int $max): int
    {
    }

    /**
     * @param  array<string>  $queries
     *
     * @throws Exception
     */
    public function sum(string $tenant, array $queries, string $attribute, string $type): int
    {
    }

    /**
     * @param  array<string>  $queries
     * @return array<array<string, mixed>>
     *
     * @throws Exception
     */
    public function findDaily(string $tenant, array $queries): array
    {
    }

    /**
     * @param  array<string>  $queries
     *
     * @throws Exception
     */
    public function sumDaily(string $tenant, array $queries, string $attribute): int
    {
    }

    /**
     * @param  array<string>  $metrics
     * @param  array<string>  $queries
     * @return array<string, int>
     *
     * @throws Exception
     */
    public function sumDailyBatch(string $tenant, array $metrics, array $queries): array
    {
    }
}
