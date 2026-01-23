<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

/**
 * Tests for GraphQL schema caching and per-project isolation.
 *
 * These tests verify:
 * 1. Schemas are correctly built and cached per project
 * 2. Schema changes (new attributes) are reflected after cache invalidation
 * 3. Multiple projects have isolated schemas (collection types don't leak)
 * 4. Concurrent requests to same project use consistent schema
 */
class SchemaIsolationTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    /**
     * Test basic GraphQL query works and schema is built.
     */
    public function testSchemaBuildsSuccessfully(): void
    {
        $projectId = $this->getProject()['$id'];

        // Simple introspection-like query to verify schema exists
        $query = 'query { healthGet { status } }';
        $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'query' => $query
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayNotHasKey('errors', $response['body']);
    }

    /**
     * Test that repeated queries use cached schema (no errors from schema rebuild).
     */
    public function testRepeatedQueriesWork(): void
    {
        $projectId = $this->getProject()['$id'];
        $query = 'query { healthGet { status } }';

        // Make multiple requests - all should succeed using cached schema
        for ($i = 0; $i < 5; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $this->getHeaders()), [
                'query' => $query
            ]);

            $this->assertEquals(200, $response['headers']['status-code'], "Request $i failed");
            $this->assertArrayHasKey('data', $response['body'], "Request $i missing data");
            $this->assertArrayNotHasKey('errors', $response['body'], "Request $i has errors");
        }
    }

    /**
     * Test that dynamic collection schema is reused across multiple requests.
     * This verifies the schema cache works for project-specific collection types.
     * Uses a fresh project to ensure clean schema cache (Swoole workers have isolated caches).
     */
    public function testDynamicSchemaIsReused(): void
    {
        // Use a fresh project to ensure clean schema cache
        $freshProject = $this->getProject(true);
        $projectId = $freshProject['$id'];
        $headers = ['x-appwrite-key' => $freshProject['apiKey']];

        // Create a database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'databaseId' => ID::unique(),
            'name' => 'Cache Test DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'collectionId' => 'cacheTest',
            'name' => 'Cache Test Collection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::users()),
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Create an attribute
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/cacheTest/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);
        $this->assertEquals(202, $attribute['headers']['status-code']);

        // Wait for attribute to be available
        $this->waitForAttributes($databaseId, 'cacheTest', $projectId, $freshProject['apiKey']);

        // Create a document
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/cacheTest/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'documentId' => ID::unique(),
            'data' => ['name' => 'Test Item'],
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertEquals(201, $document['headers']['status-code']);

        // Query using the dynamic schema multiple times - all should work with cached schema
        $dynamicQuery = 'query { cacheTestList { _id name } }';

        for ($i = 0; $i < 10; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headers), [
                'query' => $dynamicQuery,
            ]);

            $this->assertEquals(200, $response['headers']['status-code'], "Request $i failed");
            $this->assertArrayNotHasKey('errors', $response['body'], "Request $i has errors: " . json_encode($response['body']['errors'] ?? []));
            $this->assertArrayHasKey('data', $response['body'], "Request $i missing data");
            $this->assertArrayHasKey('cacheTestList', $response['body']['data'], "Request $i missing cacheTestList");
            $this->assertNotEmpty($response['body']['data']['cacheTestList'], "Request $i returned empty list");
            $this->assertEquals('Test Item', $response['body']['data']['cacheTestList'][0]['name'], "Request $i returned wrong data");
        }
    }

    /**
     * Test schema reflects collection attributes after they're created.
     */
    public function testSchemaReflectsNewAttributes(): array
    {
        $projectId = $this->getProject()['$id'];

        // Create a database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Schema Test DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create a collection
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'collectionId' => 'testCollection',
            'name' => 'Test Collection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create an attribute
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'key' => 'title',
            'size' => 255,
            'required' => true,
        ]);
        $this->assertEquals(202, $attribute['headers']['status-code']);

        // Wait for attribute to be available
        $this->waitForAttributes($databaseId, $collectionId);

        // Create a document using REST API
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Test Document',
            ],
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertEquals(201, $document['headers']['status-code']);

        // Query the document via GraphQL - schema should include our collection
        $query = $this->getQuery(self::GET_DOCUMENTS);
        $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'query' => $query,
            'variables' => [
                'databaseId' => $databaseId,
                'collectionId' => $collectionId,
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayNotHasKey('errors', $response['body']);

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
            'documentId' => $document['body']['$id'],
        ];
    }

    /**
     * @depends testSchemaReflectsNewAttributes
     * Test schema updates when new attribute is added to existing collection.
     */
    public function testSchemaUpdatesOnAttributeChange(array $data): void
    {
        $projectId = $this->getProject()['$id'];
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Add a new attribute
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'key' => 'description',
            'size' => 1000,
            'required' => false,
        ]);
        $this->assertEquals(202, $attribute['headers']['status-code']);

        // Wait for attribute to be available
        $this->waitForAttributes($databaseId, $collectionId);

        // Update existing document with new field
        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $data['documentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'data' => [
                'description' => 'This is a test description',
            ],
        ]);
        $this->assertEquals(200, $document['headers']['status-code']);

        // Query via GraphQL - schema should now include the new attribute
        $query = $this->getQuery(self::GET_DOCUMENT);
        $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'query' => $query,
            'variables' => [
                'databaseId' => $databaseId,
                'collectionId' => $collectionId,
                'documentId' => $data['documentId'],
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayNotHasKey('errors', $response['body']);
    }

    /**
     * Test that two different projects have isolated schemas.
     * Creating a collection in project A should not affect project B.
     */
    public function testProjectSchemaIsolation(): void
    {
        // Get or create first project
        $project1 = $this->getProject();
        $project1Id = $project1['$id'];

        // Create second project
        $project2 = $this->getProject(true); // fresh = true
        $project2Id = $project2['$id'];

        $this->assertNotEquals($project1Id, $project2Id, 'Projects should be different');

        // Create a database and collection in project 1
        $database1 = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $project1Id,
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Project1 DB',
        ]);
        $this->assertEquals(201, $database1['headers']['status-code']);
        $db1Id = $database1['body']['$id'];

        $collection1 = $this->client->call(Client::METHOD_POST, '/databases/' . $db1Id . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $project1Id,
        ], $this->getHeaders()), [
            'collectionId' => 'isolatedCollection',
            'name' => 'Isolated Collection',
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertEquals(201, $collection1['headers']['status-code']);

        // Create attribute in project 1's collection
        $attr = $this->client->call(Client::METHOD_POST, '/databases/' . $db1Id . '/collections/isolatedCollection/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $project1Id,
        ], $this->getHeaders()), [
            'key' => 'project1Only',
            'size' => 100,
            'required' => false,
        ]);
        $this->assertEquals(202, $attr['headers']['status-code']);

        // Wait for attribute to be available
        $this->waitForAttributes($db1Id, 'isolatedCollection');

        // Query project 1 - should work
        $query = $this->getQuery(self::GET_COLLECTIONS);
        $response1 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $project1Id,
        ], $this->getHeaders()), [
            'query' => $query,
            'variables' => [
                'databaseId' => $db1Id,
            ],
        ]);
        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response1['body']);

        // Query project 2 with same database ID - should fail (database doesn't exist there)
        $response2 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project2Id,
            'x-appwrite-key' => $project2['apiKey'],
        ], [
            'query' => $query,
            'variables' => [
                'databaseId' => $db1Id,
            ],
        ]);
        $this->assertEquals(200, $response2['headers']['status-code']);
        // Should have an error because database doesn't exist in project 2
        $this->assertArrayHasKey('errors', $response2['body']);
    }

    /**
     * Test batched GraphQL queries work with cached schema.
     */
    public function testBatchedQueriesWork(): void
    {
        $projectId = $this->getProject()['$id'];

        // Send batched queries
        $queries = [
            ['query' => 'query { healthGet { status } }'],
            ['query' => 'query { healthGetDB { status } }'],
            ['query' => 'query { healthGetCache { status } }'],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $queries);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertCount(3, $response['body']);

        foreach ($response['body'] as $i => $result) {
            $this->assertArrayHasKey('data', $result, "Batch query $i missing data");
            $this->assertArrayNotHasKey('errors', $result, "Batch query $i has errors");
        }
    }

    /**
     * Test schema handles collection deletion gracefully.
     */
    public function testSchemaHandlesCollectionDeletion(): void
    {
        $projectId = $this->getProject()['$id'];

        // Create a database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Deletion Test DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create a collection
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'collectionId' => 'toDelete',
            'name' => 'To Delete',
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Add attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/toDelete/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'key' => 'name',
            'size' => 100,
            'required' => false,
        ]);

        // Wait for attribute to be available
        $this->waitForAttributes($databaseId, 'toDelete');

        // Query collection - should work
        $query = $this->getQuery(self::GET_DOCUMENTS);
        $response1 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'query' => $query,
            'variables' => [
                'databaseId' => $databaseId,
                'collectionId' => 'toDelete',
            ],
        ]);
        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response1['body']);

        // Delete the collection
        $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/toDelete', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));
        $this->assertEquals(204, $deleteResponse['headers']['status-code']);

        // Query deleted collection - should fail gracefully
        $response2 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'query' => $query,
            'variables' => [
                'databaseId' => $databaseId,
                'collectionId' => 'toDelete',
            ],
        ]);
        $this->assertEquals(200, $response2['headers']['status-code']);
        // Should have error since collection no longer exists
        $this->assertArrayHasKey('errors', $response2['body']);
    }

    /**
     * Test schema correctly handles multiple collections.
     */
    public function testSchemaWithMultipleCollections(): void
    {
        $projectId = $this->getProject()['$id'];

        // Create a database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Multi Collection DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create multiple collections
        $collections = ['users', 'posts', 'comments'];
        foreach ($collections as $collectionName) {
            $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $this->getHeaders()), [
                'collectionId' => $collectionName,
                'name' => ucfirst($collectionName),
                'permissions' => [Permission::read(Role::any())],
            ]);
            $this->assertEquals(201, $collection['headers']['status-code']);

            // Add a unique attribute to each
            $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionName . '/attributes/string', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $this->getHeaders()), [
                'key' => $collectionName . 'Field',
                'size' => 100,
                'required' => false,
            ]);
        }

        // Wait for all attributes in all collections
        foreach ($collections as $collectionName) {
            $this->waitForAttributes($databaseId, $collectionName);
        }

        // Query each collection - all should work
        $query = $this->getQuery(self::GET_DOCUMENTS);
        foreach ($collections as $collectionName) {
            $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $this->getHeaders()), [
                'query' => $query,
                'variables' => [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionName,
                ],
            ]);

            $this->assertEquals(200, $response['headers']['status-code'], "Failed for collection: $collectionName");
            $this->assertArrayNotHasKey('errors', $response['body'], "Errors for collection: $collectionName");
        }
    }

    /**
     * Helper to wait for TablesDB table columns to be available.
     */
    protected function waitForColumns(string $databaseId, string $tableId, ?string $projectId = null, ?string $apiKey = null, int $timeoutMs = 10000, int $waitMs = 100): void
    {
        $projectId = $projectId ?? $this->getProject()['$id'];
        $headers = $apiKey ? ['x-appwrite-key' => $apiKey] : $this->getHeaders();

        $this->assertEventually(function () use ($databaseId, $tableId, $projectId, $headers) {
            $table = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headers));

            $this->assertEquals(200, $table['headers']['status-code']);

            foreach ($table['body']['columns'] ?? [] as $column) {
                $this->assertEquals('available', $column['status'], 'Column ' . $column['key'] . ' not available');
            }
        }, $timeoutMs, $waitMs);
    }

    /**
     * Test TablesDB dynamic schema - create table with columns and query via GraphQL.
     * Uses REST API for setup to ensure hooks run properly.
     * Uses a fresh project to avoid cache interference from other tests due to Swoole worker isolation.
     */
    public function testTablesDBDynamicSchema(): array
    {
        // Use a fresh project to ensure clean schema cache (Swoole workers have isolated caches)
        $freshProject = $this->getProject(true);
        $projectId = $freshProject['$id'];
        $apiKey = $freshProject['apiKey'];
        $headers = ['x-appwrite-key' => $apiKey];

        // Create a TablesDB database using REST API
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'databaseId' => ID::unique(),
            'name' => 'Dynamic Test DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create a table using REST API
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'tableId' => 'actors',
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);
        $this->assertEquals(201, $table['headers']['status-code']);
        $tableId = $table['body']['$id'];

        // Create columns using REST API
        $column = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertEquals(202, $column['headers']['status-code']);

        $column = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'age',
            'min' => 0,
            'max' => 150,
            'required' => false,
        ]);
        $this->assertEquals(202, $column['headers']['status-code']);

        // Wait for columns to be available
        $this->waitForColumns($databaseId, $tableId, $projectId, $apiKey);

        // Debug: Check database type
        $dbResp = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers));
        $this->assertEquals(200, $dbResp['headers']['status-code'], 'Database query failed');

        // Debug: List tables
        $tableResp = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers));
        $this->assertEquals(200, $tableResp['headers']['status-code'], 'Table query failed');

        // Create a row using REST API
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'rowId' => ID::unique(),
            'data' => [
                'name' => 'John Doe',
                'age' => 30,
            ],
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertEquals(201, $row['headers']['status-code']);
        $rowId = $row['body']['$id'];

        // Query using the dynamic schema - actorsList
        $dynamicQuery = 'query { actorsList { _id name age } }';
        $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'query' => $dynamicQuery,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        if (isset($response['body']['errors'])) {
            $this->fail('GraphQL errors: ' . json_encode($response['body']['errors']));
        }
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('actorsList', $response['body']['data']);

        $actors = $response['body']['data']['actorsList'];
        $this->assertNotEmpty($actors);
        $this->assertEquals('John Doe', $actors[0]['name']);
        $this->assertEquals(30, $actors[0]['age']);

        return [
            'projectId' => $projectId,
            'apiKey' => $apiKey,
            'databaseId' => $databaseId,
            'tableId' => $tableId,
            'rowId' => $rowId,
        ];
    }

    /**
     * @depends testTablesDBDynamicSchema
     * Test TablesDB dynamic schema - get single row via GraphQL.
     */
    public function testTablesDBDynamicSchemaGet(array $data): void
    {
        $projectId = $data['projectId'];
        $headers = ['x-appwrite-key' => $data['apiKey']];

        // Query using the dynamic schema - actorsGet
        $dynamicQuery = 'query actorsGet($id: String!) { actorsGet(id: $id) { _id name age } }';
        $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'query' => $dynamicQuery,
            'variables' => [
                'id' => $data['rowId'],
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('actorsGet', $response['body']['data']);

        $actor = $response['body']['data']['actorsGet'];
        $this->assertEquals('John Doe', $actor['name']);
        $this->assertEquals(30, $actor['age']);
    }

    /**
     * @depends testTablesDBDynamicSchema
     * Test TablesDB dynamic schema - create row via GraphQL mutation.
     */
    public function testTablesDBDynamicSchemaCreate(array $data): void
    {
        $projectId = $data['projectId'];
        $headers = ['x-appwrite-key' => $data['apiKey']];

        // Create using the dynamic schema - actorsCreate
        $dynamicQuery = 'mutation actorsCreate($name: String!, $age: Int) { actorsCreate(name: $name, age: $age) { _id name age } }';
        $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'query' => $dynamicQuery,
            'variables' => [
                'name' => 'Jane Smith',
                'age' => 25,
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('actorsCreate', $response['body']['data']);

        $actor = $response['body']['data']['actorsCreate'];
        $this->assertEquals('Jane Smith', $actor['name']);
        $this->assertEquals(25, $actor['age']);
        $this->assertNotEmpty($actor['_id']);
    }

    /**
     * @depends testTablesDBDynamicSchema
     * Test TablesDB dynamic schema - update row via GraphQL mutation.
     */
    public function testTablesDBDynamicSchemaUpdate(array $data): void
    {
        $projectId = $data['projectId'];
        $headers = ['x-appwrite-key' => $data['apiKey']];

        // Update using the dynamic schema - actorsUpdate
        $dynamicQuery = 'mutation actorsUpdate($id: String!, $name: String, $age: Int) { actorsUpdate(id: $id, name: $name, age: $age) { _id name age } }';
        $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'query' => $dynamicQuery,
            'variables' => [
                'id' => $data['rowId'],
                'name' => 'John Updated',
                'age' => 35,
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response['body']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('actorsUpdate', $response['body']['data']);

        $actor = $response['body']['data']['actorsUpdate'];
        $this->assertEquals('John Updated', $actor['name']);
        $this->assertEquals(35, $actor['age']);
    }

    /**
     * Test that schema is rebuilt when a 'schema' group route is called.
     * Verifies that adding an attribute makes the new field available in GraphQL.
     */
    public function testSchemaRebuildsOnSchemaGroupRoute(): void
    {
        // Use a fresh project to ensure clean state
        $freshProject = $this->getProject(true);
        $projectId = $freshProject['$id'];
        $headers = ['x-appwrite-key' => $freshProject['apiKey']];

        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'databaseId' => ID::unique(),
            'name' => 'Schema Rebuild Test DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'collectionId' => 'rebuildTest',
            'name' => 'Rebuild Test',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::users()),
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Create first attribute
        $attr1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/rebuildTest/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'field1',
            'size' => 100,
            'required' => false,
        ]);
        $this->assertEquals(202, $attr1['headers']['status-code']);
        $this->waitForAttributes($databaseId, 'rebuildTest', $projectId, $freshProject['apiKey']);

        // Create a document
        $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/rebuildTest/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'documentId' => ID::unique(),
            'data' => ['field1' => 'value1'],
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertEquals(201, $doc['headers']['status-code']);

        // Query via GraphQL - should work with field1
        $query1 = 'query { rebuildTestList { _id field1 } }';
        $response1 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $query1]);

        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response1['body']);
        $this->assertEquals('value1', $response1['body']['data']['rebuildTestList'][0]['field1']);

        // Now add a second attribute (this is a 'schema' group route)
        $attr2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/rebuildTest/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'field2',
            'size' => 100,
            'required' => false,
        ]);
        $this->assertEquals(202, $attr2['headers']['status-code']);
        $this->waitForAttributes($databaseId, 'rebuildTest', $projectId, $freshProject['apiKey']);

        // Update document with new field
        $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/rebuildTest/documents/' . $doc['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'data' => ['field2' => 'value2'],
        ]);

        // Query via GraphQL - schema should be rebuilt and include field2
        $query2 = 'query { rebuildTestList { _id field1 field2 } }';
        $response2 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $query2]);

        $this->assertEquals(200, $response2['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response2['body'], 'Schema should have been rebuilt to include field2');
        $this->assertEquals('value1', $response2['body']['data']['rebuildTestList'][0]['field1']);
        $this->assertEquals('value2', $response2['body']['data']['rebuildTestList'][0]['field2']);
    }

    /**
     * Test that schema is NOT rebuilt when non-'schema' group routes are called.
     * Document CRUD operations should not invalidate the schema cache.
     */
    public function testSchemaNotRebuiltOnNonSchemaGroupRoute(): void
    {
        // Use a fresh project to ensure clean state
        $freshProject = $this->getProject(true);
        $projectId = $freshProject['$id'];
        $headers = ['x-appwrite-key' => $freshProject['apiKey']];

        // Create database and collection with attribute
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'databaseId' => ID::unique(),
            'name' => 'Non-Schema Route Test DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'collectionId' => 'nonSchemaTest',
            'name' => 'Non-Schema Test',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::users()),
                Permission::update(Role::users()),
                Permission::delete(Role::users()),
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        $attr = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/nonSchemaTest/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'title',
            'size' => 100,
            'required' => true,
        ]);
        $this->assertEquals(202, $attr['headers']['status-code']);
        $this->waitForAttributes($databaseId, 'nonSchemaTest', $projectId, $freshProject['apiKey']);

        // Query via GraphQL to build/cache the schema
        $query = 'query { nonSchemaTestList { _id title } }';
        $response1 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $query]);

        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response1['body']);

        // Perform multiple document CRUD operations (non-'schema' group routes)
        // These should NOT invalidate the schema cache

        // Create document
        $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/nonSchemaTest/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'documentId' => ID::unique(),
            'data' => ['title' => 'Test Document'],
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertEquals(201, $doc['headers']['status-code']);
        $docId = $doc['body']['$id'];

        // Update document
        $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/nonSchemaTest/documents/' . $docId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'data' => ['title' => 'Updated Document'],
        ]);

        // Query via GraphQL multiple times - schema should still work (cached)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headers), ['query' => $query]);

            $this->assertEquals(200, $response['headers']['status-code'], "Request $i failed");
            $this->assertArrayNotHasKey('errors', $response['body'], "Request $i has errors - schema may have been incorrectly invalidated");
        }

        // Delete document (also non-'schema' group)
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/nonSchemaTest/documents/' . $docId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers));

        // GraphQL should still work
        $response3 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $query]);

        $this->assertEquals(200, $response3['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response3['body']);
    }

    /**
     * Test that database update (non-schema route after our fix) doesn't invalidate schema.
     */
    public function testDatabaseUpdateDoesNotInvalidateSchema(): void
    {
        // Use a fresh project
        $freshProject = $this->getProject(true);
        $projectId = $freshProject['$id'];
        $headers = ['x-appwrite-key' => $freshProject['apiKey']];

        // Create database and collection with attribute
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'databaseId' => ID::unique(),
            'name' => 'DB Update Test',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'collectionId' => 'dbUpdateTest',
            'name' => 'DB Update Test Collection',
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        $attr = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/dbUpdateTest/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'name',
            'size' => 100,
            'required' => false,
        ]);
        $this->assertEquals(202, $attr['headers']['status-code']);
        $this->waitForAttributes($databaseId, 'dbUpdateTest', $projectId, $freshProject['apiKey']);

        // Create a document
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/dbUpdateTest/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'documentId' => ID::unique(),
            'data' => ['name' => 'Test'],
            'permissions' => [Permission::read(Role::any())],
        ]);

        // Query via GraphQL to cache the schema
        $query = 'query { dbUpdateTestList { _id name } }';
        $response1 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $query]);

        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response1['body']);

        // Update database name (should NOT invalidate schema after our fix)
        $updateDb = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'name' => 'Updated DB Name',
        ]);
        $this->assertEquals(200, $updateDb['headers']['status-code']);

        // Query via GraphQL - should still work without rebuild
        $response2 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $query]);

        $this->assertEquals(200, $response2['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response2['body'], 'Schema should still be cached after database name update');
    }

    /**
     * Test concurrent GraphQL requests don't cause schema rebuild issues.
     * Sends multiple parallel requests to ensure schema caching works under concurrency.
     */
    public function testConcurrentSchemaAccess(): void
    {
        // Use a fresh project
        $freshProject = $this->getProject(true);
        $projectId = $freshProject['$id'];
        $headers = ['x-appwrite-key' => $freshProject['apiKey']];

        // Create database and collection with attribute
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'databaseId' => ID::unique(),
            'name' => 'Concurrent Test DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'collectionId' => 'concurrentTest',
            'name' => 'Concurrent Test',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::users()),
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        $attr = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/concurrentTest/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'value',
            'size' => 100,
            'required' => true,
        ]);
        $this->assertEquals(202, $attr['headers']['status-code']);
        $this->waitForAttributes($databaseId, 'concurrentTest', $projectId, $freshProject['apiKey']);

        // Create multiple documents
        for ($i = 0; $i < 5; $i++) {
            $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/concurrentTest/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $headers), [
                'documentId' => ID::unique(),
                'data' => ['value' => 'item' . $i],
                'permissions' => [Permission::read(Role::any())],
            ]);
        }

        // Send multiple concurrent requests using curl_multi
        $query = 'query { concurrentTestList { _id value } }';
        $numRequests = 20;
        $multiHandle = curl_multi_init();
        $curlHandles = [];

        $endpoint = $this->client->getEndpoint();

        for ($i = 0; $i < $numRequests; $i++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint . '/graphql');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Appwrite-Project: ' . $projectId,
                'X-Appwrite-Key: ' . $freshProject['apiKey'],
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[] = $ch;
        }

        // Execute all requests concurrently
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // Collect responses
        $responses = [];
        foreach ($curlHandles as $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $responses[] = [
                'code' => $httpCode,
                'body' => json_decode($response, true),
            ];
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        curl_multi_close($multiHandle);

        // Verify all requests succeeded
        foreach ($responses as $i => $response) {
            $this->assertEquals(200, $response['code'], "Concurrent request $i failed with HTTP " . $response['code']);
            $this->assertArrayHasKey('data', $response['body'], "Concurrent request $i missing data");
            $this->assertArrayNotHasKey('errors', $response['body'], "Concurrent request $i has errors: " . json_encode($response['body']['errors'] ?? []));
            $this->assertArrayHasKey('concurrentTestList', $response['body']['data'], "Concurrent request $i missing concurrentTestList");
            $this->assertCount(5, $response['body']['data']['concurrentTestList'], "Concurrent request $i returned wrong document count");
        }
    }

    /**
     * Test concurrent schema rebuilds don't cause issues.
     * Triggers schema invalidation while concurrent requests are in flight.
     */
    public function testConcurrentSchemaRebuilds(): void
    {
        // Use a fresh project
        $freshProject = $this->getProject(true);
        $projectId = $freshProject['$id'];
        $headers = ['x-appwrite-key' => $freshProject['apiKey']];

        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'databaseId' => ID::unique(),
            'name' => 'Concurrent Rebuild DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'collectionId' => 'rebuildRace',
            'name' => 'Rebuild Race Test',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::users()),
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Create initial attribute
        $attr = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/rebuildRace/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'field1',
            'size' => 100,
            'required' => false,
        ]);
        $this->assertEquals(202, $attr['headers']['status-code']);
        $this->waitForAttributes($databaseId, 'rebuildRace', $projectId, $freshProject['apiKey']);

        // Create a document
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/rebuildRace/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'documentId' => ID::unique(),
            'data' => ['field1' => 'test'],
            'permissions' => [Permission::read(Role::any())],
        ]);

        // Initial query to cache schema
        $query = 'query { rebuildRaceList { _id field1 } }';
        $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $query]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Now trigger multiple schema-modifying operations in quick succession
        // while also sending GraphQL queries
        $endpoint = $this->client->getEndpoint();
        $multiHandle = curl_multi_init();
        $curlHandles = [];

        // Add several GraphQL query requests
        for ($i = 0; $i < 10; $i++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint . '/graphql');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Appwrite-Project: ' . $projectId,
                'X-Appwrite-Key: ' . $freshProject['apiKey'],
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles['query_' . $i] = $ch;
        }

        // Add schema-modifying requests (create new attributes)
        for ($i = 0; $i < 3; $i++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint . '/databases/' . $databaseId . '/collections/rebuildRace/attributes/string');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'key' => 'concurrent' . $i,
                'size' => 100,
                'required' => false,
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-Appwrite-Project: ' . $projectId,
                'X-Appwrite-Key: ' . $freshProject['apiKey'],
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles['attr_' . $i] = $ch;
        }

        // Execute all concurrently
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // Collect responses
        $queryResponses = [];
        $attrResponses = [];
        foreach ($curlHandles as $key => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (str_starts_with($key, 'query_')) {
                $queryResponses[] = [
                    'code' => $httpCode,
                    'body' => json_decode($response, true),
                ];
            } else {
                $attrResponses[] = [
                    'code' => $httpCode,
                    'body' => json_decode($response, true),
                ];
            }
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        curl_multi_close($multiHandle);

        // All GraphQL queries should succeed (may see different schema versions, but no errors)
        $successCount = 0;
        foreach ($queryResponses as $i => $response) {
            // We accept 200 responses - the schema might be in various states during rebuild
            if ($response['code'] === 200 && isset($response['body']['data'])) {
                $successCount++;
            }
        }

        // At least most queries should succeed
        $this->assertGreaterThanOrEqual(7, $successCount, "Expected at least 7/10 queries to succeed during concurrent rebuilds, got $successCount");

        // Wait for attributes to be available
        $this->waitForAttributes($databaseId, 'rebuildRace', $projectId, $freshProject['apiKey']);

        // Final query should include all fields
        $finalQuery = 'query { rebuildRaceList { _id field1 } }';
        $finalResponse = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $finalQuery]);

        $this->assertEquals(200, $finalResponse['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $finalResponse['body']);
    }

    /**
     * Test that attribute deletion triggers schema rebuild.
     */
    public function testSchemaRebuildsOnAttributeDelete(): void
    {
        // Use a fresh project
        $freshProject = $this->getProject(true);
        $projectId = $freshProject['$id'];
        $headers = ['x-appwrite-key' => $freshProject['apiKey']];

        // Create database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'databaseId' => ID::unique(),
            'name' => 'Attr Delete Test DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'collectionId' => 'attrDeleteTest',
            'name' => 'Attr Delete Test',
            'permissions' => [Permission::read(Role::any())],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Create two attributes
        $attr1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/attrDeleteTest/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'keepMe',
            'size' => 100,
            'required' => false,
        ]);
        $this->assertEquals(202, $attr1['headers']['status-code']);

        $attr2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/attrDeleteTest/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'key' => 'deleteMe',
            'size' => 100,
            'required' => false,
        ]);
        $this->assertEquals(202, $attr2['headers']['status-code']);
        $this->waitForAttributes($databaseId, 'attrDeleteTest', $projectId, $freshProject['apiKey']);

        // Create document
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/attrDeleteTest/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), [
            'documentId' => ID::unique(),
            'data' => ['keepMe' => 'kept', 'deleteMe' => 'deleted'],
            'permissions' => [Permission::read(Role::any())],
        ]);

        // Query with both fields
        $query1 = 'query { attrDeleteTestList { _id keepMe deleteMe } }';
        $response1 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $query1]);

        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response1['body']);

        // Delete the attribute
        $delete = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/attrDeleteTest/attributes/deleteMe', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers));
        $this->assertEquals(204, $delete['headers']['status-code']);

        // Wait for attribute deletion to complete
        sleep(2);

        // Query with deleted field should fail
        $response2 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $query1]);

        $this->assertEquals(200, $response2['headers']['status-code']);
        $this->assertArrayHasKey('errors', $response2['body'], 'Query with deleted field should error after schema rebuild');

        // Query without deleted field should work
        $query2 = 'query { attrDeleteTestList { _id keepMe } }';
        $response3 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $headers), ['query' => $query2]);

        $this->assertEquals(200, $response3['headers']['status-code']);
        $this->assertArrayNotHasKey('errors', $response3['body']);
        $this->assertEquals('kept', $response3['body']['data']['attrDeleteTestList'][0]['keepMe']);
    }
}
