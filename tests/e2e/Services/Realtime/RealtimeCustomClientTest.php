<?php

namespace Tests\E2E\Services\Realtime;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;
use Utopia\CLI\Console;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use WebSocket\ConnectionException;

class RealtimeCustomClientTest extends Scope
{
    use RealtimeBase;
    use ProjectCustom;
    use SideClient;

    public function testChannelParsing()
    {
        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';

        $headers =  [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session
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
            'collections.1.documents',
            'collections.2.documents',
            'documents',
            'collections.1.documents.1',
            'collections.2.documents.2',
        ], $headers);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertCount(10, $response['data']['channels']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertContains('files', $response['data']['channels']);
        $this->assertContains('files.1', $response['data']['channels']);
        $this->assertContains('collections', $response['data']['channels']);
        $this->assertContains('collections.1.documents', $response['data']['channels']);
        $this->assertContains('collections.2.documents', $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains('collections.1.documents.1', $response['data']['channels']);
        $this->assertContains('collections.2.documents.2', $response['data']['channels']);
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

    public function testConnectionPlatform()
    {
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
        \usleep(250000); // 250ms
        $this->expectException(ConnectionException::class); // Check if server disconnnected client
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
            'cookie' => 'a_session_' . $projectId . '=' . $session
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
        $this->assertContains("users.{$userId}.update.name", $response['data']['events']);
        $this->assertContains("users.{$userId}.update", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.update.name", $response['data']['events']);
        $this->assertContains("users.*.update", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $this->assertEquals($name, $response['data']['payload']['name']);


        /**
         * Test Account Password Event
         */
        $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
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
        $this->assertContains("users.{$userId}.update.password", $response['data']['events']);
        $this->assertContains("users.{$userId}.update", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.update.password", $response['data']['events']);
        $this->assertContains("users.*.update", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $this->assertEquals($name, $response['data']['payload']['name']);

        /**
         * Test Account Email Update
         */
        $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
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
        $this->assertContains("users.{$userId}.update.email", $response['data']['events']);
        $this->assertContains("users.{$userId}.update", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.update.email", $response['data']['events']);
        $this->assertContains("users.*.update", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('torsten@appwrite.io', $response['data']['payload']['email']);

        /**
         * Test Account Verification Create
         */
        $verification = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ]), [
            'url' => 'http://localhost/verification',
        ]);
        $verificationId = $verification['body']['$id'];
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertContains("users.{$userId}.verification.{$verificationId}.create", $response['data']['events']);
        $this->assertContains("users.{$userId}.verification.{$verificationId}", $response['data']['events']);
        $this->assertContains("users.{$userId}.verification.*.create", $response['data']['events']);
        $this->assertContains("users.{$userId}.verification.*", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.verification.{$verificationId}.create", $response['data']['events']);
        $this->assertContains("users.*.verification.{$verificationId}", $response['data']['events']);
        $this->assertContains("users.*.verification.*.create", $response['data']['events']);
        $this->assertContains("users.*.verification.*", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);

        $lastEmail = $this->getLastEmail();
        $verification = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);

        /**
         * Test Account Verification Complete
         */
        $verification = $this->client->call(Client::METHOD_PUT, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
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
        $this->assertContains("users.{$userId}.verification.{$verificationId}.update", $response['data']['events']);
        $this->assertContains("users.{$userId}.verification.{$verificationId}", $response['data']['events']);
        $this->assertContains("users.{$userId}.verification.*.update", $response['data']['events']);
        $this->assertContains("users.{$userId}.verification.*", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.verification.{$verificationId}.update", $response['data']['events']);
        $this->assertContains("users.*.verification.{$verificationId}", $response['data']['events']);
        $this->assertContains("users.*.verification.*.update", $response['data']['events']);
        $this->assertContains("users.*.verification.*", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);
        /**
         * Test Acoount Prefs Update
         */
        $this->client->call(Client::METHOD_PATCH, '/account/prefs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
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
        $this->assertContains("users.{$userId}.update.prefs", $response['data']['events']);
        $this->assertContains("users.{$userId}.update", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.update.prefs", $response['data']['events']);
        $this->assertContains("users.*.update", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $createSession = function () use ($projectId): array {
            $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ]), [
                'email' => 'torsten@appwrite.io',
                'password' => 'new-password',
            ]);

            $sessionNew = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_' . $projectId];
            $sessionNewId = $response['body']['$id'];

            return array("session" => $sessionNew, "sessionId" => $sessionNewId);
        };

        /**
         * Test Account Session Create
         */
        $sessionData = $createSession();

        $sessionNew = $sessionData['session'];
        $sessionNewId = $sessionData['sessionId'];
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertContains("users.{$userId}.sessions.{$sessionNewId}.create", $response['data']['events']);
        $this->assertContains("users.{$userId}.sessions.{$sessionNewId}", $response['data']['events']);
        $this->assertContains("users.{$userId}.sessions.*.create", $response['data']['events']);
        $this->assertContains("users.{$userId}.sessions.*", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.sessions.{$sessionNewId}.create", $response['data']['events']);
        $this->assertContains("users.*.sessions.{$sessionNewId}", $response['data']['events']);
        $this->assertContains("users.*.sessions.*.create", $response['data']['events']);
        $this->assertContains("users.*.sessions.*", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test Account Session Delete
         */
        $this->client->call(Client::METHOD_DELETE, '/account/sessions/' . $sessionNewId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $sessionNew,
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
        $this->assertContains("users.{$userId}.sessions.{$sessionNewId}.delete", $response['data']['events']);
        $this->assertContains("users.{$userId}.sessions.{$sessionNewId}", $response['data']['events']);
        $this->assertContains("users.{$userId}.sessions.*.delete", $response['data']['events']);
        $this->assertContains("users.{$userId}.sessions.*", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.sessions.{$sessionNewId}.delete", $response['data']['events']);
        $this->assertContains("users.*.sessions.{$sessionNewId}", $response['data']['events']);
        $this->assertContains("users.*.sessions.*.delete", $response['data']['events']);
        $this->assertContains("users.*.sessions.*", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test User Account Session Delete
         */

        $sessionData = $createSession();
        $sessionNew = $sessionData['session'];
        $sessionNewId = $sessionData['sessionId'];
        $client->receive(); // Receive the creation message and drop; this was tested earlier already

        $this->client->call(Client::METHOD_DELETE, '/users/' . $userId . '/sessions/' . $sessionNewId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
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
        $this->assertContains("users.{$userId}.sessions.{$sessionNewId}.delete", $response['data']['events']);
        $this->assertContains("users.{$userId}.sessions.{$sessionNewId}", $response['data']['events']);
        $this->assertContains("users.{$userId}.sessions.*.delete", $response['data']['events']);
        $this->assertContains("users.{$userId}.sessions.*", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.sessions.{$sessionNewId}.delete", $response['data']['events']);
        $this->assertContains("users.*.sessions.{$sessionNewId}", $response['data']['events']);
        $this->assertContains("users.*.sessions.*.delete", $response['data']['events']);
        $this->assertContains("users.*.sessions.*", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test Account Create Recovery
         */
        $recovery = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), [
            'email' => 'torsten@appwrite.io',
            'url' => 'http://localhost/recovery',
        ]);
        $recoveryId = $recovery['body']['$id'];
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
        $this->assertContains("users.{$userId}.recovery.{$recoveryId}.create", $response['data']['events']);
        $this->assertContains("users.{$userId}.recovery.{$recoveryId}", $response['data']['events']);
        $this->assertContains("users.{$userId}.recovery.*.create", $response['data']['events']);
        $this->assertContains("users.{$userId}.recovery.*", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.recovery.{$recoveryId}.create", $response['data']['events']);
        $this->assertContains("users.*.recovery.{$recoveryId}", $response['data']['events']);
        $this->assertContains("users.*.recovery.*.create", $response['data']['events']);
        $this->assertContains("users.*.recovery.*", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);
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
        $this->assertContains("users.{$userId}.recovery.{$recoveryId}.update", $response['data']['events']);
        $this->assertContains("users.{$userId}.recovery.{$recoveryId}", $response['data']['events']);
        $this->assertContains("users.{$userId}.recovery.*.update", $response['data']['events']);
        $this->assertContains("users.{$userId}.recovery.*", $response['data']['events']);
        $this->assertContains("users.{$userId}", $response['data']['events']);
        $this->assertContains("users.*.recovery.{$recoveryId}.update", $response['data']['events']);
        $this->assertContains("users.*.recovery.{$recoveryId}", $response['data']['events']);
        $this->assertContains("users.*.recovery.*.update", $response['data']['events']);
        $this->assertContains("users.*.recovery.*", $response['data']['events']);
        $this->assertContains("users.*", $response['data']['events']);
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
            'cookie' => 'a_session_' . $projectId . '=' . $session
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
         * Test Database Create
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

        /**
         * Test Collection Create
         */
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
            'documentSecurity' => true,
        ]);

        $actorsId = $actors['body']['$id'];

        $name = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals($name['headers']['status-code'], 202);
        $this->assertEquals($name['body']['key'], 'name');
        $this->assertEquals($name['body']['type'], 'string');
        $this->assertEquals($name['body']['size'], 256);
        $this->assertEquals($name['body']['required'], true);

        sleep(2);

        /**
         * Test Document Create
         */
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Chris Evans'
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $response = json_decode($client->receive(), true);

        $documentId = $document['body']['$id'];

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains('databases.' . $databaseId . '.collections.' . $actorsId . '.documents.' . $documentId, $response['data']['channels']);
        $this->assertContains('databases.' . $databaseId . '.collections.' . $actorsId . '.documents', $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals($response['data']['payload']['name'], 'Chris Evans');

        /**
         * Test Document Update
         */
        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Chris Evans 2'
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $this->assertEquals($response['data']['payload']['name'], 'Chris Evans 2');

        /**
         * Test Document Delete
         */
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Bradley Cooper'
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $client->receive();

        $documentId = $document['body']['$id'];

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents/' . $documentId, array_merge([
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
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals($response['data']['payload']['name'], 'Bradley Cooper');

        $client->close();
    }

    public function testChannelDatabaseCollectionPermissions()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['documents', 'collections'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session
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
         * Test Database Create
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

        /**
         * Test Collection Create
         */
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ]
        ]);

        $actorsId = $actors['body']['$id'];

        $name = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals($name['headers']['status-code'], 202);
        $this->assertEquals($name['body']['key'], 'name');
        $this->assertEquals($name['body']['type'], 'string');
        $this->assertEquals($name['body']['size'], 256);
        $this->assertEquals($name['body']['required'], true);

        sleep(2);

        /**
         * Test Document Create
         */
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Chris Evans'
            ],
            'permissions' => [],
        ]);

        $documentId = $document['body']['$id'];

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals($response['data']['payload']['name'], 'Chris Evans');

        /**
         * Test Document Update
         */
        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'Chris Evans 2'
            ],
            'permissions' => [],
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $this->assertEquals($response['data']['payload']['name'], 'Chris Evans 2');

        /**
         * Test Document Delete
         */
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'name' => 'Bradley Cooper'
            ],
            'permissions' => [],
        ]);

        $documentId = $document['body']['$id'];

        $client->receive();

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents/' . $documentId, array_merge([
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
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.{$documentId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
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
            'cookie' => 'a_session_' . $projectId . '=' . $session
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
         * Test Bucket Create
         */
        $bucket1 = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'bucketId' => ID::unique(),
            'name' => 'Bucket 1',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ]
        ]);

        $bucketId = $bucket1['body']['$id'];

        /**
         * Test File Create
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $fileId = $file['body']['$id'];

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('files', $response['data']['channels']);
        $this->assertContains("buckets.{$bucketId}.files.{$fileId}", $response['data']['channels']);
        $this->assertContains("buckets.{$bucketId}.files", $response['data']['channels']);
        $this->assertContains("buckets.{$bucketId}.files.{$fileId}.create", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}.files.{$fileId}", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}.files.*.create", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}.files.*", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}", $response['data']['events']);
        $this->assertContains("buckets.*.files.{$fileId}.create", $response['data']['events']);
        $this->assertContains("buckets.*.files.{$fileId}", $response['data']['events']);
        $this->assertContains("buckets.*.files.*.create", $response['data']['events']);
        $this->assertContains("buckets.*.files.*", $response['data']['events']);
        $this->assertContains("buckets.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $fileId = $file['body']['$id'];

        /**
         * Test File Update
         */
        $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(3, $response['data']['channels']);
        $this->assertContains('files', $response['data']['channels']);
        $this->assertContains("buckets.{$bucketId}.files.{$fileId}", $response['data']['channels']);
        $this->assertContains("buckets.{$bucketId}.files", $response['data']['channels']);
        $this->assertContains("buckets.{$bucketId}.files.{$fileId}.update", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}.files.{$fileId}", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}.files.*.update", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}.files.*", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}", $response['data']['events']);
        $this->assertContains("buckets.*.files.{$fileId}.update", $response['data']['events']);
        $this->assertContains("buckets.*.files.{$fileId}", $response['data']['events']);
        $this->assertContains("buckets.*.files.*.update", $response['data']['events']);
        $this->assertContains("buckets.*.files.*", $response['data']['events']);
        $this->assertContains("buckets.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test File Delete
         */
        $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
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
        $this->assertContains('files', $response['data']['channels']);
        $this->assertContains("buckets.{$bucketId}.files.{$fileId}", $response['data']['channels']);
        $this->assertContains("buckets.{$bucketId}.files", $response['data']['channels']);
        $this->assertContains("buckets.{$bucketId}.files.{$fileId}.delete", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}.files.{$fileId}", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}.files.*.delete", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}.files.*", $response['data']['events']);
        $this->assertContains("buckets.{$bucketId}", $response['data']['events']);
        $this->assertContains("buckets.*.files.{$fileId}.delete", $response['data']['events']);
        $this->assertContains("buckets.*.files.{$fileId}", $response['data']['events']);
        $this->assertContains("buckets.*.files.*.delete", $response['data']['events']);
        $this->assertContains("buckets.*.files.*", $response['data']['events']);
        $this->assertContains("buckets.*", $response['data']['events']);
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
            'cookie' => 'a_session_' . $projectId . '=' . $session
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
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => ['users'],
            'runtime' => 'php-8.0',
            'timeout' => 10,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals($function['headers']['status-code'], 201);
        $this->assertNotEmpty($function['body']['$id']);

