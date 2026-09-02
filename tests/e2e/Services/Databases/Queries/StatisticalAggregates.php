<?php

namespace Tests\E2E\Services\Databases\Queries;

use Tests\E2E\Client;
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
        $this->assertEqualsWithDelta($this->isPostgreSQL() ? 77.3985 : 67.0238, (float) $row['spread'], 0.01);
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
        $this->assertEqualsWithDelta($this->isPostgreSQL() ? 5989.583 : 4492.1875, (float) $row['varAmount'], 0.1);
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
}
