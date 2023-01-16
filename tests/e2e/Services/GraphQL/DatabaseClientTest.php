<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabaseClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use Base;

    public function testCreateDatabase(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_DATABASE);
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

        $collection = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

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

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateStringAttribute']);

        return $data;
    }

    /**
     * @depends testCreateCollection
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

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertArrayNotHasKey('errors', $attribute['body']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databasesCreateIntegerAttribute']);

        return $data;
    }

    /**
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     */
    public function testCreateDocument($data): array
    {
        sleep(1);

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

        $document = $document['body']['data']['databasesCreateDocument'];
        $this->assertIsArray($document);

        return [
            'database' => $data['database'],
            'collection' => $data['collection'],
            'document' => $document,
        ];
    }

    /**
     * @depends testCreateCollection
     * @throws \Exception
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
     * @throws \Exception
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
     * @depends testCreateDocument
     * @throws \Exception
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

    /**
     * @depends testCreateDocument
     * @throws \Exception
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
}
