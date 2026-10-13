<?php

namespace Tests\Compat\Usage;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use ReflectionMethod;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Usage\Accumulator;
use Utopia\Usage\Adapter as UsageAdapter;
use Utopia\Usage\Adapter\ClickHouse;
use Utopia\Usage\Adapter\Database as DatabaseAdapter;
use Utopia\Usage\Metric;
use Utopia\Usage\Sample;
use Utopia\Usage\SampleGap;
use Utopia\Usage\SampleRange;
use Utopia\Usage\SampleResult;
use Utopia\Usage\SampleWatermark;
use Utopia\Usage\Tenant;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

/**
 * An adapter recording every call (AccumulatorTest's RecordingAdapter and
 * TenantTest's TenantRecordingAdapter in one): addBatch() succeeds or
 * fails as told, reads return nothing.
 */
final class Recording extends UsageAdapter
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public bool $succeed = true;

    public function getName(): string
    {
        return 'recording';
    }

    public function healthCheck(): array
    {
        return ['healthy' => true];
    }

    public function setup(): void
    {
    }

    public function addBatch(array $metrics, string $type, int $batchSize = 1000): bool
    {
        $this->calls[] = ['method' => 'addBatch', 'type' => $type, 'batchSize' => $batchSize, 'metrics' => array_map(Adapter::entry(...), array_values($metrics)), 'succeed' => $this->succeed];

        return $this->succeed;
    }

    public function getTimeSeries(string $tenant, array $metrics, string $interval, string $startDate, string $endDate, array $queries = [], bool $zeroFill = true, ?string $type = null): array
    {
        $this->calls[] = ['method' => 'getTimeSeries', 'tenant' => $tenant];

        return [];
    }

    public function getTotal(string $tenant, string $metric, array $queries = [], ?string $type = null): int
    {
        $this->calls[] = ['method' => 'getTotal', 'tenant' => $tenant];

        return 0;
    }

    public function getTotalBatch(string $tenant, array $metrics, array $queries = [], ?string $type = null): array
    {
        $this->calls[] = ['method' => 'getTotalBatch', 'tenant' => $tenant];

        return [];
    }

    public function purge(string $tenant, array $queries = [], ?string $type = null): bool
    {
        $this->calls[] = ['method' => 'purge', 'tenant' => $tenant];

        return true;
    }

    public function find(string $tenant, array $queries = [], ?string $type = null): array
    {
        $this->calls[] = ['method' => 'find', 'tenant' => $tenant];

        return [];
    }

    public function count(string $tenant, array $queries = [], ?string $type = null, ?int $max = null): int
    {
        $this->calls[] = ['method' => 'count', 'tenant' => $tenant];

        return 0;
    }

    public function sum(string $tenant, array $queries = [], string $attribute = 'value', string $type = Usage::TYPE_EVENT): int
    {
        $this->calls[] = ['method' => 'sum', 'tenant' => $tenant];

        return 0;
    }

    public function findDaily(string $tenant, array $queries = []): array
    {
        $this->calls[] = ['method' => 'findDaily', 'tenant' => $tenant];

        return [];
    }

    public function sumDaily(string $tenant, array $queries = [], string $attribute = 'value'): int
    {
        $this->calls[] = ['method' => 'sumDaily', 'tenant' => $tenant];

        return 0;
    }

    public function sumDailyBatch(string $tenant, array $metrics, array $queries = []): array
    {
        $this->calls[] = ['method' => 'sumDailyBatch', 'tenant' => $tenant];

        return [];
    }
}

/**
 * Operations of tests/compat/usage/spec.json on utopia-php/usage.
 *
 * Values: `{"$time": "<modifier>", "format"?: "<DateTime::format>", "midnight"?: true}`
 * anywhere in the arguments is the string of `new DateTime($modifier, UTC)`
 * (at midnight), `{"$datetime": "<string>"}` a `DateTime`, and
 * `{"$metric": {...}}` a `Metric`. Queries are `{method, attribute, values}`.
 */
