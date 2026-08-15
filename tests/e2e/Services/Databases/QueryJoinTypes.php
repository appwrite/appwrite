<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Utopia\Database\Query;

trait QueryJoinTypes
{
    public function testRightJoinMatchedRows(): void
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
                Query::rightJoin($ordersId, '$id', 'customerId')->toString(),
                Query::select(['name'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(4, $rows);
    }

    public function testCrossJoinCartesianCount(): void
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
                Query::crossJoin($ordersId)->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertCount(12, $result['body'][$this->getRecordResource()]);
    }

    public function testFullOuterJoinMatchedAndUnmatched(): void
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
                Query::fullOuterJoin($ordersId, '$id', 'customerId')->toString(),
                Query::select(['name'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(5, $rows);
    }

    public function testNaturalJoinRejected(): void
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
                Query::naturalJoin($ordersId)->toString(),
            ],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }

    public function testGetRowInnerJoinMatched(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'alice'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($ordersId, '$id', 'customerId')->toString(),
                Query::select(['name'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame('alice', $result['body']['$id']);
        $this->assertSame('Alice', $result['body']['name']);
    }

    public function testGetRowLeftJoinUnmatched(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'carol'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::leftJoin($ordersId, '$id', 'customerId', '=', 'ord')->toString(),
                Query::select(['name', 'ord.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame('carol', $result['body']['$id']);
        $this->assertSame('Carol', $result['body']['name']);
        $amount = $result['body']['ord.amount'] ?? $result['body']['amount'] ?? null;
        $this->assertTrue($amount === null || $amount === '', 'unmatched order amount must be nullish, not 0');
    }

    public function testGetRowInnerJoinUnmatchedNotFound(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'carol'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($ordersId, '$id', 'customerId')->toString(),
            ],
        ]);

        $this->assertSame(404, $result['headers']['status-code']);
    }

    public function testGetRowRightJoinUnmatchedNotFound(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'carol'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::rightJoin($ordersId, '$id', 'customerId')->toString(),
            ],
        ]);

        $this->assertSame(404, $result['headers']['status-code']);
    }

    public function testGetRowOneToManyReturnsFirst(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'alice'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($ordersId, '$id', 'customerId')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame('alice', $result['body']['$id']);
        $amount = $result['body']['ord.amount'] ?? $result['body']['amount'] ?? null;
        $this->assertContains((int) $amount, [100, 50]);
    }

    public function testGetRowFullOuterJoinExistingIdLikeLeft(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'carol'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::fullOuterJoin($ordersId, '$id', 'customerId')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame('carol', $result['body']['$id']);
        $amount = $result['body']['ord.amount'] ?? $result['body']['amount'] ?? null;
        $this->assertTrue($amount === null || $amount === '', 'unmatched order amount must be nullish, not 0');
    }

    public function testGetRowRejectsCount(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'alice'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::count()->toString(),
            ],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }
}
