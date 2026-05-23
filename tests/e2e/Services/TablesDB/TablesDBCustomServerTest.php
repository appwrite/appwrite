<?php

namespace Tests\E2E\Services\TablesDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Databases\DatabasesBase;
use Utopia\Database\Query;

class TablesDBCustomServerTest extends Scope
{
    use ApiTablesDB;
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;

    public function test_list_rows_returns_explain_when_requested(): void
    {
        $data = $this->setupDocuments();
        $databaseId = $data['databaseId'];
        $tableId = $data['moviesId'];

        $response = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $tableId),
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::orderAsc('releaseYear')->toString(),
                    Query::limit(10)->toString(),
                ],
                'explain' => true,
            ]
        );

        $this->assertEquals(200, $response['headers']['status-code']);

        // Rows still come back as normal.
        $this->assertArrayHasKey('rows', $response['body']);
        $this->assertIsArray($response['body']['rows']);
        $this->assertArrayHasKey('total', $response['body']);

        // Explain field is populated with one entry per physical read.
        $this->assertArrayHasKey('explain', $response['body']);
        $this->assertIsArray($response['body']['explain']);
        $this->assertNotEmpty($response['body']['explain']);

        $first = $response['body']['explain'][0];
        $this->assertEquals('find', $first['purpose']);
        $this->assertEquals($tableId, $first['context']['collection']);
        $this->assertArrayHasKey('plan', $first);
        $this->assertArrayHasKey('engine', $first['plan']);

        // listRows fires find() + count() (for total) by default — explain mirrors it.
        $purposes = array_column($response['body']['explain'], 'purpose');
        $this->assertContains('find', $purposes);
        $this->assertContains('count', $purposes);

        // Internal storage references must be stripped from the plan tree.
        $rawPlan = json_encode($response['body']['explain']);
        $this->assertStringNotContainsString('_perms', $rawPlan);
        $this->assertStringNotContainsString('__metadata', $rawPlan);
    }

    public function test_list_rows_explain_is_empty_by_default(): void
    {
        $data = $this->setupDocuments();
        $databaseId = $data['databaseId'];
        $tableId = $data['moviesId'];

        $response = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $tableId),
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [Query::limit(10)->toString()],
                // explain not passed — default false
            ]
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('explain', $response['body']);
        $this->assertEmpty($response['body']['explain']);
    }

    public function test_list_rows_explain_skips_count_when_total_is_false(): void
    {
        $data = $this->setupDocuments();
        $databaseId = $data['databaseId'];
        $tableId = $data['moviesId'];

        $response = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $tableId),
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [Query::limit(10)->toString()],
                'explain' => true,
                'total' => false,
            ]
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $purposes = array_column($response['body']['explain'], 'purpose');
        $this->assertContains('find', $purposes);
        $this->assertNotContains('count', $purposes);
    }
}