final class Adapter implements Base, Benchmarks
{
    public function operations(): array
    {
        $ops = [
            'clickhouse.new' => fn (array $a, Session $s) => $s->handle(self::clickhouse($a, $s)),
            'database.new' => fn (array $a, Session $s) => $s->handle(self::database($a, $s)),
            'recording.new' => function (array $a, Session $s) {
                $recording = new Recording();
                $recording->succeed = (bool) ($a['succeed'] ?? true);

                return $s->handle($recording);
            },
            'recording.succeed' => function (array $a, Session $s) {
                self::recording($a, $s)->succeed = (bool) $a['succeed'];

                return true;
            },
            'recording.calls' => function (array $a, Session $s) {
                $recording = self::recording($a, $s);
                $calls = $recording->calls;
                $recording->calls = [];

                return $calls;
            },
            'usage.new' => fn (array $a, Session $s) => $s->handle(new Usage(self::adapter($a['adapter'], $s))),
            'usage.adapter' => fn (array $a, Session $s) => self::usage($a, $s)->getAdapter()->getName(),
            'usage.health_check' => fn (array $a, Session $s) => self::usage($a, $s)->healthCheck(),
            'usage.setup' => function (array $a, Session $s) {
                self::usage($a, $s)->setup();

                return null;
            },
            'usage.add_samples' => fn (array $a, Session $s) => self::usage($a, $s)->addSamples(array_map(self::sample(...), $a['samples']), $a['batch_size'] ?? 1000),
            'usage.sample_watermark' => function (array $a, Session $s) {
                $watermark = self::usage($a, $s)->getSampleWatermark(self::range($a['range']), $a['limit']);

                return self::watermark($watermark, $s);
            },
            'usage.find_samples' => fn (array $a, Session $s) => self::result(self::usage($a, $s)->findSamples(self::range($a['range']), self::watermarkOf($a, $s), $a['limit'])),
            'usage.find_across_tenants' => fn (array $a, Session $s) => self::metrics(self::usage($a, $s)->findAcrossTenants(self::queries($a), $a['type'] ?? null)),
            'usage.backfill_daily' => function (array $a, Session $s) {
                self::usage($a, $s)->backfillDaily(self::value($a['from']), self::value($a['to']), $a['force'] ?? false);

                return null;
            },
            'tenant.new' => fn (array $a, Session $s) => $s->handle(new Tenant(self::usage($a, $s), $a['tenant'])),
            'clickhouse.route_log' => fn (array $a, Session $s) => self::ch($a, $s)->getRouteLog(),
            'clickhouse.clear_route_log' => function (array $a, Session $s) {
                self::ch($a, $s)->clearRouteLog();

                return true;
            },
            'clickhouse.next_query_id' => function (array $a, Session $s) {
                self::ch($a, $s)->setNextQueryId($a['id'] ?? null);

                return true;
            },
            'clickhouse.stats' => fn (array $a, Session $s) => self::ch($a, $s)->getConnectionStats(),
            'clickhouse.query' => function (array $a, Session $s) {
                $body = (string) new ReflectionMethod(ClickHouse::class, 'query')->invoke(self::ch($a, $s), self::value($a['sql']), self::value($a['params'] ?? []));
                $json = json_decode($body, true);

                return \is_array($json) && \is_array($json['data'] ?? null) ? $json['data'] : $body;
            },
            'clickhouse.column_type' => function (array $a, Session $s) {
                $adapter = new ClickHouse('clickhouse', 'default', '', 8123, false, namespace: 'utopia_usage_coltype');

                return new ReflectionMethod(ClickHouse::class, 'getColumnType')->invoke($adapter, $a['id'], $a['type'] ?? 'event');
            },
            'adapter.schema' => function (array $a, Session $s) {
                $adapter = self::adapter($a['adapter'], $s);
                $result = $adapter->{$a['method']}(...(isset($a['type']) ? [$a['type']] : []));

                return \is_array($result) ? array_map(static fn ($v) => $v instanceof \Utopia\Database\Document ? $v->getArrayCopy() : $v, $result) : $result;
            },
            'metric.new' => fn (array $a) => new Metric(self::value($a['attributes'] ?? []))->getArrayCopy(),
            'metric.call' => function (array $a) {
                $metric = new Metric(self::value($a['attributes'] ?? []));
                $result = $metric->{$a['method']}(...self::value($a['args'] ?? []));

                return ['result' => $result instanceof Metric ? '$this' : $result, 'attributes' => $metric->getArrayCopy()];
            },
            'metric.schema' => fn (array $a) => match ($a['kind'] ?? 'any') {
                'event' => Metric::getEventSchema(),
                'gauge' => Metric::getGaugeSchema(),
                default => Metric::getSchema(),
            },
            'metric.indexes' => fn (array $a) => match ($a['kind'] ?? 'any') {
                'event' => Metric::getEventIndexes(),
                'gauge' => Metric::getGaugeIndexes(),
                default => Metric::getIndexes(),
            },
            'metric.columns' => fn () => ['event' => Metric::EVENT_COLUMNS, 'gauge' => Metric::GAUGE_COLUMNS],
            'metric.extract_columns' => fn (array $a) => Metric::extractColumns(self::value($a['tags']), $a['type']),
            'metric.validate' => function (array $a) {
                Metric::validate(self::value($a['data']), $a['type'] ?? 'event');

                return null;
            },
            'query.group_by_interval' => fn (array $a) => self::query(UsageQuery::groupByInterval($a['attribute'], $a['interval'])),
            'query.group_by' => fn (array $a) => self::query(UsageQuery::groupBy($a['attributes'])),
            'query.aggregate' => fn (array $a) => self::query(UsageQuery::aggregate($a['function'])),
            'query.extract_aggregate' => fn (array $a) => UsageQuery::extractAggregate(self::queries($a)),
            'query.constants' => fn () => ['intervals' => UsageQuery::VALID_INTERVALS, 'aggregates' => UsageQuery::VALID_AGGREGATES],
            'sample.new' => fn (array $a) => self::encodeSample(self::sample($a)),
            'range.new' => function (array $a) {
                $range = self::range($a);

                return ['start' => $range->getFormattedIntervalStart(), 'end' => $range->getFormattedIntervalEnd()];
            },
            'watermark.new' => fn (array $a, Session $s) => self::watermark(new SampleWatermark(self::range($a['range']), $a['entries'], $a['truncated'] ?? false), $s),
            'watermark.matches' => fn (array $a, Session $s) => self::watermarkOf($a, $s)->matches(self::range($a['range'])),
            'gap.new' => function (array $a) {
                $gap = new SampleGap($a['first'], $a['last']);

                return ['first' => $gap->first, 'last' => $gap->last];
            },
            'accumulator.new' => fn (array $a, Session $s) => $s->handle(new Accumulator(self::usage($a, $s))),
            'accumulator.collect' => function (array $a, Session $s) {
                $result = self::accumulator($a, $s)->collect(
                    $a['tenant'],
                    $a['metric'],
                    $a['value'],
                    $a['type'],
                    self::value($a['tags'] ?? []),
                    isset($a['time']) ? self::value($a['time']) : null,
                    $a['allow_negative'] ?? false,
                );

                return $result instanceof Accumulator;
            },
            'accumulator.flush' => fn (array $a, Session $s) => self::accumulator($a, $s)->flush(),
            'accumulator.count' => fn (array $a, Session $s) => self::accumulator($a, $s)->count(),
            'accumulator.elapsed' => fn (array $a, Session $s) => self::accumulator($a, $s)->elapsedSeconds(),
        ];

        // The reads and writes Usage and Tenant share: `usage.*` on a Usage
        // (tenant as an argument), `tenant.*` on a Tenant (bound).
        foreach (['usage' => true, 'tenant' => false] as $prefix => $explicit) {
            $target = fn (array $a, Session $s) => $explicit ? self::usage($a, $s) : self::tenant($a, $s);
            $tenant = fn (array $a) => $explicit ? [$a['tenant']] : [];
            $ops["{$prefix}.add_batch"] = fn (array $a, Session $s) => $target($a, $s)->addBatch(self::value($a['metrics']), $a['type'], $a['batch_size'] ?? 1000);
            $ops["{$prefix}.time_series"] = fn (array $a, Session $s) => $target($a, $s)->getTimeSeries(...$tenant($a), ...[$a['metrics'], $a['interval'], self::value($a['start']), self::value($a['end']), self::queries($a), $a['zero_fill'] ?? true, $a['type'] ?? null]);
            $ops["{$prefix}.total"] = fn (array $a, Session $s) => $target($a, $s)->getTotal(...$tenant($a), ...[$a['metric'], self::queries($a), $a['type'] ?? null]);
            $ops["{$prefix}.total_batch"] = fn (array $a, Session $s) => $target($a, $s)->getTotalBatch(...$tenant($a), ...[$a['metrics'], self::queries($a), $a['type'] ?? null]);
            $ops["{$prefix}.purge"] = fn (array $a, Session $s) => $target($a, $s)->purge(...$tenant($a), ...[self::queries($a), $a['type'] ?? null]);
            $ops["{$prefix}.find"] = fn (array $a, Session $s) => self::metrics($target($a, $s)->find(...$tenant($a), ...[self::queries($a), $a['type'] ?? null]));
            $ops["{$prefix}.count"] = fn (array $a, Session $s) => $target($a, $s)->count(...$tenant($a), ...[self::queries($a), $a['type'] ?? null, $a['max'] ?? null]);
            $ops["{$prefix}.sum"] = fn (array $a, Session $s) => $target($a, $s)->sum(...$tenant($a), ...[self::queries($a), $a['attribute'] ?? 'value', $a['type'] ?? Usage::TYPE_EVENT]);
            $ops["{$prefix}.find_daily"] = fn (array $a, Session $s) => self::metrics($target($a, $s)->findDaily(...$tenant($a), ...[self::queries($a)]));
            $ops["{$prefix}.sum_daily"] = fn (array $a, Session $s) => $target($a, $s)->sumDaily(...$tenant($a), ...[self::queries($a), $a['attribute'] ?? 'value']);
            $ops["{$prefix}.sum_daily_batch"] = fn (array $a, Session $s) => $target($a, $s)->sumDailyBatch(...$tenant($a), ...[$a['metrics'], self::queries($a)]);
        }

        return $ops;
    }

