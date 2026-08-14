<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Utopia\Database\Query;

trait QueryJoinOperators
{
    public function testJoinOperatorNotEqual(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($ordersId, '$id', 'customerId', '!=')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(9, $rows);
    }

    public function testJoinOperatorGreaterThan(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $ordersId = $data['ordersId'];
        $paymentsId = $data['paymentsId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $ordersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($paymentsId, 'amount', 'amount', '>')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(1, $rows);
    }

    public function testJoinOperatorLessThan(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $ordersId = $data['ordersId'];
        $paymentsId = $data['paymentsId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $ordersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($paymentsId, 'amount', 'amount', '<')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(5, $rows);
    }

    public function testJoinOperatorGreaterThanEqual(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $ordersId = $data['ordersId'];
        $paymentsId = $data['paymentsId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $ordersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($paymentsId, 'amount', 'amount', '>=')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(3, $rows);
    }

    public function testJoinOperatorLessThanEqual(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $ordersId = $data['ordersId'];
        $paymentsId = $data['paymentsId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $ordersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($paymentsId, 'amount', 'amount', '<=')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(7, $rows);
    }

    public function testJoinInvalidOperatorRejected(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($ordersId, '$id', 'customerId', 'LIKE')->toString(),
            ],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }

    public function testJoinUserAliasPreserved(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($ordersId, '$id', 'customerId', '=', 'ord')->toString(),
                Query::select(['name', 'ord.amount'])->toString(),
            ],
        ]);

        $rows = $result['body'][$this->getRecordResource()] ?? [];
        $this->assertSame(200, $result['headers']['status-code']);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('name', $row);
            $this->assertTrue(isset($row['ord.amount']) || isset($row['amount']));
        }
    }

    public function testChainedJoinsCustomersOrdersPayments(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }
        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];
        $paymentsId = $data['paymentsId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($ordersId, '$id', 'customerId', '=', 'ord')->toString(),
                Query::join($paymentsId, 'ord.$id', 'orderId', '=', 'pay')->toString(),
                Query::select(['name', 'ord.amount', 'pay.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(2, $rows);
    }
}
