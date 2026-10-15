<?php

namespace Utopia\Usage;

abstract class Adapter
{
    /**
     * Get adapter name
     */
    abstract public function getName(): string;

    /**
     * Check adapter health and connection status
     *
     * @return array<string, mixed> Health check result with 'healthy' bool and additional adapter-specific information
     */
    abstract public function healthCheck(): array;

    /**
     * Setup database structure
     */
    abstract public function setup(): void;

    /**
     * Add metrics in batch (raw append).
     *
     * Routes rows to the correct table based on the $type parameter.
     * For events, path/method/status/resource/resourceId are extracted from tags
     * into dedicated columns; remaining tags stay in the tags JSON.
     *
     * Each metric carries its own `tenant` (shared-tables mode), so a single
     * batch may span multiple tenants.
     *
     * @param  array<array{tenant: string, metric: string, value: int, tags?: array<string,mixed>}>  $metrics
     * @param  string  $type  Metric type: 'event' or 'gauge' — determines which table to write to
     * @param  int  $batchSize  Maximum number of metrics per INSERT statement
     */
    abstract public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool;

    /**
     * Add immutable, canonically identified usage samples.
     *
     * Adapters that do not provide canonical sample storage leave this
     * unsupported. Existing telemetry APIs are unaffected.
     *
     * @param list<Sample> $samples
     */
    public function addSamples(array $samples, int $batchSize = 1000): bool
    {
        throw new \Exception($this->getName() . ' does not support canonical samples');
    }

    /**
     * Capture at most $limit logical ingestion IDs from one exact range.
     * Truncation is carried by the returned watermark and fails completeness.
     */
    public function getSampleWatermark(SampleRange $range, int $limit): SampleWatermark
    {
        throw new \Exception($this->getName() . ' does not support canonical samples');
    }

    public function findSamples(SampleRange $range, SampleWatermark $watermark, int $limit): SampleResult
    {
        throw new \Exception($this->getName() . ' does not support canonical samples');
    }