    public function benchmarks(): array
    {
        return [
            'accumulator_collect' => ['Fold 100 tagged events and gauges into an accumulator (the worker hot path)', function (Session $s) {
                $accumulator = new Accumulator(new Usage(new Recording()));

                return function () use ($accumulator) {
                    for ($i = 0; $i < 100; $i++) {
                        $accumulator->collect('42', 'bandwidth', 1024, Usage::TYPE_EVENT, ['service' => 'storage', 'resourceId' => 'b' . ($i % 10)]);
                        $accumulator->collect('42', 'storage', $i, Usage::TYPE_GAUGE, ['resourceId' => 'b' . ($i % 10)]);
                    }
                };
            }],
            'extract_columns' => ['Extract the dimension columns of an event row from its tags', function (Session $s) {
                $tags = ['path' => '/v1/storage/files', 'method' => 'POST', 'status' => '201', 'service' => 'storage', 'country' => 'US', 'region' => 'EU-West', 'resourceId' => 'bucket1', 'osName' => 'iOS'];

                return fn () => Metric::extractColumns($tags, Usage::TYPE_EVENT);
            }],
            'sample_payload_hash' => ['Hash a canonical sample: its identity and payload', function (Session $s) {
                $sample = self::sample(self::BENCH_SAMPLE);

                return fn () => $sample->getPayloadHash();
            }],
            'clickhouse_add_batch' => ['Insert 100 events into ClickHouse in one JSONEachRow batch', function (Session $s) {
                $usage = self::benchUsage($s, 'insert');
                $metrics = [];
                for ($i = 0; $i < 100; $i++) {
                    $metrics[] = ['tenant' => '1', 'metric' => 'requests', 'value' => 1, 'tags' => ['service' => 'storage', 'path' => '/v1/storage/files']];
                }

                return fn () => $usage->addBatch($metrics, Usage::TYPE_EVENT);
            }],
            'clickhouse_aggregate' => ['Read an hourly time-bucketed aggregate grouped by service over seeded events', function (Session $s) {
                $usage = self::benchUsage($s, 'aggregate');
                $queries = [UsageQuery::groupByInterval('time', '1h'), UsageQuery::groupBy('service'), Query::equal('metric', ['requests']), Query::greaterThanEqual('time', '2025-01-01 00:00:00'), Query::lessThan('time', '2025-01-02 00:00:00')];

                return fn () => $usage->find('1', $queries, Usage::TYPE_EVENT);
            }],
            'clickhouse_total_batch' => ['Read routed totals of three metrics over a closed window (the daily rollup)', function (Session $s) {
                $usage = self::benchUsage($s, 'totals');
                $queries = [Query::greaterThanEqual('time', '2025-01-01 00:00:00'), Query::lessThan('time', '2025-01-02 00:00:00')];

                return fn () => $usage->getTotalBatch('1', ['requests', 'bandwidth', 'executions'], $queries, Usage::TYPE_EVENT);
            }],
        ];
    }

