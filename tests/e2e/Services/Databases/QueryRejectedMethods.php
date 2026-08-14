<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Utopia\Database\Query;

trait QueryRejectedMethods
{
    public function testGroupByIntervalRejected(): void
    {
        $data = $this->setupDocuments();
        $databaseId = $data['databaseId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $data['moviesId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['{"method":"groupByInterval","attribute":"birthDay","values":["1h"]}'],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }

    public function testGroupByTimeBucketRejected(): void
    {
        $data = $this->setupDocuments();
        $databaseId = $data['databaseId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $data['moviesId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['{"method":"groupByTimeBucket","attribute":"birthDay","values":["1h"]}'],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }

    public function testUnionRejected(): void
    {
        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $data['ordersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::union([Query::equal('status', ['paid'])])->toString(),
            ],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }

    public function testUnionAllRejected(): void
    {
        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $data['ordersId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::unionAll([Query::equal('status', ['paid'])])->toString(),
            ],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }
}
