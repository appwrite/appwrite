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

    /**
     * @throws Exception
     */
    public function testCreateCollection(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_COLLECTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'collectionId' => 'actors',
                'name' => 'Actors',
                'permission' => 'collection',
                'read' => ['role:all'],
                'write' => ['role:member'],
            ]
        ];

        $actors = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($actors['body']['data']);
        $this->assertArrayNotHasKey('errors', $actors['body']);
        $actors = $actors['body']['data']['databaseCreateCollection'];
        $this->assertEquals('Actors', $actors['name']);

        return $actors;
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
        $this->assertIsArray($attribute['body']['data']['databaseCreateStringAttribute']);

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
        $this->assertIsArray($attribute['body']['data']['databaseCreateIntegerAttribute']);

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
        $this->assertIsArray($attribute['body']['data']['databaseCreateBooleanAttribute']);

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
        $this->assertIsArray($attribute['body']['data']['databaseCreateFloatAttribute']);

        // Wait for attribute to be ready
        sleep(2);
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
//        $this->assertIsArray($document['body']['data']['databaseCreateDocument']);
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

}