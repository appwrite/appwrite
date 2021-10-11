<?php

namespace Tests\E2E\Services\Realtime;

use CURLFile;
use Tests\E2E\Client;
use WebSocket\Client as WebSocketClient;
use WebSocket\ConnectionException;

trait RealtimeBase
{

    private function getWebsocket($channels = [], $headers = [])
    {
        $headers = array_merge([
            'Origin' => 'appwrite.test'
        ], $headers);

        $query = [
            'project' => $this->getProject()['$id'],
            'channels' => $channels
        ];
        return new WebSocketClient('ws://appwrite-traefik/v1/realtime?' . http_build_query($query), [
            'headers' => $headers,
            'timeout' => 30,
        ]);
    }

    public function testConnection()
    {
        /**
         * Test for SUCCESS
         */
        $client = $this->getWebsocket(['documents']);
        $this->assertNotEmpty($client->receive());
        $client->close();

        /**
         * Test for FAILURE
         */
        $client = $this->getWebsocket(['documents'], ['origin' => 'http://appwrite.unknown']);
        $payload = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertEquals('error', $payload['type']);
        $this->assertEquals(1008, $payload['data']['code']);
        $this->assertEquals('Invalid Origin. Register your new client (appwrite.unknown) as a new Web platform on your project console dashboard', $payload['data']['message']);
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();

        $client = $this->getWebsocket();
        $payload = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertEquals('error', $payload['type']);
        $this->assertEquals(1008, $payload['data']['code']);
        $this->assertEquals('Missing channels', $payload['data']['message']);
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();

        $client = new WebSocketClient('ws://appwrite-traefik/v1/realtime', [
            'headers' => [
                'Origin' => 'appwrite.test'
            ]
        ]);
        $payload = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertEquals('error', $payload['type']);
        $this->assertEquals(1008, $payload['data']['code']);
        $this->assertEquals('Missing or unknown project ID', $payload['data']['message']);
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();

        $client = new WebSocketClient('ws://appwrite-traefik/v1/realtime?project=123', [
            'headers' => [
                'Origin' => 'appwrite.test'
            ]
        ]);
        $payload = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertEquals('error', $payload['type']);
        $this->assertEquals(1008, $payload['data']['code']);
        $this->assertEquals('Missing or unknown project ID', $payload['data']['message']);
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();
    }

