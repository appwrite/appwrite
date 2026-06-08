<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Users;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;

final class UsersConsoleClientTest extends Scope
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

        $this->assertEquals(400, $response['headers']['status-code']);

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
        $this->assertCount(5, $response['body']);
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

        $actor = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => $actorId,
            'email' => $actorEmail,
            'password' => 'password123',
            'name' => 'Impersonator User',
        ]);
        $this->assertEquals(201, $actor['headers']['status-code']);
        $this->assertFalse($actor['body']['impersonator']);

        // Create the target user (will be impersonated)
        $targetId = ID::unique();
        $targetEmail = 'target-' . $targetId . '@example.com';

        $target = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => $targetId,
            'email' => $targetEmail,
            'password' => 'password123',
            'name' => 'Target User',
        ]);
        $this->assertEquals(201, $target['headers']['status-code']);

        // Confirm target has never been accessed — accessedAt is empty on a fresh user.
        // After impersonated requests it must remain empty: if the guard were removed,
        // the first impersonated request would set it, making this assertion fail.
        $this->assertEmpty($target['body']['accessedAt']);

        // Grant impersonator capability — should reflect in response
        $grant = $this->client->call(Client::METHOD_PATCH, '/users/' . $actorId . '/impersonator', $headers, [
            'impersonator' => true,
        ]);
        $this->assertEquals(200, $grant['headers']['status-code']);
        $this->assertTrue($grant['body']['impersonator']);

        // Create a server-side session for the actor (avoids email verification requirements)
        $session = $this->client->call(Client::METHOD_POST, '/users/' . $actorId . '/sessions', $headers);
        $this->assertEquals(201, $session['headers']['status-code']);
        $sessionSecret = $session['body']['secret'];

        $actorHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-session' => $sessionSecret,
        ];

        // Impersonate by user ID via header — GET /account should return the target
        $accountAsTarget = $this->client->call(Client::METHOD_GET, '/account', array_merge($actorHeaders, [
            'x-appwrite-impersonation' => $targetId,
        ]));
        $this->assertEquals(200, $accountAsTarget['headers']['status-code']);
        $this->assertEquals($targetId, $accountAsTarget['body']['$id']);
        $this->assertEquals($targetEmail, $accountAsTarget['body']['email']);
        $this->assertEquals($actorId, $accountAsTarget['body']['impersonatorUserId']);

        // Impersonate via URL query param (mirrors file/image URL use case)
        $accountByParam = $this->client->call(Client::METHOD_GET, '/account', $actorHeaders, [
            'impersonation' => $targetId,
        ]);
        $this->assertEquals(200, $accountByParam['headers']['status-code']);
        $this->assertEquals($targetId, $accountByParam['body']['$id']);

        // accessedAt on target must still be empty — impersonated requests must not mark activity
        $targetAfter = $this->client->call(Client::METHOD_GET, '/users/' . $targetId, $headers);
        $this->assertEquals(200, $targetAfter['headers']['status-code']);
        $this->assertEmpty($targetAfter['body']['accessedAt']);

        // Unknown impersonation value must return 404, not silently fall back
        $notFound = $this->client->call(Client::METHOD_GET, '/account', array_merge($actorHeaders, [
            'x-appwrite-impersonation' => 'nonexistent-user-id',
        ]));
        $this->assertEquals(404, $notFound['headers']['status-code']);

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
