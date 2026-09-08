<?php

namespace Appwrite\Platform\Modules\Usage\Http\Events;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Usage\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
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

    protected const VALID_DIMENSIONS = [
        'path', 'method', 'status', 'service', 'resourceType',
        'country', 'region', 'hostname', 'ip',
        'osName', 'clientType', 'clientName', 'deviceName',
        'sdk', 'sdkVersion',
        'resourceId',
    ];

    protected const VALID_ORDER_BY = ['time', 'value'];

    protected const VALID_ORDER_DIRS = ['asc', 'desc'];

    protected const DEFAULT_AGGREGATE_WINDOW_SECONDS = 7 * 86400;

    /**
     * Attributes that may be used as filter targets in `queries[]`. Subset
     * of EVENT_COLUMNS in utopia-php/usage; excludes high-cardinality fields
     * (osVersion, clientVersion, deviceBrand …) where dimension-by use is
     * preferred over equality filters.
     */
    protected const VALID_FILTER_ATTRIBUTES = [
        'path', 'method', 'status', 'service', 'resourceType', 'resourceId',
        'country', 'region', 'hostname', 'ip',
        'osName', 'clientType', 'clientName', 'deviceName',
        'sdk', 'sdkVersion',
    ];

    /**
     * Query methods supported on the filter surface.
     * Equality / set membership / null-presence on dimension columns and
     * string prefix-match on path-shaped fields are useful; we deliberately
     * skip numeric inequality and full-text search since time range has its
     * own dedicated startAt/endAt params and ClickHouse doesn't fulltext
     * these LowCardinality(String) columns.
     */
    protected const VALID_FILTER_METHODS = [
        QueryMethod::Equal,
        QueryMethod::NotEqual,
        QueryMethod::Contains,
        QueryMethod::StartsWith,
        QueryMethod::EndsWith,
        QueryMethod::IsNull,
        QueryMethod::IsNotNull,
    ];

    public static function getName(): string
    {
        return 'listEvents';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/usage/events')
            ->desc('List usage events')
            ->groups(['api', 'usage'])
            ->label('scope', 'usage.read')
            ->label('abuse-limit', 60)
            ->label('abuse-time', 60)
            ->label('abuse-key', 'projectId:{project.$id}')
            ->label('sdk', new Method(
                namespace: 'usage',
                group: 'events',
                name: 'listEvents',
                description: <<<EOT
                Aggregate usage event metrics. `metrics[]` (1-10) is required; the response always contains one entry per requested metric, each with its own `points[]` time series.

                **Two response shapes**:
                - Omit `interval` for a flat top-N table — one point per dimension combination, no time axis. Useful for "top 10 paths by bandwidth in the last 7 days".
                - Pass `interval` (`1m`, `15m`, `30m`, `1h`, `1d`) for a time series — one point per (time bucket × dimension combination).

                `dimensions[]` breaks each point down by one or more attributes. `queries[]` filters the underlying events using standard Utopia query syntax. Pass multiple metrics to render stacked charts in one round-trip. When `startAt` is omitted, the default window adapts to `interval` (or 7d when interval is omitted).
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USAGE_EVENT_LIST,
                    ),
                ],
                // Preview SDK builds show the whole surface, so they do not hide.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') === 'enabled' ? false : ['server'],
            ))
            ->param('metrics', [], new ArrayList(new Text(255), 10), 'One to ten metric names. Single-metric callers pass a one-element array.', false)
            ->param('queries', [], new ArrayList(new Text(4096), 10), 'Up to 10 filter queries in Utopia syntax. Allowed attributes, also published as the `UsageEventDimension` enum: ' . implode(', ', static::VALID_FILTER_ATTRIBUTES) . '. Allowed methods: equal, notEqual, contains, startsWith, endsWith, isNull, isNotNull. Example: `queries[]=equal("resourceType", ["bucket"])`.', true)
            ->param('interval', null, new Nullable(new WhiteList(static::VALID_INTERVALS)), 'Time interval size. Omit (null) for a flat aggregate over the whole window. Allowed: ' . implode(', ', static::VALID_INTERVALS) . '.', true, enum: new Enum(
                name: 'UsageInterval',
                map: static::INTERVAL_ENUM_KEYS
            ))
            ->param('dimensions', [], new ArrayList(new WhiteList(static::VALID_DIMENSIONS, true), 10), 'Break-down dimensions (max 10). Allowed: ' . implode(', ', static::VALID_DIMENSIONS) . '.', true, enum: new Enum(name: 'UsageEventDimension'))
            ->param('startAt', '', new DatetimeValidator(), 'Range start in ISO 8601. Defaults adapt to interval (7d for the no-interval aggregate).', true)
            ->param('endAt', '', new DatetimeValidator(), 'Range end in ISO 8601. Defaults to the current time.', true)
            ->param('orderBy', 'time', new WhiteList(static::VALID_ORDER_BY), 'Column to order by. Allowed: time, value. Default time when an interval is set; otherwise value.', true, enum: new Enum(name: 'UsageOrderBy'))
            ->param('orderDir', 'desc', new WhiteList(static::VALID_ORDER_DIRS), 'Sort direction: asc or desc. Default desc — paired with the default limit, returns the most recent / highest-value groups first.', true, enum: new Enum(name: 'UsageOrderDirection'))
            ->param('limit', parent::DEFAULT_TYPED_LIMIT, new Range(1, parent::MAX_LIMIT), 'Maximum rows to return (1-' . parent::MAX_LIMIT . ').', true)
            ->param('offset', 0, new Range(0, parent::MAX_OFFSET), 'Pagination offset (0-' . parent::MAX_OFFSET . ').', true)
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
        Response $response,
        Tenant $usageForProject,
        Policy $usagePolicy
    ): void {

        $metricsList = $this->resolveMetrics($metrics);
        $usagePolicy->assertMetricAccess($metricsList);
        $filterQueries = $this->parseFilterQueries($queries, static::VALID_FILTER_ATTRIBUTES, static::VALID_FILTER_METHODS);

        $usagePolicy->assertGeoDimensions($dimensions, $filterQueries);

        // Fold country filters the same way the write path folds them, or an
        // uppercase value silently matches nothing.
        foreach ($filterQueries as $query) {
            if ($query->getAttribute() === 'country') {
                $query->setValues(\array_map(
                    static fn (mixed $value): mixed => \is_string($value)
                        ? Context::normalizeCountry($value)
                        : $value,
                    $query->getValues()
                ));
            }
        }

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

        // Pure aggregate (no interval, no dimensions): one sum() row per
        // requested metric.
        if ($interval === null && empty($dimensions)) {
            $aggregateTime = $this->formatTime($end);
            $byMetric = [];
            foreach ($metricsList as $singleMetric) {
                $singleFilters = $filters;
                $singleFilters[0] = Query::equal('metric', [$singleMetric]);
                $value = $usageForProject->sum($singleFilters, 'value', Usage::TYPE_EVENT);
                $byMetric[$singleMetric] = [new Document([
                    'value' => $value,
                    'time' => $aggregateTime,
                ])];
            }
        } else {
            $queries = $filters;
            if ($interval !== null) {
                $queries[] = UsageQuery::groupByInterval('time', $interval);
            }
            foreach ($dimensions as $dimension) {
                $queries[] = UsageQuery::groupBy($dimension);
            }

            // `orderBy=time` only makes sense with time bucketing — without
            // an interval the result has no time axis. Coerce to value so
            // the library doesn't reject it.
            $effectiveOrderBy = ($interval === null && $orderBy === 'time') ? 'value' : $orderBy;
            $queries[] = $orderDir === 'desc'
                ? Query::orderDesc($effectiveOrderBy)
                : Query::orderAsc($effectiveOrderBy);

            $queries[] = Query::limit($limit);

            if ($offset > 0) {
                $queries[] = Query::offset($offset);
            }

            $rows = $usageForProject->find($queries, Usage::TYPE_EVENT);

            $byMetric = [];
            foreach ($metricsList as $m) {
                $byMetric[$m] = [];
            }
            foreach ($rows as $row) {
                $key = $multiMetric ? (string) ($row['metric'] ?? '') : $metricsList[0];
                if (!isset($byMetric[$key])) {
                    $byMetric[$key] = [];
                }
                $group = ['value' => (int) ($row['value'] ?? 0)];

                if ($interval !== null) {
                    $group['time'] = $row['time'] ?? '';
                }

                foreach ($dimensions as $dimension) {
                    $group[$dimension] = (string) ($row[$dimension] ?? '');
                }

                $byMetric[$key][] = new Document($group);
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
        ]), Response::MODEL_USAGE_EVENT_LIST);
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

        // Delta metrics total to the net change over the window, never the
        // concurrency the name implies. Refusing is load-bearing: without it
        // the endpoint silently answers with a net delta.
        if (in_array(METRIC_REALTIME_CONNECTIONS, $resolved, true)) {
            throw new Exception(
                Exception::GENERAL_ARGUMENT_INVALID,
                'Metric `' . METRIC_REALTIME_CONNECTIONS . '` is only available from '
                . 'GET /v1/usage/gauges, which reports the concurrent level. '
                . 'Pair it with `aggregate=max` for the peak over a window.'
            );
        }

        return $resolved;
    }
}