    public function testChannelParsing()
    {
        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';
        $headers =  [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $session
        ];

        $client = $this->getWebsocket(['documents'], $headers);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertEquals($userId, $response['data']['user']['$id']);

        $client->close();

        $client = $this->getWebsocket(['account'], $headers);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals($userId, $response['data']['user']['$id']);

        $client->close();

        $client = $this->getWebsocket(['account', 'documents', 'account.123'], $headers);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals($userId, $response['data']['user']['$id']);

        $client->close();

        $client = $this->getWebsocket([
            'account',
            'files',
            'files.1',
            'collections',
            'collections.1',
            'collections.1.documents',
            'collections.2',
            'collections.2.documents',
            'documents',
            'documents.1',
            'documents.2',
        ], $headers);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertCount(12, $response['data']['channels']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertContains('files', $response['data']['channels']);
        $this->assertContains('files.1', $response['data']['channels']);
        $this->assertContains('collections', $response['data']['channels']);
        $this->assertContains('collections.1', $response['data']['channels']);
        $this->assertContains('collections.1.documents', $response['data']['channels']);
        $this->assertContains('collections.2', $response['data']['channels']);
        $this->assertContains('collections.2.documents', $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains('documents.1', $response['data']['channels']);
        $this->assertContains('documents.2', $response['data']['channels']);
        $this->assertEquals($userId, $response['data']['user']['$id']);

        $client->close();
    }

    public function testManualAuthentication()
    {
        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $client = $this->getWebsocket(['account'], [
            'origin' => 'http://localhost'
        ]);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('account', $response['data']['channels']);

        $client->send(\json_encode([
            'type' => 'authentication',
            'data' => [
                'session' => $session
            ]
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('response', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals('authentication', $response['data']['to']);
        $this->assertTrue($response['data']['success']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertEquals($userId, $response['data']['user']['$id']);

        /**
         * Test for FAILURE
         */
        $client->send(\json_encode([
            'type' => 'authentication',
            'data' => [
                'session' => 'invalid_session'
            ]
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Session is not valid.', $response['data']['message']);

        $client->send(\json_encode([
            'type' => 'authentication',
            'data' => []
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Payload is not valid.', $response['data']['message']);

        $client->send(\json_encode([
            'type' => 'unknown',
            'data' => [
                'session' => 'invalid_session'
            ]
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Message type is not valid.', $response['data']['message']);

        $client->send(\json_encode([
            'test' => '123',
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Message format is not valid.', $response['data']['message']);


        $client->close();
    }

    public function testChannelAccount()
    {
        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['account'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_'.$projectId.'=' . $session
        ]);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertEquals($userId, $response['data']['user']['$id']);

        /**
         * Test Account Name Event
         */
        $name = "Torsten Dittmann";

        $this->client->call(Client::METHOD_PATCH, '/account/name', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'name' => $name
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals('account.update.name', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $this->assertEquals($name, $response['data']['payload']['name']);


        /**
         * Test Account Password Event
         */
        $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$projectId.'=' . $session,
        ]), [
            'password' => 'new-password',
            'oldPassword' => 'password',
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals('account.update.password', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $this->assertEquals($name, $response['data']['payload']['name']);

        /**
         * Test Account Email Update
         */
        $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$projectId.'=' . $session,
        ]), [
            'email' => 'torsten@appwrite.io',
            'password' => 'new-password',
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals('account.update.email', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $this->assertEquals('torsten@appwrite.io', $response['data']['payload']['email']);

        /**
         * Test Account Verification Create
         */
        $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$projectId.'=' . $session,
        ]), [
            'url' => 'http://localhost/verification',
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals('account.verification.create', $response['data']['event']);

        $lastEmail = $this->getLastEmail();
        $verification = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);

        /**
         * Test Account Verification Complete
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$projectId.'=' . $session,
        ]), [
            'userId' => $userId,
            'secret' => $verification,
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals('account.verification.update', $response['data']['event']);

        /**
         * Test Acoount Prefs Update
         */
        $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$projectId.'=' . $session,
        ]), [
            'prefs' => [
                'prefKey1' => 'prefValue1',
                'prefKey2' => 'prefValue2',
            ]
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals('account.update.prefs', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test Account Session Create
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'email' => 'torsten@appwrite.io',
            'password' => 'new-password',
        ]);

        $sessionNew = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_'.$projectId];
        $sessionNewId = $response['body']['$id'];

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals('account.sessions.create', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test Account Session Delete
         */
        $this->client->call(Client::METHOD_DELETE, '/account/sessions/'.$sessionNewId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$projectId.'=' . $sessionNew,
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals('account.sessions.delete', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test Account Create Recovery
         */
        $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'email' => 'torsten@appwrite.io',
            'url' => 'http://localhost/recovery',
        ]);

        $response = json_decode($client->receive(), true);

        $lastEmail = $this->getLastEmail();
        $recovery = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals('account.recovery.create', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'userId' => $userId,
            'secret' => $recovery,
            'password' => 'test-recovery',
            'passwordAgain' => 'test-recovery',
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertEquals('account.recovery.update', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();
    }

    public function testChannelDatabase()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['documents', 'collections'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_'.$projectId.'=' . $session
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains('collections', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertEquals($user['$id'], $response['data']['user']['$id']);

        /**
         * Test Collection Create
         */
        $actors = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'Actors',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'collection'
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('collections', $response['data']['channels']);
        $this->assertContains('collections.' . $actors['body']['$id'], $response['data']['channels']);
        $this->assertEquals('database.collections.create', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $data = ['actorsId' => $actors['body']['$id']];

        $name = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals($name['headers']['status-code'], 201);
        $this->assertEquals($name['body']['key'], 'name');
        $this->assertEquals($name['body']['type'], 'string');
        $this->assertEquals($name['body']['size'], 256);
        $this->assertEquals($name['body']['required'], true);

        sleep(2);

        /**
         * Test Document Create
         */
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'name' => 'Chris Evans'
            ],
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains('documents.' . $document['body']['$id'], $response['data']['channels']);
        $this->assertContains('collections.' . $actors['body']['$id'] . '.documents', $response['data']['channels']);
        $this->assertEquals('database.documents.create', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals($response['data']['payload']['name'], 'Chris Evans');

        $data['documentId'] = $document['body']['$id'];

        /**
         * Test Document Update
         */
        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['actorsId'] . '/documents/' . $data['documentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'name' => 'Chris Evans 2'
            ],
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains('documents.' . $data['documentId'], $response['data']['channels']);
        $this->assertContains('collections.' . $data['actorsId'] . '.documents', $response['data']['channels']);
        $this->assertEquals('database.documents.update', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $this->assertEquals($response['data']['payload']['name'], 'Chris Evans 2');


        /**
         * Test Document Delete
         */
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'name' => 'Bradley Cooper'
            ],
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $client->receive();

        $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $data['actorsId'] . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains('documents.' . $document['body']['$id'], $response['data']['channels']);
        $this->assertContains('collections.' . $data['actorsId'] . '.documents', $response['data']['channels']);
        $this->assertEquals('database.documents.delete', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals($response['data']['payload']['name'], 'Bradley Cooper');

        $client->close();
    }

    public function testChannelFiles()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['files'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_'.$projectId.'=' . $session
        ]);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('files', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertEquals($user['$id'], $response['data']['user']['$id']);

        /**
         * Test File Create
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['role:all'],
            'write' => ['role:all'],
            'folderId' => 'xyz',
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('files', $response['data']['channels']);
        $this->assertContains('files.' . $file['body']['$id'], $response['data']['channels']);
        $this->assertEquals('storage.files.create', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $data = ['fileId' => $file['body']['$id']];

        /**
         * Test File Update
         */
        $this->client->call(Client::METHOD_PUT, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('files', $response['data']['channels']);
        $this->assertContains('files.' . $file['body']['$id'], $response['data']['channels']);
        $this->assertEquals('storage.files.update', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test File Delete
         */
        $this->client->call(Client::METHOD_DELETE, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('files', $response['data']['channels']);
        $this->assertContains('files.' . $file['body']['$id'], $response['data']['channels']);
        $this->assertEquals('storage.files.delete', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();
    }

    public function testChannelExecutions()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['executions'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_'.$projectId.'=' . $session
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('executions', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertEquals($user['$id'], $response['data']['user']['$id']);

        /**
         * Test Functions Create
         */
        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'functionId' => 'unique()',
            'name' => 'Test',
            'execute' => ['role:member'],
            'runtime' => 'php-8.0',
            'timeout' => 10,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals($function['headers']['status-code'], 201);
        $this->assertNotEmpty($function['body']['$id']);

        $tag = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/tags', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'command' => 'php index.php',
            'code' => new CURLFile(realpath(__DIR__ . '/../../../resources/functions/timeout.tar.gz'), 'application/x-gzip', 'php-fx.tar.gz'),
        ]);

        $tagId = $tag['body']['$id'] ?? '';

        $this->assertEquals($tag['headers']['status-code'], 201);
        $this->assertNotEmpty($tag['body']['$id']);

        $response = $this->client->call(Client::METHOD_PATCH, '/functions/'.$functionId.'/tag', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tag' => $tagId,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']['$id']);

        // Wait for tag to be built.
        sleep(5);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), []);

        $this->assertEquals($execution['headers']['status-code'], 201);
        $this->assertNotEmpty($execution['body']['$id']);

        $response = json_decode($client->receive(), true);
        $responseUpdate = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('executions', $response['data']['channels']);
        $this->assertContains('executions.' . $execution['body']['$id'], $response['data']['channels']);
        $this->assertContains('functions.' . $execution['body']['functionId'], $response['data']['channels']);
        $this->assertEquals('functions.executions.create', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $this->assertArrayHasKey('type', $responseUpdate);
        $this->assertArrayHasKey('data', $responseUpdate);
        $this->assertEquals('event', $responseUpdate['type']);
        $this->assertNotEmpty($responseUpdate['data']);
        $this->assertArrayHasKey('timestamp', $responseUpdate['data']);
        $this->assertCount(3, $responseUpdate['data']['channels']);
        $this->assertContains('executions', $responseUpdate['data']['channels']);
        $this->assertContains('executions.' . $execution['body']['$id'], $responseUpdate['data']['channels']);
        $this->assertContains('functions.' . $execution['body']['functionId'], $responseUpdate['data']['channels']);
        $this->assertEquals('functions.executions.update', $responseUpdate['data']['event']);
        $this->assertNotEmpty($responseUpdate['data']['payload']);

        $client->close();
    }

    public function testChannelTeams(): array
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['teams'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_'.$projectId.'=' . $session
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('teams', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertEquals($user['$id'], $response['data']['user']['$id']);

        /**
         * Test Team Create
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'teamId' => 'unique()',
            'name' => 'Arsenal'
        ]);

        $teamId = $team['body']['$id'] ?? '';

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('teams', $response['data']['channels']);
        $this->assertContains('teams.' . $teamId, $response['data']['channels']);
        $this->assertEquals('teams.create', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test Team Update
         */
        $team = $this->client->call(Client::METHOD_PUT, '/teams/'.$teamId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'name' => 'Manchester'
        ]);

        $this->assertEquals($team['headers']['status-code'], 200);
        $this->assertNotEmpty($team['body']['$id']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('teams', $response['data']['channels']);
        $this->assertContains('teams.' . $teamId, $response['data']['channels']);
        $this->assertEquals('teams.update', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();

        return ['teamId' => $teamId];
    }

    /**
     * @depends testChannelTeams
     */
    public function testChannelMemberships(array $data)
    {
        $teamId = $data['teamId'] ?? '';

        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['memberships'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_'.$projectId.'='.$session
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('memberships', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertEquals($user['$id'], $response['data']['user']['$id']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/'.$teamId.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $membershipId = $response['body']['memberships'][0]['$id'];

        /**
         * Test Update Membership
         */
        $roles = ['admin', 'editor', 'uncle'];
        $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamId.'/memberships/'.$membershipId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'roles' => $roles
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('memberships', $response['data']['channels']);
        $this->assertContains('memberships.' . $membershipId, $response['data']['channels']);
        $this->assertEquals('teams.memberships.update', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();
    }
}
