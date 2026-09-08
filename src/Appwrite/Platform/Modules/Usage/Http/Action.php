<?php

namespace Appwrite\Platform\Modules\Usage\Http;

use Appwrite\Extend\Exception;
use Utopia\Platform\Action as PlatformAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Query\Exception as QueryException;
use Utopia\Query\Method as QueryMethod;
use Utopia\Query\Query;
use Utopia\Usage\UsageQuery;

abstract class Action extends PlatformAction
{
    use HTTP;

    public const MAX_LIMIT = 5000;

    protected const DEFAULT_TYPED_LIMIT = 500;

    public const MAX_OFFSET = 100000;

    /**
     * Hard cap on the number of time buckets a single query may request.
     * Protects ClickHouse from minute-granularity × wide-window combos.
     */
    protected const MAX_BUCKETS = 5000;

    /**
     * Bucket size in seconds for each supported interval.
     *
     * @var array<string, int>
     */
    protected const INTERVAL_SECONDS = [
        '1m' => 60,
        '15m' => 900,
        '30m' => 1800,
        '1h' => 3600,
        '1d' => 86400,
    ];

    /**
     * Generated-enum case names for the interval values. The SDK generator
     * upper-snake-cases the key it is given, and every interval starts with a
     * digit, which is not a legal identifier in most target languages.
     *
     * @var array<string, string>
     */
    protected const INTERVAL_ENUM_KEYS = [
        '1m' => 'One Minute',
        '15m' => 'Fifteen Minutes',
        '30m' => 'Thirty Minutes',
        '1h' => 'One Hour',
        '1d' => 'One Day',
    ];

    /**
     * Default time window (seconds) chosen per interval so the default
     * call returns a sensible bucket count rather than blasting the
     * caller with thousands of points.
     *
     * @var array<string, int>
     */
    protected const INTERVAL_DEFAULT_WINDOW_SECONDS = [
        '1m' => 3600,
        '15m' => 86400,
        '30m' => 172800,
        '1h' => 604800,
        '1d' => 2592000,
    ];

    /**
     * @param array<string> $queries
     * @return array<Query>
     * @throws Exception
     */
    protected function parseQueries(array $queries): array
    {
        try {
            $parsed = [];
            foreach ($queries as $queryStr) {
                $parsed[] = UsageQuery::parse($queryStr);
            }
            return $parsed;
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }
    }

    /**
     * Parse filter `queries[]` strings, restrict to whitelisted attributes
     * and methods, and reject anything that targets the engine-managed
     * `metric` / `time` / `value` columns (those have dedicated params).
     *
     * @param array<string> $queries Raw Utopia query strings from the request.
     * @param array<string> $allowedAttributes Attributes the endpoint accepts as filters.
     * @param array<QueryMethod> $allowedMethods Query methods the endpoint accepts.
     * @return array<Query>
     * @throws Exception
     */
    protected function parseFilterQueries(array $queries, array $allowedAttributes, array $allowedMethods): array
    {
        $parsed = $this->parseQueries($queries);
        $allowedMethodNames = \array_map(static fn (QueryMethod $method): string => $method->value, $allowedMethods);

        foreach ($parsed as $query) {
            $attribute = $query->getAttribute();
            $method = $query->getMethod();

            if ($attribute === '') {
                throw new Exception(
                    Exception::GENERAL_QUERY_INVALID,
                    "Structural queries (limit, offset, order, select, …) are not allowed in queries[]. Allowed methods: " . \implode(', ', $allowedMethodNames)
                );
            }

            if (!\in_array($attribute, $allowedAttributes, true)) {
                throw new Exception(
                    Exception::GENERAL_QUERY_INVALID,
                    "Filtering on attribute '{$attribute}' is not supported. Allowed: " . \implode(', ', $allowedAttributes)
                );
            }

            if (!\in_array($method, $allowedMethods, true)) {
                throw new Exception(
                    Exception::GENERAL_QUERY_INVALID,
                    "Query method '{$method->value}' is not supported. Allowed: " . \implode(', ', $allowedMethodNames)
                );
            }
        }

        return $parsed;
    }

    /**
     * Render a timestamp as the ISO 8601 UTC form the response models expect.
     *
     * @throws Exception
     */
    protected function formatTime(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\\TH:i:s.vP');
        } catch (\Throwable) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid usage timestamp.');
        }
    }

    /**
     * Reject queries whose `(endAt - startAt) / interval` exceeds MAX_BUCKETS.
     * Surfaces a clear hint so callers know to use a coarser interval or a
     * narrower range instead of letting ClickHouse churn through tens of
     * thousands of buckets.
     *
     * @throws Exception
     */
    protected function assertBucketBudget(string $startAt, string $endAt, string $interval): void
    {
        $bucketSeconds = static::INTERVAL_SECONDS[$interval] ?? null;
        if ($bucketSeconds === null) {
            return;
        }

        $rangeSeconds = max(0, (int) \strtotime($endAt) - (int) \strtotime($startAt));
        $bucketCount = (int) \ceil($rangeSeconds / $bucketSeconds);

        if ($bucketCount > static::MAX_BUCKETS) {
            throw new Exception(
                Exception::GENERAL_ARGUMENT_INVALID,
                "Time range × interval would produce {$bucketCount} buckets (max " . static::MAX_BUCKETS . "). Use a coarser interval or narrow the range."
            );
        }
    }
}
