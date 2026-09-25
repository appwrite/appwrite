<?php

namespace Tests\E2E\Services\Databases\Queries;

use Tests\E2E\Client;
use Tests\E2E\Services\Databases\Queries\Oracle\Statistic;
use Utopia\Database\Query;

trait StatisticalAggregates
{
    public function testStddevOrderAmount(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['ordersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::stddev('amount', 'spread')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $row = $result['body'][$this->getRecordResource()][0];
        $this->assertArrayHasKey('spread', $row);
        $this->assertEqualsWithDelta(67.0238, (float) $row['spread'], 0.01);
    }

    public function testStddevPopOrderAmount(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['ordersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::stddevPop('amount', 'spreadPop')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $row = $result['body'][$this->getRecordResource()][0];
        $this->assertEqualsWithDelta(67.0238, (float) $row['spreadPop'], 0.01);
    }

    public function testStddevSampOrderAmount(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['ordersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::stddevSamp('amount', 'spreadSamp')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $row = $result['body'][$this->getRecordResource()][0];
        $this->assertEqualsWithDelta(77.3985, (float) $row['spreadSamp'], 0.01);
    }

    public function testVarianceOrderAmount(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['ordersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::variance('amount', 'varAmount')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $row = $result['body'][$this->getRecordResource()][0];
        $this->assertEqualsWithDelta(4492.1875, (float) $row['varAmount'], 0.1);
    }

    public function testVarPopOrderAmount(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['ordersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::varPop('amount', 'varPopAmount')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $row = $result['body'][$this->getRecordResource()][0];
        $this->assertEqualsWithDelta(4492.1875, (float) $row['varPopAmount'], 0.1);
    }

    public function testVarSampOrderAmount(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['ordersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::varSamp('amount', 'varSampAmount')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $row = $result['body'][$this->getRecordResource()][0];
        $this->assertEqualsWithDelta(5989.583, (float) $row['varSampAmount'], 0.1);
    }

    public function testBitAndCustomerFlags(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::bitAnd('flags', 'allFlags')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $row = $result['body'][$this->getRecordResource()][0];
        $this->assertSame(1, (int) $row['allFlags']);
    }

    public function testBitOrCustomerFlags(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::bitOr('flags', 'anyFlags')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $row = $result['body'][$this->getRecordResource()][0];
        $this->assertSame(7, (int) $row['anyFlags']);
    }

    public function testBitXorCustomerFlags(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::bitXor('flags', 'xorFlags')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame(1, (int) $result['body'][$this->getRecordResource()][0]['xorFlags']);
    }

    public function testStatisticalAggregatesComputeOverReadableRowsOnly(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();

        $result = $this->queryRecords($fixture->databaseId, $fixture->ordersId, \array_map(
            static fn (Statistic $statistic): string => $statistic->query()->toString(),
            Statistic::cases(),
        ));

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertCount(1, $result['body'][$this->getRecordResource()]);
        $row = $result['body'][$this->getRecordResource()][0];

        foreach (Statistic::cases() as $statistic) {
            $this->assertStatistic($statistic->expected($seed), $statistic, $row);
        }
    }

    public function testAggregatesOverAnEmptySetReturnZeroCountsAndNulls(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();

        $result = $this->queryRecords($fixture->databaseId, $fixture->ordersId, [
            Query::equal('label', ['nonexistent'])->toString(),
            ...\array_map(static fn (Statistic $statistic): string => $statistic->query()->toString(), Statistic::cases()),
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertCount(1, $result['body'][$this->getRecordResource()]);
        $row = $result['body'][$this->getRecordResource()][0];

        foreach (Statistic::cases() as $statistic) {
            $this->assertStatistic($statistic->overEmptySet(), $statistic, $row);
        }
    }

    public function testNumericAggregatesRejectStringAndArrayAttributes(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();

        foreach (['label', 'scores'] as $attribute) {
            foreach (Statistic::cases() as $statistic) {
                if (!$statistic->requiresNumber()) {
                    continue;
                }

                $rejected = $this->queryRecords($fixture->databaseId, $fixture->ordersId, [$statistic->on($attribute)->toString()]);
                $this->assertSame(400, $rejected['headers']['status-code'], "{$statistic->method()->value}('{$attribute}')");
                $this->assertSame('general_query_invalid', $rejected['body']['type']);
            }
        }

        $labels = $this->seedVisibleToCaller()->labels();
        $accepted = $this->queryRecords($fixture->databaseId, $fixture->ordersId, [
            Query::count('label', 'labelTotal')->toString(),
            Query::min('label', 'firstLabel')->toString(),
            Query::max('label', 'lastLabel')->toString(),
        ]);

        $this->assertSame(200, $accepted['headers']['status-code']);
        $row = $accepted['body'][$this->getRecordResource()][0];
        $this->assertSame(\count($labels), $this->aggregateInteger($row, 'labelTotal'));
        $this->assertSame(\min($labels), $row['firstLabel'] ?? null);
        $this->assertSame(\max($labels), $row['lastLabel'] ?? null);
    }

    public function testAggregateQueriesRejectedWhereAdapterLacksAggregations(): void
    {
        if ($this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter supports aggregation queries');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $probesId = $this->createProbeContainer($databaseId);

        $plain = $this->queryRecords($databaseId, $probesId, []);
        $this->assertSame(200, $plain['headers']['status-code']);
        $this->assertSame(['probe'], \array_column($plain['body'][$this->getRecordResource()], '$id'));

        $aggregations = [
            [Query::count('*', 'rowCount')->toString()],
            [Query::count('*', 'rowCount')->toString(), Query::groupBy(['$id'])->toString()],
        ];

        foreach ($aggregations as $queries) {
            $rejected = $this->queryRecords($databaseId, $probesId, $queries);
            $this->assertSame(400, $rejected['headers']['status-code']);
            $this->assertSame('general_query_invalid', $rejected['body']['type']);
        }
    }
}
