<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;


class GraphQLServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use GraphQLBase;

    /**
     * @depends testCreateCollection
     */
    public function testDocumentCreate(array $data)
    {
        $projectId = $this->getProject()['$id'];
        $key = '';
        $query = $this->getQuery(self::$CREATE_DOCUMENT_REST);

        $variables = [
            'collectionId' => $data['collectionId'],
            'data' => [
                'name' => 'Robert',
                'ago' => 100,
                'alive' => true,
            ],
            'read' => ['role:all'],
            'write' => ['role:all'],
        ];

        $graphQLPayload = [
            'query' => $query,
            'variables' => $variables
        ];

        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $errorMessage = 'User (role: guest) missing scope (documents.write)';
        $this->assertEquals($errorMessage, $document['body']['errors'][0]['message']);
        $this->assertIsArray($document['body']['data']);
        $this->assertNull($document['body']['data']['databaseCreateDocument']);

        $key = $this->getNewKey(['documents.write']);
        $document = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ]), $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseCreateDocument']);

        $doc = $document['body']['data']['databaseCreateDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['collectionId'], $doc['$collection']);
        $this->assertEquals($variables['data']['name'], $doc['name']);
        $this->assertEquals($variables['data']['age'], $doc['age']);
        $this->assertEquals($variables['read'], $doc['read']);
        $this->assertEquals($variables['write'], $doc['write']);
    }

    /**
     * @throws \Exception
     */
    public function testUserCreate()
    {
        /**
         * Try to create a user without the required scope
         */
        $projectId = $this->getProject()['$id'];
        $key = '';
        $query = $this->getQuery(self::$CREATE_USER);

        $variables = [
            'email' => 'users.service@example.com',
            'password' => 'password',
            'name' => 'Project User',
        ];

        $graphQLPayload = [
            'query' => $query,
            'variables' => $variables
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $errorMessage = 'User (role: guest) missing scope (users.write)';
        $this->assertEquals($errorMessage, $user['body']['errors'][0]['message']);
        $this->assertIsArray($user['body']['data']);
        $this->assertNull($user['body']['data']['usersCreate']);

        /**
         * Create the user with the reqiured scopes
         */
        $key = $this->getNewKey(['users.write']);
        $user = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ]), $graphQLPayload);

        $this->assertNull($user['body']['errors']);
        $this->assertIsArray($user['body']['data']);
        $this->assertIsArray($user['body']['data']['usersCreate']);

        $data = $user['body']['data']['usersCreate'];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('registration', $data);
        $this->assertEquals($variables['name'], $data['name']);
        $this->assertEquals($variables['email'], $data['email']);
        $this->assertEquals(0, $data['status']);
        $this->assertEquals(false, $data['emailVerification']);
        $this->assertEquals([], $data['prefs']);

        return ['userId' => $user['body']['data']['users_create']['id']];
    }

    /**
     * @depends testUserCreate
     */
    public function testUserDelete(array $data)
    {
        /**
         * Try to delete a user without the required scope
         */
        $projectId = $this->getProject()['$id'];
        $key = '';
        $query = $this->getQuery(self::$DELETE_USER);

        $variables = [
            'userId' => $data['userId'],
        ];

        $graphQLPayload = [
            'query' => $query,
            'variables' => $variables
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $errorMessage = 'User (role: guest) missing scope (users.write)';
        $this->assertEquals($errorMessage, $user['body']['errors'][0]['message']);
        $this->assertIsArray($user['body']['data']);
        $this->assertNull($user['body']['data']['users_deleteUser']);

        /**
         * Delete the user with the reqiured scopes
         */
        $key = $this->getNewKey(['users.write']);
        $user = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ]), $graphQLPayload);

        $this->assertNull($user['body']['errors']);
        $this->assertIsArray($user['body']['data']);
        $this->assertIsArray($user['body']['data']['usersDeleteUser']);
        $this->assertEquals([], $user['body']['data']['usersDeleteUser']);

        /**
         * Try to fetch the user and check that its empty
         */
        $query = $this->getQuery(self::$GET_USER);
        $key = $this->getNewKey(['users.read']);

        $graphQLPayload = [
            'query' => $query,
            'variables' => $variables
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $errorMessage = 'User not found';
        $this->assertEquals($errorMessage, $user['body']['errors'][0]['message']);
        $this->assertIsArray($user['body']['data']);
        $this->assertNull($user['body']['data']['users_get']);
    }


    public function testScopeBasedAuth()
    {
        $key = $this->getNewKey(['locale.read']);
        $projectId = $this->getProject()['$id'];

        /**
         * Check that countries can be fetched
         */
        $query = $this->getQuery(self::$LIST_COUNTRIES);
        $variables = [];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $variables
        ];
        $countries = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $this->assertNull($countries['body']['errors']);
        $this->assertIsArray($countries['body']['data']);
        $this->assertIsArray($countries['body']['data']['localeGetCountries']);

        $data = $countries['body']['data']['localeGetCountries'];
        $this->assertEquals(194, count($data['countries']));
        $this->assertEquals(194, $data['total']);


        /**
         * Create a key withouut any scopes
         */
        $key = $this->getNewKey([]);
        $countries = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $errorMessage = 'app.${projectId}@service.localhost (role: application) missing scope (locale.read)';
        $this->assertEquals($countries['headers']['status-code'], 401);
        $this->assertEquals($countries['body']['errors'][0]['message'], $errorMessage);
        $this->assertIsArray($countries['body']['data']);
        $this->assertNull($countries['body']['data']['locale_getCountries']);
    }

}