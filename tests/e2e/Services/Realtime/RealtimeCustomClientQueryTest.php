<?php

namespace Tests\E2E\Services\Realtime;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use WebSocket\TimeoutException;

class RealtimeCustomClientQueryTest extends Scope
{
    use FunctionsBase;
    use RealtimeBase;
    use ProjectCustom;
    use SideClient;

    public function testAccountChannelWithQuery()
    {
        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Subscribe with query that matches current user
        $client = $this->getWebsocket(['account'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::equal('$id', [$userId])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Update account name - should receive event (matches query)
        $name = "Test User " . uniqid();
        $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'name' => $name
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($name, $event['data']['payload']['name']);

        $client->close();


        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Subscribe with query that does NOT match current user
        $client = $this->getWebsocket(['account'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::notEqual('$id', [$userId])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Update account name - should NOT receive event (doesn't match query)
        $name = "Test User " . uniqid();
        $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'name' => $name
        ]);

        // Should timeout - no event should be received
        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();
    }

    public function testDatabaseChannelWithQuery()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Query Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        $targetDocumentId = ID::unique();

        // Subscribe with query for specific document ID
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::equal('$id', [$targetDocumentId])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document with matching ID - should receive event
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $targetDocumentId,
            'data' => [
                'status' => 'active'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($targetDocumentId, $event['data']['payload']['$id']);

        // Create document with different ID - should NOT receive event
        $otherDocumentId = ID::unique();
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $otherDocumentId,
            'data' => [
                'status' => 'inactive'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'NotEqual Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        $excludedDocumentId = ID::unique();

        // Subscribe with query that excludes specific document ID
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::notEqual('$id', [$excludedDocumentId])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document with different ID - should receive event
        $allowedDocumentId = ID::unique();
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $allowedDocumentId,
            'data' => [
                'status' => 'active'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($allowedDocumentId, $event['data']['payload']['$id']);

        // Create document with excluded ID - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $excludedDocumentId,
            'data' => [
                'status' => 'inactive'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'GreaterThan Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'score',
            'required' => false,
        ]);

        sleep(2);

        // Subscribe with query for score > 50
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::greaterThan('score', 50)->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document with score > 50 - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'score' => 75
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals(75, $event['data']['payload']['score']);

        // Create document with score <= 50 - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'score' => 30
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'LesserThan Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'age',
            'required' => false,
        ]);

        sleep(2);

        // Subscribe with query for age < 18
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::lessThan('age', 18)->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document with age < 18 - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'age' => 15
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals(15, $event['data']['payload']['age']);

        // Create document with age >= 18 - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'age' => 25
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'GreaterEqual Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'priority',
            'required' => false,
        ]);

        sleep(2);

        // Subscribe with query for priority >= 5
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::greaterThanEqual('priority', 5)->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document with priority = 5 - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'priority' => 5
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals(5, $event['data']['payload']['priority']);

        // Create document with priority > 5 - should receive event
        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'priority' => 8
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals(8, $event['data']['payload']['priority']);

        // Create document with priority < 5 - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'priority' => 3
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'LesserEqual Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'level',
            'required' => false,
        ]);

        sleep(2);

        // Subscribe with query for level <= 10
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::lessThanEqual('level', 10)->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document with level = 10 - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'level' => 10
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals(10, $event['data']['payload']['level']);

        // Create document with level < 10 - should receive event
        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'level' => 7
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals(7, $event['data']['payload']['level']);

        // Create document with level > 10 - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'level' => 15
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'IsNull Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'description',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        // Subscribe with query for description IS NULL
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::isNull('description')->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document without description - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'description' => null
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);

        // Create document with description - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'description' => 'Has description'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'IsNotNull Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        // Subscribe with query for email IS NOT NULL
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::isNotNull('email')->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document with email - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'email' => 'test@example.com'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals('test@example.com', $event['data']['payload']['email']);

        // Create document without email - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'And Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'priority',
            'required' => false,
        ]);

        sleep(2);

        // Subscribe with AND query: status = 'active' AND priority > 5
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::and([
                Query::equal('status', ['active']),
                Query::greaterThan('priority', 5)
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document matching both conditions - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'status' => 'active',
                'priority' => 8
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals('active', $event['data']['payload']['status']);
        $this->assertEquals(8, $event['data']['payload']['priority']);

        // Create document with status = 'active' but priority <= 5 - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'status' => 'active',
                'priority' => 3
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        // Create document with priority > 5 but status != 'active' - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'status' => 'inactive',
                'priority' => 9
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Or Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'type',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        // Subscribe with OR query: type = 'urgent' OR type = 'critical'
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::or([
                Query::equal('type', ['urgent']),
                Query::equal('type', ['critical'])
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document with type = 'urgent' - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'type' => 'urgent'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals('urgent', $event['data']['payload']['type']);

        // Create document with type = 'critical' - should receive event
        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'type' => 'critical'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals('critical', $event['data']['payload']['type']);

        // Create document with type = 'normal' - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'type' => 'normal'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Complex Query Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'category',
            'size' => 256,
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'score',
            'required' => false,
        ]);

        sleep(2);

        // Subscribe with complex query: (category = 'premium' OR category = 'vip') AND score >= 80
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::and([
                Query::or([
                    Query::equal('category', ['premium']),
                    Query::equal('category', ['vip'])
                ]),
                Query::greaterThanEqual('score', 80)
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document with category = 'premium' and score >= 80 - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'category' => 'premium',
                'score' => 85
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals('premium', $event['data']['payload']['category']);
        $this->assertEquals(85, $event['data']['payload']['score']);

        // Create document with category = 'vip' and score >= 80 - should receive event
        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'category' => 'vip',
                'score' => 90
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals('vip', $event['data']['payload']['category']);
        $this->assertEquals(90, $event['data']['payload']['score']);

        // Create document with category = 'premium' but score < 80 - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'category' => 'premium',
                'score' => 70
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        // Create document with score >= 80 but category != 'premium' or 'vip' - should NOT receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'category' => 'standard',
                'score' => 85
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();
    }

    public function testFilesChannelWithQuery()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Create bucket
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'bucketId' => ID::unique(),
            'name' => 'Query Test Bucket',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ]
        ]);
        $bucketId = $bucket['body']['$id'];

        $targetFileId = ID::unique();

        // Subscribe with query for specific file ID
        $client = $this->getWebsocket(['files'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::equal('$id', [$targetFileId])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create file with matching ID - should receive event
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'fileId' => $targetFileId,
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($targetFileId, $event['data']['payload']['$id']);

        // Create file with different ID - should NOT receive event
        $otherFileId = ID::unique();
        $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'fileId' => $otherFileId,
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo2.png'),
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();
    }

    public function testMultipleQueriesWithOrLogic()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Multiple Queries Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        $docId1 = ID::unique();
        $docId2 = ID::unique();

        // Subscribe with multiple queries (OR logic - any query matching returns event)
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::equal('$id', [$docId1])->toString(),
            Query::equal('$id', [$docId2])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        // Create document with first ID - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $docId1,
            'data' => [
                'status' => 'active'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($docId1, $event['data']['payload']['$id']);

        // Create document with second ID - should receive event
        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $docId2,
            'data' => [
                'status' => 'active'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($docId2, $event['data']['payload']['$id']);

        // Create document with different ID - should NOT receive event
        $otherDocId = ID::unique();
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $otherDocId,
            'data' => [
                'status' => 'active'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->assertTrue(true);
        }

        $client->close();
    }

    public function testInvalidQueryShouldNotSubscribe()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Test 1: Simple invalid query method (contains is not allowed)
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::contains('status', ['active'])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('contains', $response['data']['message']);

        // Test 2: Invalid query method in nested AND query
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::and([
                Query::equal('status', ['active']),
                Query::search('name', 'test') // search is not allowed
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('search', $response['data']['message']);

        // Test 3: Invalid query method in nested OR query
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::or([
                Query::equal('status', ['active']),
                Query::between('score', 0, 100) // between is not allowed
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('between', $response['data']['message']);

        // Test 4: Deeply nested invalid query (AND -> OR -> invalid)
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::and([
                Query::equal('status', ['active']),
                Query::or([
                    Query::greaterThan('score', 50),
                    Query::startsWith('name', 'test') // startsWith is not allowed
                ])
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        $this->assertStringContainsString('startsWith', $response['data']['message']);

        // Test 5: Multiple invalid queries in nested structure
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::and([
                Query::contains('tags', ['important']), // contains is not allowed
                Query::or([
                    Query::endsWith('email', '@example.com'), // endsWith is not allowed
                    Query::equal('status', ['active'])
                ])
            ])->toString(),
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('not supported in Realtime queries', $response['data']['message']);
        // Should catch the first invalid method encountered
        $this->assertTrue(
            str_contains($response['data']['message'], 'contains') ||
            str_contains($response['data']['message'], 'endsWith')
        );
    }
}
