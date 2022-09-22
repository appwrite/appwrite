<?php

namespace Tests\E2E\Services\GraphQL;

use Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;

class DatabaseServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    public function testCreateDatabase(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => 'actors',
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
        $this->assertEquals('Actors', $database['name']);

        return $database;
    }

    /**
     * @depends testCreateDatabase
     */
    public function testCreateCollection($database): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_COLLECTION);
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

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($collection['body']['data']);
        $this->assertArrayNotHasKey('errors', $collection['body']);
        $collection = $collection['body']['data']['databasesCreateCollection'];
        $this->assertEquals('Actors', $collection['name']);

        return [
            'database' => $database,
            'collection' => $collection,
        ];
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateStringAttribute($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_STRING_ATTRIBUTE);
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

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateStringAttribute']);

        return $data;
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateIntegerAttribute($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_INTEGER_ATTRIBUTE);
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

        return $data;
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateBooleanAttribute($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_BOOLEAN_ATTRIBUTE);
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

        return $data;
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateFloatAttribute($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_FLOAT_ATTRIBUTE);
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

        return $data;
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateEmailAttribute($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_EMAIL_ATTRIBUTE);
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

        return $data;
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateEnumAttribute($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_ENUM_ATTRIBUTE);
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

        return $data;
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateDatetimeAttribute($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_DATETIME_ATTRIBUTE);
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

        return $data;
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateIPAttribute($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_IP_ATTRIBUTE);
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

        return $data;
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateURLAttribute($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_URL_ATTRIBUTE);
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

        return $data;
    }

    /**
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @throws Exception
     */
    public function testCreateIndex($data): array
    {
        // Wait for attributes to be available
        sleep(3);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_INDEX);
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

        return [
            'database' => $data['database'],
            'collection' => $data['collection'],
            'index' => $index['body']['data']['databasesCreateIndex'],
        ];
    }

    /**
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @depends testCreateBooleanAttribute
     * @depends testCreateEnumAttribute
     * @throws Exception
     */
    public function testCreateDocument($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_DOCUMENT);
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

        return [
            'database' => $data['database'],
            'collection' => $data['collection'],
            'document' => $document,
        ];
    }

    /**
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @depends testCreateBooleanAttribute
     * @depends testCreateFloatAttribute
     * @depends testCreateEmailAttribute
     * @depends testCreateEnumAttribute
     * @throws Exception
     */
    public function testCreateCustomEntity(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_CUSTOM_ENTITY);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'name' => 'John Doe',
                'age' => 35,
                'alive' => true,
                'salary' => 9999.9,
                'email' => 'johndoe@appwrite.io',
                'role' => 'crew',
                'dob' => '2000-01-01T00:00:00Z',
            ]
        ];

        $actor = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $actor['body']);
        $this->assertIsArray($actor['body']['data']);
        $actor = $actor['body']['data']['actorsCreate'];
        $this->assertIsArray($actor);

        return $actor;
    }

    public function testGetDatabases(): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_DATABASES);
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
     * @depends testCreateDatabase
     * @throws Exception
     */
    public function testGetDatabase($database): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_DATABASE);
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
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testGetCollections($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_COLLECTIONS);
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
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testGetCollection($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_COLLECTION);
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
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @throws Exception
     */
    public function testGetAttributes($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ATTRIBUTES);
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
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testGetAttribute($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ATTRIBUTE);
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
     * @depends testCreateIndex
     * @throws Exception
     */
    public function testGetIndexes($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_INDEXES);
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
     * @depends testCreateIndex
     * @throws Exception
     */
    public function testGetIndex($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_INDEX);
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
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testGetDocuments($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_DOCUMENTS);
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
     * @depends testCreateDocument
     * @throws Exception
     */
    public function testGetDocument($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_DOCUMENT);
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
     * @depends testCreateCustomEntity
     * @throws Exception
     */
    public function testGetCustomEntity($data)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_CUSTOM_ENTITY);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'id' => $data['_id'],
            ]
        ];

        $entity = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $entity['body']);
        $this->assertIsArray($entity['body']['data']);
        $this->assertIsArray($entity['body']['data']['actorsGet']);
    }

    /**
     * @depends testCreateDatabase
     * @throws Exception
     */
    public function testUpdateDatabase($database)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_DATABASE);
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
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testUpdateCollection($data)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_COLLECTION);
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
     * @depends testCreateDocument
     * @throws Exception
     */
    public function testUpdateDocument($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_DOCUMENT);
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
//    public function testUpdateCustomEntity(array $data) {
//        $projectId = $this->getProject()['$id'];
//        $query = $this->getQuery(self::$UPDATE_CUSTOM_ENTITY);
//        $gqlPayload = [
//            'query' => $query,
//            'variables' => [
//                'id' => $data['_id'],
//                'data' => [
//                    'name' => 'New Custom Entity Name',
//                ],
//            ]
//        ];
//
//        $entity = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
//            'content-type' => 'application/json',
//            'x-appwrite-project' => $projectId,
//        ], $this->getHeaders()), $gqlPayload);
//
//        \var_dump($entity);
//
//        $this->assertArrayNotHasKey('errors', $entity['body']);
//        $this->assertIsArray($entity['body']['data']);
//        $entity = $entity['body']['data']['actorsUpdate'];
//        $this->assertIsArray($entity);
//        $this->assertStringContainsString('New Custom Entity Name', $entity['data']);
//    }

    /**
     * @depends testCreateDocument
     * @throws Exception
     */
    public function testDeleteDocument($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_DOCUMENT);
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

    /**
     * @depends testCreateStringAttribute
     * @throws Exception
     */
    public function testDeleteAttribute($data): void
    {
        // Wait for attributes to be available
        sleep(3);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_ATTRIBUTE);
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
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testDeleteCollection($data)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_COLLECTION);
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
     * @depends testCreateDatabase
     * @throws Exception
     */
    public function testDeleteDatabase($database)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_DATABASE);
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

//    /**
//     * @depends testCreateCustomEntity
//     * @throws Exception
//     */
//    public function testDeleteCustomEntity(array $data) {
//        $projectId = $this->getProject()['$id'];
//        $query = $this->getQuery(self::$DELETE_CUSTOM_ENTITY);
//        $gqlPayload = [
//            'query' => $query,
//            'variables' => [
//                'id' => $data['_id'],
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
}
