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

        if ($this->isPostgreSQL()) {
            $this->assertSame(200, $result['headers']['status-code']);
            $this->assertCount(5, $result['body'][$this->getRecordResource()]);
        } else {
            $this->assertSame(400, $result['headers']['status-code']);
            $this->assertSame('general_query_invalid', $result['body']['type']);
        }
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
}
