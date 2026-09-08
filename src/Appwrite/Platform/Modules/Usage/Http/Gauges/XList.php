<?php

namespace Appwrite\Platform\Modules\Usage\Http\Gauges;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Usage\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Policy;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Platform\Enum;
use Utopia\Query\Method as QueryMethod;
use Utopia\Query\Query;
use Utopia\System\System;
use Utopia\Usage\Tenant;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class XList extends Action
{
    protected const VALID_INTERVALS = ['1m', '15m', '30m', '1h', '1d'];

    protected const VALID_DIMENSIONS = ['resourceId', 'service', 'resourceType', 'ordinal'];

    protected const VALID_ORDER_BY = ['time', 'value'];

    protected const VALID_ORDER_DIRS = ['asc', 'desc'];

    /**
     * `last` keeps the historical argMax(value, time) behaviour — the latest
     * reading in the bucket. `max` takes the highest reading instead, which is
     * how a sampled level series (realtime concurrency) rolls up to a peak.
     */
    protected const VALID_AGGREGATES = ['last', 'max'];

    protected const DEFAULT_AGGREGATE_WINDOW_SECONDS = 7 * 86400;

    /**
     * Attributes that may be used as filter targets in `queries[]`. Matches
     * GAUGE_COLUMNS in utopia-php/usage.
     */
    protected const VALID_FILTER_ATTRIBUTES = ['service', 'resourceType', 'resourceId', 'ordinal'];

    /**
     * Query methods supported on the filter surface. Gauges only
     * carry short low-cardinality string dimensions, so equality + null
     * presence is what makes sense; we skip contains/startsWith here since
     * they're rarely useful on values like 'bucket' / 'function'.
     */
    protected const VALID_FILTER_METHODS = [
        QueryMethod::Equal,
        QueryMethod::NotEqual,
        QueryMethod::IsNull,
        QueryMethod::IsNotNull,
    ];

    public static function getName(): string
    {
        return 'listGauges';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/usage/gauges')
            ->desc('List usage gauges')
            ->groups(['api', 'usage'])
            ->label('scope', 'usage.read')
            ->label('abuse-limit', 60)
            ->label('abuse-time', 60)
            ->label('abuse-key', 'projectId:{project.$id}')
            ->label('sdk', new Method(
                namespace: 'usage',
                group: 'gauges',
                name: 'listGauges',
                description: <<<EOT
                Aggregate usage gauge snapshots. Gauges are point-in-time values (storage totals, resource counts, …); each point carries the latest snapshot in its interval via `argMax(value, time)`. `metrics[]` (1-10) is required; the response always contains one entry per requested metric, each with its own `points[]` time series.

                A metric with no stored samples in the window returns an empty `points[]`. A metric that really did read zero returns a point whose `value` is `0`, so "no such series" and "a genuine zero" are different answers.

                **Two response shapes**:
                - Omit `interval` for a flat top-N table — `argMax(value, time)` per dimension combination over the whole window, no time axis. Useful for "top 10 resources by current storage".
                - Pass `interval` (`1m`, `15m`, `30m`, `1h`, `1d`) for a time series — one snapshot per (time bucket × dimension combination).

                `dimensions[]` breaks each point down by resource, service, resource type, or ordinal. `queries[]` filters rows using standard Utopia query syntax. Pass multiple metrics to render stacked charts in one round-trip. When `startAt` is omitted, the default window adapts to interval (or 7d when interval is omitted).

                `aggregate` selects how the samples in a bucket are combined: `last` (default) is the latest reading — correct for a snapshot such as storage — while `max` is the highest reading. Use `max` for a sampled level series: peak concurrent realtime connections is `metrics[]=realtime.connections&aggregate=max`, at whatever `interval` the chart needs, since the peak of a set of samples is just the max of their per-bucket maxima. `realtime.connections` is served only here - it is a concurrency level, not a countable event, so `/v1/usage/events` rejects it.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USAGE_GAUGE_LIST,
                    ),
                ],
                // Preview SDK builds show the whole surface, so they do not hide.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') === 'enabled' ? false : ['server'],
            ))
            ->param('metrics', [], new ArrayList(new Text(255), 10), 'One to ten metric names. Single-metric callers pass a one-element array.', false)
            ->param('queries', [], new ArrayList(new Text(4096), 10), 'Up to 10 filter queries in Utopia syntax. Allowed attributes, also published as the `UsageGaugeDimension` enum: ' . implode(', ', static::VALID_FILTER_ATTRIBUTES) . '. Allowed methods: equal, notEqual, isNull, isNotNull. Example: `queries[]=equal("resourceType", ["bucket"])`.', true)
            ->param('interval', null, new Nullable(new WhiteList(static::VALID_INTERVALS)), 'Time interval size. Omit (null) for a flat aggregate over the whole window. Allowed: ' . implode(', ', static::VALID_INTERVALS) . '.', true, enum: new Enum(
                name: 'UsageInterval',
                map: static::INTERVAL_ENUM_KEYS
            ))
            ->param('dimensions', [], new ArrayList(new WhiteList(static::VALID_DIMENSIONS, true), 2), 'Break-down dimensions. Allowed: ' . implode(', ', static::VALID_DIMENSIONS) . '.', true, enum: new Enum(name: 'UsageGaugeDimension'))
            ->param('startAt', '', new DatetimeValidator(), 'Range start in ISO 8601. Defaults to endAt - 7d.', true)
            ->param('endAt', '', new DatetimeValidator(), 'Range end in ISO 8601. Defaults to the current time.', true)
            ->param('orderBy', 'time', new WhiteList(static::VALID_ORDER_BY), 'Column to order by. Allowed: time, value. Default time.', true, enum: new Enum(name: 'UsageOrderBy'))
            ->param('orderDir', 'desc', new WhiteList(static::VALID_ORDER_DIRS), 'Sort direction: asc or desc. Default desc — paired with the default limit, this returns the most recent groups first. Pass asc for chronological charting.', true, enum: new Enum(name: 'UsageOrderDirection'))
            ->param('limit', parent::DEFAULT_TYPED_LIMIT, new Range(1, parent::MAX_LIMIT), 'Maximum rows to return (1-' . parent::MAX_LIMIT . ').', true)
            ->param('offset', 0, new Range(0, parent::MAX_OFFSET), 'Pagination offset (0-' . parent::MAX_OFFSET . ').', true)
            ->param('aggregate', 'last', new WhiteList(static::VALID_AGGREGATES), 'How to combine the samples in each bucket. `last` (default) returns the latest reading — the right answer for a snapshot such as storage. `max` returns the highest reading, which is what a sampled level series needs: peak concurrent realtime connections is the max of `' . METRIC_REALTIME_CONNECTIONS . '` over the window.', true)
            ->inject('response')
            ->inject('usageForProject')
            ->inject('usagePolicy')
            ->callback($this->action(...));
    }

    public function action(
        array $metrics,
        array $queries,
        ?string $interval,
        array $dimensions,
        string $startAt,
        string $endAt,
        string $orderBy,
        string $orderDir,
        int $limit,
        int $offset,
        string $aggregate,
        Response $response,
        Tenant $usageForProject,
        Policy $usagePolicy
    ): void {

        $metricsList = $this->resolveMetrics($metrics);
        $filterQueries = $this->parseFilterQueries($queries, static::VALID_FILTER_ATTRIBUTES, static::VALID_FILTER_METHODS);

        $end = $endAt !== '' ? $endAt : \gmdate('Y-m-d H:i:s');
        $defaultWindow = $interval !== null
            ? static::INTERVAL_DEFAULT_WINDOW_SECONDS[$interval]
            : static::DEFAULT_AGGREGATE_WINDOW_SECONDS;
        $start = $startAt !== ''
            ? $startAt
            : \gmdate('Y-m-d H:i:s', \strtotime($end) - $defaultWindow);

        $usagePolicy->assertHistory($startAt);

        if ($interval !== null) {
            $this->assertBucketBudget($start, $end, $interval);
        }

        $filters = array_merge(
            [
                Query::equal('metric', $metricsList),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
            ],
            $filterQueries,
        );

        $multiMetric = count($metricsList) > 1;

        // Pure aggregate (no interval, no dimensions): one latest-snapshot
        // row per requested metric. The per-metric fan-out preserves each
        // series's actual snapshot time rather than substituting the request
        // end time.
        if ($interval === null && empty($dimensions)) {
            $fallbackTime = $this->formatTime($end);
            $byMetric = [];
            foreach ($metricsList as $singleMetric) {
                $singleFilters = $filters;
                $singleFilters[0] = Query::equal('metric', [$singleMetric]);

                if ($aggregate === 'max') {
                    // One flat max() row over the window. There is no single
                    // row to attribute it to, so the window
                    // end stands in as the time.
                    $sampleQueries = array_merge($singleFilters, [
                        Query::orderDesc('time'),
                        Query::limit(1),
                    ]);
                    $samples = $usageForProject->find($sampleQueries, Usage::TYPE_GAUGE);
                    $seed = $this->levelAt($usageForProject, $singleMetric, $start, $filterQueries);
                    if (!isset($samples[0]) && $seed === null) {
                        $byMetric[$singleMetric] = [];
                        continue;
                    }

                    $maxQueries = array_merge($singleFilters, [UsageQuery::aggregate('max')]);
                    $rows = $usageForProject->find($maxQueries, Usage::TYPE_GAUGE);
                    $value = isset($rows[0]['value'])
                        ? (float) $rows[0]['value']
                        : (float) ($samples[0]['value'] ?? $seed);
                    if ($seed !== null) {
                        $value = max($value, $seed);
                    }

                    $byMetric[$singleMetric] = [new Document([
                        'value' => $value,
                        'time' => $fallbackTime,
                    ])];

                    continue;
                }

                $latestQueries = array_merge($singleFilters, [
                    Query::orderDesc('time'),
                    Query::limit(1),
                ]);
                $latest = $usageForProject->find($latestQueries, Usage::TYPE_GAUGE);
                if (!isset($latest[0])) {
                    $byMetric[$singleMetric] = [];
                    continue;
                }

                $value = isset($latest[0]['value']) ? (float) $latest[0]['value'] : 0.0;
                $time = isset($latest[0]['time'])
                    ? (string) $latest[0]['time']
                    : $fallbackTime;
                $byMetric[$singleMetric] = [new Document(['value' => $value, 'time' => $time])];
            }
        } else {
            $queries = $filters;
            if ($interval !== null) {
                $queries[] = UsageQuery::groupByInterval('time', $interval);
            }
            foreach ($dimensions as $dimension) {
                $queries[] = UsageQuery::groupBy($dimension);
            }
            if ($aggregate === 'max') {
                $queries[] = UsageQuery::aggregate('max');
            }

            // A fold needs every bucket, so pagination cannot go into the
            // query feeding it - it applies to the folded result instead.
            $foldsLevel = $aggregate === 'max' && $interval !== null && empty($dimensions);

            $effectiveOrderBy = ($interval === null && $orderBy === 'time') ? 'value' : $orderBy;
            $queries[] = $orderDir === 'desc'
                ? Query::orderDesc($effectiveOrderBy)
                : Query::orderAsc($effectiveOrderBy);

            $queries[] = Query::limit($foldsLevel ? static::MAX_BUCKETS : $limit);

            if (!$foldsLevel && $offset > 0) {
                $queries[] = Query::offset($offset);
            }

            $rows = $usageForProject->find($queries, Usage::TYPE_GAUGE);

            $fallbackTime = $this->formatTime($end);
            $byMetric = [];
            foreach ($metricsList as $m) {
                $byMetric[$m] = [];
            }
            foreach ($rows as $row) {
                $key = $multiMetric ? (string) ($row['metric'] ?? '') : $metricsList[0];
                if (!isset($byMetric[$key])) {
                    $byMetric[$key] = [];
                }
                $group = ['value' => (float) ($row['value'] ?? 0)];

                // A flat dimensioned aggregate has no per-group snapshot time,
                // so the window end stands in — the same substitution the pure
                // `max` aggregate above makes.
                $group['time'] = $interval !== null
                    ? ($row['time'] ?? '')
                    : $fallbackTime;

                foreach ($dimensions as $dimension) {
                    $group[$dimension] = (string) ($row[$dimension] ?? '');
                }

                $byMetric[$key][] = new Document($group);
            }

            // Maxima alone miss the level each bucket inherited; only `max`
            // needs this, and a dimensioned break-down has no single series.
            if ($foldsLevel) {
                foreach ($metricsList as $m) {
                    $folded = $this->foldLevelThroughBuckets(
                        $usageForProject,
                        $m,
                        $start,
                        $end,
                        $interval,
                        $filterQueries,
                        $byMetric[$m] ?? [],
                    );

                    // Folding walks forward; honour orderDir, then page.
                    if ($orderDir === 'desc') {
                        $folded = array_reverse($folded);
                    }

                    $byMetric[$m] = array_slice($folded, $offset, $limit);
                }
            }
        }

        $series = [];
        foreach ($metricsList as $m) {
            $series[] = new Document([
                'metric' => $m,
                'points' => $byMetric[$m] ?? [],
            ]);
        }

        $response->dynamic(new Document([
            'interval' => $interval ?? '',
            'metrics' => $series,
        ]), Response::MODEL_USAGE_GAUGE_LIST);
    }

    /**
     * Turn per-bucket maxima into per-bucket peaks.
     *
     * A bucket's peak is the higher of the level it inherited and its own
     * samples. What it inherits is the level the previous bucket *ended* at -
     * the gauge default, `argMax(value, time)` - so a second pass supplies
     * those last readings and the window is walked in order:
     *
     *   peak(b)  = max(level, maxSample(b))
     *   level(b) = lastSample(b) ?? level
     *
     * seeded from the level at or before the window start. Carrying the running
     * *maximum* instead would leave a series that can never fall.
     *
     * @param array<int, Query> $filterQueries
     * @param array<int, Document> $maxPoints Per-bucket maxima, from the caller's query.
     * @return array<int, Document>
     */
    protected function foldLevelThroughBuckets(
        Tenant $usageForProject,
        string $metric,
        string $start,
        string $end,
        string $interval,
        array $filterQueries,
        array $maxPoints,
    ): array {
        $step = static::INTERVAL_SECONDS[$interval] ?? null;
        if ($step === null) {
            return $maxPoints;
        }

        $lastQueries = array_merge(
            [
                Query::equal('metric', [$metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
            ],
            $filterQueries,
            [
                UsageQuery::groupByInterval('time', $interval),
                Query::orderAsc('time'),
                Query::limit(static::MAX_BUCKETS),
            ],
        );

        $maxByBucket = $this->indexByBucket($maxPoints);
        $lastByBucket = $this->indexByBucket(
            $usageForProject->find($lastQueries, Usage::TYPE_GAUGE)
        );

        $level = $this->levelAt($usageForProject, $metric, $start, $filterQueries);

        $first = (int) \strtotime($start);
        $until = (int) \strtotime($end);
        $cursor = \intdiv($first, $step) * $step;

        $points = [];
        // assertBucketBudget() admits a window by ceil(range / step), but an
        // inclusive walk over an exactly-aligned window visits one more start
        // than that - both boundaries land on a bucket. Cap one above the
        // budget so an admitted request keeps its newest bucket instead of
        // having it silently dropped here.
        $maxPoints = static::MAX_BUCKETS + 1;

        while ($cursor <= $until && count($points) < $maxPoints) {
            $hasMaximum = array_key_exists($cursor, $maxByBucket);
            $hasLast = array_key_exists($cursor, $lastByBucket);
            if ($level === null && !$hasMaximum && !$hasLast) {
                $cursor += $step;
                continue;
            }

            $peak = $level ?? ($hasMaximum ? $maxByBucket[$cursor] : $lastByBucket[$cursor]);
            if ($hasMaximum) {
                $peak = max($peak, $maxByBucket[$cursor]);
            }

            $points[] = new Document([
                'value' => $peak,
                'time' => \gmdate('Y-m-d\TH:i:s', $cursor) . '+00:00',
            ]);

            // No sample means the level simply stood still.
            if ($hasLast) {
                $level = $lastByBucket[$cursor];
            }
            $cursor += $step;
        }

        return $points;
    }

    /**
     * Index bucketed points by their bucket-start timestamp.
     *
     * @param array<int, Document|\ArrayObject<string, mixed>> $points
     * @return array<int, float>
     */
    protected function indexByBucket(array $points): array
    {
        $indexed = [];
        foreach ($points as $point) {
            $time = (string) ($point['time'] ?? '');
            if ($time === '') {
                continue;
            }
            $indexed[(int) \strtotime($time)] = (float) ($point['value'] ?? 0);
        }

        return $indexed;
    }

    /**
     * The level a gauge series already stood at when the window opened - the
     * newest row at or before the start. One indexed lookup, not a scan of the
     * deltas it was folded from.
     *
     * @param array<int, Query> $filterQueries
     */
    protected function levelAt(Tenant $usageForProject, string $metric, string $start, array $filterQueries): ?float
    {
        $queries = array_merge(
            [
                Query::equal('metric', [$metric]),
                Query::lessThan('time', $start),
            ],
            $filterQueries,
            [
                Query::orderDesc('time'),
                Query::limit(1),
            ],
        );

        $rows = $usageForProject->find($queries, Usage::TYPE_GAUGE);

        return isset($rows[0]['value']) ? (float) $rows[0]['value'] : null;
    }

    /**
     * @param array<int, string> $metrics
     * @return array<int, string>
     * @throws Exception
     */
    protected function resolveMetrics(array $metrics): array
    {
        $resolved = array_values(array_unique(array_filter($metrics, static fn ($m) => $m !== '')));

        if (empty($resolved)) {
            throw new Exception(
                Exception::GENERAL_ARGUMENT_INVALID,
                '`metrics[]` must contain at least one metric name.'
            );
        }

        return $resolved;
    }
}
