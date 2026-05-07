<?php

namespace Tests\E2E\Services\Realtime;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use WebSocket\TimeoutException;

trait RealtimeQueryBase
{
    use FunctionsBase;
    use ProjectCustom;
    use SideClient;

    protected function supportForCheckConnectionStatus(): bool
    {
        return false;
    }

    private function assertConnectionStatusIfSupported($client): ?array
    {
        if (!$this->supportForCheckConnectionStatus()) {
            return null;
        }

        $response = json_decode($client->receive(), true);
        $this->assertSame('connected', $response['type']);
        return $response;
    }

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

        $this->assertConnectionStatusIfSupported($client);

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

        $this->assertConnectionStatusIfSupported($client);

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
            $data = $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        $targetDocumentId = ID::unique();

        // Subscribe with query for specific document ID
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::equal('$id', [$targetDocumentId])->toString(),
        ]);

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        $excludedDocumentId = ID::unique();

        // Subscribe with query that excludes specific document ID
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::notEqual('$id', [$excludedDocumentId])->toString(),
        ]);

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/score', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        // Subscribe with query for score > 50
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::greaterThan('score', 50)->toString(),
        ]);

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/age', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        // Subscribe with query for age < 18
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::lessThan('age', 18)->toString(),
        ]);

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/priority', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        // Subscribe with query for priority >= 5
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::greaterThanEqual('priority', 5)->toString(),
        ]);

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/level', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        // Subscribe with query for level <= 10
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::lessThanEqual('level', 10)->toString(),
        ]);

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/description', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        // Subscribe with query for description IS NULL
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::isNull('description')->toString(),
        ]);

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        // Subscribe with query for email IS NOT NULL
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::isNotNull('email')->toString(),
        ]);

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/priority', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

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

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/type', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

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

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/category', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/score', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

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

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
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
            $this->addToAssertionCount(1);
        }

        $client->close();
    }

    public function testCollectionScopedDocumentsChannelReceivesEvents()
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
            'name' => 'Scoped Channel DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Scoped Channel Collection',
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        // Subscribe only to the fully-qualified documents channel for this collection
        $scopedChannel = 'databases.' . $databaseId . '.collections.' . $collectionId . '.documents';
        $client = $this->getWebsocket([$scopedChannel], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]);

        $response = $this->assertConnectionStatusIfSupported($client);
        if ($response !== null) {
            $this->assertContains($scopedChannel, $response['data']['channels']);
        }

        // Create document in that collection - should receive event on the scoped channel
        $documentId = ID::unique();
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $documentId,
            'data' => [
                'status' => 'active'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($documentId, $event['data']['payload']['$id']);

        $client->close();
    }

    public function testCollectionScopedDocumentsChannelWithQuery()
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
            'name' => 'Scoped Channel Query DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Scoped Channel Query Collection',
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        $targetDocumentId = ID::unique();

        // Subscribe with query for specific document ID on the fully-qualified documents channel
        $scopedChannel = 'databases.' . $databaseId . '.collections.' . $collectionId . '.documents';
        $client = $this->getWebsocket([$scopedChannel], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::equal('$id', [$targetDocumentId])->toString(),
        ]);

        $response = $this->assertConnectionStatusIfSupported($client);
        if ($response !== null) {
            $this->assertContains($scopedChannel, $response['data']['channels']);
        }

        // Create document with matching ID - should receive event
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
            $this->fail('Expected TimeoutException - event should be filtered for scoped channel query');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
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

        $this->assertConnectionStatusIfSupported($client);

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
            $this->addToAssertionCount(1);
        }

        $client->close();
    }

    public function testMultipleQueriesWithAndLogic()
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        $targetDocId = ID::unique();

        // Subscribe with multiple 'queries' (AND logic - ALL 'queries' must match for event to be received)
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::equal('$id', [$targetDocId])->toString(),
            Query::equal('status', ['active'])->toString(),
        ]);

        $this->assertConnectionStatusIfSupported($client);

        // Create document matching BOTH 'queries' - should receive event
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $targetDocId,
            'data' => [
                'status' => 'active'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($targetDocId, $event['data']['payload']['$id']);
        $this->assertEquals('active', $event['data']['payload']['status']);

        // Create document matching NEITHER query - should not receive event
        // keeping it here as below are the documents created with status=>active
        // so it will also be received, but the query key can be used to distinguish it
        $anotherDocId = ID::unique();
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $anotherDocId,
            'data' => [
                'status' => 'inactive'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered (neither query matches)');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        // Create document with matching ID but wrong status - should NOT receive event (only one query matches)
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $targetDocId,
            'data' => [
                'status' => 'inactive'
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        try {
            $client->receive();
            $this->fail('Expected TimeoutException - event should be filtered (ID matches but status does not)');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        $client->close();
    }

    public function testQueryKeys()
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
            'name' => 'Query Keys Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Query Keys Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        // Attributes used by 'queries'
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => false,
        ]);

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'category',
            'size' => 256,
            'required' => false,
        ]);

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/category', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        $queryStatusActive = Query::equal('status', ['active'])->toString();
        $queryStatusPending = Query::equal('status', ['pending'])->toString();
        $queryComplex = Query::and([
            Query::equal('status', ['active']),
            Query::equal('category', ['gold']),
        ])->toString();

        // Subscribe with no 'queries' -> should receive all events (has select("*") subscription)
        $clientAll = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]);

        // Subscribe with query1 (status == active)
        $clientQ1 = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            $queryStatusActive,
        ]);

        // Subscribe with query2 (status == pending)
        $clientQ2 = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            $queryStatusPending,
        ]);

        // Subscribe with complex query (status == active AND category == gold)
        $clientComplex = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            $queryComplex,
        ]);

        // All clients should be connected
        foreach ([$clientAll, $clientQ1, $clientQ2, $clientComplex] as $client) {
            $this->assertConnectionStatusIfSupported($client);
        }

        // 1) Create active/gold document -> should match Q1 and complex, and be seen by all
        $docActiveGoldId = ID::unique();
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $docActiveGoldId,
            'data' => [
                'status' => 'active',
                'category' => 'gold',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        // clientAll: should receive event, subscriptions should not be empty (has select("*") subscription that matches)
        $eventAll = json_decode($clientAll->receive(), true);
        $this->assertEquals('event', $eventAll['type']);
        $this->assertEquals($docActiveGoldId, $eventAll['data']['payload']['$id']);
        $this->assertArrayHasKey('subscriptions', $eventAll['data']);
        $this->assertIsArray($eventAll['data']['subscriptions']);
        // clientAll has select("*") subscription that matches all events, so subscriptions should not be empty
        $this->assertNotEmpty($eventAll['data']['subscriptions']);

        // clientQ1: should receive event, subscriptions should not be empty (query matched)
        $eventQ1 = json_decode($clientQ1->receive(), true);
        $this->assertEquals('event', $eventQ1['type']);
        $this->assertEquals($docActiveGoldId, $eventQ1['data']['payload']['$id']);
        $this->assertArrayHasKey('subscriptions', $eventQ1['data']);
        $this->assertIsArray($eventQ1['data']['subscriptions']);
        // clientQ1 has a query that matches, so subscriptions should not be empty
        $this->assertNotEmpty($eventQ1['data']['subscriptions']);

        // clientQ2: should NOT receive event (status is active, not pending)
        try {
            $clientQ2->receive();
            $this->fail('Expected TimeoutException - event should be filtered for clientQ2 (active document)');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        // clientComplex: should receive event, subscriptions should not be empty (query matched)
        $eventComplex = json_decode($clientComplex->receive(), true);
        $this->assertEquals('event', $eventComplex['type']);
        $this->assertEquals($docActiveGoldId, $eventComplex['data']['payload']['$id']);
        $this->assertArrayHasKey('subscriptions', $eventComplex['data']);
        $this->assertIsArray($eventComplex['data']['subscriptions']);
        // clientComplex has a query that matches, so subscriptions should not be empty
        $this->assertNotEmpty($eventComplex['data']['subscriptions']);

        // 2) Create pending/silver document -> should match Q2 only, and be seen by all
        $docPendingSilverId = ID::unique();
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $docPendingSilverId,
            'data' => [
                'status' => 'pending',
                'category' => 'silver',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        // clientAll: should receive event, subscriptions should not be empty (has select("*") subscription that matches)
        $eventAll2 = json_decode($clientAll->receive(), true);
        $this->assertEquals('event', $eventAll2['type']);
        $this->assertEquals($docPendingSilverId, $eventAll2['data']['payload']['$id']);
        $this->assertArrayHasKey('subscriptions', $eventAll2['data']);
        $this->assertIsArray($eventAll2['data']['subscriptions']);
        // clientAll has select("*") subscription that matches all events, so subscriptions should not be empty
        $this->assertNotEmpty($eventAll2['data']['subscriptions']);

        // clientQ1: should NOT receive event (status is pending)
        try {
            $clientQ1->receive();
            $this->fail('Expected TimeoutException - event should be filtered for clientQ1 (pending document)');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        // clientQ2: should receive event, subscriptions should not be empty (query matched)
        $eventQ2 = json_decode($clientQ2->receive(), true);
        $this->assertEquals('event', $eventQ2['type']);
        $this->assertEquals($docPendingSilverId, $eventQ2['data']['payload']['$id']);
        $this->assertArrayHasKey('subscriptions', $eventQ2['data']);
        $this->assertIsArray($eventQ2['data']['subscriptions']);
        // clientQ2 has a query that matches, so subscriptions should not be empty
        $this->assertNotEmpty($eventQ2['data']['subscriptions']);

        // clientComplex: should NOT receive event (status is pending, category silver)
        try {
            $clientComplex->receive();
            $this->fail('Expected TimeoutException - event should be filtered for complex subscription (pending document)');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        $clientAll->close();
        $clientQ1->close();
        $clientQ2->close();
        $clientComplex->close();
    }

    /**
     * Ensure two separate subscriptions with different query keys
     * only see their own matching events and expose the correct
     * queryKey in queryKeys.
     */
    public function testMultipleSubscriptionsDifferentQueryKeys()
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
            'name' => 'Multiple Query Keys Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Multiple Query Keys Collection',
            'permissions' => [
                Permission::create(Role::user($user['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        // Attribute used by 'queries'
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'status',
            'size' => 256,
            'required' => false,
        ]);

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        $queryStatusActive = Query::equal('status', ['active'])->toString();
        $queryStatusPending = Query::equal('status', ['pending'])->toString();

        // Two subscriptions on the same channel with different query keys
        $clientQ1 = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            $queryStatusActive,
        ]);

        $clientQ2 = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            $queryStatusPending,
        ]);

        // Both should connect
        $this->assertConnectionStatusIfSupported($clientQ1);
        $this->assertConnectionStatusIfSupported($clientQ2);

        // 1) active document -> only queryStatusActive subscription should see it
        $docActiveId = ID::unique();
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $docActiveId,
            'data' => [
                'status' => 'active',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $eventQ1 = json_decode($clientQ1->receive(), true);
        $this->assertEquals('event', $eventQ1['type']);
        $this->assertEquals($docActiveId, $eventQ1['data']['payload']['$id']);
        $this->assertArrayHasKey('subscriptions', $eventQ1['data']);
        $this->assertIsArray($eventQ1['data']['subscriptions']);
        // clientQ1 has a query that matches, so subscriptions should not be empty
        $this->assertNotEmpty($eventQ1['data']['subscriptions']);

        try {
            $clientQ2->receive();
            $this->fail('Expected TimeoutException - clientQ2 should not receive active document');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        // 2) pending document -> only queryStatusPending subscription should see it
        $docPendingId = ID::unique();
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $docPendingId,
            'data' => [
                'status' => 'pending',
            ],
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $eventQ2 = json_decode($clientQ2->receive(), true);
        $this->assertEquals('event', $eventQ2['type']);
        $this->assertEquals($docPendingId, $eventQ2['data']['payload']['$id']);
        $this->assertArrayHasKey('subscriptions', $eventQ2['data']);
        $this->assertIsArray($eventQ2['data']['subscriptions']);
        // clientQ2 has a query that matches, so subscriptions should not be empty
        $this->assertNotEmpty($eventQ2['data']['subscriptions']);

        try {
            $clientQ1->receive();
            $this->fail('Expected TimeoutException - clientQ1 should not receive pending document');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        $clientQ1->close();
        $clientQ2->close();
    }

    public function testSubscriptionPreservedAfterPermissionChange()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];
        $userId = $user['$id'] ?? '';

        // Setup database and collection
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Permission Change Test DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Permission Change Collection',
            'permissions' => [
                Permission::create(Role::user($userId)),
                Permission::read(Role::user($userId)),
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

        $this->assertEventually(function () use ($databaseId, $collectionId, $projectId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/status', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        $targetDocumentId = ID::unique();

        // Subscribe with query for specific document ID
        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], null, [
            Query::equal('$id', [$targetDocumentId])->toString(),
        ]);

        $originalSubscriptionId = null;
        $response = $this->assertConnectionStatusIfSupported($client);
        if ($response !== null) {
            $this->assertArrayHasKey('subscriptions', $response['data']);
            $this->assertIsArray($response['data']['subscriptions']);

            // Store the original subscription mapping (index => subscriptionId)
            $originalSubscriptionMapping = $response['data']['subscriptions'];
            $this->assertNotEmpty($originalSubscriptionMapping);
            // Get the first subscription ID and its index
            $originalIndex = array_key_first($originalSubscriptionMapping);
            $originalSubscriptionId = $originalSubscriptionMapping[$originalIndex];
        }

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
                Permission::read(Role::user($userId)),
                Permission::update(Role::user($userId)),
            ],
        ]);

        $event = json_decode($client->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertEquals($targetDocumentId, $event['data']['payload']['$id']);
        if ($originalSubscriptionId !== null) {
            $this->assertArrayHasKey('subscriptions', $event['data']);
            $this->assertContains($originalSubscriptionId, $event['data']['subscriptions']);
        }

        // Trigger permission change by creating a team owned by a DIFFERENT user,
        $teamOwnerEmail = uniqid() . 'owner@localhost.test';
        $teamOwnerPassword = 'password';

        $teamOwner = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'userId' => ID::unique(),
            'email' => $teamOwnerEmail,
            'password' => $teamOwnerPassword,
            'name' => 'Team Owner',
        ]);

        $this->assertEquals(201, $teamOwner['headers']['status-code']);

        $teamOwnerSession = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $teamOwnerEmail,
            'password' => $teamOwnerPassword,
        ]);

        $teamOwnerSession = $teamOwnerSession['cookies']['a_session_' . $projectId] ?? '';

        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $teamOwnerSession,
        ], [
            'teamId' => ID::unique(),
            'name' => 'Test Team',
        ]);
        $teamId = $team['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'email' => $user['email'],
            'roles' => ['member'],
            'url' => 'http://localhost',
        ]);

        sleep(1);

        // Verify subscription is still working after permission change
        $nonMatchingDocumentId = ID::unique();
        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $nonMatchingDocumentId,
            'data' => [
                'status' => 'active'
            ],
            'permissions' => [
                Permission::read(Role::user($userId)),
                Permission::update(Role::user($userId)),
            ],
        ]);

        // This document doesn't match the query, so we shouldn't receive it
        try {
            $data = $client->receive();
            $this->fail('Expected TimeoutException - document does not match query after permission change');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        // Create a NEW document with a different ID - should NOT receive event
        $targetDocumentId2 = ID::unique();
        $document3 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => $targetDocumentId2,
            'data' => [
                'status' => 'active'
            ],
            'permissions' => [
                Permission::read(Role::user($userId)),
                Permission::update(Role::user($userId)),
            ],
        ]);

        sleep(1);

        // This should NOT receive event because the query is for $targetDocumentId, not $targetDocumentId2
        // This verifies the query is preserved after permission change
        try {
            $data = $client->receive();
            $this->fail('Expected TimeoutException - new document does not match original query after permission change');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        // Create a document with the ORIGINAL matching ID - should receive event
        $document4 = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $targetDocumentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'data' => [
                'status' => 'updated-after-permission-change'
            ],
        ]);

        // Wait a bit for the event to be processed
        sleep(1);

        // Verify the event is received with the preserved subscription
        $event2 = json_decode($client->receive(), true);
        $this->assertEquals('event', $event2['type']);
        $this->assertEquals($targetDocumentId, $event2['data']['payload']['$id']);
        $this->assertEquals('updated-after-permission-change', $event2['data']['payload']['status']);
        $this->assertArrayHasKey('subscriptions', $event2['data']);
        $this->assertIsArray($event2['data']['subscriptions']);
        $this->assertNotEmpty($event2['data']['subscriptions']);
        // Subscription ID should remain stable after permission change
        if ($originalSubscriptionId !== null) {
            $this->assertContains($originalSubscriptionId, $event2['data']['subscriptions']);
        }

        $client->close();
    }

    public function testProjectChannelWithQuery()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        // Test OLD SDK behavior: project=projectId (string) in query param
        // For reserved \"project\" param, string is treated as routing-only (project ID),
        // and is not used as queries for the project channel. We should fall back to select(*).
        $clientOldSdk = $this->getWebsocket(['project'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], $projectId, null);

        $response = $this->assertConnectionStatusIfSupported($clientOldSdk);
        if ($response !== null) {
            $this->assertContains('project', $response['data']['channels']);
            // Should have default select(['*']) subscription since project param was treated as project ID, not queries
            $this->assertArrayHasKey('subscriptions', $response['data']);
            $this->assertIsArray($response['data']['subscriptions']);
            $this->assertNotEmpty($response['data']['subscriptions']);
        }

        $clientOldSdk->close();

        // Test NEW SDK behavior: project=Query array in query param, project ID in header
        // The reserved param logic should use Query array as subscription queries for project channel
        $queryArray = [Query::select(['*'])->toString()];
        $clientNewSdk = $this->getWebsocketWithCustomQuery(
            [
                'channels' => ['project'],
                'project' => [
                    0 => [
                        0 => $queryArray[0]
                    ]
                ]
            ],
            [
                'origin' => 'http://localhost',
                'cookie' => 'a_session_' . $projectId . '=' . $session,
                'x-appwrite-project' => $projectId,
            ]
        );

        $response = $this->assertConnectionStatusIfSupported($clientNewSdk);
        if ($response !== null) {
            $this->assertContains('project', $response['data']['channels']);
            // Should have subscription with the provided query
            $this->assertArrayHasKey('subscriptions', $response['data']);
            $this->assertIsArray($response['data']['subscriptions']);
            $this->assertNotEmpty($response['data']['subscriptions']);
        }

        $clientNewSdk->close();

        // Test edge case: project param is array but not a valid Query array
        // This should now fail with an invalid query error rather than silently falling back.
        $clientEdgeCase = $this->getWebsocketWithCustomQuery(
            [
                'channels' => ['project'],
                'project' => ['invalid', 'array']
            ],
            [
                'origin' => 'http://localhost',
                'cookie' => 'a_session_' . $projectId . '=' . $session,
                'x-appwrite-project' => $projectId,
            ]
        );

        $response = json_decode($clientEdgeCase->receive(), true);
        $this->assertEquals('error', $response['type']);
        $this->assertStringContainsString('Invalid query', $response['data']['message']);
    }

    public function testTestsChannelWithQueries()
    {
        $projectId = 'console';

        // Subscribe without queries - should receive all events
        $clientNoQuery = $this->getWebsocket(
            channels: ['tests'],
            headers: ['origin' => 'http://localhost'],
            projectId: $projectId,
            timeout: 5
        );

        $this->assertConnectionStatusIfSupported($clientNoQuery);

        // Subscribe with matching query - should receive events
        $clientWithMatchingQuery = $this->getWebsocket(
            channels: ['tests'],
            headers: ['origin' => 'http://localhost'],
            projectId: $projectId,
            queries: [Query::equal('response', ['WS:/v1/realtime:passed'])->toString()],
            timeout: 5
        );

        $this->assertConnectionStatusIfSupported($clientWithMatchingQuery);

        // Subscribe with non-matching query - should NOT receive events
        $clientWithNonMatchingQuery = $this->getWebsocket(
            channels: ['tests'],
            headers: ['origin' => 'http://localhost'],
            projectId: $projectId,
            queries: [Query::equal('response', ['failed'])->toString()]
        );

        $this->assertConnectionStatusIfSupported($clientWithNonMatchingQuery);

        sleep(2);

        // Client without query should receive event
        $eventNoQuery = json_decode($clientNoQuery->receive(), true);
        $this->assertEquals('event', $eventNoQuery['type']);
        $this->assertEquals('test.event', $eventNoQuery['data']['events'][0]);
        $this->assertEquals('WS:/v1/realtime:passed', $eventNoQuery['data']['payload']['response']);

        // Client with matching query should receive event
        $eventMatching = json_decode($clientWithMatchingQuery->receive(), true);
        $this->assertEquals('event', $eventMatching['type']);
        $this->assertEquals('test.event', $eventMatching['data']['events'][0]);
        $this->assertEquals('WS:/v1/realtime:passed', $eventMatching['data']['payload']['response']);

        // Client with non-matching query should NOT receive event
        try {
            $clientWithNonMatchingQuery->receive();
            $this->fail('Expected TimeoutException - client with non-matching query should not receive event');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        $clientNoQuery->close();
        $clientWithMatchingQuery->close();
        $clientWithNonMatchingQuery->close();
    }

    /**
     * Sets up a database + collection + 'name' string attribute, returning their IDs.
     * Used by action-channel tests to avoid duplicating fixture code.
     *
     * @return array{databaseId: string, collectionId: string}
     */
    private function createActorsCollection(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Action Channel DB',
        ]);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
            'documentSecurity' => true,
        ]);
        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEventually(function () use ($databaseId, $collectionId) {
            $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/name', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('available', $response['body']['status']);
        }, 30000, 250);

        return ['databaseId' => $databaseId, 'collectionId' => $collectionId];
    }

    /**
     * Creates a document with the given ID and name. Returns the parsed body.
     * Permissions allow Role::any() for all CRUD so any session can observe the events.
     *
     * @return array<string, mixed>
     */
    private function createActor(string $databaseId, string $collectionId, string $documentId, string $name): array
    {
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => $documentId,
            'data' => ['name' => $name],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        return $document['body'];
    }

    public function testChannelActionFilterReflectedInConnectedResponse(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];

        // Subscribing with an action suffix should round-trip the original channel
        // name on the connected response. Only meaningful in URL-subscribe mode —
        // the message-based path consumes the connected response inside its
        // getWebsocket helper before returning, so we can't observe it here.
        $client = $this->getWebsocket([
            'documents.create',
            'documents.update',
            'documents.upsert',
            'documents',
        ], $headers);

        $connected = $this->assertConnectionStatusIfSupported($client);
        if ($connected === null) {
            $client->close();
            $this->markTestSkipped('Connected-response channels are not surfaced through the message-based subscribe path.');
        }

        $this->assertContains('documents.create', $connected['data']['channels']);
        $this->assertContains('documents.update', $connected['data']['channels']);
        $this->assertContains('documents.upsert', $connected['data']['channels']);
        $this->assertContains('documents', $connected['data']['channels']);

        $client->close();
    }

    public function testChannelActionFilterDeliversOnlyMatchingActions(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];

        ['databaseId' => $databaseId, 'collectionId' => $collectionId] = $this->createActorsCollection();

        $createChannel = "databases.{$databaseId}.collections.{$collectionId}.documents.create";
        $updateChannel = "databases.{$databaseId}.collections.{$collectionId}.documents.update";
        $upsertChannel = "databases.{$databaseId}.collections.{$collectionId}.documents.upsert";

        $clientCreate = $this->getWebsocket([$createChannel], $headers);
        $clientUpdate = $this->getWebsocket([$updateChannel], $headers);
        $clientUpsert = $this->getWebsocket([$upsertChannel], $headers);

        $this->assertConnectionStatusIfSupported($clientCreate);
        $this->assertConnectionStatusIfSupported($clientUpdate);
        $this->assertConnectionStatusIfSupported($clientUpsert);

        $documentId = ID::unique();
        $this->createActor($databaseId, $collectionId, $documentId, 'Chris Evans');

        // Create event delivers only to the .create subscriber.
        $createEvent = json_decode($clientCreate->receive(), true);
        $this->assertEquals('event', $createEvent['type']);
        $this->assertContains(
            "databases.{$databaseId}.collections.{$collectionId}.documents.{$documentId}.create",
            $createEvent['data']['events']
        );
        $this->assertEquals('Chris Evans', $createEvent['data']['payload']['name']);

        try {
            $clientUpdate->receive();
            $this->fail('Update subscriber should not receive a create event.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        try {
            $clientUpsert->receive();
            $this->fail('Upsert subscriber should not receive a create event.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        // Update fires update events; only the .update subscriber should hear them.
        $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'data' => ['name' => 'Chris Evans 2'],
        ]);

        $updateEvent = json_decode($clientUpdate->receive(), true);
        $this->assertEquals('event', $updateEvent['type']);
        $this->assertContains(
            "databases.{$databaseId}.collections.{$collectionId}.documents.{$documentId}.update",
            $updateEvent['data']['events']
        );
        $this->assertEquals('Chris Evans 2', $updateEvent['data']['payload']['name']);

        try {
            $clientCreate->receive();
            $this->fail('Create subscriber should not receive an update event.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        try {
            $clientUpsert->receive();
            $this->fail('Upsert subscriber should not receive an update event.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        // PUT bulk upsert fires upsert events; only the .upsert subscriber should hear them.
        $this->client->call(Client::METHOD_PUT, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'name' => 'Robert Downey Jr.',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                ],
            ],
        ]);

        $upsertEvent = json_decode($clientUpsert->receive(), true);
        $this->assertEquals('event', $upsertEvent['type']);
        $this->assertContains(
            "databases.{$databaseId}.collections.*.documents.*.upsert",
            $upsertEvent['data']['events']
        );

        try {
            $clientCreate->receive();
            $this->fail('Create subscriber should not receive an upsert event.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        try {
            $clientUpdate->receive();
            $this->fail('Update subscriber should not receive an upsert event.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        $clientCreate->close();
        $clientUpdate->close();
        $clientUpsert->close();
    }

    public function testChannelActionFilterByDocumentId(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];

        ['databaseId' => $databaseId, 'collectionId' => $collectionId] = $this->createActorsCollection();

        // Use a known custom ID so the .id.action channel can be subscribed before the
        // document exists. Without this the channel name can't be predicted.
        $watchedId = 'actor-watched';
        $idCreateChannel = "databases.{$databaseId}.collections.{$collectionId}.documents.{$watchedId}.create";

        $clientWatched = $this->getWebsocket([$idCreateChannel], $headers);
        $connected = $this->assertConnectionStatusIfSupported($clientWatched);
        if ($connected !== null) {
            $this->assertContains($idCreateChannel, $connected['data']['channels']);
        }

        // Creating a *different* document should not trigger the watched-id subscription.
        $this->createActor($databaseId, $collectionId, ID::unique(), 'Other Actor');

        try {
            $clientWatched->receive();
            $this->fail('Subscriber to .{id}.create should not receive events for a different document.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        // Creating the watched document delivers exactly one create event.
        $this->createActor($databaseId, $collectionId, $watchedId, 'Watched Actor');

        $event = json_decode($clientWatched->receive(), true);
        $this->assertEquals('event', $event['type']);
        $this->assertContains(
            "databases.{$databaseId}.collections.{$collectionId}.documents.{$watchedId}.create",
            $event['data']['events']
        );
        $this->assertEquals($watchedId, $event['data']['payload']['$id']);
        $this->assertEquals('Watched Actor', $event['data']['payload']['name']);

        // Updating the watched document does NOT match — action filter is `create` only.
        $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents/{$watchedId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'data' => ['name' => 'Watched Actor v2'],
        ]);

        try {
            $clientWatched->receive();
            $this->fail('Subscriber to .{id}.create should not receive update events on the same document.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        $clientWatched->close();
    }

    public function testChannelActionFilterMultiChannelSubscription(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];

        ['databaseId' => $databaseId, 'collectionId' => $collectionId] = $this->createActorsCollection();

        $watchedId = 'actor-multi';
        $idCreateChannel = "databases.{$databaseId}.collections.{$collectionId}.documents.{$watchedId}.create";
        $rowsChannel = "databases.{$databaseId}.tables.{$collectionId}.rows";

        // One subscription that listens on both:
        //   1. `databases...documents.{watchedId}.create` — narrow, action-filtered
        //   2. `databases...tables.{collectionId}.rows`   — broad, non-action (tablesdb mirror)
        // A create on the watched document must reach this subscriber via *both* channels.
        $clientMulti = $this->getWebsocket([$idCreateChannel, $rowsChannel], $headers);
        $connected = $this->assertConnectionStatusIfSupported($clientMulti);
        if ($connected !== null) {
            $this->assertContains($idCreateChannel, $connected['data']['channels']);
            $this->assertContains($rowsChannel, $connected['data']['channels']);
        }

        $this->createActor($databaseId, $collectionId, $watchedId, 'Multi Actor');

        $event = json_decode($clientMulti->receive(), true);
        $this->assertEquals('event', $event['type']);
        // The event payload's channels list reports the underlying base channels that
        // the published event carries. Both the broad rows channel and the document
        // channel that the action filter is anchored on should be present.
        $this->assertContains($rowsChannel, $event['data']['channels']);
        $this->assertContains(
            "databases.{$databaseId}.collections.{$collectionId}.documents.{$watchedId}",
            $event['data']['channels']
        );
        $this->assertContains(
            "databases.{$databaseId}.collections.{$collectionId}.documents.{$watchedId}.create",
            $event['data']['events']
        );
        $this->assertEquals('Multi Actor', $event['data']['payload']['name']);

        // Update on the same doc: the .{id}.create branch is filtered out, but the
        // broad rows channel has no action filter — the subscription still receives
        // the event via that branch (a single delivery, not two).
        $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$collectionId}/documents/{$watchedId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'data' => ['name' => 'Multi Actor v2'],
        ]);

        $update = json_decode($clientMulti->receive(), true);
        $this->assertEquals('event', $update['type']);
        $this->assertContains($rowsChannel, $update['data']['channels']);
        $this->assertContains(
            "databases.{$databaseId}.collections.{$collectionId}.documents.{$watchedId}.update",
            $update['data']['events']
        );

        // No second copy of the same update should arrive — getSubscribers folds
        // multi-channel matches into a single connection delivery.
        try {
            $clientMulti->receive();
            $this->fail('Multi-channel subscriber should receive a single delivery per event.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        $clientMulti->close();
    }

    public function testChannelActionFilterDeliversDeleteEvents(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];

        ['databaseId' => $databaseId, 'collectionId' => $collectionId] = $this->createActorsCollection();

        $deleteChannel = "databases.{$databaseId}.collections.{$collectionId}.documents.delete";
        $clientDelete = $this->getWebsocket([$deleteChannel], $headers);
        $connected = $this->assertConnectionStatusIfSupported($clientDelete);
        if ($connected !== null) {
            $this->assertContains($deleteChannel, $connected['data']['channels']);
        }

        $documentId = ID::unique();
        $this->createActor($databaseId, $collectionId, $documentId, 'About To Be Deleted');

        // Create event must not arrive — the action filter is `delete`.
        try {
            $clientDelete->receive();
            $this->fail('Delete subscriber should not receive a create event.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        $this->client->call(Client::METHOD_DELETE, "/databases/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $deleteEvent = json_decode($clientDelete->receive(), true);
        $this->assertEquals('event', $deleteEvent['type']);
        $this->assertContains(
            "databases.{$databaseId}.collections.{$collectionId}.documents.{$documentId}.delete",
            $deleteEvent['data']['events']
        );
        $this->assertEquals($documentId, $deleteEvent['data']['payload']['$id']);

        $clientDelete->close();
    }

    public function testChannelActionFilterUnknownSuffixTreatedAsLiteral(): void
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $headers = [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ];

        ['databaseId' => $databaseId, 'collectionId' => $collectionId] = $this->createActorsCollection();

        // An unrecognised suffix is NOT in SUPPORTED_ACTIONS, so parseActionChannel
        // leaves the channel name intact and treats it as a literal channel that no
        // published event ever carries — the subscriber should receive nothing.
        $client = $this->getWebsocket(['documents.bogus'], $headers);
        $connected = $this->assertConnectionStatusIfSupported($client);
        if ($connected !== null) {
            $this->assertContains('documents.bogus', $connected['data']['channels']);
        }

        $documentId = ID::unique();
        $this->createActor($databaseId, $collectionId, $documentId, 'No Bogus Listener');

        $this->client->call(Client::METHOD_DELETE, "/databases/{$databaseId}/collections/{$collectionId}/documents/{$documentId}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        try {
            $client->receive();
            $this->fail('Unrecognised action suffix should not deliver any events.');
        } catch (TimeoutException $e) {
            $this->addToAssertionCount(1);
        }

        $client->close();
    }
}
