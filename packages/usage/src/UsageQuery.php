<?php

namespace Utopia\Usage;

use Utopia\Query\Method;
use Utopia\Query\Query;

/**
 * Usage Query
 *
 * Extends the base Query class with usage-specific query factories.
 * `groupByInterval` enables time-bucketed aggregated queries in the
 * ClickHouse adapter (compiled as `Method::GroupByTimeBucket`), and
 * `groupBy` buckets results by a dimension column
 * (compiled as `Method::GroupBy`).
 *
 * Example usage:
 * ```php
 * $queries = [
 *     UsageQuery::groupByInterval('time', '1h'),
 *     Query::equal('metric', ['bandwidth']),
 *     Query::greaterThanEqual('time', '2026-03-01'),
 *     Query::lessThanEqual('time', '2026-04-01'),
 * ];
 * $results = $usage->find($tenant, $queries, 'event');
 * ```
 *
 * When a `groupByInterval` query is present in the queries array, the
 * ClickHouse adapter switches from raw row returns to aggregated results
 * grouped by time bucket:
 * - Events: SUM(value) per bucket
 * - Gauges: argMax(value, time) per bucket
 *
 * An `aggregate` hint overrides how values are combined: `max` takes the
 * largest value per bucket, which is how a gauge level series rolls up to a
 * coarser interval — the default `argMax` would return the latest reading
 * rather than the highest.
 */
class UsageQuery extends Query
{
    /**
     * Aggregation functions `aggregate()` accepts, mapped to the query 0.3
     * `Method` that expresses each one.
     *
     * - `max` — the largest value per bucket, overriding the per-type default.
     *   Intended for gauges, where the default `argMax(value, time)` returns the
     *   *latest* reading rather than the highest one. Reading a pre-computed
     *   level series (e.g. realtime concurrency sampled every 5 minutes) at a
     *   coarser interval needs the peak of the samples, not the last one.
     *
     * There is deliberately no `sum`: it is already the default for events, and
     * on gauges it would total point-in-time snapshots, which means nothing.
     *
     * Encoded as a real `Method` rather than the `TYPE_AGGREGATE` string this
     * carried before the query 0.3 migration, for the same reason
     * `groupByInterval` and `groupBy` are: 0.3 removed the string method
     * constants, and `getMethod()` now returns the enum, so a string comparison
     * would compile and always be false.
     *
     * @var array<string, Method>
     */
    private const AGGREGATE_METHODS = ['max' => Method::Max];

    /**
     * Valid aggregation functions, as accepted by `aggregate()`.
     *
     * @see UsageQuery::AGGREGATE_METHODS for what each one means.
     */
    public const VALID_AGGREGATES = ['max'];

    /**
     * Valid interval values and their ClickHouse INTERVAL equivalents.
     */
    public const VALID_INTERVALS = [
        '1m' => 'INTERVAL 1 MINUTE',
        '5m' => 'INTERVAL 5 MINUTE',
        '15m' => 'INTERVAL 15 MINUTE',
        '30m' => 'INTERVAL 30 MINUTE',
        '1h' => 'INTERVAL 1 HOUR',
        '1d' => 'INTERVAL 1 DAY',
        '1w' => 'INTERVAL 1 WEEK',
        '1M' => 'INTERVAL 1 MONTH',
    ];

    /**

     * Create a groupByInterval query.
     *
     * When passed to `find()`, this switches the adapter to return time-bucketed
     * aggregated results instead of raw rows.
     *
     * @param string $attribute The time attribute to bucket (usually 'time')
     * @param string $interval The bucket size: '1m', '5m', '15m', '30m', '1h', '1d', '1w', '1M'
     * @return self
     */
    public static function groupByInterval(string $attribute, string $interval): self
    {
        if (!isset(self::VALID_INTERVALS[$interval])) {
            throw new \InvalidArgumentException(
                "Invalid interval '{$interval}'. Allowed: " . implode(', ', array_keys(self::VALID_INTERVALS))
            );
        }

        return new self(Method::GroupByTimeBucket, $attribute, [$interval]);
    }

    /**
     * Create a groupBy query for dimensional aggregation.
     *
     * Buckets results by the given attribute in addition to the time bucket
     * supplied via `groupByInterval`. Multiple `groupBy` queries may be
     * combined to bucket by several dimensions at once (e.g. service x status).
     *
     * @param array<string>|string $attributes The dimension column(s) to bucket on (service, path, status, ...).
     */
    public static function groupBy(array|string $attributes): static
    {
        if (is_string($attributes)) {
            return new static(Method::GroupBy, $attributes, []);
        }

        return parent::groupBy($attributes);
    }

    /**
     * Create an aggregate query selecting the aggregation function.
     *
     * `max` takes the largest value in the bucket, overriding the per-type
     * default — the meaningful roll-up for a gauge level series, whose default
     * `argMax(value, time)` would return the latest reading.
     * See {@see UsageQuery::VALID_AGGREGATES}.
     *
     * @param string $function One of {@see UsageQuery::VALID_AGGREGATES}.
     * @return self
     */
    public static function aggregate(string $function): self
    {
        if (!isset(self::AGGREGATE_METHODS[$function])) {
            throw new \InvalidArgumentException(
                "Invalid aggregate '{$function}'. Allowed: " . implode(', ', self::VALID_AGGREGATES)
            );
        }

        // The function is carried by the method itself, so there is no value to
        // pass; `extractAggregate()` reads the name back off the method.
        return new self(self::AGGREGATE_METHODS[$function], 'value', []);
    }

    /**
     * Extract the aggregation function from an array of queries, if present.
     *
     * Queries parsed via `Query::parse()` are base `Query` objects rather than
     * `UsageQuery` instances, so we match on the method alone.
     *
     * @param array<Query> $queries
     * @return string|null The aggregation function, or null if not present.
     */
    public static function extractAggregate(array $queries): ?string
    {
        foreach ($queries as $query) {
            $function = \array_search($query->getMethod(), self::AGGREGATE_METHODS, true);

            if ($function !== false) {
                return $function;
            }
        }

        return null;
    }

}