    /**
     * Get time series data for metrics with query-time aggregation.
     *
     * Groups data by the specified interval (1h or 1d) and applies
     * SUM for event metrics and argMax for gauge metrics.
     *
     * @param  string  $tenant  Tenant scope (shared-tables mode)
     * @param  array<string>  $metrics  List of metric names
     * @param  string  $interval  Aggregation interval: '1h' or '1d'
     * @param  string  $startDate  Start datetime string
     * @param  string  $endDate  End datetime string
     * @param  array<\Utopia\Query\Query>  $queries  Additional query filters
     * @param  bool  $zeroFill  Whether to fill gaps with zero values
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     * @return array<string, array{total: float, data: array<array{value: float, date: string}>}>
     */
    abstract public function getTimeSeries(string $tenant, array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array;

    /**
     * Get total value for a single metric.
     *
     * Returns sum for event metrics, latest value for gauge metrics.
     * When $type is null, queries both tables.
     *
     * @param  string  $tenant  Tenant scope (shared-tables mode)
     * @param  string  $metric  Metric name
     * @param  array<\Utopia\Query\Query>  $queries  Additional query filters
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     * @return int
     */
    abstract public function getTotal(string $tenant, string $metric, array $queries = [], ?string $type = null): int;

    /**
     * Get totals for multiple metrics in a single query.
     *
     * Returns sum for event metrics, latest value for gauge metrics.
     *
     * @param  string  $tenant  Tenant scope (shared-tables mode)
     * @param  array<string>  $metrics  List of metric names
     * @param  array<\Utopia\Query\Query>  $queries  Additional query filters
     * @param  string|null  $type  Metric type: 'event', 'gauge', or null (query both)
     * @return array<string, int>
     */
    abstract public function getTotalBatch(string $tenant, array $metrics, array $queries = [], ?string $type = null): array;

    /**
     * Purge usage metrics matching the given queries.
     * When no queries are provided, all metrics are deleted.
     *
     * @param string $tenant Tenant scope (shared-tables mode)
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (purge both)
     */
    abstract public function purge(string $tenant, array $queries = [], ?string $type = null): bool;

    /**
     * Find metrics using Query objects.
     *
     * @param string $tenant Tenant scope (shared-tables mode)
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return array<Metric>
     */
    abstract public function find(string $tenant, array $queries = [], ?string $type = null): array;

    /**
     * Find metrics across every tenant in shared-tables mode.
     *
     * Deliberately crosses the per-tenant isolation every other read enforces,
     * so it is reserved for operator-side aggregation jobs that roll many
     * tenants up in one pass. Never reachable from a tenant-scoped request
     * path. Pair with `groupBy('tenant')` to keep the rows attributable.
     *
     * Adapters that cannot express an unscoped read leave this unsupported.
     *
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (query both)
     * @return array<Metric>
     * @throws \Exception
     */
    public function findAcrossTenants(array $queries = [], ?string $type = null): array
    {
        throw new \Exception($this->getName() . ' does not support cross-tenant reads');
    }

    /**
     * Count metrics using Query objects.
     *
     * When $max is non-null the count is bounded at the database level —
     * the adapter must stop counting once $max rows have been matched.
     * This keeps large counts cheap for endpoints that only need a capped
     * total. When $max is null the count is unbounded.
     *
     * @param string $tenant Tenant scope (shared-tables mode)
     * @param array<\Utopia\Query\Query> $queries
     * @param string|null $type Metric type: 'event', 'gauge', or null (count both)
     * @param int|null $max Optional upper bound for the count (inclusive)
     * @return int
     */
    abstract public function count(string $tenant, array $queries = [], ?string $type = null, ?int $max = null): int;

    /**
     * Sum metric values using Query objects.
     *
     * Events-only by default because summing gauges is semantically meaningless
     * (adding point-in-time snapshots doesn't produce a useful total).
     *
     * @param string $tenant Tenant scope (shared-tables mode)
     * @param array<\Utopia\Query\Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @param string $type Metric type: 'event' or 'gauge'
     * @return int
     */
    abstract public function sum(string $tenant, array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT): int;

    /**
     * Find event metrics from the pre-aggregated daily table.
     *
     * Queries the SummingMergeTree daily materialized view for fast billing/analytics.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param string $tenant Tenant scope (shared-tables mode)
     * @param array<\Utopia\Query\Query> $queries  Filters (metric, time range, resourceType, etc.)
     * @return array<Metric>
     */
    abstract public function findDaily(string $tenant, array $queries = []): array;

    /**
     * Sum event metric values from the pre-aggregated daily table.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param string $tenant Tenant scope (shared-tables mode)
     * @param array<\Utopia\Query\Query> $queries
     * @param string $attribute Attribute to sum (default: 'value')
     * @return int
     */
    abstract public function sumDaily(string $tenant, array $queries = [], string $attribute = 'value'): int;

    /**
     * Sum multiple event metrics from the pre-aggregated daily table in one query.
     *
     * Note: Daily MV only stores event metrics. This method always queries
     * the daily events table — gauges are never pre-aggregated.
     *
     * @param string $tenant Tenant scope (shared-tables mode)
     * @param array<string> $metrics List of metric names
     * @param array<\Utopia\Query\Query> $queries Additional filters (e.g. date range)
     * @return array<string, int> Metric name => sum value
     */
    abstract public function sumDailyBatch(string $tenant, array $metrics, array $queries = []): array;

    /**
     * Backfill the pre-aggregated daily events table from the raw events
     * table for the half-open, UTC-midnight-aligned window [$from, $to).
     *
     * Only meaningful on adapters that maintain a daily rollup; the base
     * refuses so a mis-wired caller fails loudly instead of silently
     * backfilling nothing.
     *
     * @throws \Exception
     */
    public function backfillDaily(string $from, string $to, bool $force = false): void
    {
        throw new \Exception(static::class . ' does not maintain a daily rollup to backfill.');
    }
}