        $folder = 'timeout';
        $stderr = '';
        $stdout = '';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/{$folder}/code.tar.gz";

        Console::execute('cd ' . realpath(__DIR__ . "/../../../resources/functions") . "/{$folder}  && tar --exclude code.tar.gz -czf code.tar.gz .", '', $stdout, $stderr);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', basename($code))
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals($deployment['headers']['status-code'], 202);
        $this->assertNotEmpty($deployment['body']['$id']);

        // Wait for deployment to be built.
        sleep(5);

        $response = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']['$id']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'async' => true
        ]);

        $this->assertEquals($execution['headers']['status-code'], 202);
        $this->assertNotEmpty($execution['body']['$id']);

        $response = json_decode($client->receive(), true);
        $responseUpdate = json_decode($client->receive(), true);

        $executionId = $execution['body']['$id'];

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(4, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains('executions', $response['data']['channels']);
        $this->assertContains("executions.{$executionId}", $response['data']['channels']);
        $this->assertContains("functions.{$functionId}", $response['data']['channels']);
        $this->assertContains("functions.{$functionId}.executions.{$executionId}.create", $response['data']['events']);
        $this->assertContains("functions.{$functionId}.executions.{$executionId}", $response['data']['events']);
        $this->assertContains("functions.{$functionId}.executions.*.create", $response['data']['events']);
        $this->assertContains("functions.{$functionId}.executions.*", $response['data']['events']);
        $this->assertContains("functions.{$functionId}", $response['data']['events']);
        $this->assertContains("functions.*.executions.{$executionId}.create", $response['data']['events']);
        $this->assertContains("functions.*.executions.{$executionId}", $response['data']['events']);
        $this->assertContains("functions.*.executions.*.create", $response['data']['events']);
        $this->assertContains("functions.*.executions.*", $response['data']['events']);
        $this->assertContains("functions.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $this->assertArrayHasKey('type', $responseUpdate);
        $this->assertArrayHasKey('data', $responseUpdate);
        $this->assertEquals('event', $responseUpdate['type']);
        $this->assertNotEmpty($responseUpdate['data']);
        $this->assertArrayHasKey('timestamp', $responseUpdate['data']);
        $this->assertCount(4, $responseUpdate['data']['channels']);
        $this->assertContains('console', $responseUpdate['data']['channels']);
        $this->assertContains('executions', $responseUpdate['data']['channels']);
        $this->assertContains("executions.{$executionId}", $responseUpdate['data']['channels']);
        $this->assertContains("functions.{$functionId}", $responseUpdate['data']['channels']);
        $this->assertContains("functions.{$functionId}.executions.{$executionId}.update", $responseUpdate['data']['events']);
        $this->assertContains("functions.{$functionId}.executions.{$executionId}", $responseUpdate['data']['events']);
        $this->assertContains("functions.{$functionId}.executions.*.update", $responseUpdate['data']['events']);
        $this->assertContains("functions.{$functionId}.executions.*", $responseUpdate['data']['events']);
        $this->assertContains("functions.{$functionId}", $responseUpdate['data']['events']);
        $this->assertContains("functions.*.executions.{$executionId}.update", $responseUpdate['data']['events']);
        $this->assertContains("functions.*.executions.{$executionId}", $responseUpdate['data']['events']);
        $this->assertContains("functions.*.executions.*.update", $responseUpdate['data']['events']);
        $this->assertContains("functions.*.executions.*", $responseUpdate['data']['events']);
        $this->assertContains("functions.*", $responseUpdate['data']['events']);
        $this->assertNotEmpty($responseUpdate['data']['payload']);

        $client->close();

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testChannelTeams(): array
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['teams'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session
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
            'teamId' => ID::unique(),
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
        $this->assertContains("teams.{$teamId}", $response['data']['channels']);
        $this->assertContains("teams.{$teamId}.create", $response['data']['events']);
        $this->assertContains("teams.{$teamId}", $response['data']['events']);
        $this->assertContains("teams.*.create", $response['data']['events']);
        $this->assertContains("teams.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test Team Update
         */
        $team = $this->client->call(Client::METHOD_PUT, '/teams/' . $teamId, array_merge([
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
        $this->assertContains("teams.{$teamId}", $response['data']['channels']);
        $this->assertContains("teams.{$teamId}.update", $response['data']['events']);
        $this->assertContains("teams.{$teamId}", $response['data']['events']);
        $this->assertContains("teams.*.update", $response['data']['events']);
        $this->assertContains("teams.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        /**
         * Test Team Update Prefs
         */
        $team = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamId . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'prefs' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
            ]
        ]);

        $this->assertEquals($team['headers']['status-code'], 200);
        $this->assertEquals($team['body']['funcKey1'], 'funcValue1');
        $this->assertEquals($team['body']['funcKey2'], 'funcValue2');

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(2, $response['data']['channels']);
        $this->assertContains('teams', $response['data']['channels']);
        $this->assertContains("teams.{$teamId}", $response['data']['channels']);
        $this->assertContains("teams.{$teamId}.update", $response['data']['events']);
        $this->assertContains("teams.{$teamId}.update.prefs", $response['data']['events']);
        $this->assertContains("teams.{$teamId}", $response['data']['events']);
        $this->assertContains("teams.*.update.prefs", $response['data']['events']);
        $this->assertContains("teams.*.update", $response['data']['events']);
        $this->assertContains("teams.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals($response['data']['payload']['funcKey1'], 'funcValue1');
        $this->assertEquals($response['data']['payload']['funcKey2'], 'funcValue2');

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
            'cookie' => 'a_session_' . $projectId . '=' . $session
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

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamId . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $membershipId = $response['body']['memberships'][0]['$id'];

        /**
         * Test Update Membership
         */
        $roles = ['admin', 'editor', 'uncle'];
        $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamId . '/memberships/' . $membershipId, array_merge([
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
        $this->assertContains("memberships.{$membershipId}", $response['data']['channels']);
        $this->assertContains("teams.{$teamId}.memberships.{$membershipId}.update", $response['data']['events']);
        $this->assertContains("teams.{$teamId}.memberships.{$membershipId}", $response['data']['events']);
        $this->assertContains("teams.{$teamId}.memberships.*.update", $response['data']['events']);
        $this->assertContains("teams.{$teamId}.memberships.*", $response['data']['events']);
        $this->assertContains("teams.{$teamId}", $response['data']['events']);
        $this->assertContains("teams.*.memberships.{$membershipId}.update", $response['data']['events']);
        $this->assertContains("teams.*.memberships.{$membershipId}", $response['data']['events']);
        $this->assertContains("teams.*.memberships.*.update", $response['data']['events']);
        $this->assertContains("teams.*.memberships.*", $response['data']['events']);
        $this->assertContains("teams.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();
    }
}
