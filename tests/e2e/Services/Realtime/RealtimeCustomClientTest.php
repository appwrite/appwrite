<?php

namespace Tests\E2E\Services\Realtime;

use CURLFile;
use Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

class RealtimeCustomClientTest extends Scope
{
    use FunctionsBase;
    use RealtimeBase;
    use ProjectCustom;
    use SideClient;

    public function testChannelParsing()
    {
        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';

        $headers = [
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
            'tables',
            'collections.1.documents',
            'collections.2.documents',
            'tables.1.rows',
            'tables.2.rows',
            'documents',
            'rows',
            'collections.1.documents.1',
            'collections.2.documents.2',
            'tables.1.rows.1',
            'tables.2.rows.2',
        ], $headers);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertCount(16, $response['data']['channels']);
        $this->assertContains('account', $response['data']['channels']);
        $this->assertContains('account.' . $userId, $response['data']['channels']);
        $this->assertContains('files', $response['data']['channels']);
        $this->assertContains('files.1', $response['data']['channels']);
        $this->assertContains('collections', $response['data']['channels']);
        $this->assertContains('tables', $response['data']['channels']);
        $this->assertContains('collections.1.documents', $response['data']['channels']);
        $this->assertContains('collections.2.documents', $response['data']['channels']);
        $this->assertContains('tables.1.rows', $response['data']['channels']);
        $this->assertContains('tables.2.rows', $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains('collections.1.documents.1', $response['data']['channels']);
        $this->assertContains('collections.2.documents.2', $response['data']['channels']);
        $this->assertContains('tables.1.rows.1', $response['data']['channels']);
        $this->assertContains('tables.2.rows.2', $response['data']['channels']);
        $this->assertEquals($userId, $response['data']['user']['$id']);

        $client->close();
    }

    public function testPingPong()
    {
        $client = $this->getWebsocket(['files'], [
            'origin' => 'http://localhost'
        ]);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('files', $response['data']['channels']);

        $client->send(\json_encode([
            'type' => 'ping'
        ]));

        $response = json_decode($client->receive(), true);
        $this->assertEquals('pong', $response['type']);

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
        $this->assertArrayNotHasKey('secret', $response['data']);
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
        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);
        $verification = $tokens['secret'];

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

