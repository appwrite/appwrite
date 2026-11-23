<?php

namespace Tests\E2E\Services\Databases\Queries;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Utopia\Database\Query;

trait DottedPaths
{
    public function testListRejectsADottedPathOnAPlainAttribute(): void
    {
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('A schemaless collection has no plain attribute to reject a dotted path on');
        }

        $data = $this->setupDocuments();

        $reads = [
            'filter' => [Query::equal('title.x', ['Captain America'])->toString()],
            'select' => [Query::select(['title.x'])->toString()],
        ];

        foreach ($reads as $read => $queries) {
            $response = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['moviesId']), \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'queries' => $queries,
            ]);

            $this->assertSame(400, $response['headers']['status-code'], "{$read}: " . \json_encode($response['body']));
            $this->assertSame(Exception::GENERAL_QUERY_INVALID, $response['body']['type'], $read);
            $this->assertStringContainsString('nested attribute on: title', $response['body']['message'], $read);
        }

        $listed = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($data['databaseId'], $data['moviesId']), \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('title', ['Captain America'])->toString()],
        ]);

        $this->assertSame(200, $listed['headers']['status-code'], \json_encode($listed['body']));
        $this->assertNotEmpty($listed['body'][$this->getRecordResource()], 'The plain attribute itself still filters');
    }
}
