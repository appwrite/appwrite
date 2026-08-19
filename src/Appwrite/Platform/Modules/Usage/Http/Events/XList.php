<?php

namespace Appwrite\Platform\Modules\Usage\Http\Events;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Usage\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Platform\Enum;
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
    private const VALID_INTERVALS = ['1m', '15m', '30m', '1h', '1d'];

    private const VALID_DIMENSIONS = [
        'path', 'method', 'status', 'service', 'resourceType',
        'country', 'region', 'hostname', 'ip',
        'osName', 'clientType', 'clientName', 'deviceName',
        'sdk', 'sdkVersion',
        'resourceId',
    ];

    private const VALID_ORDER_BY = ['time', 'value'];

    private const VALID_ORDER_DIRS = ['asc', 'desc'];

    private const DEFAULT_AGGREGATE_WINDOW_SECONDS = 7 * 86400;

    /**
     * Attributes that may be used as filter targets in `queries[]`. Subset
     * of EVENT_COLUMNS in utopia-php/usage; excludes high-cardinality fields
     * (osVersion, clientVersion, deviceBrand …) where dimension-by use is
     * preferred over equality filters.
     */
    private const VALID_FILTER_ATTRIBUTES = [
        'path', 'method', 'status', 'service', 'resourceType', 'resourceId',
        'country', 'region', 'hostname', 'ip',
        'osName', 'clientType', 'clientName', 'deviceName',
        'sdk', 'sdkVersion',
    ];

    /**
     * Query::TYPE_* values supported on the filter surface.
     * Equality / set membership / null-presence on dimension columns and
     * string prefix-match on path-shaped fields are useful; we deliberately
     * skip numeric inequality and full-text search since time range has its
     * own dedicated startAt/endAt params and ClickHouse doesn't fulltext
     * these LowCardinality(String) columns.
     */
    private const VALID_FILTER_METHODS = [
        Query::TYPE_EQUAL,
        Query::TYPE_NOT_EQUAL,
        Query::TYPE_CONTAINS,
        Query::TYPE_STARTS_WITH,
        Query::TYPE_ENDS_WITH,
        Query::TYPE_IS_NULL,
        Query::TYPE_IS_NOT_NULL,
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
            ->param('queries', [], new ArrayList(new Text(4096), 10), 'Up to 10 filter queries in Utopia syntax. Allowed attributes, also published as the `UsageEventDimension` enum: ' . implode(', ', self::VALID_FILTER_ATTRIBUTES) . '. Allowed methods: equal, notEqual, contains, startsWith, endsWith, isNull, isNotNull. Example: `queries[]=equal("resourceType", ["bucket"])`.', true)
            ->param('interval', null, new Nullable(new WhiteList(self::VALID_INTERVALS)), 'Time interval size. Omit (null) for a flat aggregate over the whole window. Allowed: ' . implode(', ', self::VALID_INTERVALS) . '.', true, enum: new Enum(
                name: 'UsageInterval',
                map: parent::INTERVAL_ENUM_KEYS
            ))
            ->param('dimensions', [], new ArrayList(new WhiteList(self::VALID_DIMENSIONS, true), 10), 'Break-down dimensions (max 10). Allowed: ' . implode(', ', self::VALID_DIMENSIONS) . '.', true, enum: new Enum(name: 'UsageEventDimension'))
            ->param('startAt', '', new DatetimeValidator(), 'Range start in ISO 8601. Defaults adapt to interval (7d for the no-interval aggregate).', true)
            ->param('endAt', '', new DatetimeValidator(), 'Range end in ISO 8601. Defaults to the current time.', true)
            ->param('orderBy', 'time', new WhiteList(self::VALID_ORDER_BY), 'Column to order by. Allowed: time, value. Default time when an interval is set; otherwise value.', true, enum: new Enum(name: 'UsageOrderBy'))
            ->param('orderDir', 'desc', new WhiteList(self::VALID_ORDER_DIRS), 'Sort direction: asc or desc. Default desc — paired with the default limit, returns the most recent / highest-value groups first.', true, enum: new Enum(name: 'UsageOrderDirection'))
            ->param('limit', parent::DEFAULT_TYPED_LIMIT, new Range(1, parent::MAX_LIMIT), 'Maximum rows to return (1-' . parent::MAX_LIMIT . ').', true)
            ->param('offset', 0, new Range(0, parent::MAX_OFFSET), 'Pagination offset (0-' . parent::MAX_OFFSET . ').', true)
            ->inject('response')
            ->inject('usageForProject')
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
        Tenant $usageForProject
    ): void {

        $metricsList = $this->resolveMetrics($metrics);
        $filterQueries = $this->parseFilterQueries($queries, self::VALID_FILTER_ATTRIBUTES, self::VALID_FILTER_METHODS);

        $end = $endAt !== '' ? $endAt : \gmdate('Y-m-d H:i:s');
        $defaultWindow = $interval !== null
            ? parent::INTERVAL_DEFAULT_WINDOW_SECONDS[$interval]
            : self::DEFAULT_AGGREGATE_WINDOW_SECONDS;
        $start = $startAt !== ''
            ? $startAt
            : \gmdate('Y-m-d H:i:s', \strtotime($end) - $defaultWindow);

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
    private function resolveMetrics(array $metrics): array
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
