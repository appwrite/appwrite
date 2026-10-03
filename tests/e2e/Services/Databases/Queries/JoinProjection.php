<?php

namespace Tests\E2E\Services\Databases\Queries;

use Tests\E2E\Client;
use Tests\E2E\Services\Databases\Queries\Oracle\Join;
use Tests\E2E\Services\Databases\Queries\Oracle\Order;
use Tests\E2E\Services\Databases\Queries\Oracle\Summary;
use Utopia\Database\Query;

trait JoinProjection
{
    public function testLeftJoinUnmatchedCustomerHasNullOrder(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::leftJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
                Query::select(['name', 'ord.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(4, $rows);
        $this->assertSame(4, $result['body']['total']);

        $carol = null;
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === 'Carol') {
                $carol = $row;
                break;
            }
        }
        $this->assertNotNull($carol);
        $this->assertArrayHasKey('ord.amount', $carol);
        $this->assertNull($carol['ord.amount'], 'An unmatched left-joined amount must be null, not 0');
        $this->assertArrayNotHasKey('amount', $carol);
    }

    public function testRightJoinUnmatchedOrderHasNullCustomer(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::rightJoin($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
                Query::select(['name', 'ord.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(4, $rows);
        $this->assertSame(4, $result['body']['total']);

        $orphans = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if ($name === null || $name === '') {
                $orphans[] = $row;
            }
        }
        $this->assertCount(1, $orphans);
        $this->assertSame(25, $orphans[0]['ord.amount']);
        $this->assertArrayNotHasKey('amount', $orphans[0]);
    }

    public function testSelectJoinedColumnByAlias(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
                Query::select(['name', 'ord.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(3, $rows);
        $this->assertSame(3, $result['body']['total']);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('name', $row);
            $this->assertNotEmpty($row['name']);
            $this->assertArrayHasKey('ord.amount', $row);
            $this->assertArrayNotHasKey('amount', $row);
            $this->assertContains($row['ord.amount'], [100, 50, 200]);
        }
    }

    public function testSelectUnqualifiedJoinedColumnRejected(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($data['ordersId'], '$id', 'customerId')->toString(),
                Query::select(['name', 'amount'])->toString(),
            ],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }

    public function testGetRowSelectJoinedColumnByAlias(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['customersId'], 'alice'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($data['ordersId'], '$id', 'customerId', '=', 'ord')->toString(),
                Query::select(['name', 'ord.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame('alice', $result['body']['$id']);
        $this->assertArrayHasKey('ord.amount', $result['body']);
        $this->assertArrayNotHasKey('amount', $result['body']);
        $this->assertContains($result['body']['ord.amount'], [100, 50]);
    }

    public function testJoinWithoutSelectReturnsMainAttributesAndAliasedJoinedAttributes(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();
        $join = Query::join($fixture->ordersId, '$id', 'customerId', '=', 'ord')->toString();
        $joinedKeys = ['ord.$id', 'ord.customerId', 'ord.amount', 'ord.label', 'ord.flags', 'ord.scores'];
        $readableOrderIds = \array_map(static fn (Order $order): string => $order->id, $seed->orders);

        $direct = $this->queryRecords($fixture->databaseId, $fixture->customersId, [Query::limit(100)->toString()]);
        $this->assertSame(200, $direct['headers']['status-code']);
        $mainKeys = [];
        foreach ($direct['body'][$this->getRecordResource()] as $customer) {
            $mainKeys[$customer['$id']] = \array_keys($customer);
        }

        $listed = $this->queryRecords($fixture->databaseId, $fixture->customersId, [$join, Query::limit(100)->toString()]);
        $this->assertSame(200, $listed['headers']['status-code']);
        $rows = $listed['body'][$this->getRecordResource()];
        $this->assertCount(\count(Join::Inner->pairs($seed->customers, $seed->orders)), $rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey($row['$id'], $mainKeys);
            $this->assertSame($this->sortedStrings([...$mainKeys[$row['$id']], ...$joinedKeys]), $this->sortedStrings(\array_keys($row)));
            $this->assertSame($row['$id'], $row['ord.customerId']);
            $this->assertContains($row['ord.$id'], $readableOrderIds);
            $this->assertJoinedOrderValues($seed->order($row['ord.$id']), $row);
        }

        $got = $this->fetchRecord($fixture->databaseId, $fixture->customersId, 'alice', [$join]);
        $this->assertSame(200, $got['headers']['status-code']);
        $this->assertSame($this->sortedStrings([...$mainKeys['alice'], ...$joinedKeys]), $this->sortedStrings(\array_keys($got['body'])));
        $this->assertSame('alice', $got['body']['ord.customerId']);
        $this->assertContains($got['body']['ord.$id'], $readableOrderIds);
        $this->assertJoinedOrderValues($seed->order($got['body']['ord.$id']), $got['body']);
    }

    public function testBareAggregateAttributeDeclaredByTwoJoinsRejected(): void
    {
        if (!$this->getSupportForJoins() || !$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support join or aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();
        $orders = Query::join($fixture->ordersId, '$id', 'customerId', '=', 'ord')->toString();
        $payments = Query::join($fixture->paymentsId, 'ord.$id', 'orderId', '=', 'pay')->toString();

        $qualified = $this->queryRecords($fixture->databaseId, $fixture->customersId, [
            $orders,
            $payments,
            Query::count('*', 'rowCount')->toString(),
            Query::sum('ord.amount', 'orderTotal')->toString(),
            Query::sum('pay.amount', 'paymentTotal')->toString(),
        ]);

        $this->assertSame(200, $qualified['headers']['status-code']);
        $row = $qualified['body'][$this->getRecordResource()][0];
        $this->assertSame(\count($seed->joinedPayments()), $this->aggregateInteger($row, 'rowCount'));
        $this->assertSame($seed->joinedOrderTotal(), $this->aggregateInteger($row, 'orderTotal'));
        $this->assertSame($seed->joinedPaymentTotal(), $this->aggregateInteger($row, 'paymentTotal'));

        $ambiguous = [
            [Query::sum('amount', 'total')->toString()],
            [Query::count('*', 'rowCount')->toString(), Query::groupBy(['amount'])->toString()],
        ];

        foreach ($ambiguous as $queries) {
            $rejected = $this->queryRecords($fixture->databaseId, $fixture->customersId, [$orders, $payments, ...$queries]);
            $this->assertSame(400, $rejected['headers']['status-code'], 'Both joined collections declare amount');
            $this->assertSame('general_query_invalid', $rejected['body']['type']);
        }
    }

    public function testBareAggregateAttributeResolvesToTheOnlyJoinDeclaringIt(): void
    {
        if (!$this->getSupportForJoins() || !$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support join or aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();
        $expected = Summary::of(Join::Inner->pairs($seed->customers, $seed->orders))->sum;
        $orders = Query::join($fixture->ordersId, '$id', 'customerId', '=', 'ord')->toString();

        foreach (['amount', 'ord.amount'] as $attribute) {
            $summed = $this->queryRecords($fixture->databaseId, $fixture->customersId, [
                $orders,
                Query::sum($attribute, 'total')->toString(),
            ]);

            $this->assertSame(200, $summed['headers']['status-code'], "sum('{$attribute}')");
            $this->assertSame($expected, $this->aggregateInteger($summed['body'][$this->getRecordResource()][0], 'total'), "sum('{$attribute}')");
        }

        $undeclared = $this->queryRecords($fixture->databaseId, $fixture->customersId, [
            $orders,
            Query::sum('undeclared', 'total')->toString(),
        ]);

        $this->assertSame(400, $undeclared['headers']['status-code'], 'No collection in the query declares the attribute');
        $this->assertSame('general_query_invalid', $undeclared['body']['type']);
    }
}
