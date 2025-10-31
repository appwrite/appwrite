<?php

namespace Tests\E2E\Services\Databases\VectorDB;

use Tests\E2E\Client;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Agents\Adapters\Ollama;

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
            'embeddingModel' => Ollama::MODEL_EMBEDDING_GEMMA,
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
            'embeddingModel' => Ollama::MODEL_EMBEDDING_GEMMA,
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
            'embeddingModel' => 'text-embedding-3-large',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals('Sample Collection', $collection['body']['name']);
        $this->assertEquals(1536, $collection['body']['dimensions']);
        $this->assertEquals('text-embedding-3-large', $collection['body']['embeddingModel']);

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
        $createCollection = function (string $databaseId, string $name, int $dimensions = 1536, string $embeddingModel = Ollama::MODEL_EMBEDDING_GEMMA) use ($projectId, $apiKey, $userId) {
            $res = $this->client->call(Client::METHOD_POST, '/vectordb/' . $databaseId . '/collections', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey
            ], [
                'collectionId' => ID::unique(),
                'name' => $name,
                'documentSecurity' => true,
                'dimensions' => $dimensions,
                'embeddingModel' => $embeddingModel,
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
        $tinyCollectionId = $createCollection($mediaDbId, $tinyCollectionName, $tinyDimensions, Ollama::MODEL_EMBEDDING_GEMMA);

        // Build embeddings vector of correct length and metadata object
        $embeddings = [];
        for ($i = 0; $i < $tinyDimensions; $i++) {
            $embeddings[] = (float)($i + 1) / 10.0;
        }
        $metadata = [
            'genre' => 'drama',
            'score' => 9,
            'tags' => ['award', 'festival']
        ];

        $docRes = $this->client->call(Client::METHOD_POST, '/vectordb/' . $mediaDbId . '/collections/' . $tinyCollectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'embeddings' => $embeddings,
                'metadata' => $metadata,
            ],
            'permissions' => [
                Permission::read(Role::user($userId)),
                Permission::write(Role::user($userId)),
            ],
        ]);

        $this->assertEquals(201, $docRes['headers']['status-code']);

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

}