    private const BENCH_SAMPLE = [
        'environment' => 'production', 'region' => 'fra1', 'projectInternalId' => '101', 'databaseInternalId' => '202',
        'member' => 'mysql-0', 'generation' => 'generation-1', 'sequence' => 7, 'metric' => 'bandwidth.inbound',
        'intervalStart' => '2026-08-01T00:00:00Z', 'intervalEnd' => '2026-08-01T00:01:00Z', 'value' => 42, 'eventVersion' => 1,
    ];

    /**
     * A Usage on ClickHouse for a benchmark: tables in database `<ns>_bench`,
     * seeded with a day of events across services.
     */
    private static function benchUsage(Session $s, string $name): Usage
    {
        $adapter = self::clickhouse(['database' => $s->ns . '_bench', 'namespace' => $name, 'shared_tables' => true], $s);
        $usage = new Usage($adapter);
        $usage->setup();
        $usage->purge('1');
        $metrics = [];
        foreach (['requests', 'bandwidth', 'executions'] as $metric) {
            for ($i = 0; $i < 240; $i++) {
                $metrics[] = ['tenant' => '1', 'metric' => $metric, 'value' => $i, 'time' => sprintf('2025-01-01 %02d:%02d:00', intdiv($i, 10), ($i % 10) * 6), 'tags' => ['service' => ['storage', 'databases', 'functions'][$i % 3]]];
            }
        }
        $usage->addBatch($metrics, Usage::TYPE_EVENT);
        $adapter->backfillDaily('2025-01-01 00:00:00', '2025-01-02 00:00:00', true);

        return $usage;
    }

