<?php

namespace Utopia\Tests\Benchmark;

use DateTime;
use DateTimeZone;
use Utopia\Query\Query;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

class EventsBench extends BenchmarkBase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeAll();
        $this->seedEventRows($this->defaultRows);
    }

    public function testBenchmarks(): void
    {
        $start = (new DateTime('-30 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-1 day', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $this->runBench('bench_events_sum_30d', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->sum($this->tenant, [
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
            ], 'value', Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_timeseries_30d_1h', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find($this->tenant, [
                UsageQuery::groupByInterval('time', '1h'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(5000),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_count_max_5k', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->count($this->tenant, [
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
            ], Usage::TYPE_EVENT, 5000);
        });

        $this->runBench('bench_insert_10k', function (string $queryId): void {
            $batch = [];
            for ($i = 0; $i < 10000; $i++) {
                $batch[] = [
                    'tenant' => $this->tenant,
                    'metric' => 'bench.insert.10k',
                    'value' => $i,
                    'tags' => [
                        'path' => '/v1/insert/' . ($i % 100),
                        'method' => 'POST',
                        'status' => '201',
                        'service' => 'storage',
                    ],
                ];
            }
            $this->adapter->setNextQueryId($queryId);
            $this->usage->addBatch($batch, Usage::TYPE_EVENT);
        }, 3);

        $this->runBench('bench_events_topN_path_30d', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find($this->tenant, [
                UsageQuery::groupBy('path'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(500),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_topN_country_30d', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find($this->tenant, [
                UsageQuery::groupBy('country'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(200),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_topN_service_30d', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find($this->tenant, [
                UsageQuery::groupBy('service'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(200),
            ], Usage::TYPE_EVENT);
        });

        $todayStart = (new DateTime('today', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $todayEnd = (new DateTime('+2 hour'))->format('Y-m-d H:i:s');
        $this->runBench('bench_events_topN_path_today_partial', function (string $queryId) use ($todayStart, $todayEnd): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find($this->tenant, [
                UsageQuery::groupBy('path'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $todayStart),
                Query::lessThanEqual('time', $todayEnd),
                Query::limit(500),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_topN_path_30d_filtered_resourceType', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find($this->tenant, [
                UsageQuery::groupBy('path'),
                Query::equal('metric', [$this->metric]),
                Query::equal('resourceType', ['function']),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(500),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_events_topN_path_country', function (string $queryId) use ($start, $end): void {
            $this->adapter->setNextQueryId($queryId);
            $this->usage->find($this->tenant, [
                UsageQuery::groupBy('path'),
                UsageQuery::groupBy('country'),
                Query::equal('metric', [$this->metric]),
                Query::greaterThanEqual('time', $start),
                Query::lessThanEqual('time', $end),
                Query::limit(500),
            ], Usage::TYPE_EVENT);
        });

        $this->runBench('bench_insert_with_projections', function (string $queryId): void {
            $batch = [];
            for ($i = 0; $i < 10000; $i++) {
                $batch[] = [
                    'tenant' => $this->tenant,
                    'metric' => 'bench.insert.with_projections',
                    'value' => $i,
                    'tags' => [
                        'path' => '/v1/mv/' . ($i % 100),
                        'method' => 'POST',
                        'status' => '201',
                        'service' => 'storage',
                        'country' => 'us',
                    ],
                ];
            }
            $this->adapter->setNextQueryId($queryId);
            $this->usage->addBatch($batch, Usage::TYPE_EVENT);
        }, 3);

        $this->assertNotEmpty($this->results, 'Benchmark scenarios must record results');

        // Library-level routing for FLAT (non-grouped) events queries.
        $expectedLibraryRoute = [
            'bench_events_sum_30d' => 'daily',
        ];
        foreach ($expectedLibraryRoute as $scenario => $route) {
            $this->assertArrayHasKey($scenario, $this->routes, "missing routing log for {$scenario}");
            $this->assertContains($route, $this->routes[$scenario], "{$scenario} expected to route to {$route}, got " . implode(',', $this->routes[$scenario]));
        }

        // Projection-level routing: grouped scenarios scan the base events
        // table; the optimizer picks the matching projection. We assert
        // projection usage by name via system.query_log.
        $expectedProjections = [
            'bench_events_topN_path_30d' => 'p_by_path',
            'bench_events_topN_country_30d' => 'p_by_country',
            'bench_events_topN_service_30d' => 'p_by_service',
            'bench_events_topN_path_today_partial' => 'p_by_path',
        ];
        foreach ($expectedProjections as $scenario => $projection) {
            $this->assertProjectionFiredAtLeastOnce($scenario, $projection);
        }
    }
}
