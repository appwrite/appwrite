<?php

namespace Tests\E2E\Services\GraphQL\Legacy;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\GraphQL\Base;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabaseClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use Base;

    /**
     * Cached database data
     */
    private static array $database = [];

    /**
     * Cached collection data (includes database)
     */
    private static array $collection = [];

    /**
     * Cached document data (includes database, collection)
     */
    private static array $document = [];

    /**
     * Cached bulk operations data
     */
    private static array $bulkData = [];

    /**
     * Helper to set up database
     */
    protected function setupDatabase(): array
    {
        if (!empty(static::$database)) {
            return static::$database;
        }

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => ID::unique(),
                'name' => 'Actors',
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertIsArray($database['body']['data']);
        $this->assertArrayNotHasKey('errors', $database['body']);
        static::$database = $database['body']['data']['databasesCreate'];

        return static::$database;
    }

    /**
     * Helper to set up collection (includes database setup)
     */
    protected function setupCollection(): array
    {
        if (!empty(static::$collection)) {
            return static::$collection;
        }

        $database = $this->setupDatabase();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => 'actors',
                'name' => 'Actors',
                'documentSecurity' => false,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ],
            ]
        ];

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertIsArray($collection['body']['data']);
        $this->assertArrayNotHasKey('errors', $collection['body']);

        static::$collection = [
            'database' => $database,
            'collection' => $collection['body']['data']['databasesCreateCollection'],
        ];

        return static::$collection;
    }

    /**
     * Helper to set up attributes (string and integer)
     */
    protected function setupAttributes(): array
    {
        $data = $this->setupCollection();

        // Use a static flag to track if attributes have been created
        static $attributesCreated = false;
        if ($attributesCreated) {
            return $data;
        }

        $projectId = $this->getProject()['$id'];
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        // Create string attribute
        $query = $this->getQuery(self::CREATE_STRING_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'name',
                'size' => 256,
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);

        // Create integer attribute
        $query = $this->getQuery(self::CREATE_INTEGER_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'age',
                'min' => 18,
                'max' => 150,
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);

        $attributesCreated = true;

        return $data;
    }

    /**
     * Helper to set up document (includes database, collection, and attributes setup)
     */
    protected function setupDocument(): array
    {
        if (!empty(static::$document)) {
            return static::$document;
        }

        $data = $this->setupAttributes();
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_DOCUMENT);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'documentId' => ID::unique(),
                'data' => [
                    'name' => 'John Doe',
                    'age' => 35,
                ],
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]
        ];

        $document = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);

        static::$document = [
            'database' => $data['database'],
            'collection' => $data['collection'],
            'document' => $document['body']['data']['databasesCreateDocument'],
        ];

        return static::$document;
    }

    /**
     * Helper to set up bulk operations data
     */
    protected function setupBulkData(): array
    {
        if (!empty(static::$bulkData)) {
            return static::$bulkData;
        }

        $project = $this->getProject();
        $projectId = $project['$id'];
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $project['apiKey'],
        ];

        // Step 1: Create database
        $query = $this->getQuery(self::CREATE_DATABASE);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => 'bulk',
                'name' => 'Bulk',
            ],
        ];
        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $databaseId = $res['body']['data']['databasesCreate']['_id'];

        // Step 2: Create collection
        $query = $this->getQuery(self::CREATE_COLLECTION);
        $payload['query'] = $query;
        $payload['variables'] = [
            'databaseId' => $databaseId,
            'collectionId' => 'operations',
            'name' => 'Operations',
            'documentSecurity' => false,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ];
        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $collectionId = $res['body']['data']['databasesCreateCollection']['_id'];

        // Step 3: Create attribute
        $query = $this->getQuery(self::CREATE_STRING_ATTRIBUTE);
        $payload['query'] = $query;
        $payload['variables'] = [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ];
        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        sleep(1);

        // Step 4: Create documents
        $query = $this->getQuery(self::CREATE_DOCUMENTS);
        $documents = [];
        for ($i = 1; $i <= 10; $i++) {
            $documents[] = ['$id' => 'doc' . $i, 'name' => 'Doc #' . $i];
        }

        $payload['query'] = $query;
        $payload['variables'] = [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
            'documents' => $documents,
        ];
        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $this->assertCount(10, $res['body']['data']['databasesCreateDocuments']['documents']);

        static::$bulkData = [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
            'projectId' => $projectId,
        ];

        return static::$bulkData;
    }

    /**
     * Helper to update bulk documents
     */
    protected function setupBulkUpdatedData(): array
    {
        $data = $this->setupBulkData();

        static $bulkUpdated = false;
        if ($bulkUpdated) {
            return $data;
        }

        $userId = $this->getUser()['$id'];
        $permissions = [
            Permission::read(Role::user($userId)),
            Permission::update(Role::user($userId)),
            Permission::delete(Role::user($userId)),
        ];

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $query = $this->getQuery(self::UPDATE_DOCUMENTS);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'collectionId' => $data['collectionId'],
                'data' => [
                    'name' => 'Docs Updated',
                    '$permissions' => $permissions,
                ],
            ],
        ];
        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $this->assertCount(10, $res['body']['data']['databasesUpdateDocuments']['documents']);

        $bulkUpdated = true;

        return $data;
    }

    /**
     * Helper to upsert bulk documents
     */
    protected function setupBulkUpsertedData(): array
    {
        $data = $this->setupBulkUpdatedData();

        static $bulkUpserted = false;
        if ($bulkUpserted) {
            return $data;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        // Upsert: Update one, insert one
        $query = $this->getQuery(self::UPSERT_DOCUMENTS);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'collectionId' => $data['collectionId'],
                'documents' => [
                    ['$id' => 'doc10', 'name' => 'Doc #1000'],
                    ['name' => 'Doc #11'],
                ],
            ],
        ];
        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $this->assertCount(2, $res['body']['data']['databasesUpsertDocuments']['documents']);

        $bulkUpserted = true;

        return $data;
    }

    public function testCreateDatabase(): void
    {
        $database = $this->setupDatabase();
        $this->assertEquals('Actors', $database['name']);
    }

    public function testCreateCollection(): void
    {
        $data = $this->setupCollection();
        $this->assertEquals('Actors', $data['collection']['name']);
    }

    public function testCreateStringAttribute(): void
    {
        $data = $this->setupCollection();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_STRING_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'name',
                'size' => 256,
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        // Attribute may already exist from setupAttributes, so we check for either success or already exists error
        if (isset($attribute['body']['errors'])) {
            $this->assertStringContainsString('already', $attribute['body']['errors'][0]['message']);
        } else {
            $this->assertIsArray($attribute['body']['data']);
            $this->assertIsArray($attribute['body']['data']['databasesCreateStringAttribute']);
        }
    }

    public function testCreateIntegerAttribute(): void
    {
        $data = $this->setupCollection();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_INTEGER_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'age',
                'min' => 18,
                'max' => 150,
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        // Attribute may already exist from setupAttributes, so we check for either success or already exists error
        if (isset($attribute['body']['errors'])) {
            $this->assertStringContainsString('already', $attribute['body']['errors'][0]['message']);
        } else {
            $this->assertIsArray($attribute['body']['data']);
            $this->assertIsArray($attribute['body']['data']['databasesCreateIntegerAttribute']);
        }
    }

    public function testCreateDocument(): void
    {
        $data = $this->setupDocument();
        $this->assertIsArray($data['document']);
    }

    /**
     * @throws \Exception
     */
    public function testGetDocuments(): void
    {
        $data = $this->setupCollection();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_DOCUMENTS);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
            ]
        ];

        $documents = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $documents['body']);
        $this->assertIsArray($documents['body']['data']);
        $this->assertIsArray($documents['body']['data']['databasesListDocuments']);
    }

    /**
     * @throws \Exception
     */
    public function testGetDocument(): void
    {
        $data = $this->setupDocument();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_DOCUMENT);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'documentId' => $data['document']['_id'],
            ]
        ];

        $document = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databasesGetDocument']);
    }

    /**
     * @throws \Exception
     */
    public function testUpdateDocument(): void
    {
        $data = $this->setupDocument();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_DOCUMENT);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'documentId' => $data['document']['_id'],
                'data' => [
                    'name' => 'New Document Name',
                ],
            ]
        ];

        $document = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $document = $document['body']['data']['databasesUpdateDocument'];
        $this->assertIsArray($document);

        $this->assertStringContainsString('New Document Name', $document['data']);
    }

    /**
     * @throws \Exception
     */
    public function testDeleteDocument(): void
    {
        // Create a fresh document for deletion to avoid conflicts with other tests
        $data = $this->setupAttributes();
        sleep(1);

        $projectId = $this->getProject()['$id'];

        // Create a document specifically for this delete test
        $query = $this->getQuery(self::CREATE_DOCUMENT);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'documentId' => ID::unique(),
                'data' => [
                    'name' => 'To Be Deleted',
                    'age' => 25,
                ],
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]
        ];

        $document = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $document['body']);
        $documentId = $document['body']['data']['databasesCreateDocument']['_id'];

        // Now delete it
        $query = $this->getQuery(self::DELETE_DOCUMENT);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'documentId' => $documentId,
            ]
        ];

        $document = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($document['body']);
        $this->assertEquals(204, $document['headers']['status-code']);
    }

    /**
     * @throws \Exception
     */
    public function testBulkCreateDocuments(): void
    {
        $data = $this->setupBulkData();
        $this->assertNotEmpty($data['databaseId']);
        $this->assertNotEmpty($data['collectionId']);
        $this->assertNotEmpty($data['projectId']);
    }

    public function testBulkUpdateDocuments(): void
    {
        $data = $this->setupBulkUpdatedData();
        $this->assertNotEmpty($data['databaseId']);
        $this->assertNotEmpty($data['collectionId']);
    }

    public function testBulkUpsertDocuments(): void
    {
        $data = $this->setupBulkUpsertedData();
        $this->assertNotEmpty($data['databaseId']);
        $this->assertNotEmpty($data['collectionId']);
    }

    public function testBulkDeleteDocuments(): void
    {
        $data = $this->setupBulkUpsertedData();

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $query = $this->getQuery(self::DELETE_DOCUMENTS);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'collectionId' => $data['collectionId'],
            ],
        ];
        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $this->assertCount(11, $res['body']['data']['databasesDeleteDocuments']['documents']);
    }
}
