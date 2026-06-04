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

    /**
     * Test that impersonation works via:
     *   - X-Appwrite-Impersonate-User-Id header (existing behavior, unchanged)
     *   - ?impersonateuserid= query param (lowercase, emitted by the SDK caseLower filter)
     *   - ?impersonateUserId= query param (camelCase, backward compat)
     *
     * The query param variants are the key addition of this PR — they allow Console
     * to embed impersonation in file/image URLs where browsers cannot set custom headers.
     */
    public function testImpersonateUserIdQueryParam(): void
    {
        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create actor (impersonator)
        $actorId = ID::unique();
        $actor = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => $actorId,
            'email' => 'impersonator-queryparam-' . $actorId . '@example.com',
            'password' => 'password123',
            'name' => 'Impersonator',
        ]);
        $this->assertEquals(201, $actor['headers']['status-code']);

        // Create target
        $targetId = ID::unique();
        $target = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => $targetId,
            'email' => 'target-queryparam-' . $targetId . '@example.com',
            'password' => 'password123',
            'name' => 'Target',
        ]);
        $this->assertEquals(201, $target['headers']['status-code']);

        // Grant impersonator capability
        $grant = $this->client->call(Client::METHOD_PATCH, '/users/' . $actorId . '/impersonator', $headers, [
            'impersonator' => true,
        ]);
        $this->assertEquals(200, $grant['headers']['status-code']);

        // Create server-side session for actor
        $session = $this->client->call(Client::METHOD_POST, '/users/' . $actorId . '/sessions', $headers);
        $this->assertEquals(201, $session['headers']['status-code']);
        $sessionSecret = $session['body']['secret'];

        $sessionHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-session' => $sessionSecret,
        ];

        // Header-based impersonation — existing behavior, must still work
        $byHeader = $this->client->call(Client::METHOD_GET, '/account', array_merge($sessionHeaders, [
            'x-appwrite-impersonate-user-id' => $targetId,
        ]));
        $this->assertEquals(200, $byHeader['headers']['status-code']);
        $this->assertEquals($targetId, $byHeader['body']['$id']);
        $this->assertEquals($actorId, $byHeader['body']['impersonatorUserId']);

        // ?impersonateuserid= (lowercase) — emitted by SDK caseLower filter on LOCATION methods
        $byLowerParam = $this->client->call(Client::METHOD_GET, '/account', $sessionHeaders, [
            'impersonateuserid' => $targetId,
        ]);
        $this->assertEquals(200, $byLowerParam['headers']['status-code']);
        $this->assertEquals($targetId, $byLowerParam['body']['$id']);
        $this->assertEquals($actorId, $byLowerParam['body']['impersonatorUserId']);

        // ?impersonateUserId= (camelCase) — backward compat
        $byCamelParam = $this->client->call(Client::METHOD_GET, '/account', $sessionHeaders, [
            'impersonateUserId' => $targetId,
        ]);
        $this->assertEquals(200, $byCamelParam['headers']['status-code']);
        $this->assertEquals($targetId, $byCamelParam['body']['$id']);
        $this->assertEquals($actorId, $byCamelParam['body']['impersonatorUserId']);

        // Header takes priority over query param when both are present.
        // Pass actorId as the decoy in ?impersonateuserid= and targetId in the header —
        // the response must be targetId, not actorId.
        $priority = $this->client->call(
            Client::METHOD_GET,
            '/account',
            array_merge($sessionHeaders, ['x-appwrite-impersonate-user-id' => $targetId]),
            ['impersonateuserid' => $actorId]
        );
        $this->assertEquals(200, $priority['headers']['status-code']);
        $this->assertEquals($targetId, $priority['body']['$id'], 'header must take priority over query param');
    }
}