    /**
     * A ClickHouse adapter on service `usage-clickhouse` (`http://user:password@host:port`);
     * `host`, `port`, `username` and `password` override it.
     *
     * @param array<string, mixed> $a
     */
    private static function clickhouse(array $a, Session $s): ClickHouse
    {
        $url = parse_url($s->service('usage-clickhouse'));

        return new ClickHouse(
            $a['host'] ?? ($url['host'] ?? ''),
            $a['username'] ?? urldecode($url['user'] ?? 'default'),
            $a['password'] ?? urldecode($url['pass'] ?? ''),
            $a['port'] ?? ($url['port'] ?? 8123),
            $a['secure'] ?? false,
            namespace: $a['namespace'] ?? '',
            database: $a['database'] ?? 'default',
            sharedTables: $a['shared_tables'] ?? false,
            asyncInserts: $a['async_inserts'] ?? false,
            asyncInsertWait: $a['async_insert_wait'] ?? true,
            dualReadSampleRate: $a['dual_read_sample_rate'] ?? 0.0,
            retention: $a['retention'] ?? null,
        );
    }

    /**
     * A Database adapter on a MariaDB database (service `database-mariadb`),
     * with no cache; `create` creates the database first.
     *
     * @param array<string, mixed> $a
     */
    private static function database(array $a, Session $s): DatabaseAdapter
    {
        $url = parse_url($s->service('database-mariadb'));
        $dsn = 'mysql:host=' . ($url['host'] ?? '') . ';port=' . ($url['port'] ?? 3306) . ';charset=utf8mb4';
        $pdo = new PDO($dsn, urldecode($url['user'] ?? ''), urldecode($url['pass'] ?? ''), MariaDB::getPdoAttributes());
        $database = new Database(new MariaDB($pdo), new Cache(new NoCache()));
        $database->setDatabase($a['database']);
        $database->setNamespace($a['namespace'] ?? 'utopia_usage');
        if ($a['create'] ?? true) {
            $database->create();
        }

        return new DatabaseAdapter($database);
    }

