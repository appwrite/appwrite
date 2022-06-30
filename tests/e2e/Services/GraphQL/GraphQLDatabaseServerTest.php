<?php

namespace Tests\E2E\Services\GraphQL;

use Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class GraphQLDatabaseServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use GraphQLBase;

    public function testCreateDatabase(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databasesId' => 'actors',
                'name' => 'Actors',
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($database['body']['data']);
        $this->assertArrayNotHasKey('errors', $database['body']);
        $database = $database['body']['data']['databasesCreateCollection'];
        $this->assertEquals('Actors', $database['name']);

        return $database;
    }

    /**
     * @depends testCreateDatabase
     */
    public function testCreateCollection($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databasesId' => $data['_id'],
                'collectionId' => 'actors',
                'name' => 'Actors',
                'permission' => 'collection',
                'read' => ['role:all'],
                'write' => ['role:member'],
            ]
        ];

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($collection['body']['data']);
        $this->assertArrayNotHasKey('errors', $collection['body']);
        $collection = $collection['body']['data']['databasesCreateCollection'];
        $this->assertEquals('Actors', $collection['name']);

        return $collection;
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateStringAttribute(array $data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_STRING_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'collectionId' => $data['_id'],
                'key' => 'name',
                'size' => 256,
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateStringAttribute']);

        // Wait for attribute to be ready
        sleep(2);
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateIntegerAttribute(array $data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_INTEGER_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'collectionId' => $data['_id'],
                'key' => 'age',
                'min' => 18,
                'max' => 150,
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateIntegerAttribute']);

        // Wait for attribute to be ready
        sleep(2);
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateBooleanAttribute(array $data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_BOOLEAN_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'collectionId' => $data['_id'],
                'key' => 'alive',
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateBooleanAttribute']);

        // Wait for attribute to be ready
        sleep(2);
    }

    /**
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateFloatAttribute(array $data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_FLOAT_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'collectionId' => $data['_id'],
                'key' => 'salary',
                'min' => 1000.0,
                'max' => 999999.99,
                'default' => 1000.0,
                'required' => false,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateFloatAttribute']);

        // Wait for attribute to be ready
        sleep(2);
    }

    /**
     * @depends testCreateDatabase
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateEmailAttribute($database, $collection): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_EMAIL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
                'key' => 'email',
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateEmailAttribute']);

        // Wait for attribute to be ready
        sleep(2);
    }

    /**
     * @depends testCreateDatabase
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateEnumAttribute($database, $collection): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_ENUM_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
                'key' => 'role',
                'elements' => [
                    'admin',
                    'user',
                    'guest',
                ],
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateEnumAttribute']);

        // Wait for attribute to be ready
        sleep(2);
    }

    /**
     * @depends testCreateDatabase
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateIPAttribute($database, $collection): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_IP_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
                'key' => 'ip',
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateIPAttribute']);

        // Wait for attribute to be ready
        sleep(2);
    }

    /**
     * @depends testCreateDatabase
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testCreateURLAttribute($database, $collection): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_URL_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
                'key' => 'url',
                'required' => true,
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateURLAttribute']);

        // Wait for attribute to be ready
        sleep(2);
    }

    /**
     * @depends testCreateDatabase
     * @depends testCreateCollection
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @throws Exception
     */
    public function testCreateIndex($database, $collection): void
    {
$projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_INDEX);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
                'key' => 'nameIdx',
                'type' => 'key',
                'attributes' => [
                    'name',
                    'int',
                ],
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateIndex']);
    }

//    /**
//     * @depends testCreateCollection
//     * @depends testCreateStringAttribute
//     * @depends testCreateIntegerAttribute
//     * @depends testCreateBooleanAttribute
//     * @depends testCreateFloatAttribute
//     * @throws \Exception
//     */
//    public function testCreateDocument(array $data): void
//    {
//        $projectId = $this->getProject()['$id'];
//        $query = $this->getQuery(self::$CREATE_DOCUMENT_REST);
//        $gqlPayload = [
//            'query' => $query,
//            'variables' => [
//                'collectionId' => $data['_id'],
//                'documentId' => 'unique()',
//                'data' => [
//                    'name' => 'John Doe',
//                    'age' => 30,
//                    'alive' => true,
//                    'salary' => 9999.5
//                ],
//                'read' => ['role:all'],
//                'write' => ['role:all'],
//            ]
//        ];
//
//        $document = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
//            'content-type' => 'application/json',
//            'x-appwrite-project' => $projectId,
//        ], $this->getHeaders()), $gqlPayload);
//
//        $this->assertArrayNotHasKey('errors', $document['body']);
//        $this->assertIsArray($document['body']['data']);
//        $this->assertIsArray($document['body']['data']['databasesCreateDocument']);
//    }

    /**
     * @depends testCreateCollection
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @depends testCreateBooleanAttribute
     * @depends testCreateFloatAttribute
     * @throws Exception
     */
    public function testCreateCustomEntity(): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_CUSTOM_ENTITY);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'name' => 'John Doe',
                'age' => 35,
                'alive' => true,
                'salary' => 9999.5,
            ]
        ];

        $actor = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        \var_dump($actor);

        $this->assertArrayNotHasKey('errors', $actor['body']);
        $this->assertIsArray($actor['body']['data']);
        $this->assertIsArray($actor['body']['data']['actorCreate']);
    }

    public function testGetDatabases(): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_DATABASES);
        $gqlPayload = [
            'query' => $query,
        ];

        $databases = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $databases['body']);
        $this->assertIsArray($databases['body']['data']);
        $this->assertIsArray($databases['body']['data']['databasesGetDatabases']);
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

        $database = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $database['body']);
        $this->assertIsArray($database['body']['data']);
        $this->assertIsArray($database['body']['data']['databasesGetDatabase']);
    }

    public function testGetCollections($database): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_COLLECTIONS);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
            ]
        ];

        $collections = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $collections['body']);
        $this->assertIsArray($collections['body']['data']);
        $this->assertIsArray($collections['body']['data']['databasesGetCollections']);
    }

    /**
    * @depends testCreateDatabase
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testGetCollection($database, $collection): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
            ]
        ];

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $collection['body']);
        $this->assertIsArray($collection['body']['data']);
        $this->assertIsArray($collection['body']['data']['databasesGetCollection']);
    }

    /**
     * @depends testCreateDatabase
     * @depends testCreateCollection
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @throws Exception
     */
    public function testGetAttributes($database, $collection): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ATTRIBUTES);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
            ]
        ];

        $attributes = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attributes['body']);
        $this->assertIsArray($attributes['body']['data']);
        $this->assertIsArray($attributes['body']['data']['databasesGetAttributes']);
    }

    /**
     * @depends testCreateDatabase
     * @depends testCreateCollection
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @throws Exception
     */
    public function testGetAttribute($database, $collection): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
                'key' => 'name',
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesGetAttribute']);
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

        $database = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $database['body']);
        $this->assertIsArray($database['body']['data']);
        $this->assertIsArray($database['body']['data']['databasesUpdateDatabase']);
    }

    /**
     * @depends testCreateDatabase
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testUpdateCollection($database, $collection)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
                'name' => 'New Collection Name',
                'permission' => 'collection',
            ]
        ];

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $collection['body']);
        $this->assertIsArray($collection['body']['data']);
        $this->assertIsArray($collection['body']['data']['databasesUpdateCollection']);
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

        $database = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $database['body']);
        $this->assertIsArray($database['body']['data']);
        $this->assertIsArray($database['body']['data']['databasesDeleteDatabase']);
    }

    /**
     * @depends testCreateDatabase
     * @depends testCreateCollection
     * @throws Exception
     */
    public function testDeleteCollection($database, $collection)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
            ]
        ];

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertEquals(204, $collection['headers']['status-code']);
    }

    /**
     * @depends testCreateDatabase
     * @depends testCreateCollection
     * @depends testCreateStringAttribute
     * @throws Exception
     */
    public function testDeleteAttribute($database, $collection): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'collectionId' => $collection['_id'],
                'key' => 'name',
            ]
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertEquals(204, $attribute['headers']['status-code']);
    }
}