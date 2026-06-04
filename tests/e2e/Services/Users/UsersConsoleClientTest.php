<?php

namespace Tests\E2E\Services\Users;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;

class UsersConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testGetUsersUsage()
    {
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h',
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertIsNumeric($response['body']['usersTotal']);
        $this->assertIsNumeric($response['body']['sessionsTotal']);
        $this->assertIsArray($response['body']['users']);
        $this->assertIsArray($response['body']['sessions']);
    }

    public function testCreateUserWithoutPasswordThenSetPassword()
    {
        // Create a user with email but without password
        $userId = ID::unique();
        $email = $userId . '@example.com';

        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'userId' => $userId,
            'email' => $email,
            // no password provided
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['$id']);
        $this->assertEquals($email, $response['body']['email']);
        $this->assertEmpty($response['body']['password']);

        // Now set the password for that user (console-side)
        $newPassword = 'NewPass123!';

        $set = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/password', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'password' => $newPassword,
        ]);

        $this->assertEquals(200, $set['headers']['status-code']);
        $this->assertEquals($userId, $set['body']['$id']);
        $this->assertNotEmpty($set['body']['password']);
    }

    public function testImpersonation(): void
    {
        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create the acting user (will become impersonator)
        $actorId = ID::unique();
        $actorEmail = 'impersonator-' . $actorId . '@example.com';
        $actorPassword = 'password123';

        $actor = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => $actorId,
            'email' => $actorEmail,
            'password' => $actorPassword,
            'name' => 'Impersonator User',
        ]);
        $this->assertEquals(201, $actor['headers']['status-code']);
        $this->assertFalse($actor['body']['impersonator']);

        // Create the target user (will be impersonated)
        $targetId = ID::unique();
        $targetEmail = 'target-' . $targetId . '@example.com';
        $targetPhone = '+1' . rand(2000000000, 2999999999);

        $target = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => $targetId,
            'email' => $targetEmail,
            'password' => 'password123',
            'name' => 'Target User',
        ]);
        $this->assertEquals(201, $target['headers']['status-code']);

        $this->client->call(Client::METHOD_PATCH, '/users/' . $targetId . '/phone', $headers, [
            'number' => $targetPhone,
        ]);

        // Grant impersonator capability — should reflect in response
        $grant = $this->client->call(Client::METHOD_PATCH, '/users/' . $actorId . '/impersonator', $headers, [
            'impersonator' => true,
        ]);
        $this->assertEquals(200, $grant['headers']['status-code']);
        $this->assertTrue($grant['body']['impersonator']);

        // Create a session for the actor so they can make authenticated client requests
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $actorEmail,
            'password' => $actorPassword,
        ]);
        $this->assertEquals(201, $session['headers']['status-code']);
        $sessionCookie = 'a_session_' . $projectId . '=' . $session['cookies']['a_session_' . $projectId];

        $actorHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => $sessionCookie,
        ];

        // Impersonate by user ID via header — GET /account should return the target
        $accountAsTarget = $this->client->call(Client::METHOD_GET, '/account', array_merge($actorHeaders, [
            'x-appwrite-impersonation' => $targetId,
        ]));
        $this->assertEquals(200, $accountAsTarget['headers']['status-code']);
        $this->assertEquals($targetId, $accountAsTarget['body']['$id']);
        $this->assertEquals($targetEmail, $accountAsTarget['body']['email']);
        $this->assertNotEmpty($accountAsTarget['body']['impersonatorUserId']);
        $this->assertEquals($actorId, $accountAsTarget['body']['impersonatorUserId']);

        // Impersonate by email via header
        $accountByEmail = $this->client->call(Client::METHOD_GET, '/account', array_merge($actorHeaders, [
            'x-appwrite-impersonation' => $targetEmail,
        ]));
        $this->assertEquals(200, $accountByEmail['headers']['status-code']);
        $this->assertEquals($targetId, $accountByEmail['body']['$id']);

        // Impersonate by phone via header
        $accountByPhone = $this->client->call(Client::METHOD_GET, '/account', array_merge($actorHeaders, [
            'x-appwrite-impersonation' => $targetPhone,
        ]));
        $this->assertEquals(200, $accountByPhone['headers']['status-code']);
        $this->assertEquals($targetId, $accountByPhone['body']['$id']);

        // Impersonate via URL query param (mirrors file/image URL use case)
        $accountByParam = $this->client->call(Client::METHOD_GET, '/account', $actorHeaders, [
            'impersonation' => $targetId,
        ]);
        $this->assertEquals(200, $accountByParam['headers']['status-code']);
        $this->assertEquals($targetId, $accountByParam['body']['$id']);

        // accessedAt on the target should not have changed after impersonated requests
        $targetAfter = $this->client->call(Client::METHOD_GET, '/users/' . $targetId, $headers);
        $this->assertEquals(200, $targetAfter['headers']['status-code']);
        $this->assertEquals($target['body']['accessedAt'], $targetAfter['body']['accessedAt']);

        // Without impersonator flag — header must be ignored, actor sees their own account
        $revoke = $this->client->call(Client::METHOD_PATCH, '/users/' . $actorId . '/impersonator', $headers, [
            'impersonator' => false,
        ]);
        $this->assertEquals(200, $revoke['headers']['status-code']);
        $this->assertFalse($revoke['body']['impersonator']);

        $accountSelf = $this->client->call(Client::METHOD_GET, '/account', array_merge($actorHeaders, [
            'x-appwrite-impersonation' => $targetId,
        ]));
        $this->assertEquals(200, $accountSelf['headers']['status-code']);
        $this->assertEquals($actorId, $accountSelf['body']['$id']);
    }
}
