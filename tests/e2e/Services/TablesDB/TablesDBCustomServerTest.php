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
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;
    use ApiTablesDB;

    /**
     * `explainRows` is registered only on the TablesDB namespace (the legacy
     * /v1/databases path is deprecated, so we deliberately did not expose
     * `explainDocuments`). Verifying it here rather than in DatabasesBase keeps
     * the shared trait from accidentally running this against an endpoint that
     * doesn't exist.
     */
    public function testExplainRows(): void
    {
        $data = $this->setupDocuments();
        $databaseId = $data['databaseId'];
        $tableId = $data['moviesId'];

        $response = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($databaseId, $tableId) . '/explain',
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
        $this->assertNotEmpty($response['body']['queries'], 'must capture at least the main find()');

        $first = $response['body']['queries'][0];
        $this->assertEquals('find', $first['purpose']);
        $this->assertArrayHasKey('context', $first);
        $this->assertArrayHasKey('collection', $first['context']);
        $this->assertEquals($tableId, $first['context']['collection'], 'physical table id must be translated back to the user-facing table id');

        $this->assertArrayHasKey('plan', $first);
        $this->assertArrayHasKey('engine', $first['plan']);

        // Sanitizer must have stripped any reference to internal storage tables.
        $rawPlan = json_encode($first['plan']);
        $this->assertStringNotContainsString('_perms', $rawPlan, 'permission companion table must be redacted');
        $this->assertStringNotContainsString('__metadata', $rawPlan, 'metadata system table must be redacted');
    }
}
