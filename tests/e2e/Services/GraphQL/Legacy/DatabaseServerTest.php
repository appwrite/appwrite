<?php

namespace Tests\E2E\Services\GraphQL\Legacy;

use Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\GraphQL\Base;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabaseServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    /**
     * Static cache for database data
     */
    private static array $databaseCache = [];

    /**
     * Static cache for collection data (includes database, collection, collection2)
     */
    private static array $collectionCache = [];

    /**
     * Static cache for data after all attributes are created
     */
    private static array $allAttributesCache = [];

    /**
     * Static cache for index data
     */
    private static array $indexCache = [];

    /**
     * Static cache for document data
     */
    private static array $documentCache = [];

    /**
     * Static cache for relationship data
     */
    private static array $relationshipCache = [];

    /**
     * Static cache for bulk operations data
     */
    private static array $bulkCache = [];

    /**
     * Helper to set up a database
     */
    protected function setupDatabase(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$databaseCache[$cacheKey])) {
            return self::$databaseCache[$cacheKey];
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

        $database = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($database['body']['data']);
        $this->assertArrayNotHasKey('errors', $database['body']);
        $database = $database['body']['data']['databasesCreate'];

        self::$databaseCache[$cacheKey] = $database;
        return self::$databaseCache[$cacheKey];
    }

    /**
     * Helper to set up collections (requires database)
     */
    protected function setupCollections(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$collectionCache[$cacheKey])) {
            return self::$collectionCache[$cacheKey];
        }

        $database = $this->setupDatabase();

        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create 'Actors' collection
        $query = $this->getQuery(self::CREATE_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => ID::unique(),
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

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertArrayNotHasKey('errors', $collection['body']);
        $this->assertIsArray($collection['body']['data']);
        $collection = $collection['body']['data']['databasesCreateCollection'];

        // Create 'Movies' collection
        $query = $this->getQuery(self::CREATE_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => ID::unique(),
                'name' => 'Movies',
                'documentSecurity' => false,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ],
            ]
        ];

        $collection2 = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertArrayNotHasKey('errors', $collection2['body']);
        $this->assertIsArray($collection2['body']['data']);
        $collection2 = $collection2['body']['data']['databasesCreateCollection'];

        self::$collectionCache[$cacheKey] = [
            'database' => $database,
            'collection' => $collection,
            'collection2' => $collection2,
        ];

        return self::$collectionCache[$cacheKey];
    }

    /**
     * Helper to set up all attributes on the collection
     */
    protected function setupAllAttributes(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$allAttributesCache[$cacheKey])) {
            return self::$allAttributesCache[$cacheKey];
        }

        $data = $this->setupCollections();
        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

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
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/name', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        // Update string attribute
        $query = $this->getQuery(self::UPDATE_STRING_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'name',
                'required' => false,
                'default' => 'Default Value',
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

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
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/age', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        // Update integer attribute
        $query = $this->getQuery(self::UPDATE_INTEGER_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'age',
                'required' => false,
                'min' => 12,
                'max' => 160,
                'default' => 50
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Create boolean attribute
        $query = $this->getQuery(self::CREATE_BOOLEAN_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'alive',
                'required' => true,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/alive', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        // Update boolean attribute
        $query = $this->getQuery(self::UPDATE_BOOLEAN_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'alive',
                'required' => false,
                'default' => true
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Create float attribute
        $query = $this->getQuery(self::CREATE_FLOAT_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'salary',
                'min' => 1000.0,
                'max' => 999999.99,
                'default' => 1000.0,
                'required' => false,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/salary', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        // Update float attribute
        $query = $this->getQuery(self::UPDATE_FLOAT_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'salary',
                'required' => false,
                'min' => 100.0,
                'max' => 1000000.0,
                'default' => 2500.0
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Create email attribute
        $query = $this->getQuery(self::CREATE_EMAIL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'email',
                'required' => true,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/email', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        // Update email attribute
        $query = $this->getQuery(self::UPDATE_EMAIL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'email',
                'required' => false,
                'default' => 'torsten@appwrite.io',
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Create enum attribute
        $query = $this->getQuery(self::CREATE_ENUM_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'role',
                'elements' => [
                    'crew',
                    'actor',
                    'guest',
                ],
                'required' => true,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/role', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        // Update enum attribute
        $query = $this->getQuery(self::UPDATE_ENUM_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'role',
                'required' => false,
                'elements' => [
                    'crew',
                    'tech',
                    'actor'
                ],
                'default' => 'tech'
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Create datetime attribute
        $query = $this->getQuery(self::CREATE_DATETIME_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'dob',
                'required' => true,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/dob', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        // Update datetime attribute
        $query = $this->getQuery(self::UPDATE_DATETIME_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'dob',
                'required' => false,
                'default' => '2000-01-01T00:00:00Z'
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Create IP attribute
        $query = $this->getQuery(self::CREATE_IP_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'ip',
                'required' => false,
                'default' => '::1',
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/ip', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        // Update IP attribute
        $query = $this->getQuery(self::UPDATE_IP_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'ip',
                'required' => false,
                'default' => '127.0.0.1'
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Create URL attribute
        $query = $this->getQuery(self::CREATE_URL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'url',
                'required' => false,
                'default' => 'https://appwrite.io',
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/url', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        // Update URL attribute
        $query = $this->getQuery(self::UPDATE_URL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'url',
                'required' => false,
                'default' => 'https://cloud.appwrite.io'
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Poll for the last attribute to confirm all are available
        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/url', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        self::$allAttributesCache[$cacheKey] = $data;
        return self::$allAttributesCache[$cacheKey];
    }

    /**
     * Helper to set up an index (requires all attributes)
     */
    protected function setupIndex(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$indexCache[$cacheKey])) {
            return self::$indexCache[$cacheKey];
        }

        $data = $this->setupAllAttributes();
        $projectId = $this->getProject()['$id'];

        $query = $this->getQuery(self::CREATE_INDEX);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'index',
                'type' => 'key',
                'attributes' => [
                    'name',
                    'age',
                ],
            ]
        ];

        $index = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        // Handle 409 conflict - index may already exist from testCreateIndex
        if (isset($index['body']['errors'])) {
            $errorMessage = $index['body']['errors'][0]['message'] ?? '';
            if (strpos($errorMessage, 'already exists') !== false || strpos($errorMessage, 'Document with the requested ID already exists') !== false) {
                self::$indexCache[$cacheKey] = [
                    'database' => $data['database'],
                    'collection' => $data['collection'],
                    'index' => ['key' => 'index'],
                ];
                return self::$indexCache[$cacheKey];
            }
        }

        $this->assertArrayNotHasKey('errors', $index['body']);
        $this->assertIsArray($index['body']['data']);
        $this->assertIsArray($index['body']['data']['databasesCreateIndex']);

        self::$indexCache[$cacheKey] = [
            'database' => $data['database'],
            'collection' => $data['collection'],
            'index' => $index['body']['data']['databasesCreateIndex'],
        ];

        return self::$indexCache[$cacheKey];
    }

    /**
     * Helper to set up a document (requires index)
     */
    protected function setupDocument(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$documentCache[$cacheKey])) {
            return self::$documentCache[$cacheKey];
        }

        $data = $this->setupIndex();
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
                    'email' => 'example@appwrite.io',
                    'age' => 30,
                    'alive' => true,
                    'salary' => 9999.9,
                    'role' => 'crew',
                    'dob' => '2000-01-01T00:00:00Z',
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

        $document = $document['body']['data']['databasesCreateDocument'];

        self::$documentCache[$cacheKey] = [
            'database' => $data['database'],
            'collection' => $data['collection'],
            'document' => $document,
        ];

        return self::$documentCache[$cacheKey];
    }

    /**
     * Helper to set up relationship attribute (requires collections)
     */
    protected function setupRelationship(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$relationshipCache[$cacheKey])) {
            return self::$relationshipCache[$cacheKey];
        }

        $data = $this->setupCollections();
        $projectId = $this->getProject()['$id'];

        $query = $this->getQuery(self::CREATE_RELATIONSHIP_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection2']['_id'],          // Movies
                'relatedCollectionId' => $data['collection']['_id'],    // Actors
                'type' => Database::RELATION_ONE_TO_MANY,
                'twoWay' => true,
                'key' => 'actors',
                'twoWayKey' => 'movie'
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        // Handle 409 conflict - relationship may already exist from testCreateRelationshipAttribute
        if (isset($attribute['body']['errors'])) {
            $errorMessage = $attribute['body']['errors'][0]['message'] ?? '';
            if (strpos($errorMessage, 'already exists') !== false || strpos($errorMessage, 'Document with the requested ID already exists') !== false) {
                self::$relationshipCache[$cacheKey] = $data;
                return self::$relationshipCache[$cacheKey];
            }
        }

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateRelationshipAttribute']);

        self::$relationshipCache[$cacheKey] = $data;
        return self::$relationshipCache[$cacheKey];
    }

    /**
     * Helper to set up bulk operations data
     */
    protected function setupBulkData(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$bulkCache[$cacheKey])) {
            return self::$bulkCache[$cacheKey];
        }

        $project = $this->getProject();
        $projectId = $project['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Step 1: Create database
        $query = $this->getQuery(self::CREATE_DATABASE);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => ID::unique(),
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
            'collectionId' => ID::unique(),
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
        $this->assertEventually(function () use ($databaseId, $collectionId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/name', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        // Step 4: Create documents
        $query = $this->getQuery(self::CREATE_DOCUMENTS);
        $documents = [];
        for ($i = 1; $i <= 10; $i++) {
            $documents[] = ['$id' => ID::unique(), 'name' => 'Doc #' . $i];
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

        self::$bulkCache[$cacheKey] = [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
            'projectId' => $projectId,
        ];

        return self::$bulkCache[$cacheKey];
    }

    public function testCreateDatabase(): void
    {
        $database = $this->setupDatabase();
        $this->assertEquals('Actors', $database['name']);
    }

    public function testCreateCollection(): void
    {
        $data = $this->setupCollections();
        $this->assertEquals('Actors', $data['collection']['name']);
        $this->assertEquals('Movies', $data['collection2']['name']);
    }

    /**
     * @throws Exception
     */
    public function testCreateStringAttribute(): void
    {
        $data = $this->setupCollections();

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

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        // TODO: @itznotabug - check for `encrypt` attribute in string column's response body as well!
        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateStringAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateStringAttribute(): void
    {
        $data = $this->setupCollections();

        // Create string attribute first
        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

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
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/name', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        $query = $this->getQuery(self::UPDATE_STRING_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'name',
                'required' => false,
                'default' => 'Default Value',
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesUpdateStringAttribute']);
        $this->assertFalse($attribute['body']['data']['databasesUpdateStringAttribute']['required']);
        $this->assertEquals('Default Value', $attribute['body']['data']['databasesUpdateStringAttribute']['default']);
        $this->assertEquals(200, $attribute['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateIntegerAttribute(): void
    {
        $data = $this->setupCollections();

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

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateIntegerAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateIntegerAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create integer attribute first
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
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/age', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        $query = $this->getQuery(self::UPDATE_INTEGER_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'age',
                'required' => false,
                'min' => 12,
                'max' => 160,
                'default' => 50
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesUpdateIntegerAttribute']);
        $this->assertFalse($attribute['body']['data']['databasesUpdateIntegerAttribute']['required']);
        $this->assertEquals(12, $attribute['body']['data']['databasesUpdateIntegerAttribute']['min']);
        $this->assertEquals(160, $attribute['body']['data']['databasesUpdateIntegerAttribute']['max']);
        $this->assertEquals(50, $attribute['body']['data']['databasesUpdateIntegerAttribute']['default']);
        $this->assertEquals(200, $attribute['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateBooleanAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_BOOLEAN_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'alive',
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateBooleanAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateBooleanAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create boolean attribute first
        $query = $this->getQuery(self::CREATE_BOOLEAN_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'alive',
                'required' => true,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/alive', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        $query = $this->getQuery(self::UPDATE_BOOLEAN_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'alive',
                'required' => false,
                'default' => true
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesUpdateBooleanAttribute']);
        $this->assertFalse($attribute['body']['data']['databasesUpdateBooleanAttribute']['required']);
        $this->assertTrue($attribute['body']['data']['databasesUpdateBooleanAttribute']['default']);
        $this->assertEquals(200, $attribute['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateFloatAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_FLOAT_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'salary',
                'min' => 1000.0,
                'max' => 999999.99,
                'default' => 1000.0,
                'required' => false,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateFloatAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateFloatAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create float attribute first
        $query = $this->getQuery(self::CREATE_FLOAT_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'salary',
                'min' => 1000.0,
                'max' => 999999.99,
                'default' => 1000.0,
                'required' => false,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/salary', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        $query = $this->getQuery(self::UPDATE_FLOAT_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'salary',
                'required' => false,
                'min' => 100.0,
                'max' => 1000000.0,
                'default' => 2500.0
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesUpdateFloatAttribute']);
        $this->assertFalse($attribute['body']['data']['databasesUpdateFloatAttribute']['required']);
        $this->assertEquals(100.0, $attribute['body']['data']['databasesUpdateFloatAttribute']['min']);
        $this->assertEquals(1000000.0, $attribute['body']['data']['databasesUpdateFloatAttribute']['max']);
        $this->assertEquals(2500.0, $attribute['body']['data']['databasesUpdateFloatAttribute']['default']);
        $this->assertEquals(200, $attribute['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateEmailAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_EMAIL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'email',
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateEmailAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateEmailAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create email attribute first
        $query = $this->getQuery(self::CREATE_EMAIL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'email',
                'required' => true,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/email', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        $query = $this->getQuery(self::UPDATE_EMAIL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'email',
                'required' => false,
                'default' => 'torsten@appwrite.io',
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesUpdateEmailAttribute']);
        $this->assertFalse($attribute['body']['data']['databasesUpdateEmailAttribute']['required']);
        $this->assertEquals('torsten@appwrite.io', $attribute['body']['data']['databasesUpdateEmailAttribute']['default']);
        $this->assertEquals(200, $attribute['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateEnumAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_ENUM_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'role',
                'elements' => [
                    'crew',
                    'actor',
                    'guest',
                ],
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateEnumAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateEnumAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create enum attribute first
        $query = $this->getQuery(self::CREATE_ENUM_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'role',
                'elements' => [
                    'crew',
                    'actor',
                    'guest',
                ],
                'required' => true,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/role', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        $query = $this->getQuery(self::UPDATE_ENUM_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'role',
                'required' => false,
                'elements' => [
                    'crew',
                    'tech',
                    'actor'
                ],
                'default' => 'tech'
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesUpdateEnumAttribute']);
        $this->assertFalse($attribute['body']['data']['databasesUpdateEnumAttribute']['required']);
        $this->assertEquals('tech', $attribute['body']['data']['databasesUpdateEnumAttribute']['default']);
        $this->assertContains('tech', $attribute['body']['data']['databasesUpdateEnumAttribute']['elements']);
        $this->assertNotContains('guest', $attribute['body']['data']['databasesUpdateEnumAttribute']['elements']);
        $this->assertEquals(200, $attribute['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateDatetimeAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_DATETIME_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'dob',
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateDatetimeAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateDatetimeAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create datetime attribute first
        $query = $this->getQuery(self::CREATE_DATETIME_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'dob',
                'required' => true,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/dob', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        $query = $this->getQuery(self::UPDATE_DATETIME_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'dob',
                'required' => false,
                'default' => '2000-01-01T00:00:00Z'
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesUpdateDatetimeAttribute']);
        $this->assertFalse($attribute['body']['data']['databasesUpdateDatetimeAttribute']['required']);
        $this->assertEquals('2000-01-01T00:00:00Z', $attribute['body']['data']['databasesUpdateDatetimeAttribute']['default']);
        $this->assertEquals(200, $attribute['headers']['status-code']);
    }

    public function testCreateRelationshipAttribute(): void
    {
        $data = $this->setupCollections();



        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_RELATIONSHIP_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection2']['_id'],          // Movies
                'relatedCollectionId' => $data['collection']['_id'],    // Actors
                'type' => Database::RELATION_ONE_TO_MANY,
                'twoWay' => true,
                'key' => 'actors',
                'twoWayKey' => 'movie'
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateRelationshipAttribute']);

        // Store for caching so setupRelationship() doesn't try to recreate
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        self::$relationshipCache[$cacheKey] = $data;
    }

    public function testUpdateRelationshipAttribute(): void
    {
        $data = $this->setupRelationship();

        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection2']['_id'] . '/attributes/actors', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_RELATIONSHIP_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection2']['_id'],
                'key' => 'actors',
                'onDelete' => Database::RELATION_MUTATE_CASCADE,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesUpdateRelationshipAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testCreateIPAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_IP_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'ip',
                'required' => false,
                'default' => '::1',
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateIpAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateIPAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create IP attribute first
        $query = $this->getQuery(self::CREATE_IP_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'ip',
                'required' => false,
                'default' => '::1',
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/ip', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        $query = $this->getQuery(self::UPDATE_IP_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'ip',
                'required' => false,
                'default' => '127.0.0.1'
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesUpdateIpAttribute']);
        $this->assertFalse($attribute['body']['data']['databasesUpdateIpAttribute']['required']);
        $this->assertEquals('127.0.0.1', $attribute['body']['data']['databasesUpdateIpAttribute']['default']);
        $this->assertEquals(200, $attribute['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateURLAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_URL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'url',
                'required' => false,
                'default' => 'https://appwrite.io',
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateUrlAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateURLAttribute(): void
    {
        $data = $this->setupCollections();

        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create URL attribute first
        $query = $this->getQuery(self::CREATE_URL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'url',
                'required' => false,
                'default' => 'https://appwrite.io',
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertEventually(function () use ($data) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $data['database']['_id'] . '/collections/' . $data['collection']['_id'] . '/attributes/url', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertEquals('available', $response['body']['status']);
        }, 60000, 250);

        $query = $this->getQuery(self::UPDATE_URL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'url',
                'required' => false,
                'default' => 'https://cloud.appwrite.io'
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesUpdateUrlAttribute']);
        $this->assertFalse($attribute['body']['data']['databasesUpdateUrlAttribute']['required']);
        $this->assertEquals('https://cloud.appwrite.io', $attribute['body']['data']['databasesUpdateUrlAttribute']['default']);
        $this->assertEquals(200, $attribute['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateIndex(): void
    {
        $data = $this->setupAllAttributes();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_INDEX);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'index',
                'type' => 'key',
                'attributes' => [
                    'name',
                    'age',
                ],
            ]
        ];

        $index = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $index['body']);
        $this->assertIsArray($index['body']['data']);
        $this->assertIsArray($index['body']['data']['databasesCreateIndex']);

        // Store for caching so setupIndex() doesn't try to recreate
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        self::$indexCache[$cacheKey] = [
            'database' => $data['database'],
            'collection' => $data['collection'],
            'index' => $index['body']['data']['databasesCreateIndex'],
        ];
    }

    /**
     * @throws Exception
     */
    public function testCreateDocument(): void
    {
        $data = $this->setupIndex();

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
                    'email' => 'example@appwrite.io',
                    'age' => 30,
                    'alive' => true,
                    'salary' => 9999.9,
                    'role' => 'crew',
                    'dob' => '2000-01-01T00:00:00Z',
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

        $document = $document['body']['data']['databasesCreateDocument'];
        $this->assertIsArray($document);

        // Store for caching so setupDocument() doesn't try to recreate
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        self::$documentCache[$cacheKey] = [
            'database' => $data['database'],
            'collection' => $data['collection'],
            'document' => $document,
        ];
    }

    //    /**
    //     * @depends testCreateStringAttribute
    //     * @depends testCreateIntegerAttribute
    //     * @depends testCreateBooleanAttribute
    //     * @depends testCreateFloatAttribute
    //     * @depends testCreateEmailAttribute
    //     * @depends testCreateEnumAttribute
    //     * @depends testCreateDatetimeAttribute
    //     * @throws Exception
    //     */
    //    public function testCreateCustomEntity(): array
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::CREATE_CUSTOM_ENTITY);
    //        $gqlPayload = [
    //            'query' => $query,
    //            'variables' => [
    //                'name' => 'John Doe',
    //                'age' => 35,
    //                'alive' => true,
    //                'salary' => 9999.9,
    //                'email' => 'johndoe@appwrite.io',
    //                'role' => 'crew',
    //                'dob' => '2000-01-01T00:00:00Z',
    //            ]
    //        ];
    //
    //        $actor = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
    //            'content-type' => 'application/json',
    //            'x-appwrite-project' => $projectId,
    //        ], $this->getHeaders()), $gqlPayload);
    //
    //        $this->assertArrayNotHasKey('errors', $actor['body']);
    //        $this->assertIsArray($actor['body']['data']);
    //        $actor = $actor['body']['data']['actorsCreate'];
    //        $this->assertIsArray($actor);
    //
    //        return $actor;
    //    }

    public function testGetDatabases(): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_DATABASES);
        $gqlPayload = [
            'query' => $query,
        ];

        $databases = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $databases['body']);
        $this->assertIsArray($databases['body']['data']);
        $this->assertIsArray($databases['body']['data']['databasesList']);
    }

    /**
     * @throws Exception
     */
    public function testGetDatabase(): void
    {
        $database = $this->setupDatabase();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $database['body']);
        $this->assertIsArray($database['body']['data']);
        $this->assertIsArray($database['body']['data']['databasesGet']);
    }

    /**
     * @throws Exception
     */
    public function testGetCollections(): void
    {
        $data = $this->setupDocument();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_COLLECTIONS);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
            ]
        ];

        $collections = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $collections['body']);
        $this->assertIsArray($collections['body']['data']);
        $this->assertIsArray($collections['body']['data']['databasesListCollections']);
    }

    /**
     * @throws Exception
     */
    public function testGetCollection(): void
    {
        $data = $this->setupDocument();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
            ]
        ];

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $collection['body']);
        $this->assertIsArray($collection['body']['data']);
        $this->assertIsArray($collection['body']['data']['databasesGetCollection']);
    }

    /**
     * @throws Exception
     */
    public function testGetAttributes(): void
    {
        $data = $this->setupDocument();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_ATTRIBUTES);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
            ]
        ];

        $attributes = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attributes['body']);
        $this->assertIsArray($attributes['body']['data']);
        $this->assertIsArray($attributes['body']['data']['databasesListAttributes']);
    }

    /**
     * @throws Exception
     */
    public function testGetAttribute(): void
    {
        $data = $this->setupDocument();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'name',
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesGetAttribute']);
    }

    /**
     * @throws Exception
     */
    public function testGetIndexes(): void
    {
        $data = $this->setupIndex();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_INDEXES);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
            ]
        ];

        $indices = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $indices['body']);
        $this->assertIsArray($indices['body']['data']);
        $this->assertIsArray($indices['body']['data']['databasesListIndexes']);
    }

    /**
     * @throws Exception
     */
    public function testGetIndex(): void
    {
        $data = $this->setupIndex();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_INDEX);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => $data['index']['key'],
            ]
        ];

        $index = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $index['body']);
        $this->assertIsArray($index['body']['data']);
        $this->assertIsArray($index['body']['data']['databasesGetIndex']);
    }

    /**
     * @throws Exception
     */
    public function testGetDocuments(): void
    {
        $data = $this->setupDocument();

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
     * @throws Exception
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

    //    /**
    //     * @depends testCreateCustomEntity
    //     * @throws Exception
    //     */
    //    public function testGetCustomEntities($data)
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::GET_CUSTOM_ENTITIES);
    //        $gqlPayload = [
    //            'query' => $query,
    //        ];
    //
    //        $customEntities = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
    //            'content-type' => 'application/json',
    //            'x-appwrite-project' => $projectId,
    //        ], $this->getHeaders()), $gqlPayload);
    //
    //        $this->assertArrayNotHasKey('errors', $customEntities['body']);
    //        $this->assertIsArray($customEntities['body']['data']);
    //        $this->assertIsArray($customEntities['body']['data']['actorsList']);
    //    }
    //
    //    /**
    //     * @depends testCreateCustomEntity
    //     * @throws Exception
    //     */
    //    public function testGetCustomEntity($data)
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::GET_CUSTOM_ENTITY);
    //        $gqlPayload = [
    //            'query' => $query,
    //            'variables' => [
    //                'id' => $data['id'],
    //            ]
    //        ];
    //
    //        $entity = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
    //            'content-type' => 'application/json',
    //            'x-appwrite-project' => $projectId,
    //        ], $this->getHeaders()), $gqlPayload);
    //
    //        $this->assertArrayNotHasKey('errors', $entity['body']);
    //        $this->assertIsArray($entity['body']['data']);
    //        $this->assertIsArray($entity['body']['data']['actorsGet']);
    //    }

    /**
     * @throws Exception
     */
    public function testUpdateDatabase(): void
    {
        $database = $this->setupDatabase();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'name' => 'New Database Name',
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $database['body']);
        $this->assertIsArray($database['body']['data']);
        $this->assertIsArray($database['body']['data']['databasesUpdate']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateCollection(): void
    {
        $data = $this->setupDocument();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'name' => 'New Collection Name',
                'documentSecurity' => false,
            ]
        ];

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $collection['body']);
        $this->assertIsArray($collection['body']['data']);
        $this->assertIsArray($collection['body']['data']['databasesUpdateCollection']);
    }

    /**
     * @throws Exception
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

    //    /**
    //     * @depends testCreateCustomEntity
    //     * @throws Exception
    //     */
    //    public function testUpdateCustomEntity(array $data)
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::UPDATE_CUSTOM_ENTITY);
    //        $gqlPayload = [
    //            'query' => $query,
    //            'variables' => [
    //                'id' => $data['id'],
    //                'name' => 'New Custom Entity Name',
    //            ]
    //        ];
    //
    //        $entity = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
    //            'content-type' => 'application/json',
    //            'x-appwrite-project' => $projectId,
    //        ], $this->getHeaders()), $gqlPayload);
    //
    //        $this->assertArrayNotHasKey('errors', $entity['body']);
    //        $this->assertIsArray($entity['body']['data']);
    //        $entity = $entity['body']['data']['actorsUpdate'];
    //        $this->assertIsArray($entity);
    //        $this->assertStringContainsString('New Custom Entity Name', $entity['name']);
    //    }

    /**
     * @throws Exception
     */
    public function testDeleteDocument(): void
    {
        $data = $this->setupDocument();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::DELETE_DOCUMENT);
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

        $this->assertIsNotArray($document['body']);
        $this->assertEquals(204, $document['headers']['status-code']);
    }

    //    /**
    //     * @depends testCreateCustomEntity
    //     * @throws Exception
    //     */
    //    public function testDeleteCustomEntity(array $data)
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::DELETE_CUSTOM_ENTITY);
    //        $gqlPayload = [
    //            'query' => $query,
    //            'variables' => [
    //                'id' => $data['id'],
    //            ]
    //        ];
    //
    //        $entity = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
    //            'content-type' => 'application/json',
    //            'x-appwrite-project' => $projectId,
    //        ], $this->getHeaders()), $gqlPayload);
    //
    //        $this->assertIsNotArray($entity['body']);
    //        $this->assertEquals(204, $entity['headers']['status-code']);
    //    }

    /**
     * @throws Exception
     */
    public function testDeleteAttribute(): void
    {
        $data = $this->setupDocument();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::DELETE_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
                'key' => 'name',
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($attribute['body']);
        $this->assertEquals(204, $attribute['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testDeleteCollection(): void
    {
        $data = $this->setupDocument();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::DELETE_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'collectionId' => $data['collection']['_id'],
            ]
        ];

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($collection['body']);
        $this->assertEquals(204, $collection['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testDeleteDatabase(): void
    {
        $database = $this->setupDatabase();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::DELETE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($database['body']);
        $this->assertEquals(204, $database['headers']['status-code']);
    }

    /**
     * @throws Exception
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
        $data = $this->setupBulkData();

        $userId = $this->getUser()['$id'];
        $permissions = [
            Permission::read(Role::user($userId)),
            Permission::update(Role::user($userId)),
            Permission::delete(Role::user($userId)),
        ];

        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
        ], $this->getHeaders());

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
    }

    public function testBulkUpsertDocuments(): void
    {
        $data = $this->setupBulkData();

        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
        ], $this->getHeaders());

        // Upsert: Insert two new documents
        $query = $this->getQuery(self::UPSERT_DOCUMENTS);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'collectionId' => $data['collectionId'],
                'documents' => [
                    ['$id' => ID::unique(), 'name' => 'Doc #1000'],
                    ['name' => 'Doc #11'],
                ],
            ],
        ];
        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $this->assertCount(2, $res['body']['data']['databasesUpsertDocuments']['documents']);
    }

    public function testBulkDeleteDocuments(): void
    {
        $data = $this->setupBulkData();

        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
        ], $this->getHeaders());

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
        $this->assertGreaterThanOrEqual(10, count($res['body']['data']['databasesDeleteDocuments']['documents']));
    }
}
