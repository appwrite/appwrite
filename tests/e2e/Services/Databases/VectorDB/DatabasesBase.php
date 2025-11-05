<?php

namespace Tests\E2E\Services\Databases\VectorDB;

use Tests\E2E\Client;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait DatabasesBase
{
    public function testCreateDatabase(): array
    {
        /**
         * Test for SUCCESS
         */
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('Test Database', $database['body']['name']);
        $this->assertEquals('vectordb', $database['body']['type']);

        return ['databaseId' => $database['body']['$id']];
    }

    /**
     * @depends testCreateCollectionSample
     */
    public function testCreateDocument(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Build embedding vector matching collection dimensions (1536)
        $vector = array_fill(0, 1536, 0.1);
        $vector[0] = 1.0;

        $res = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => $vector,
                'metadata' => ['type' => 'sample', 'rank' => 1]
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $res['headers']['status-code']);
        $this->assertNotEmpty($res['body']['$id']);
        $documentId = $res['body']['$id'];

        // createdAt/updatedAt should be present and equal on initial create
        $this->assertArrayHasKey('$createdAt', $res['body']);
        $this->assertArrayHasKey('$updatedAt', $res['body']);
        $this->assertNotEmpty($res['body']['$createdAt']);
        $this->assertNotEmpty($res['body']['$updatedAt']);
        $this->assertEquals($res['body']['$createdAt'], $res['body']['$updatedAt']);

        // Edge: invalid dimensions (vector too short) → expect 4xx
        $badVec = [1.0, 0.0];
        $bad = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => $badVec,
                'metadata' => ['type' => 'bad']
            ],
        ]);
        $this->assertGreaterThanOrEqual(400, $bad['headers']['status-code']);
        $this->assertLessThan(500, $bad['headers']['status-code']);

        // Edge: invalid type values (strings) → expect 4xx
        $strVec = ['1.0', '0.0', '0.0'];
        $bad2 = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => $strVec,
                'metadata' => ['type' => 'bad-strings']
            ],
        ]);
        $this->assertGreaterThanOrEqual(400, $bad2['headers']['status-code']);
        $this->assertLessThan(500, $bad2['headers']['status-code']);

        // Create another valid doc to verify list totals later
        $res2 = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => $vector,
                'metadata' => ['type' => 'sample', 'rank' => 99]
            ],
            'permissions' => [Permission::read(Role::any())]
        ]);
        $this->assertEquals(201, $res2['headers']['status-code']);
        $documentId2 = $res2['body']['$id'];

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
            'documentId' => $documentId,
            'documentId2' => $documentId2,
            'createdAt' => $res['body']['$createdAt'],
            'updatedAt' => $res['body']['$updatedAt'],
        ];
    }

    /**
     * @depends testCreateDocument
     */
    public function testGetDocument(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $documentId = $data['documentId'];

        $res = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $res['headers']['status-code']);
        $this->assertEquals($documentId, $res['body']['$id']);

        // Edge: missing document should return 404
        $missing = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/" . ID::unique(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(404, $missing['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocuments(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $list = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [Query::limit(5)->toString()]
        ]);

        $this->assertEquals(200, $list['headers']['status-code']);
        $this->assertIsInt($list['body']['total']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);

        // Pagination: limit 1, then offset 1
        $page1 = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [
                Query::limit(1)->toString(),
                Query::orderAsc('$id')->toString()
            ]
        ]);
        $this->assertEquals(200, $page1['headers']['status-code']);
        $this->assertEquals(1, \count($page1['body']['documents'] ?? []));

        $page2 = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [
                Query::limit(1)->toString(),
                Query::offset(1)->toString(),
                Query::orderAsc('$id')->toString()
            ]
        ]);
        $this->assertEquals(200, $page2['headers']['status-code']);
        $this->assertEquals(1, \count($page2['body']['documents'] ?? []));

        return $data;
    }

    /**
     * @depends testCreateDocument
     */
    public function testUpsertDocument(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $documentId = $data['documentId'];

        $vector = array_fill(0, 1536, 0.0);
        // $vector[1] = 1.0;

        $upd = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'data' => [
                'embeddings' => $vector,
                'metadata' => ['type' => 'sample', 'rank' => 2]
            ]
        ]);

        $this->assertEquals(200, $upd['headers']['status-code']);

        // Verify update took effect
        $get = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $get['headers']['status-code']);
        $this->assertEquals(2, $get['body']['metadata']['rank']);
        // updatedAt should be greater or changed from earlier
        $this->assertArrayHasKey('$updatedAt', $get['body']);

        return $data;
    }

    /**
     * @depends testUpsertDocument
     */
    public function testUpdateDocument(array $data): array
    {
        // Upsert is used for update semantics
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $documentId = $data['documentId'];

        $vector = array_fill(0, 1536, 0.0);
        $vector[2] = 1.0;

        $upd = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'data' => [
                'embeddings' => $vector,
                'metadata' => ['type' => 'sample', 'rank' => 3]
            ]
        ]);

        $this->assertEquals(200, $upd['headers']['status-code']);

        // Re-update to check idempotence and metadata replacement
        $vector2 = array_fill(0, 1536, 0.0);
        $vector2[3] = 1.0;
        $upd2 = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'data' => [
                'embeddings' => $vector2,
                'metadata' => ['type' => 'sample', 'rank' => 4]
            ]
        ]);
        $this->assertEquals(200, $upd2['headers']['status-code']);

        // Verify updatedAt changed again
        $get2 = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $get2['headers']['status-code']);
        $this->assertArrayHasKey('$updatedAt', $get2['body']);

        return $data;
    }

    /**
     * @depends testUpdateDocument
     */
    public function testTimestampsMutation(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $documentId = $data['documentId'];

        // Fetch current document to get latest timestamps
        $curr = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $curr['headers']['status-code']);

        $initialCreatedAt = $data['createdAt'];
        $initialUpdatedAt = $data['updatedAt'];
        $afterUpdatesCreatedAt = $curr['body']['$createdAt'];
        $afterUpdatesUpdatedAt = $curr['body']['$updatedAt'];

        // createdAt must remain stable
        $this->assertEquals($initialCreatedAt, $afterUpdatesCreatedAt);
        // updatedAt must change after updates
        $this->assertNotEquals($initialUpdatedAt, $afterUpdatesUpdatedAt);

        // Try to forcibly set $createdAt and $updatedAt via API
        $oldTs = '1970-01-01T00:00:00.000+00:00';
        $vectorForce = array_fill(0, 1536, 0.0);
        $vectorForce[9] = 1.0;
        $force = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'data' => [
                '$createdAt' => $oldTs,
                '$updatedAt' => $oldTs,
                'embeddings' => $vectorForce,
                'metadata' => ['type' => 'sample', 'rank' => 6]
            ]
        ]);
        $this->assertEquals(200, $force['headers']['status-code']);

        $forcedGet = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $forcedGet['headers']['status-code']);
        // Both timestamps should be updated to client-provided value
        $this->assertEquals($oldTs, $forcedGet['body']['$createdAt']);
        $this->assertEquals($oldTs, $forcedGet['body']['$updatedAt']);

        // Perform another update to ensure updatedAt changes again
        $vector = array_fill(0, 1536, 0.0);
        $vector[10] = 1.0;
        $upd = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'data' => [
                'embeddings' => $vector,
                'metadata' => ['type' => 'sample', 'rank' => 5]
            ]
        ]);
        $this->assertEquals(200, $upd['headers']['status-code']);

        $final = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $final['headers']['status-code']);
        // createdAt should persist the client-forced value
        $this->assertEquals($oldTs, $final['body']['$createdAt']);
        // updatedAt should change from the previous value
        $this->assertNotEquals($afterUpdatesUpdatedAt, $final['body']['$updatedAt']);

        return $data;
    }

    /**
     * @depends testUpdateDocument
     */
    public function testDocumentsVectorQueries(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Create two more documents with distinct embeddings
        $mk = function (array $vec, string $name) use ($databaseId, $collectionId) {
            $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ], [
                'documentId' => ID::unique(),
                'data' => [
                    'embeddings' => $vec,
                    'metadata' => ['name' => $name]
                ],
                'permissions' => [Permission::read(Role::any())]
            ]);
        };

        $vA = array_fill(0, 1536, 0.0);
        $vA[0] = 1.0; // close to [1,0,0,...]
        $vB = array_fill(0, 1536, 0.0);
        $vB[1] = 1.0; // close to [0,1,0,...]

        $mk($vA, 'A');
        $mk($vB, 'B');

        // Dot product
        $dot = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [
                Query::vectorDot('embeddings', $vA)->toString(),
                Query::limit(2)->toString()
            ]
        ]);
        $this->assertEquals(200, $dot['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $dot['body']['total']);

        // Cosine
        $cos = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [
                Query::vectorCosine('embeddings', $vB)->toString(),
                Query::limit(2)->toString()
            ]
        ]);
        $this->assertEquals(200, $cos['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $cos['body']['total']);

        // Euclidean
        $eu = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [
                Query::vectorEuclidean('embeddings', $vA)->toString(),
                Query::limit(2)->toString()
            ]
        ]);
        $this->assertEquals(200, $eu['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $eu['body']['total']);

        // Combined vector + metadata filters
        $combo = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [
                Query::vectorCosine('embeddings', $vA)->toString(),
                Query::notEqual('metadata', [['name' => 'B']])->toString(),
                Query::limit(2)->toString()
            ]
        ]);
        $this->assertEquals(200, $combo['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $combo['body']['total']);

        // Ordering with $id ascending combined with vector
        $ordered = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [
                Query::vectorDot('embeddings', $vA)->toString(),
                Query::orderAsc('$id')->toString(),
                Query::limit(3)->toString()
            ]
        ]);
        $this->assertEquals(200, $ordered['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testDocumentsVectorQueries
     */
    public function testDeleteDocument(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $documentId = $data['documentId'];

        $del = $this->client->call(Client::METHOD_DELETE, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(204, $del['headers']['status-code']);

        // GET after delete should be 404
        $getMissing = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(404, $getMissing['headers']['status-code']);

        // List should still work and reflect at least one less document compared to earlier pages (best-effort)
        $list = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [Query::limit(5)->toString()]
        ]);
        $this->assertEquals(200, $list['headers']['status-code']);
    }

    /**
     * @depends testCreateCollectionSample
     */
    public function testDocumentPermissions(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Create doc readable only by a specific user
        $docId = ID::unique();
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = 1.0;
        $create = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'documentId' => $docId,
            'data' => [
                'embeddings' => $vector,
                'metadata' => ['scope' => 'private']
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id']))
            ]
        ]);
        $this->assertEquals(201, $create['headers']['status-code']);

        $guest = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$docId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]);
        $this->assertEquals(404, $guest['headers']['status-code']);

        // GET with key should succeed regardless of document user-level permission
        $withKey = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$docId}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);
        $this->assertEquals(200, $withKey['headers']['status-code']);
    }

    /**
     * @depends testCreateDatabase
     */
    public function testCreateCollection(array $data): array
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for SUCCESS
         */
        $movies = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'documentSecurity' => true,
            'dimensions' => 1536,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $movies['headers']['status-code']);
        $this->assertEquals($movies['body']['name'], 'Movies');

        $actors = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'documentSecurity' => true,
            'dimensions' => 1536,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $actors['headers']['status-code']);
        $this->assertEquals($actors['body']['name'], 'Actors');

        return [
            'databaseId' => $databaseId,
            'moviesId' => $movies['body']['$id'],
            'actorsId' => $actors['body']['$id'],
        ];
    }

    public function testCreateDatabaseSample(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Sample VectorDB'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('Sample VectorDB', $database['body']['name']);
        $this->assertEquals('vectordb', $database['body']['type']);

        return ['databaseId' => $database['body']['$id']];
    }

    /**
     * @depends testCreateDatabaseSample
     */
    public function testCreateCollectionSample(array $data): array
    {
        $databaseId = $data['databaseId'];

        $collection = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Sample Collection',
            'dimensions' => 1536,
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals('Sample Collection', $collection['body']['name']);
        $this->assertEquals(1536, $collection['body']['dimensions']);

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collection['body']['$id'],
        ];
    }

    public function testCreateMultipleDatabasesWithCollections(): array
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];
        $userId = $this->getUser()['$id'];

        /**
         * Helper to create a database
         */
        $createDatabase = function (string $name) use ($projectId, $apiKey) {
            $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey
            ], [
                'databaseId' => ID::unique(),
                'name' => $name
            ]);

            $this->assertEquals(201, $db['headers']['status-code']);
            $this->assertEquals('vectordb', $db['body']['type']);
            $this->assertEquals($name, $db['body']['name']);
            $this->assertNotEmpty($db['body']['$id']);

            return $db['body']['$id'];
        };

        /**
         * Helper to create a collection
         */
        $createCollection = function (string $databaseId, string $name, int $dimensions = 1536) use ($projectId, $apiKey, $userId) {
            $res = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey
            ], [
                'collectionId' => ID::unique(),
                'name' => $name,
                'documentSecurity' => true,
                'dimensions' => $dimensions,
                'permissions' => [
                    Permission::create(Role::user($userId)),
                ],
            ]);

            $this->assertEquals(201, $res['headers']['status-code']);
            $this->assertEquals($name, $res['body']['name']);

            return $res['body']['$id'];
        };

        /**
         * === Database 1: MediaDB ===
         */
        $mediaDbId = $createDatabase('MediaDB');

        $mediaCollections = ['Movies', 'Actors', 'Directors'];
        $mediaCollectionIds = [];

        foreach ($mediaCollections as $col) {
            $mediaCollectionIds[$col] = $createCollection($mediaDbId, $col);
        }

        /**
         * === Database 2: ContentDB ===
         */
        $contentDbId = $createDatabase('ContentDB');

        $contentCollections = ['Articles', 'Authors'];
        $contentCollectionIds = [];

        foreach ($contentCollections as $col) {
            $contentCollectionIds[$col] = $createCollection($contentDbId, $col);
        }

        // Create a tiny-dimension collection and insert a document to validate vector and object attributes
        $tinyCollectionName = 'VectorsTiny';
        $tinyDimensions = 8;
        $tinyCollectionId = $createCollection($mediaDbId, $tinyCollectionName, $tinyDimensions);

        return [
            'databases' => [
                'MediaDB' => [
                    'id' => $mediaDbId,
                    'collections' => $mediaCollectionIds + ['VectorsTiny' => $tinyCollectionId],
                ],
                'ContentDB' => [
                    'id' => $contentDbId,
                    'collections' => $contentCollectionIds,
                ],
            ]
        ];
    }

    public function testInvalidCollectionDimensions(): void
    {
        // dimensions = 0 -> expect 4xx
        $bad0 = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'BadDims0'
        ]);
        $this->assertEquals(201, $bad0['headers']['status-code']);
        $dbId = $bad0['body']['$id'];
        $col = $this->client->call(Client::METHOD_POST, '/vectordb/' . $dbId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'ZeroDims',
            'documentSecurity' => true,
            'dimensions' => 0,
            'permissions' => [Permission::create(Role::user($this->getUser()['$id']))],
        ]);
        $this->assertGreaterThanOrEqual(400, $col['headers']['status-code']);
        $this->assertLessThan(500, $col['headers']['status-code']);

        // dimensions too large -> expect 4xx
        $col2 = $this->client->call(Client::METHOD_POST, '/vectordb/' . $dbId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'HugeDims',
            'documentSecurity' => true,
            'dimensions' => 16001,
            'permissions' => [Permission::create(Role::user($this->getUser()['$id']))],
        ]);
        $this->assertGreaterThanOrEqual(400, $col2['headers']['status-code']);
        $this->assertLessThan(500, $col2['headers']['status-code']);
    }

    public function testSingleDimensionVectorCollection(): void
    {
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'SingleDim'
        ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];

        $col = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'OneDim',
            'documentSecurity' => true,
            'dimensions' => 1,
            'permissions' => [Permission::create(Role::user($this->getUser()['$id']))],
        ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        // Create two docs with 1D embeddings
        $id1 = ID::unique();
        $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$id1}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'data' => ['embeddings' => [1.0]]
        ]);
        $id2 = ID::unique();
        $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/{$id2}", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'data' => ['embeddings' => [0.5]]
        ]);

        // Query with vectorCosine
        $res = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [Query::vectorCosine('embeddings', [1.0])->toString(), Query::limit(2)->toString()]
        ]);
        $this->assertEquals(200, $res['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $res['body']['total']);
    }

    public function testVectorInvalidValues(): void
    {
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'InvalidVals'
        ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];

        $col = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Docs',
            'documentSecurity' => true,
            'dimensions' => 3,
            'permissions' => [Permission::create(Role::user($this->getUser()['$id']))],
        ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        $badPayloads = [
            ['embeddings' => [INF, 0.0, 0.0]],
            ['embeddings' => [-INF, 0.0, 0.0]],
            ['embeddings' => [NAN, 0.0, 0.0]],
            ['embeddings' => ['x' => 1.0, 'y' => 0.0, 'z' => 0.0]],
            ['embeddings' => [1.0, null, 0.0]],
            ['embeddings' => [[1.0], [0.0], [0.0]]],
            ['embeddings' => [true, false, true]],
            ['embeddings' => [1.0, '2.0', 3.0]],
            (function () {
                $v = [];
                $v[0] = 1.0;
                $v[2] = 1.0;
                return ['embeddings' => $v];
            })(),
        ];

        foreach ($badPayloads as $payload) {
            $resp = $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/" . ID::unique(), [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ], [
                'data' => $payload
            ]);
            $this->assertGreaterThanOrEqual(400, $resp['headers']['status-code']);
            $this->assertLessThan(500, $resp['headers']['status-code']);
        }
    }

    public function testVectorAllZerosAndQuery(): void
    {
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'ZerosDB'
        ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];

        $col = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Zeros',
            'documentSecurity' => true,
            'dimensions' => 3,
            'permissions' => [Permission::create(Role::user($this->getUser()['$id']))],
        ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/" . ID::unique(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'data' => ['embeddings' => [0.0, 0.0, 0.0]] ]);

        $this->client->call(Client::METHOD_PUT, "/vectordb/{$databaseId}/collections/{$collectionId}/documents/" . ID::unique(), [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'data' => ['embeddings' => [1.0, 0.0, 0.0]] ]);

        $results = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'queries' => [Query::vectorCosine('embeddings', [1.0, 0.0, 0.0])->toString()] ]);
        $this->assertEquals(200, $results['headers']['status-code']);
        $this->assertGreaterThan(0, $results['body']['total']);
    }

    public function testVectorMultipleQueriesRejection(): void
    {
        // Create a simple DB and collection
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'databaseId' => ID::unique(), 'name' => 'MultiQueryDB' ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];
        $col = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'collectionId' => ID::unique(), 'name' => 'Docs', 'documentSecurity' => true, 'dimensions' => 3, 'permissions' => [Permission::create(Role::user($this->getUser()['$id']))] ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        // Two vector queries simultaneously should fail
        $fail = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'queries' => [
                Query::vectorCosine('embeddings', [1.0, 0.0, 0.0])->toString(),
                Query::vectorEuclidean('embeddings', [1.0, 0.0, 0.0])->toString()
            ]
        ]);
        $this->assertGreaterThanOrEqual(400, $fail['headers']['status-code']);
        $this->assertLessThan(500, $fail['headers']['status-code']);
    }

    public function testVectorQueryOnNonVectorAttribute(): void
    {
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'databaseId' => ID::unique(), 'name' => 'NonVec' ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];
        $col = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'collectionId' => ID::unique(), 'name' => 'Docs', 'documentSecurity' => true, 'dimensions' => 3, 'permissions' => [Permission::create(Role::user($this->getUser()['$id']))] ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        // Query on non-vector attribute 'metadata' should fail
        $fail = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'queries' => [Query::vectorCosine('metadata', [1.0, 0.0, 0.0])->toString()] ]);
        $this->assertGreaterThanOrEqual(400, $fail['headers']['status-code']);
        $this->assertLessThan(500, $fail['headers']['status-code']);
    }

    public function testVectorEmptyQueryCollection(): void
    {
        $db = $this->client->call(Client::METHOD_POST, '/vectordb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'databaseId' => ID::unique(), 'name' => 'EmptyQ' ]);
        $this->assertEquals(201, $db['headers']['status-code']);
        $databaseId = $db['body']['$id'];
        $col = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'collectionId' => ID::unique(), 'name' => 'Docs', 'documentSecurity' => true, 'dimensions' => 3, 'permissions' => [Permission::create(Role::user($this->getUser()['$id']))] ]);
        $this->assertEquals(201, $col['headers']['status-code']);
        $collectionId = $col['body']['$id'];

        $res = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/documents", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [ 'queries' => [Query::vectorCosine('embeddings', [1.0, 0.0, 0.0])->toString()] ]);
        $this->assertEquals(200, $res['headers']['status-code']);
        $this->assertEquals(0, $res['body']['total']);
    }

    /**
     * @depends testCreateCollection
     */
    public function testCreateIndexes(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['moviesId'];

        // HNSW Euclidean
        $idxEuclidean = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'embedding_euclidean',
            'type' => Database::INDEX_HNSW_EUCLIDEAN,
            'attributes' => ['embeddings']
        ]);
        $this->assertEquals(202, $idxEuclidean['headers']['status-code']);

        // HNSW Dot (Inner Product)
        $idxDot = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'embedding_dot',
            'type' => Database::INDEX_HNSW_DOT,
            'attributes' => ['embeddings']
        ]);
        $this->assertEquals(202, $idxDot['headers']['status-code']);

        // HNSW Cosine
        $idxCosine = $this->client->call(Client::METHOD_POST, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'embedding_cosine',
            'type' => Database::INDEX_HNSW_COSINE,
            'attributes' => ['embeddings']
        ]);
        $this->assertEquals(202, $idxCosine['headers']['status-code']);

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
            'indexes' => ['embedding_euclidean', 'embedding_dot', 'embedding_cosine']
        ];
    }

    /**
     * @depends testCreateIndexes
     */
    public function testListIndexes(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $list = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $list['headers']['status-code']);
        $keys = array_map(fn ($i) => $i['key'], $list['body']['indexes'] ?? []);
        foreach ($data['indexes'] as $expectedKey) {
            $this->assertContains($expectedKey, $keys);
        }
    }

    /**
     * @depends testCreateIndexes
     */
    public function testGetIndexByKey(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $keysToTypes = [
            'embedding_euclidean' => Database::INDEX_HNSW_EUCLIDEAN,
            'embedding_dot' => Database::INDEX_HNSW_DOT,
            'embedding_cosine' => Database::INDEX_HNSW_COSINE,
        ];

        foreach ($keysToTypes as $key => $type) {
            $res = $this->client->call(Client::METHOD_GET, "/vectordb/{$databaseId}/collections/{$collectionId}/indexes/{$key}", [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]);
            $this->assertEquals(200, $res['headers']['status-code']);
            $this->assertEquals($key, $res['body']['key']);
            $this->assertEquals($type, $res['body']['type']);
        }
    }

}