    private static function adapter(mixed $handle, Session $s): UsageAdapter
    {
        $adapter = $s->get($handle);

        return $adapter instanceof UsageAdapter ? $adapter : throw new Fault('not a usage adapter');
    }

    /** @param array<string, mixed> $a */
    private static function recording(array $a, Session $s): Recording
    {
        $adapter = $s->get($a['adapter']);

        return $adapter instanceof Recording ? $adapter : throw new Fault('not a recording adapter');
    }

    /** @param array<string, mixed> $a */
    private static function ch(array $a, Session $s): ClickHouse
    {
        $adapter = $s->get($a['adapter']);

        return $adapter instanceof ClickHouse ? $adapter : throw new Fault('not a ClickHouse adapter');
    }

    /** @param array<string, mixed> $a */
    private static function usage(array $a, Session $s): Usage
    {
        $usage = $s->get($a['usage']);

        return $usage instanceof Usage ? $usage : throw new Fault('not a usage');
    }

    /** @param array<string, mixed> $a */
    private static function tenant(array $a, Session $s): Tenant
    {
        $tenant = $s->get($a['view']);

        return $tenant instanceof Tenant ? $tenant : throw new Fault('not a tenant view');
    }

    /** @param array<string, mixed> $a */
    private static function accumulator(array $a, Session $s): Accumulator
    {
        $accumulator = $s->get($a['accumulator']);

        return $accumulator instanceof Accumulator ? $accumulator : throw new Fault('not an accumulator');
    }

    /**
     * Decodes the tagged values of the arguments.
     */
    private static function value(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return [];
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (\array_key_exists('$time', $value)) {
            $time = new DateTime($value['$time'], new DateTimeZone('UTC'));
            if ($value['midnight'] ?? false) {
                $time->setTime(0, 0, 0);
            }

            return $time->format($value['format'] ?? 'Y-m-d H:i:s');
        }
        if (\array_key_exists('$datetime', $value)) {
            return new DateTime($value['$datetime']);
        }
        if (\array_key_exists('$metric', $value)) {
            return new Metric(self::value($value['$metric']));
        }

        return array_map(self::value(...), $value);
    }

