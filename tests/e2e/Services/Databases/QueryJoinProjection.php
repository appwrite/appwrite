<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Utopia\Database\Query;

trait QueryJoinProjection
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

        $carol = null;
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === 'Carol') {
                $carol = $row;
                break;
            }
        }
        $this->assertNotNull($carol);
        $amount = $carol['ord.amount'] ?? $carol['amount'] ?? null;
        $this->assertTrue($amount === null || $amount === '', 'unmatched order amount must be nullish, not 0');
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

        $orphans = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if ($name === null || $name === '') {
                $orphans[] = $row;
            }
        }
        $this->assertCount(1, $orphans);
        $amount = $orphans[0]['ord.amount'] ?? $orphans[0]['amount'] ?? null;
        $this->assertSame(25, (int) $amount);
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

        foreach ($rows as $row) {
            $this->assertArrayHasKey('name', $row);
            $this->assertNotEmpty($row['name']);
            $this->assertTrue(array_key_exists('ord.amount', $row) || array_key_exists('amount', $row));
            $amount = $row['ord.amount'] ?? $row['amount'] ?? null;
            $this->assertContains((int) $amount, [100, 50, 200]);
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
}
