<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;


class GraphQLServerTest extends Scope 
{
    use SideServer;
    use GraphQLBase;

    /**
    * @depends testCreateCollection
    */
    public function testDocumentCreate(array $data) {
        $projectId = $this->getProject()['$id'];
        $key = '';
        $query = $this->getQuery(self::$CREATE_DOCUMENT);

        $variables = [
            'collectionId' => $data['actorsId'],
            'data' => [
                'firstName' => 'Robert',
                'lastName' => "Downey"
            ],
            'read' => ['*'],
            'write' => ['*'],
        ];

        $graphQLPayload = [
            "query" => $query,
            "variables" => $variables
        ];

        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $errorMessage = "User (role: guest) missing scope (documents.write)";
        $this->assertEquals($document['headers']['status-code'], 401);
        $this->assertEquals($document['body']['errors'][0]['message'], $errorMessage);
        $this->assertIsArray($document['body']['data']);
        $this->assertNull($document['body']['data']['database_createDocument']);

        $key = $this->createKey('test', ['documents.write']);
        $document = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ]), $graphQLPayload);
        
        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['database_createDocument']);
        $doc = $document['body']['data']['database_createDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['actorsId'], $doc['$collection']);
        $this->assertEquals($variables['data']['firstName'], $doc['firstName']);
        $this->assertEquals($variables['data']['lastName'], $doc['lastName']);
        $permissions = $doc['$permissions'];
        $this->assertEquals($variables['read'], $permissions['read']);
        $this->assertEquals($variables['write'], $permissions['write']);
    }

    public function testUserCreate() {
        $projectId = $this->getProject()['$id'];
        $key = '';
        $query = $this->getQuery(self::$CREATE_USER);
        
        $variables = [
            'email' => 'users.service@example.com',
            'password' => 'password',
            'name' => 'Project User',
        ];

        $graphQLPayload = [
            "query" => $query,
            "variables" => $variables
        ];

        $user = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $errorMessage = "User (role: guest) missing scope (users.write)";
        $this->assertEquals($user['headers']['status-code'], 401);
        $this->assertEquals($user['body']['errors'][0]['message'], $errorMessage);
        $this->assertIsArray($user['body']['data']);
        $this->assertNull($user['body']['data']['users_create']);

        $key = $this->createKey('test', ['users.write']);
        $user = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ]), $graphQLPayload);

        $this->assertEquals($user['headers']['status-code'], 201);
        $this->assertNull($user['body']['errors']);
        $this->assertIsArray($user['body']['data']);
        $this->assertIsArray($user['body']['data']['users_create']);
        $data = $user['body']['data']['users_create'];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('registration', $data);
        $this->assertEquals($variables['name'], $data['name']);
        $this->assertEquals($variables['email'], $data['email']);
        $this->assertEquals(0, $data['status']);
        $this->assertEquals(false, $data['emailVerification']);
        $this->assertEquals([], $data['prefs']);
    }


    public function testScopeBasedAuth() {
        $key = $this->createKey("test", ['locale.read']);
        $projectId = $this->getProject()['$id'];
        
        // Check that locale can be fetched
        $query = $this->getQuery(self::$LIST_COUNTRIES);
        $variables = [];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $variables
        ];
        $countries = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $this->assertEquals($countries['headers']['status-code'], 200);
        $this->assertNull($countries['body']['errors']);
        $this->assertIsArray($countries['body']['data']);
        $this->assertIsArray($countries['body']['data']['locale_getCountries']);
        $data = $countries['body']['data']['locale_getCountries'];
        $this->assertEquals(194, count($data['countries']));
        $this->assertEquals(194, $data['sum']);


        // Create a new key with no scopes granted
        $key = $this->createKey("test", []);
        $countries = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $errorMessage = "app.${projectId}@service.localhost (role: application) missing scope (locale.read)";
        $this->assertEquals($countries['headers']['status-code'], 401);
        $this->assertEquals($countries['body']['errors'][0]['message'], $errorMessage);
        $this->assertIsArray($countries['body']['data']);
        $this->assertNull($countries['body']['data']['locale_getCountries']);
    }

}