    /**
     * @param array<string, mixed> $a
     * @return list<Query>
     */
    private static function queries(array $a): array
    {
        return array_map(
            static fn (array $q) => new Query(Method::from($q['method']), $q['attribute'] ?? '', self::value($q['values'] ?? [])),
            array_values($a['queries'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    private static function query(Query $query): array
    {
        return ['class' => $query::class, 'method' => $query->getMethod()->value, 'attribute' => $query->getAttribute(), 'values' => $query->getValues()];
    }

    /**
     * @param array<Metric> $metrics
     * @return list<array<string, mixed>>
     */
    private static function metrics(array $metrics): array
    {
        return array_map(static fn (Metric $m) => $m->getArrayCopy(), array_values($metrics));
    }

    /**
     * A recorded addBatch() entry, as one shape whoever built it.
     *
     * @param array<string, mixed> $metric
     * @return array<string, mixed>
     */
    public static function entry(array $metric): array
    {
        $entry = [
            'tenant' => $metric['tenant'] ?? null,
            'metric' => $metric['metric'],
            'value' => $metric['value'],
            'tags' => $metric['tags'] ?? [],
            'allowNegative' => (bool) ($metric['allowNegative'] ?? false),
        ];
        if (isset($metric['time'])) {
            $entry['time'] = $metric['time'] instanceof \DateTimeInterface ? $metric['time']->format('Y-m-d\TH:i:s.uP') : $metric['time'];
        }

        return $entry;
    }

    /** @param array<string, mixed> $a */
    private static function sample(array $a): Sample
    {
        return new Sample(
            environment: $a['environment'],
            region: $a['region'],
            projectInternalId: $a['projectInternalId'],
            databaseInternalId: $a['databaseInternalId'],
            member: $a['member'],
            generation: $a['generation'],
            sequence: $a['sequence'],
            metric: $a['metric'],
            intervalStart: new DateTimeImmutable(self::value($a['intervalStart'])),
            intervalEnd: new DateTimeImmutable(self::value($a['intervalEnd'])),
            value: $a['value'],
            eventVersion: $a['eventVersion'],
        );
    }

    /** @param array<string, mixed> $a */
    private static function range(array $a): SampleRange
    {
        return new SampleRange(
            environment: $a['environment'],
            region: $a['region'],
            projectInternalId: $a['projectInternalId'],
            databaseInternalId: $a['databaseInternalId'],
            member: $a['member'],
            generation: $a['generation'],
            metric: $a['metric'],
            firstSequence: $a['firstSequence'],
            lastSequence: $a['lastSequence'],
            intervalStart: new DateTimeImmutable(self::value($a['intervalStart'])),
            intervalEnd: new DateTimeImmutable(self::value($a['intervalEnd'])),
        );
    }

    /** @return array<string, mixed> */
    private static function encodeSample(Sample $sample): array
    {
        return [
            'environment' => $sample->environment,
            'region' => $sample->region,
            'projectInternalId' => $sample->projectInternalId,
            'databaseInternalId' => $sample->databaseInternalId,
            'member' => $sample->member,
            'generation' => $sample->generation,
            'sequence' => $sample->sequence,
            'metric' => $sample->metric,
            'intervalStart' => $sample->getFormattedIntervalStart(),
            'intervalEnd' => $sample->getFormattedIntervalEnd(),
            'value' => $sample->value,
            'eventVersion' => $sample->eventVersion,
            'id' => $sample->getId(),
            'payloadHash' => $sample->getPayloadHash(),
        ];
    }

    /** @return array<string, mixed> */
    private static function watermark(SampleWatermark $watermark, Session $s): array
    {
        return ['watermark' => $s->handle($watermark), 'entries' => $watermark->getEntries(), 'truncated' => $watermark->isTruncated()];
    }

    /** @param array<string, mixed> $a */
    private static function watermarkOf(array $a, Session $s): SampleWatermark
    {
        $watermark = $s->get($a['watermark']);

        return $watermark instanceof SampleWatermark ? $watermark : throw new Fault('not a watermark');
    }

    /** @return array<string, mixed> */
    private static function result(SampleResult $result): array
    {
        return [
            'samples' => array_map(self::encodeSample(...), $result->getSamples()),
            'conflicts' => $result->getConflicts(),
            'gaps' => array_map(static fn (SampleGap $g) => ['first' => $g->first, 'last' => $g->last], $result->getGaps()),
            'discontinuities' => $result->getDiscontinuities(),
            'duplicateCount' => $result->getDuplicateCount(),
            'truncated' => $result->isTruncated(),
            'complete' => $result->isComplete(),
            'watermark' => ['entries' => $result->getWatermark()->getEntries(), 'truncated' => $result->getWatermark()->isTruncated()],
        ];
    }
}
