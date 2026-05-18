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

    public function test_explain_rows(): void
    {
        $data = $this->setupDocuments();
        $databaseId = $data['databaseId'];
        $tableId = $data['moviesId'];

        $response = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $tableId).'/explain',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'queries' => [
                    Query::orderAsc('releaseYear')->toString(),
                    Query::limit(10)->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('queries', $response['body']);
        $this->assertIsArray($response['body']['queries']);
        $this->assertNotEmpty($response['body']['queries']);

        $first = $response['body']['queries'][0];
        $this->assertEquals('find', $first['purpose']);
        $this->assertArrayHasKey('context', $first);
        $this->assertArrayHasKey('collection', $first['context']);
        $this->assertEquals($tableId, $first['context']['collection']);

        $this->assertArrayHasKey('plan', $first);
        $this->assertArrayHasKey('engine', $first['plan']);

        $rawPlan = json_encode($first['plan']);
        $this->assertStringNotContainsString('_perms', $rawPlan);
        $this->assertStringNotContainsString('__metadata', $rawPlan);
    }
}
