<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\VectorDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabasesCustomServerTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;

    public function testListDatabases(): array
    {
        $db1 = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::custom('first'),
            'name' => 'Test 1',
        ]);
        $this->assertEquals(201, $db1['headers']['status-code']);
        $this->assertEquals('Test 1', $db1['body']['name']);
        $this->assertEquals('vectordb', $db1['body']['type']);
        // Validate database response model fields on create
        $this->assertArrayHasKey('$id', $db1['body']);
        $this->assertArrayHasKey('$createdAt', $db1['body']);
        $this->assertArrayHasKey('$updatedAt', $db1['body']);
        $this->assertArrayHasKey('enabled', $db1['body']);

        $db2 = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::custom('second'),
            'name' => 'Test 2',
        ]);
        $this->assertEquals(201, $db2['headers']['status-code']);
        $this->assertEquals('Test 2', $db2['body']['name']);
        $this->assertEquals('vectordb', $db2['body']['type']);

        $list = $this->client->call(Client::METHOD_GET, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertIsInt($list['body']['total']);
        $this->assertGreaterThanOrEqual(2, $list['body']['total']);
        $this->assertIsArray($list['body']['databases']);
        $this->assertArrayHasKey('$id', $list['body']['databases'][0]);
        $this->assertArrayHasKey('name', $list['body']['databases'][0]);
        $this->assertArrayHasKey('type', $list['body']['databases'][0]);

        return ['databaseId' => $db1['body']['$id']];
    }

    /**
     * @depends testListDatabases
     */
    public function testGetDatabase(array $data): array
    {
        $databaseId = $data['databaseId'];
        $res = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $res['headers']['status-code']);
        $this->assertEquals($databaseId, $res['body']['$id']);
        $this->assertEquals('Test 1', $res['body']['name']);
        $this->assertEquals('vectordb', $res['body']['type']);
        return ['databaseId' => $databaseId];
    }

    /**
     * @depends testListDatabases
     */
    public function testUpdateDatabase(array $data): array
    {
        $databaseId = $data['databaseId'];
        $res = $this->client->call(Client::METHOD_PUT, '/vectordb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Test 1 Updated',
        ]);
        $this->assertEquals(200, $res['headers']['status-code']);
        $this->assertEquals('Test 1 Updated', $res['body']['name']);
        $this->assertEquals('vectordb', $res['body']['type']);
        return ['databaseId' => $databaseId];
    }

    /**
     * @depends testListDatabases
     */
    public function testDeleteDatabase(array $data): void
    {
        $databaseId = $data['databaseId'];
        $del = $this->client->call(Client::METHOD_DELETE, '/vectordb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(204, $del['headers']['status-code']);
        $this->assertEquals("", $del['body']);

        $get = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(404, $get['headers']['status-code']);
    }

    public function testCollectionsCRUD(): array
    {
        // Create database for collections tests
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Collections DB',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Create two collections
        $col1 = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Test 1',
            'collectionId' => ID::custom('first'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
            'dimensions' => 3,
        ]);
        $this->assertEquals(201, $col1['headers']['status-code']);
        // Validate collection response model on create
        $this->assertArrayHasKey('$id', $col1['body']);
        $this->assertArrayHasKey('$createdAt', $col1['body']);
        $this->assertArrayHasKey('$updatedAt', $col1['body']);
        $this->assertArrayHasKey('enabled', $col1['body']);
        $this->assertArrayHasKey('documentSecurity', $col1['body']);
        $this->assertArrayHasKey('dimensions', $col1['body']);

        $col2 = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Test 2',
            'collectionId' => ID::custom('second'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
            'dimensions' => 3,
        ]);
        $this->assertEquals(201, $col2['headers']['status-code']);
        $this->assertArrayHasKey('$id', $col2['body']);
        $this->assertArrayHasKey('$createdAt', $col2['body']);
        $this->assertArrayHasKey('$updatedAt', $col2['body']);

        // List collections
        $list = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertIsInt($list['body']['total']);
        $this->assertGreaterThanOrEqual(2, $list['body']['total']);
        $this->assertIsArray($list['body']['collections']);
        $this->assertArrayHasKey('$id', $list['body']['collections'][0]);
        $this->assertArrayHasKey('name', $list['body']['collections'][0]);
        $this->assertArrayHasKey('dimensions', $list['body']['collections'][0]);

        // Get collection
        $get = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId . '/collections/' . $col1['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals($col1['body']['$id'], $get['body']['$id']);
        $this->assertEquals('Test 1', $get['body']['name']);
        $this->assertEquals(3, $get['body']['dimensions']);

        // Update collection (name only)
        $upd = $this->client->call(Client::METHOD_PUT, '/vectordb/' . $databaseId . '/collections/' . $col1['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Test 1 Updated',
        ]);
        $this->assertEquals(200, $upd['headers']['status-code']);
        $this->assertEquals('Test 1 Updated', $upd['body']['name']);
        $this->assertArrayHasKey('$updatedAt', $upd['body']);

        // Delete collection
        $del = $this->client->call(Client::METHOD_DELETE, '/vectordb/' . $databaseId . '/collections/' . $col2['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(204, $del['headers']['status-code']);
        $this->assertEquals("", $del['body']);

        return [
            'databaseId' => $databaseId,
            'collectionId' => $col1['body']['$id'],
        ];
    }

    /**
     * @depends testCollectionsCRUD
     */
    public function testUpdateCollectionMore(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Update collection name and dimensions
        $upd = $this->client->call(Client::METHOD_PUT, '/vectordb/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Test 1 Renamed',
            'dimensions' => 4,
        ]);
        $this->assertEquals(200, $upd['headers']['status-code']);
        $this->assertEquals('Test 1 Renamed', $upd['body']['name']);
        $this->assertEquals(4, $upd['body']['dimensions']);

        // Read back to confirm
        $get = $this->client->call(Client::METHOD_GET, '/vectordb/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals('Test 1 Renamed', $get['body']['name']);
        $this->assertEquals(4, $get['body']['dimensions']);

        return $data;
    }

    /**
     * @depends testCollectionsCRUD
     */
    public function testUpdateCollectionEnabledFlag(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Disable collection
        $disable = $this->client->call(Client::METHOD_PUT, '/vectordb/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Updated',
            'enabled' => false,
        ]);
        $this->assertEquals(200, $disable['headers']['status-code']);
        $this->assertFalse($disable['body']['enabled']);

        // Re-enable collection
        $enable = $this->client->call(Client::METHOD_PUT, '/vectordb/' . $databaseId . '/collections/' . $collectionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Updated',
            'enabled' => true,
        ]);
        $this->assertEquals(200, $enable['headers']['status-code']);
        $this->assertTrue($enable['body']['enabled']);

        return $data;
    }

    public function testUpdateDatabaseNameAndEnabled(): void
    {
        // Create isolated database for this test to avoid ordering conflicts
        $create = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Update DB',
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);
        $databaseId = $create['body']['$id'];

        // Update name
        $rename = $this->client->call(Client::METHOD_PUT, '/vectordb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Test DB Renamed',
        ]);
        $this->assertEquals(200, $rename['headers']['status-code']);
        $this->assertEquals('Test DB Renamed', $rename['body']['name']);

        // Toggle enabled off then on
        $disable = $this->client->call(Client::METHOD_PUT, '/vectordb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Test DB Renamed',
            'enabled' => false,
        ]);
        $this->assertEquals(200, $disable['headers']['status-code']);
        $this->assertFalse($disable['body']['enabled']);

        $enable = $this->client->call(Client::METHOD_PUT, '/vectordb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Test DB Renamed',
            'enabled' => true,
        ]);
        $this->assertEquals(200, $enable['headers']['status-code']);
        $this->assertTrue($enable['body']['enabled']);

        // Cleanup
        $del = $this->client->call(Client::METHOD_DELETE, '/vectordb/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(204, $del['headers']['status-code']);
    }

    /**
     * @depends testCollectionsCRUD
     */
    public function testRecreateIndex(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Create a new index variant
        $create = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'embedding_euclidean_v2',
            'type' => Database::INDEX_HNSW_EUCLIDEAN,
            'attributes' => ['embeddings']
        ]);
        $this->assertEquals(202, $create['headers']['status-code']);

        // Ensure it exists
        $get = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes/embedding_euclidean_v2", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals('embedding_euclidean_v2', $get['body']['key']);

        // Delete it
        $del = $this->client->call(Client::METHOD_DELETE, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes/embedding_euclidean_v2", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(204, $del['headers']['status-code']);
    }

    /**
     * @depends testCollectionsCRUD
     */
    public function testIndexesCRUD(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Create indexes
        $eu = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'embedding_euclidean',
            'type' => Database::INDEX_HNSW_EUCLIDEAN,
            'attributes' => ['embeddings']
        ]);
        $this->assertEquals(202, $eu['headers']['status-code']);

        $dot = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'embedding_dot',
            'type' => Database::INDEX_HNSW_DOT,
            'attributes' => ['embeddings']
        ]);
        $this->assertEquals(202, $dot['headers']['status-code']);

        $cos = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'embedding_cosine',
            'type' => Database::INDEX_HNSW_COSINE,
            'attributes' => ['embeddings']
        ]);
        $this->assertEquals(202, $cos['headers']['status-code']);

        // List indexes
        $list = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertIsArray($list['body']['indexes']);
        $keys = array_map(fn ($i) => $i['key'], $list['body']['indexes']);
        $this->assertContains('embedding_euclidean', $keys);
        $this->assertContains('embedding_dot', $keys);
        $this->assertContains('embedding_cosine', $keys);

        // Get index by key
        $get = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes/embedding_euclidean", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals('embedding_euclidean', $get['body']['key']);
        $this->assertEquals(Database::INDEX_HNSW_EUCLIDEAN, $get['body']['type']);

        // Delete index
        $del = $this->client->call(Client::METHOD_DELETE, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes/embedding_dot", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(204, $del['headers']['status-code']);
        sleep(4);
        // Ensure it's gone
        $getMissing = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes/embedding_dot", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(404, $getMissing['headers']['status-code']);
    }

    public function testBulkCreate(): array
    {
        // Setup: create isolated database and collection
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'BulkDBCreate'
        ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];

        $col = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'BulkColCreate',
            'documentSecurity' => true,
            'dimensions' => 3,
            'permissions' => [Permission::read(Role::any())]
        ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        $docs = [
            [
                'embeddings' => [1.0, 0.0, 0.0],
                'metadata' => ['group' => 'bulkA'],
                '$permissions' => [Permission::read(Role::any())]
            ],
            [
                'embeddings' => [0.0, 1.0, 0.0],
                'metadata' => ['group' => 'bulkB'],
                '$permissions' => [Permission::read(Role::any())]
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documents' => $docs
        ]);

        $this->assertEquals(201, $res['headers']['status-code']);
        $this->assertIsInt($res['body']['total'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $res['body']['total']);
        $this->assertIsArray($res['body']['documents']);
        $this->assertCount(2, $res['body']['documents']);

        $ids = array_map(fn ($d) => $d['$id'], $res['body']['documents']);
        $this->assertNotEmpty($ids[0]);
        $this->assertNotEmpty($ids[1]);

        // Fetch and validate persisted data via GET
        foreach ($ids as $i => $id) {
            $get = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$id}", [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]);
            $this->assertEquals(200, $get['headers']['status-code']);
            $this->assertEquals($id, $get['body']['$id']);
            $this->assertIsArray($get['body']['embeddings']);
            $this->assertCount(3, $get['body']['embeddings']);
            $this->assertArrayHasKey('group', $get['body']['metadata']);
        }

        return [ 'databaseId' => $databaseId, 'collectionId' => $collectionId, 'bulkIds' => $ids ];
    }

    public function testCreateTextEmbeddingsSuccessAndErrors(): void
    {
        // Setup new database and collection
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'EmbedDB',
        ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];

        $col = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'EmbedCol',
            'documentSecurity' => true,
            'dimensions' => 3,
            'permissions' => [Permission::read(Role::any())]
        ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        // Success: two embeddings
        $ok = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/text", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documents' => [
                ['text' => 'hello world', 'embeddingModel' => 'embeddinggemma'],
                ['text' => 'second sentence', 'embeddingModel' => 'embeddinggemma'],
            ]
        ]);
        $this->assertEquals(200, $ok['headers']['status-code']);
        $this->assertIsInt($ok['body']['total'] ?? 0);
        $this->assertEquals(2, $ok['body']['total']);
        $this->assertIsArray($ok['body']['embeddings']);
        $this->assertCount(2, $ok['body']['embeddings']);
        foreach ($ok['body']['embeddings'] as $embed) {
            $this->assertIsString($embed['model']);
            $this->assertIsInt($embed['dimensions']);
            $this->assertIsArray($embed['embeddings']);
            $this->assertGreaterThan(0, count($embed['embeddings']));
        }

        // Error: missing documents
        $missingDocs = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/text", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], []);
        $this->assertEquals(400, $missingDocs['headers']['status-code']);

        // Error: invalid item structure (not an object)
        $invalidItem = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/text", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documents' => [ 'oops' ]
        ]);
        $this->assertEquals(400, $invalidItem['headers']['status-code']);

        // Error: missing text
        $missingText = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/text", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documents' => [ ['embeddingModel' => 'embeddinggemma'] ]
        ]);
        $this->assertEquals(400, $missingText['headers']['status-code']);

        // Error: missing embeddingModel
        $missingModel = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/text", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documents' => [ ['text' => 'no model'] ]
        ]);
        $this->assertEquals(400, $missingModel['headers']['status-code']);

        // Error: unknown embedding model
        $unknownModel = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/text", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documents' => [ ['text' => 'hello', 'embeddingModel' => 'nonexistent-model'] ]
        ]);
        $this->assertEquals(400, $unknownModel['headers']['status-code']);
    }

    public function testBulkUpsert(): void
    {
        // Setup fresh db/collection
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'databaseId' => ID::unique(), 'name' => 'BulkDBUpsert' ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];

        $col = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'BulkColUpsert',
            'documentSecurity' => true,
            'dimensions' => 3,
            'permissions' => [Permission::read(Role::any())]
        ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        $docs = [
            [
                'embeddings' => [0.5, 0.5, 0.0],
                'metadata' => ['group' => 'bulkA', 'updated' => true],
                '$permissions' => [Permission::read(Role::any())]
            ],
            [
                'embeddings' => [0.2, 0.8, 0.0],
                'metadata' => ['group' => 'bulkB', 'updated' => true],
                '$permissions' => [Permission::read(Role::any())]
            ],
        ];

        $res = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documents' => $docs
        ]);

        $this->assertEquals(200, $res['headers']['status-code']);
        $this->assertIsArray($res['body']['documents']);
        $this->assertCount(2, $res['body']['documents']);
        $this->assertTrue($res['body']['documents'][0]['metadata']['updated']);
        $this->assertTrue($res['body']['documents'][1]['metadata']['updated']);

        // Fetch and validate updated content
        $ids = array_map(fn ($d) => $d['$id'], $res['body']['documents']);
        foreach ($ids as $id) {
            $get = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$id}", [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]);
            $this->assertEquals(200, $get['headers']['status-code']);
            $this->assertTrue($get['body']['metadata']['updated']);
        }

        // Perform another bulk upsert to mutate the same documents
        $docs2 = [
            [ 'embeddings' => [0.6, 0.4, 0.0], 'metadata' => ['updatedAgain' => true] ],
            [ 'embeddings' => [0.3, 0.7, 0.0], 'metadata' => ['updatedAgain' => true] ],
        ];
        $res2 = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documents' => $docs2
        ]);
        $this->assertEquals(200, $res2['headers']['status-code']);
        $this->assertIsArray($res2['body']['documents']);
        $this->assertCount(2, $res2['body']['documents']);

        // Fetch again and assert second update persisted
        $ids2 = array_map(fn ($d) => $d['$id'], $res2['body']['documents']);
        foreach ($ids2 as $id) {
            $get2 = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$id}", [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]);
            $this->assertEquals(200, $get2['headers']['status-code']);
            $this->assertTrue($get2['body']['metadata']['updatedAgain']);
        }
    }

    public function testBulkUpdate(): void
    {
        // Setup: create db/collection and two docs
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'databaseId' => ID::unique(), 'name' => 'BulkDBUpdate' ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];

        $col = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'BulkColUpdate',
            'documentSecurity' => true,
            'dimensions' => 3,
            'permissions' => [Permission::read(Role::any())]
        ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        $seed = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documents' => [
                ['embeddings' => [1.0,0.0,0.0], 'metadata' => ['seed' => 1], '$permissions' => [Permission::read(Role::any())]],
                ['embeddings' => [0.0,1.0,0.0], 'metadata' => ['seed' => 2], '$permissions' => [Permission::read(Role::any())]]
            ]
        ]);
        $this->assertEquals(200, $seed['headers']['status-code']);
        $ids = array_map(fn ($d) => $d['$id'], $seed['body']['documents']);

        $res = $this->client->call(Client::METHOD_PATCH, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'data' => [ 'metadata' => ['bulkUpdated' => true] ],
            'queries' => [
                \Utopia\Database\Query::equal('$id', $ids)->toString()
            ]
        ]);

        $this->assertEquals(200, $res['headers']['status-code']);
        $this->assertIsArray($res['body']['documents']);
        $this->assertCount(2, $res['body']['documents']);
        foreach ($res['body']['documents'] as $doc) {
            $this->assertTrue($doc['metadata']['bulkUpdated']);
        }

        // Fetch by IDs and assert update persisted
        foreach ($ids as $id) {
            $get = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$id}", [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]);
            $this->assertEquals(200, $get['headers']['status-code']);
            $this->assertTrue($get['body']['metadata']['bulkUpdated']);
        }
    }

    public function testBulkDelete(): void
    {
        // Setup: create db/collection and two docs
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'databaseId' => ID::unique(), 'name' => 'BulkDBDelete' ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];

        $col = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'BulkColDelete',
            'documentSecurity' => true,
            'dimensions' => 3,
            'permissions' => [Permission::read(Role::any())]
        ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        $seed = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documents' => [
                ['embeddings' => [1.0,0.0,0.0], 'metadata' => ['seed' => 1], '$permissions' => [Permission::read(Role::any())]],
                ['embeddings' => [0.0,1.0,0.0], 'metadata' => ['seed' => 2], '$permissions' => [Permission::read(Role::any())]]
            ]
        ]);
        $this->assertEquals(200, $seed['headers']['status-code']);
        $ids = array_map(fn ($d) => $d['$id'], $seed['body']['documents']);

        $res = $this->client->call(Client::METHOD_DELETE, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [
                \Utopia\Database\Query::equal('$id', $ids)->toString()
            ]
        ]);
        $this->assertEquals(200, $res['headers']['status-code']);
        $this->assertIsInt($res['body']['total'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $res['body']['total']);

        // Ensure they are deleted
        foreach ($ids as $id) {
            $get = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$id}", [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]);
            $this->assertEquals(404, $get['headers']['status-code']);
        }
    }

}
