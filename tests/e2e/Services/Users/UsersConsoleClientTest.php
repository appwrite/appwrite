<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Users;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Id;

final class UsersConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testCreateUserWithoutPasswordThenSetPassword()
    {
        // Create a user with email but without password
        $userId = Id::unique();
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

    public function testImpersonateQueryParams(): void
    {
        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        $actorId = Id::unique();
        $actor = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => $actorId,
            'email' => 'impersonator-queryparam-' . $actorId . '@example.com',
            'password' => 'password123',
            'name' => 'Impersonator',
        ]);
        $this->assertEquals(201, $actor['headers']['status-code']);

        $targetId = Id::unique();
        $targetEmail = 'target-queryparam-' . $targetId . '@example.com';
        $targetPhone = '+1' . rand(2000000000, 2999999999);

        $target = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => $targetId,
            'email' => $targetEmail,
            'password' => 'password123',
            'name' => 'Target',
        ]);
        $this->assertEquals(201, $target['headers']['status-code']);

        $this->client->call(Client::METHOD_PATCH, '/users/' . $targetId . '/phone', $headers, [
            'number' => $targetPhone,
        ]);

        $this->client->call(Client::METHOD_PATCH, '/users/' . $actorId . '/impersonator', $headers, [
            'impersonator' => true,
        ]);

        $session = $this->client->call(Client::METHOD_POST, '/users/' . $actorId . '/sessions', $headers);
        $this->assertEquals(201, $session['headers']['status-code']);

        $sessionHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-session' => $session['body']['secret'],
        ];

        $byHeader = $this->client->call(Client::METHOD_GET, '/account', array_merge($sessionHeaders, [
            'x-appwrite-impersonate-user-id' => $targetId,
        ]));
        $this->assertEquals(200, $byHeader['headers']['status-code']);
        $this->assertEquals($targetId, $byHeader['body']['$id']);
        $this->assertEquals($actorId, $byHeader['body']['impersonatorUserId']);

        $byUserId = $this->client->call(Client::METHOD_GET, '/account', $sessionHeaders, [
            'impersonateuserid' => $targetId,
        ]);
        $this->assertEquals(200, $byUserId['headers']['status-code']);
        $this->assertEquals($targetId, $byUserId['body']['$id']);

        $byEmail = $this->client->call(Client::METHOD_GET, '/account', $sessionHeaders, [
            'impersonateemail' => $targetEmail,
        ]);
        $this->assertEquals(200, $byEmail['headers']['status-code']);
        $this->assertEquals($targetId, $byEmail['body']['$id']);

        $byPhone = $this->client->call(Client::METHOD_GET, '/account', $sessionHeaders, [
            'impersonatephone' => $targetPhone,
        ]);
        $this->assertEquals(200, $byPhone['headers']['status-code']);
        $this->assertEquals($targetId, $byPhone['body']['$id']);

        // header takes priority over query param
        $priority = $this->client->call(
            Client::METHOD_GET,
            '/account',
            array_merge($sessionHeaders, ['x-appwrite-impersonate-user-id' => $targetId]),
            ['impersonateuserid' => $actorId]
        );
        $this->assertEquals(200, $priority['headers']['status-code']);
        $this->assertEquals($targetId, $priority['body']['$id']);
    }

    public function testUserSessionRoutesRequireServerScope(): void
    {
        $projectId = $this->getProject()['$id'];

        // A target user created by an operator
        $target = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => Id::unique(),
            'email' => Id::unique() . '@example.com',
            'password' => 'password',
            'name' => 'Target',
        ]);
        $this->assertEquals(201, $target['headers']['status-code']);
        $targetId = $target['body']['$id'];

        // A regular account that holds a session but no server scopes
        $email = Id::unique() . '@example.com';
        $account = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => 'password',
            'name' => 'Member',
        ]);
        $this->assertEquals(201, $account['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => 'password',
        ]);
        $this->assertEquals(201, $session['headers']['status-code']);

        $memberHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session['cookies']['a_session_' . $projectId],
        ];

        /**
         * Test for FAILURE: a session alone does not reach the server session API of another user
         */
        $response = $this->client->call(Client::METHOD_POST, '/users/' . $targetId . '/sessions', $memberHeaders);
        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('general_unauthorized_scope', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_GET, '/users/' . $targetId . '/sessions', $memberHeaders);
        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('general_unauthorized_scope', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $targetId . '/sessions/' . Id::unique(), $memberHeaders);
        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('general_unauthorized_scope', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_DELETE, '/users/' . $targetId . '/sessions', $memberHeaders);
        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('general_unauthorized_scope', $response['body']['type']);
    }

    public function testSessionScopedKeyCanManageSessions(): void
    {
        $projectId = $this->getProject()['$id'];

        // A target user created by an operator
        $target = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => Id::unique(),
            'email' => Id::unique() . '@example.com',
            'password' => 'password',
            'name' => 'Target',
        ]);
        $this->assertEquals(201, $target['headers']['status-code']);
        $targetId = $target['body']['$id'];

        // A key scoped to sessions only, without users.*
        $keyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getNewKey(['sessions.read', 'sessions.write']),
        ];

        /**
         * Test for SUCCESS: a key scoped to sessions.* manages user sessions without users.*
         */
        $created = $this->client->call(Client::METHOD_POST, '/users/' . $targetId . '/sessions', $keyHeaders);
        $this->assertEquals(201, $created['headers']['status-code']);
        $sessionId = $created['body']['$id'];

        $list = $this->client->call(Client::METHOD_GET, '/users/' . $targetId . '/sessions', $keyHeaders);
        $this->assertEquals(200, $list['headers']['status-code']);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/users/' . $targetId . '/sessions/' . $sessionId, $keyHeaders);
        $this->assertEquals(204, $deleted['headers']['status-code']);

        $deletedAll = $this->client->call(Client::METHOD_DELETE, '/users/' . $targetId . '/sessions', $keyHeaders);
        $this->assertEquals(204, $deletedAll['headers']['status-code']);
    }
}