            $sessionNew = $response['cookies']['a_session_' . $projectId];
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
        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);
        $recovery = $tokens['secret'];

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

        $this->assertEquals(202, $name['headers']['status-code']);
        $this->assertEquals('name', $name['body']['key']);
        $this->assertEquals('string', $name['body']['type']);
        $this->assertEquals(256, $name['body']['size']);
        $this->assertTrue($name['body']['required']);

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
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains('databases.' . $databaseId . '.collections.' . $actorsId . '.documents.' . $documentId, $response['data']['channels']);
        $this->assertContains('databases.' . $databaseId . '.collections.' . $actorsId . '.documents', $response['data']['channels']);
        $this->assertContains('databases.' . $databaseId . '.tables.' . $actorsId . '.rows.' . $documentId, $response['data']['channels']);
        $this->assertContains('databases.' . $databaseId . '.tables.' . $actorsId . '.rows', $response['data']['channels']);
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
        $this->assertEquals('Chris Evans', $response['data']['payload']['name']);

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
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.rows", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.rows.{$documentId}.update", $response['data']['events']);
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

        $this->assertEquals('Chris Evans 2', $response['data']['payload']['name']);

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
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.rows.{$documentId}", $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$actorsId}.rows", $response['data']['channels']);
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
        $this->assertEquals('Bradley Cooper', $response['data']['payload']['name']);

        // test bulk create
        $documents = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$actorsId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
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
                [
                    '$id' => ID::unique(),
                    'name' => 'Scarlett Johansson',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                ]
            ],
        ]);

        // Receive first document event
        $response = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.create", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertArrayHasKey('name', $response['data']['payload']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);
        $this->assertIsArray($response['data']['payload']['$permissions']);
        $this->assertContains(Permission::read(Role::any()), $response['data']['payload']['$permissions']);
        $this->assertContains(Permission::update(Role::any()), $response['data']['payload']['$permissions']);
        $this->assertContains(Permission::delete(Role::any()), $response['data']['payload']['$permissions']);

        // Receive second document event
        $response = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.create", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.create", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertArrayHasKey('name', $response['data']['payload']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);
        $this->assertIsArray($response['data']['payload']['$permissions']);
        $this->assertContains(Permission::read(Role::any()), $response['data']['payload']['$permissions']);
        $this->assertContains(Permission::update(Role::any()), $response['data']['payload']['$permissions']);
        $this->assertContains(Permission::delete(Role::any()), $response['data']['payload']['$permissions']);

        // test bulk update
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents/', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'name' => 'Marvel Hero',
                '$permissions' => [
                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),
                ]
            ],
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);

        // Receive first document update event
        $response = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.update", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertEquals('Marvel Hero', $response['data']['payload']['name']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);

        // Receive second document update event
        $response = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.update", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertEquals('Marvel Hero', $response['data']['payload']['name']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);

        // Receive third document update event
        $response = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.update", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertEquals('Marvel Hero', $response['data']['payload']['name']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);

        // Test bulk delete
        $response = $this->client->call(Client::METHOD_DELETE, "/databases/{$databaseId}/collections/{$actorsId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        // Receive first document delete event
        $response = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.delete", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertArrayHasKey('name', $response['data']['payload']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);
        $this->assertIsArray($response['data']['payload']['$permissions']);

        // Receive second document delete event
        $response = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.delete", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertArrayHasKey('name', $response['data']['payload']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);
        $this->assertIsArray($response['data']['payload']['$permissions']);

        // Receive third document delete event
        $response = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.delete", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertArrayHasKey('name', $response['data']['payload']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);
        $this->assertIsArray($response['data']['payload']['$permissions']);

        // bulk upsert
        $this->client->call(Client::METHOD_PUT, "/databases/{$databaseId}/collections/{$actorsId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
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
                ]
            ],
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);

        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.upsert", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);

        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertArrayHasKey('name', $response['data']['payload']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);
        $this->assertIsArray($response['data']['payload']['$permissions']);

        $client->close();
    }

    public function testChannelDatabaseBulkOperationMultipleClient()
    {
        // user with api key will do operations and other valid users
        $user1 = $this->getUser(true);
        $user1Id = $user1['$id'];
        $session = $user1['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client1 = $this->getWebsocket(['documents', 'collections'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session
        ]);

        $response = json_decode($client1->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);

        $user2 = $this->getUser(true);
        $user2Id = $user2['$id'];
        $session = $user2['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client2 = $this->getWebsocket(['documents', 'collections'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session
        ]);

        $response = json_decode($client2->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);


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

        $this->assertEquals(202, $name['headers']['status-code']);
        $this->assertEquals('name', $name['body']['key']);
        $this->assertEquals('string', $name['body']['type']);
        $this->assertEquals(256, $name['body']['size']);
        $this->assertTrue($name['body']['required']);

        sleep(2);

        // create
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$actorsId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'name' => 'Any',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                ],
                [
                    '$id' => ID::unique(),
                    'name' => 'Users',
                    '$permissions' => [
                        Permission::read(Role::users()),
                        Permission::update(Role::users()),
                        Permission::delete(Role::users()),
                    ],
                ],
                [
                    '$id' => ID::unique(),
                    'name' => 'User1',
                    '$permissions' => [
                        Permission::read(Role::user($user1Id)),
                    ],
                ],
                [
                    '$id' => ID::unique(),
                    'name' => 'User2',
                    '$permissions' => [
                        Permission::read(Role::user($user2Id)),
                    ],
                ],
                [
                    '$id' => ID::unique(),
                    'name' => 'User2',
                    '$permissions' => [
                        Permission::read(Role::user($user2Id)),
                    ],
                ]
            ],
        ]);

        // Receive and assert for client1 - should receive 3 individual document events
        for ($i = 0; $i < 3; $i++) {
            $response1 = json_decode($client1->receive(), true);
            $this->assertArrayHasKey('type', $response1);
            $this->assertArrayHasKey('data', $response1);
            $this->assertEquals('event', $response1['type']);
            $this->assertNotEmpty($response1['data']);
            $this->assertArrayHasKey('timestamp', $response1['data']);
            $this->assertCount(6, $response1['data']['channels']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response1['data']['payload']['$id']}.create", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*.create", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.create", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*.create", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.create", $response1['data']['events']);
            $this->assertContains("databases.*", $response1['data']['events']);
            $this->assertNotEmpty($response1['data']['payload']);
            $this->assertIsArray($response1['data']['payload']);
            $this->assertArrayHasKey('$id', $response1['data']['payload']);
            $this->assertArrayHasKey('name', $response1['data']['payload']);
            $this->assertArrayHasKey('$permissions', $response1['data']['payload']);
            $this->assertIsArray($response1['data']['payload']['$permissions']);
        }

        // Receive and assert for client2 - should receive 4 individual document events
        for ($i = 0; $i < 4; $i++) {
            $response2 = json_decode($client2->receive(), true);
            $this->assertArrayHasKey('type', $response2);
            $this->assertArrayHasKey('data', $response2);
            $this->assertEquals('event', $response2['type']);
            $this->assertNotEmpty($response2['data']);
            $this->assertArrayHasKey('timestamp', $response2['data']);
            $this->assertCount(6, $response2['data']['channels']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response2['data']['payload']['$id']}.create", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*.create", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.create", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*.create", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.create", $response2['data']['events']);
            $this->assertContains("databases.*", $response2['data']['events']);
            $this->assertNotEmpty($response2['data']['payload']);
            $this->assertIsArray($response2['data']['payload']);
            $this->assertArrayHasKey('$id', $response2['data']['payload']);
            $this->assertArrayHasKey('name', $response2['data']['payload']);
            $this->assertArrayHasKey('$permissions', $response2['data']['payload']);
            $this->assertIsArray($response2['data']['payload']['$permissions']);
        }


        // Perform bulk update(making it only accessible by user1)
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$actorsId}/documents/", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'name' => 'Marvel Hero',
                '$permissions' => [
                    Permission::read(Role::user($user1Id)),
                    Permission::update(Role::user($user1Id)),
                    Permission::delete(Role::user($user1Id)),
                ]
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // Receive and assert for client1
        for ($i = 0; $i < 5; $i++) {
            $response1 = json_decode($client1->receive(), true);
            $this->assertArrayHasKey('type', $response1);
            $this->assertArrayHasKey('data', $response1);
            $this->assertEquals('event', $response1['type']);
            $this->assertNotEmpty($response1['data']);
            $this->assertArrayHasKey('timestamp', $response1['data']);
            $this->assertCount(6, $response1['data']['channels']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response1['data']['payload']['$id']}.update", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*.update", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*.update", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response1['data']['events']);
            $this->assertContains("databases.*", $response1['data']['events']);
            $this->assertNotEmpty($response1['data']['payload']);
            $this->assertIsArray($response1['data']['payload']);
            $this->assertArrayHasKey('$id', $response1['data']['payload']);
            $this->assertEquals('Marvel Hero', $response1['data']['payload']['name']);
            $this->assertArrayHasKey('$permissions', $response1['data']['payload']);
        }

        // client2 shouldn't receive any event and lead to timeout
        try {
            json_decode($client2->receive(), true);
            $this->fail('Expected TimeoutException was not thrown.');
        } catch (Exception $e) {
            $this->assertInstanceOf(TimeoutException::class, $e);
        }

        // Perform bulk update(making it only accessible by user2)
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$actorsId}/documents/", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'name' => 'Marvel Hero',
                '$permissions' => [
                    Permission::read(Role::user($user2Id)),
                    Permission::update(Role::user($user2Id)),
                    Permission::delete(Role::user($user2Id)),
                ]
            ],
        ]);

        // Receive and assert for client2
        for ($i = 0; $i < 5; $i++) {
            $response2 = json_decode($client2->receive(), true);
            $this->assertArrayHasKey('type', $response2);
            $this->assertArrayHasKey('data', $response2);
            $this->assertEquals('event', $response2['type']);
            $this->assertNotEmpty($response2['data']);
            $this->assertArrayHasKey('timestamp', $response2['data']);
            $this->assertCount(6, $response2['data']['channels']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response2['data']['payload']['$id']}.update", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*.update", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*.update", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response2['data']['events']);
            $this->assertContains("databases.*", $response2['data']['events']);
            $this->assertNotEmpty($response2['data']['payload']);
            $this->assertIsArray($response2['data']['payload']);
            $this->assertArrayHasKey('$id', $response2['data']['payload']);
            $this->assertEquals('Marvel Hero', $response2['data']['payload']['name']);
            $this->assertArrayHasKey('$permissions', $response2['data']['payload']);
        }

        // client1 shouldn't receive any event and lead to timeout
        try {
            json_decode($client1->receive(), true);
            $this->fail('Expected TimeoutException was not thrown.');
        } catch (Exception $e) {
            $this->assertInstanceOf(TimeoutException::class, $e);
        }

        // Updating the permission for both the users
        $response = $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$actorsId}/documents/", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'name' => 'Marvel Hero',
                '$permissions' => [
                    Permission::read(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ]
            ],
        ]);
        // both user1 and user2 should receive the event
        for ($i = 0; $i < 5; $i++) {
            $response1 = json_decode($client1->receive(), true);
            $this->assertArrayHasKey('type', $response1);
            $this->assertArrayHasKey('data', $response1);
            $this->assertEquals('event', $response1['type']);
            $this->assertNotEmpty($response1['data']);
            $this->assertArrayHasKey('timestamp', $response1['data']);
            $this->assertCount(6, $response1['data']['channels']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response1['data']['payload']['$id']}.update", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*.update", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*.update", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response1['data']['events']);
            $this->assertContains("databases.*", $response1['data']['events']);
            $this->assertNotEmpty($response1['data']['payload']);
            $this->assertIsArray($response1['data']['payload']);
            $this->assertArrayHasKey('$id', $response1['data']['payload']);
            $this->assertEquals('Marvel Hero', $response1['data']['payload']['name']);
            $this->assertArrayHasKey('$permissions', $response1['data']['payload']);

            $response2 = json_decode($client2->receive(), true);
            $this->assertArrayHasKey('type', $response2);
            $this->assertArrayHasKey('data', $response2);
            $this->assertEquals('event', $response2['type']);
            $this->assertNotEmpty($response2['data']);
            $this->assertArrayHasKey('timestamp', $response2['data']);
            $this->assertCount(6, $response2['data']['channels']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response2['data']['payload']['$id']}.update", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*.update", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*.update", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.update", $response2['data']['events']);
            $this->assertContains("databases.*", $response2['data']['events']);
            $this->assertNotEmpty($response2['data']['payload']);
            $this->assertIsArray($response2['data']['payload']);
            $this->assertArrayHasKey('$id', $response2['data']['payload']);
            $this->assertEquals('Marvel Hero', $response2['data']['payload']['name']);
            $this->assertArrayHasKey('$permissions', $response2['data']['payload']);
        }

        // Perform bulk delete
        $response = $this->client->call(Client::METHOD_DELETE, "/databases/{$databaseId}/collections/{$actorsId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        // Receive and assert for client1
        for ($i = 0; $i < 5; $i++) {
            $response1 = json_decode($client1->receive(), true);
            $this->assertArrayHasKey('type', $response1);
            $this->assertArrayHasKey('data', $response1);
            $this->assertEquals('event', $response1['type']);
            $this->assertNotEmpty($response1['data']);
            $this->assertArrayHasKey('timestamp', $response1['data']);
            $this->assertCount(6, $response1['data']['channels']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response1['data']['payload']['$id']}.delete", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*.delete", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*.delete", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response1['data']['events']);
            $this->assertContains("databases.*.collections.*", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*", $response1['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}", $response1['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response1['data']['events']);
            $this->assertContains("databases.*", $response1['data']['events']);
            $this->assertNotEmpty($response1['data']['payload']);
            $this->assertIsArray($response1['data']['payload']);
            $this->assertArrayHasKey('$id', $response1['data']['payload']);
            $this->assertArrayHasKey('name', $response1['data']['payload']);
            $this->assertArrayHasKey('$permissions', $response1['data']['payload']);
            $this->assertIsArray($response1['data']['payload']['$permissions']);
        }

        // Receive and assert for client2
        for ($i = 0; $i < 5; $i++) {
            $response2 = json_decode($client2->receive(), true);
            $this->assertArrayHasKey('type', $response2);
            $this->assertArrayHasKey('data', $response2);
            $this->assertEquals('event', $response2['type']);
            $this->assertNotEmpty($response2['data']);
            $this->assertArrayHasKey('timestamp', $response2['data']);
            $this->assertCount(6, $response2['data']['channels']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response2['data']['payload']['$id']}.delete", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*.delete", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*.delete", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*.documents.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response2['data']['events']);
            $this->assertContains("databases.*.collections.*", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*", $response2['data']['events']);
            $this->assertContains("databases.*.collections.{$actorsId}", $response2['data']['events']);
            $this->assertContains("databases.{$databaseId}.collections.*.documents.*.delete", $response2['data']['events']);
            $this->assertContains("databases.*", $response2['data']['events']);
            $this->assertNotEmpty($response2['data']['payload']);
            $this->assertIsArray($response2['data']['payload']);
            $this->assertArrayHasKey('$id', $response2['data']['payload']);
            $this->assertArrayHasKey('name', $response2['data']['payload']);
            $this->assertArrayHasKey('$permissions', $response2['data']['payload']);
            $this->assertIsArray($response2['data']['payload']['$permissions']);
        }

        // bulk upsert
        $this->client->call(Client::METHOD_PUT, "/databases/{$databaseId}/collections/{$actorsId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'name' => 'Robert Downey Jr.',
                    '$permissions' => [
                        Permission::read(Role::user($user1Id)),
                    ],
                ],
                [
                    '$id' => ID::unique(),
                    'name' => 'Thor',
                    '$permissions' => [
                        Permission::read(Role::user($user2Id)),
                    ],
                ]
            ],
        ]);

        $response = json_decode($client1->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);

        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.upsert", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);

        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertArrayHasKey('name', $response['data']['payload']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);
        $this->assertIsArray($response['data']['payload']['$permissions']);

        // client1 shouldnot receive more than 1 event
        try {
            json_decode(json_decode($client1->receive(), true));
            $this->fail('Expected TimeoutException was not thrown.');
        } catch (Exception $e) {
            $this->assertInstanceOf(TimeoutException::class, $e);
        }

        $response = json_decode($client2->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(6, $response['data']['channels']);

        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.{$response['data']['payload']['$id']}.upsert", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}.documents.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.*.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.*.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.documents.*.upsert", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);

        $this->assertNotEmpty($response['data']['payload']);
        $this->assertIsArray($response['data']['payload']);
        $this->assertArrayHasKey('$id', $response['data']['payload']);
        $this->assertArrayHasKey('name', $response['data']['payload']);
        $this->assertArrayHasKey('$permissions', $response['data']['payload']);
        $this->assertIsArray($response['data']['payload']['$permissions']);

        // client2 shouldnot receive more than 1 event
        try {
            json_decode(json_decode($client2->receive(), true));
            $this->fail('Expected TimeoutException was not thrown.');
        } catch (Exception $e) {
            $this->assertInstanceOf(TimeoutException::class, $e);
        }


        $client1->close();
        $client2->close();
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

        $this->assertEquals(202, $name['headers']['status-code']);
        $this->assertEquals('name', $name['body']['key']);
        $this->assertEquals('string', $name['body']['type']);
        $this->assertEquals(256, $name['body']['size']);
        $this->assertTrue($name['body']['required']);

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
        $this->assertCount(6, $response['data']['channels']);
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
        $this->assertEquals('Chris Evans', $response['data']['payload']['name']);

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
        $this->assertCount(6, $response['data']['channels']);
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

        $this->assertEquals('Chris Evans 2', $response['data']['payload']['name']);

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
        $this->assertCount(6, $response['data']['channels']);
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
        $this->assertEquals('Bradley Cooper', $response['data']['payload']['name']);

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
            'name' => 'Test timeout execution',
            'execute' => ['users'],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 10,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'code' => $this->packageFunction('timeout'),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        // Poll until deployment is built
        $this->assertEventually(function () use ($function, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals('ready', $deployment['body']['status'], \json_encode($deployment['body']));
        });

        $response = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'async' => true
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);

        $response = json_decode($client->receive(), true);
        $responseUpdate = json_decode($client->receive(), true);

        $executionId = $execution['body']['$id'];

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(5, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("projects.{$this->getProject()['$id']}", $response['data']['channels']);
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
        $this->assertCount(5, $responseUpdate['data']['channels']);
        $this->assertContains('console', $responseUpdate['data']['channels']);
        $this->assertContains("projects.{$this->getProject()['$id']}", $response['data']['channels']);
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

        $this->assertEquals(200, $team['headers']['status-code']);
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
        $team = $this->client->call(Client::METHOD_PUT, '/teams/' . $teamId . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'prefs' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
            ]
        ]);

        $this->assertEquals(200, $team['headers']['status-code']);
        $this->assertEquals('funcValue1', $team['body']['funcKey1']);
        $this->assertEquals('funcValue2', $team['body']['funcKey2']);

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
        $this->assertEquals('funcValue1', $response['data']['payload']['funcKey1']);
        $this->assertEquals('funcValue2', $response['data']['payload']['funcKey2']);

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

    public function testChannelDatabaseTransaction()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertEquals('connected', $response['type']);

        /**
         * Setup Database and Collection
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Transactions DB',
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
            'documentSecurity' => true,
        ]);

        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        /**
         * Test Transaction Create with Single Document
         */
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'ttl' => 3600  // 1 hour
        ]);

        $this->assertEquals(201, $transaction['headers']['status-code'], 'Failed to create transaction: ' . json_encode($transaction['body']));
        $this->assertNotEmpty($transaction['body']['$id']);

        $transactionId = $transaction['body']['$id'];
        $documentId = ID::unique();

        $operationsResponse = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transactionId . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'tableId' => $collectionId,
                    'rowId' => $documentId,
                    'action' => 'create',
                    'data' => [
                        'name' => 'Transaction Document',
                        '$permissions' => [
                            Permission::read(Role::any()),
                            Permission::update(Role::any()),
                            Permission::delete(Role::any()),
                        ],
                    ],
                ]
            ]
        ]);

        $this->assertEquals(201, $operationsResponse['headers']['status-code'], 'Failed to add operations: ' . json_encode($operationsResponse['body']));

        // Commit transaction
        $commitResponse = $this->client->call(Client::METHOD_PATCH, '/tablesdb/transactions/' . $transactionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'commit' => true
        ]);

        $this->assertEquals(200, $commitResponse['headers']['status-code'], 'Failed to commit transaction: ' . json_encode($commitResponse['body']));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertContains('documents', $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.tables.{$collectionId}.rows.{$documentId}.create", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('Transaction Document', $response['data']['payload']['name']);

        /**
         * Test Transaction Update
         */
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'ttl' => 3600
        ]);

        $transactionId = $transaction['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transactionId . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'tableId' => $collectionId,
                    'rowId' => $documentId,
                    'action' => 'update',
                    'data' => [
                        'name' => 'Updated Transaction Document',
                    ],
                ]
            ]
        ]);

        $this->client->call(Client::METHOD_PATCH, '/tablesdb/transactions/' . $transactionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'commit' => true
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertContains("databases.{$databaseId}.tables.{$collectionId}.rows.{$documentId}.update", $response['data']['events']);
        $this->assertEquals('Updated Transaction Document', $response['data']['payload']['name']);

        /**
         * Test Transaction Delete
         */
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'ttl' => 3600
        ]);

        $transactionId = $transaction['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transactionId . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'tableId' => $collectionId,
                    'rowId' => $documentId,
                    'action' => 'delete',
                ]
            ]
        ]);

        $this->client->call(Client::METHOD_PATCH, '/tablesdb/transactions/' . $transactionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'commit' => true
        ]);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertContains("databases.{$databaseId}.tables.{$collectionId}.rows.{$documentId}.delete", $response['data']['events']);

        $client->close();
    }

    public function testChannelDatabaseTransactionMultipleOperations()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        /**
         * Setup Database and Collection
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Multi-Op DB',
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
            'documentSecurity' => true,
        ]);

        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        /**
         * Test Multiple Operations in Single Transaction
         */
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'ttl' => 3600
        ]);

        $transactionId = $transaction['body']['$id'];
        $documentId1 = ID::unique();
        $documentId2 = ID::unique();
        $documentId3 = ID::unique();

        $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transactionId . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'tableId' => $collectionId,
                    'rowId' => $documentId1,
                    'action' => 'create',
                    'data' => [
                        'name' => 'Doc 1',
                        '$permissions' => [
                            Permission::read(Role::any()),
                            Permission::update(Role::any()),
                            Permission::delete(Role::any()),
                        ],
                    ],
                ],
                [
                    'databaseId' => $databaseId,
                    'tableId' => $collectionId,
                    'rowId' => $documentId2,
                    'action' => 'create',
                    'data' => [
                        'name' => 'Doc 2',
                        '$permissions' => [
                            Permission::read(Role::any()),
                            Permission::update(Role::any()),
                            Permission::delete(Role::any()),
                        ],
                    ],
                ],
                [
                    'databaseId' => $databaseId,
                    'tableId' => $collectionId,
                    'rowId' => $documentId3,
                    'action' => 'create',
                    'data' => [
                        'name' => 'Doc 3',
                        '$permissions' => [
                            Permission::read(Role::any()),
                            Permission::update(Role::any()),
                            Permission::delete(Role::any()),
                        ],
                    ],
                ]
            ]
        ]);

        $this->client->call(Client::METHOD_PATCH, '/tablesdb/transactions/' . $transactionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'commit' => true
        ]);

        // Should receive 3 events, one for each document
        $response1 = json_decode($client->receive(), true);
        $response2 = json_decode($client->receive(), true);
        $response3 = json_decode($client->receive(), true);

        $this->assertEquals('event', $response1['type']);
        $this->assertEquals('event', $response2['type']);
        $this->assertEquals('event', $response3['type']);

        $receivedDocIds = [
            $response1['data']['payload']['$id'],
            $response2['data']['payload']['$id'],
            $response3['data']['payload']['$id'],
        ];

        $this->assertContains($documentId1, $receivedDocIds);
        $this->assertContains($documentId2, $receivedDocIds);
        $this->assertContains($documentId3, $receivedDocIds);

        $client->close();
    }

    public function testChannelDatabaseTransactionRollback()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertEquals('connected', $response['type']);

        /**
         * Setup Database and Collection
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Rollback DB',
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Test Collection',
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
            'documentSecurity' => true,
        ]);

        $collectionId = $collection['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        /**
         * Test Transaction Rollback - Should NOT trigger realtime events
         */
        $transaction = $this->client->call(Client::METHOD_POST, '/tablesdb/transactions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'ttl' => 3600
        ]);

        $transactionId = $transaction['body']['$id'];
        $documentId = ID::unique();

        $this->client->call(Client::METHOD_POST, '/tablesdb/transactions/' . $transactionId . '/operations', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'operations' => [
                [
                    'databaseId' => $databaseId,
                    'tableId' => $collectionId,
                    'rowId' => $documentId,
                    'action' => 'create',
                    'data' => ['name' => 'Rollback Document'],
                ]
            ]
        ]);

        // Rollback transaction
        $this->client->call(Client::METHOD_PATCH, '/tablesdb/transactions/' . $transactionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rollback' => true
        ]);

        // Wait a bit to ensure no event is received
        sleep(1);

        try {
            $client->receive(1); // 1 second timeout
            $this->fail('Should not receive any event after rollback');
        } catch (TimeoutException $e) {
            // Expected - no event should be triggered
            $this->assertTrue(true);
        }

        $client->close();
    }

    public function testRelationshipPayloadHidesRelatedDoc()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = $this->getProject()['$id'];

        $client = $this->getWebsocket(['documents'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $session
        ]);

        $response = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $response);
        $this->assertEquals('connected', $response['type']);

        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'db-rel'
        ]);
        $databaseId = $database['body']['$id'];

        $level1 = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'level1',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);
        $level1Id = $level1['body']['$id'];

        $level2 = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'level2',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);
        $level2Id = $level2['body']['$id'];

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$level1Id}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => false,
        ]);

        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$level2Id}/attributes/string", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => false,
        ]);

        sleep(2);

        // two-way one-to-one relationship from level1 to level2
        $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$level1Id}/attributes/relationship", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $level2Id,
            'type' => 'oneToOne',
            'twoWay' => true,
            'key' => 'level2Ref',
            'onDelete' => 'cascade',
        ]);

        sleep(2);

        $doc2 = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$level2Id}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => ['name' => 'L2'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $doc2Id = $doc2['body']['$id'];

        $doc1 = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$level1Id}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => ['name' => 'L1'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $doc1Id = $doc1['body']['$id'];

        json_decode($client->receive(), true);

        $this->client->call(Client::METHOD_PATCH, "/databases/{$databaseId}/collections/{$level1Id}/documents/{$doc1Id}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'level2Ref' => $doc2Id,
            ],
        ]);

        // payload should not contain the relationship attribute 'level2Ref'
        $event = json_decode($client->receive(), true);
        $this->assertArrayHasKey('type', $event);
        $this->assertEquals('event', $event['type']);
        $this->assertArrayHasKey('data', $event);
        $this->assertNotEmpty($event['data']);
        $this->assertArrayHasKey('payload', $event['data']);
        $this->assertArrayHasKey('$id', $event['data']['payload']);
        $this->assertEquals($doc1Id, $event['data']['payload']['$id']);
        $this->assertArrayNotHasKey('level2Ref', $event['data']['payload']);

        $client->close();
    }
}
