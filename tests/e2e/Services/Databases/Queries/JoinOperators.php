<?php

namespace Tests\E2E\Services\Databases\Queries;

use Tests\E2E\Client;
use Utopia\Database\Query;

trait JoinOperators
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
        $this->assertCount(6, $rows);
        $this->assertSame(6, $result['body']['total']);
        $this->assertJoinedValuesStayAliased($rows, ['customerId', 'amount', 'status']);
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
        $this->assertSame(1, $result['body']['total']);
        $this->assertJoinedValuesStayAliased($rows, ['orderId']);
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
        $this->assertSame(5, $result['body']['total']);
        $this->assertJoinedValuesStayAliased($rows, ['orderId']);
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
        $this->assertSame(3, $result['body']['total']);
        $this->assertJoinedValuesStayAliased($rows, ['orderId']);
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
        $this->assertSame(7, $result['body']['total']);
        $this->assertJoinedValuesStayAliased($rows, ['orderId']);
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

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(3, $rows);
        $this->assertSame(3, $result['body']['total']);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('ord.amount', $row);
            $this->assertArrayNotHasKey('amount', $row);
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
        $this->assertSame(2, $result['body']['total']);
    }
}
