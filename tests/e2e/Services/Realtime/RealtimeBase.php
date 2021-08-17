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
        $this->assertEquals(json_encode([
            'documents' => 0
        ]), $client->receive());
        $client->close();

        /**
         * Test for FAILURE
         */
        $client = $this->getWebsocket(['documents'], ['origin' => 'http://appwrite.unknown']);
        $payload = json_decode($client->receive(), true);
        $this->assertEquals(1008, $payload['code']);
        $this->assertEquals('Invalid Origin. Register your new client (appwrite.unknown) as a new Web platform on your project console dashboard', $payload['message']);
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();

        $client = $this->getWebsocket();
        $payload = json_decode($client->receive(), true);
        $this->assertEquals(1008, $payload['code']);
        $this->assertEquals('Missing channels', $payload['message']);
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();

        $client = new WebSocketClient('ws://appwrite-traefik/v1/realtime', [
            'headers' => [
                'Origin' => 'appwrite.test'
            ]
        ]);
        $payload = json_decode($client->receive(), true);
        $this->assertEquals(1008, $payload['code']);
        $this->assertEquals('Missing or unknown project ID', $payload['message']);
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
        $client->close();

        $client = new WebSocketClient('ws://appwrite-traefik/v1/realtime?project=123', [
            'headers' => [
                'Origin' => 'appwrite.test'
            ]
        ]);
        $payload = json_decode($client->receive(), true);
        $this->assertEquals(1008, $payload['code']);
        $this->assertEquals('Missing or unknown project ID', $payload['message']);
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

        $client = $this->getWebsocket(['documents']);
        $response = json_decode($client->receive(), true);
        $this->assertCount(1, $response);
        $this->assertArrayHasKey('documents', $response);
        $client->close();

        $client = $this->getWebsocket(['account'], $headers);
        $response = json_decode($client->receive(), true);
        $this->assertCount(1, $response);
        $this->assertArrayHasKey('account.' . $userId, $response);
        $client->close();

        $client = $this->getWebsocket(['account', 'documents', 'account.123'], $headers);
        $response = json_decode($client->receive(), true);
        $this->assertCount(2, $response);
        $this->assertArrayHasKey('documents', $response);
        $this->assertArrayHasKey('account.' . $userId, $response);
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

        $this->assertCount(11, $response);
        $this->assertArrayHasKey('account.' . $userId, $response);
        $this->assertArrayHasKey('files', $response);
        $this->assertArrayHasKey('files.1', $response);
        $this->assertArrayHasKey('collections', $response);
        $this->assertArrayHasKey('collections.1', $response);
        $this->assertArrayHasKey('collections.1.documents', $response);
        $this->assertArrayHasKey('collections.2', $response);
        $this->assertArrayHasKey('collections.2.documents', $response);
        $this->assertArrayHasKey('documents', $response);
        $this->assertArrayHasKey('documents.1', $response);
        $this->assertArrayHasKey('documents.2', $response);
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
        $this->assertCount(1, $response);
        $this->assertArrayHasKey('account.' . $userId, $response);

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

        $this->assertCount(2, $response['channels']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertContains('account', $response['channels']);
        $this->assertContains('account.' . $userId, $response['channels']);
        $this->assertEquals('account.update.name', $response['event']);
        $this->assertNotEmpty($response['payload']);

        $this->assertEquals($name, $response['payload']['name']);


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

        $this->assertCount(2, $response['channels']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertContains('account', $response['channels']);
        $this->assertContains('account.' . $userId, $response['channels']);
        $this->assertEquals('account.update.password', $response['event']);
        $this->assertNotEmpty($response['payload']);

        $this->assertEquals($name, $response['payload']['name']);

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

        $this->assertCount(2, $response['channels']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertContains('account', $response['channels']);
        $this->assertContains('account.' . $userId, $response['channels']);
        $this->assertEquals('account.update.email', $response['event']);
        $this->assertNotEmpty($response['payload']);

        $this->assertEquals('torsten@appwrite.io', $response['payload']['email']);

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

        $this->assertCount(2, $response['channels']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertContains('account', $response['channels']);
        $this->assertContains('account.' . $userId, $response['channels']);
        $this->assertEquals('account.verification.create', $response['event']);

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

        $this->assertCount(2, $response['channels']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertContains('account', $response['channels']);
        $this->assertContains('account.' . $userId, $response['channels']);
        $this->assertEquals('account.verification.update', $response['event']);

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

        $this->assertCount(2, $response['channels']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertContains('account', $response['channels']);
        $this->assertContains('account.' . $userId, $response['channels']);
        $this->assertEquals('account.update.prefs', $response['event']);
        $this->assertNotEmpty($response['payload']);

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

        $this->assertCount(2, $response['channels']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertContains('account', $response['channels']);
        $this->assertContains('account.' . $userId, $response['channels']);
        $this->assertEquals('account.sessions.create', $response['event']);
        $this->assertNotEmpty($response['payload']);

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

        $this->assertCount(2, $response['channels']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertContains('account', $response['channels']);
        $this->assertContains('account.' . $userId, $response['channels']);
        $this->assertEquals('account.sessions.delete', $response['event']);
        $this->assertNotEmpty($response['payload']);

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

        $this->assertCount(2, $response['channels']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertContains('account', $response['channels']);
        $this->assertContains('account.' . $userId, $response['channels']);
        $this->assertEquals('account.recovery.create', $response['event']);
        $this->assertNotEmpty($response['payload']);

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

        $this->assertCount(2, $response['channels']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertContains('account', $response['channels']);
        $this->assertContains('account.' . $userId, $response['channels']);
        $this->assertEquals('account.recovery.update', $response['event']);
        $this->assertNotEmpty($response['payload']);

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
        $this->assertCount(2, $response);
        $this->assertArrayHasKey('documents', $response);
        $this->assertArrayHasKey('collections', $response);

        /**
         * Test Collection Create
         */
        $actors = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Actors',
            'read' => ['*'],
            'write' => ['*'],
            'rules' => [
                [
                    'label' => 'First Name',
                    'key' => 'firstName',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'array' => false
                ],
                [
                    'label' => 'Last Name',
                    'key' => 'lastName',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'array' => false
                ],
            ],
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(2, $response['channels']);
        $this->assertContains('collections', $response['channels']);
        $this->assertContains('collections.' . $actors['body']['$id'], $response['channels']);
        $this->assertEquals('database.collections.create', $response['event']);
        $this->assertNotEmpty($response['payload']);

        $data = ['actorsId' => $actors['body']['$id']];

        /**
         * Test Document Create
         */
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'firstName' => 'Chris',
                'lastName' => 'Evans',
            ],
            'read' => ['*'],
            'write' => ['*'],
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(3, $response['channels']);
        $this->assertContains('documents', $response['channels']);
        $this->assertContains('documents.' . $document['body']['$id'], $response['channels']);
        $this->assertContains('collections.' . $actors['body']['$id'] . '.documents', $response['channels']);
        $this->assertEquals('database.documents.create', $response['event']);
        $this->assertNotEmpty($response['payload']);

        $data['documentId'] = $document['body']['$id'];

        /**
         * Test Document Update
         */
        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['actorsId'] . '/documents/' . $data['documentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'firstName' => 'Chris1',
                'lastName' => 'Evans2',
            ],
            'read' => ['*'],
            'write' => ['*'],
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(3, $response['channels']);
        $this->assertContains('documents', $response['channels']);
        $this->assertContains('documents.' . $data['documentId'], $response['channels']);
        $this->assertContains('collections.' . $data['actorsId'] . '.documents', $response['channels']);
        $this->assertEquals('database.documents.update', $response['event']);
        $this->assertNotEmpty($response['payload']);

        $this->assertEquals($response['payload']['firstName'], 'Chris1');
        $this->assertEquals($response['payload']['lastName'], 'Evans2');

        /**
         * Test Document Delete
         */
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'firstName' => 'Bradly',
                'lastName' => 'Cooper',

            ],
            'read' => ['*'],
            'write' => ['*'],
        ]);

        $client->receive();

        $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $data['actorsId'] . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(3, $response['channels']);
        $this->assertContains('documents', $response['channels']);
        $this->assertContains('documents.' . $document['body']['$id'], $response['channels']);
        $this->assertContains('collections.' . $data['actorsId'] . '.documents', $response['channels']);
        $this->assertEquals('database.documents.delete', $response['event']);
        $this->assertNotEmpty($response['payload']);

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
        $this->assertCount(1, $response);
        $this->assertArrayHasKey('files', $response);

        /**
         * Test File Create
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['*'],
            'write' => ['*'],
            'folderId' => 'xyz',
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(2, $response['channels']);
        $this->assertContains('files', $response['channels']);
        $this->assertContains('files.' . $file['body']['$id'], $response['channels']);
        $this->assertEquals('storage.files.create', $response['event']);
        $this->assertNotEmpty($response['payload']);

        $data = ['fileId' => $file['body']['$id']];

        /**
         * Test File Update
         */
        $this->client->call(Client::METHOD_PUT, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'read' => ['*'],
            'write' => ['*'],
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(2, $response['channels']);
        $this->assertContains('files', $response['channels']);
        $this->assertContains('files.' . $file['body']['$id'], $response['channels']);
        $this->assertEquals('storage.files.update', $response['event']);
        $this->assertNotEmpty($response['payload']);

        /**
         * Test File Delete
         */
        $this->client->call(Client::METHOD_DELETE, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(2, $response['channels']);
        $this->assertContains('files', $response['channels']);
        $this->assertContains('files.' . $file['body']['$id'], $response['channels']);
        $this->assertEquals('storage.files.delete', $response['event']);
        $this->assertNotEmpty($response['payload']);

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
        $this->assertCount(1, $response);
        $this->assertArrayHasKey('executions', $response);

        /**
         * Test File Create
         */
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test',
            'runtime' => 'php-7.4',
            'execute' => ['*'],
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

        $execution = $this->client->call(Client::METHOD_POST, '/functions/'.$functionId.'/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), []);

        $this->assertEquals($execution['headers']['status-code'], 201);
        $this->assertNotEmpty($execution['body']['$id']);

        $response = json_decode($client->receive(), true);
        $responseUpdate = json_decode($client->receive(), true);

        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(3, $response['channels']);
        $this->assertContains('executions', $response['channels']);
        $this->assertContains('executions.' . $execution['body']['$id'], $response['channels']);
        $this->assertContains('functions.' . $execution['body']['functionId'], $response['channels']);
        $this->assertEquals('functions.executions.create', $response['event']);
        $this->assertNotEmpty($response['payload']);

        $this->assertArrayHasKey('timestamp', $responseUpdate);
        $this->assertCount(3, $responseUpdate['channels']);
        $this->assertContains('executions', $responseUpdate['channels']);
        $this->assertContains('executions.' . $execution['body']['$id'], $responseUpdate['channels']);
        $this->assertContains('functions.' . $execution['body']['functionId'], $responseUpdate['channels']);
        $this->assertEquals('functions.executions.update', $responseUpdate['event']);
        $this->assertNotEmpty($responseUpdate['payload']);

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

        $this->assertCount(1, $response);
        $this->assertArrayHasKey('teams', $response);

        /**
         * Test Team Create
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'name' => 'Arsenal'
        ]);

        $teamId = $team['body']['$id'] ?? '';

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(2, $response['channels']);
        $this->assertContains('teams', $response['channels']);
        $this->assertContains('teams.' . $teamId, $response['channels']);
        $this->assertEquals('teams.create', $response['event']);
        $this->assertNotEmpty($response['payload']);

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

        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(2, $response['channels']);
        $this->assertContains('teams', $response['channels']);
        $this->assertContains('teams.' . $teamId, $response['channels']);
        $this->assertEquals('teams.update', $response['event']);
        $this->assertNotEmpty($response['payload']);

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

        $this->assertCount(1, $response);
        $this->assertArrayHasKey('memberships', $response);

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
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertCount(2, $response['channels']);
        $this->assertContains('memberships', $response['channels']);
        $this->assertContains('memberships.' . $membershipId, $response['channels']);
        $this->assertEquals('teams.memberships.update', $response['event']);
        $this->assertNotEmpty($response['payload']);

        $client->close();
    }
}
