<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Utopia\Database\Id;

trait SessionsDeleteBase
{
    public function testDeleteSessions(): void
    {
        // A dedicated project keeps the project-wide sign-out from disturbing the
        // sessions of any test running in parallel against the shared project.
        self::$project = $this->getProject(true);
        $projectId = $this->getProject()['$id'];

        $projectHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        $publicHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        $accountHeaders = function (string $sessionSecret) use ($projectId): array {
            return [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'cookie' => 'a_session_' . $projectId . '=' . $sessionSecret,
            ];
        };

        $getAccount = function (string $sessionSecret) use ($accountHeaders): array {
            return $this->client->call(Client::METHOD_GET, '/account', $accountHeaders($sessionSecret));
        };

        $password = 'Password123!';

        $login = function (string $email) use ($publicHeaders, $projectId, $password): string {
            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(201, $session['headers']['status-code']);

            return $session['cookies']['a_session_' . $projectId];
        };

        // Setup: two users with a session each
        $email1 = 'sessions-delete-' . uniqid() . '@localhost.test';
        $email2 = 'sessions-delete-' . uniqid() . '@localhost.test';

        $user1 = $this->client->call(Client::METHOD_POST, '/users', $projectHeaders, [
            'userId' => Id::unique(),
            'email' => $email1,
            'password' => $password,
            'name' => 'Sessions Delete User 1',
        ]);
        $this->assertSame(201, $user1['headers']['status-code']);
        $userId1 = $user1['body']['$id'];

        $user2 = $this->client->call(Client::METHOD_POST, '/users', $projectHeaders, [
            'userId' => Id::unique(),
            'email' => $email2,
            'password' => $password,
            'name' => 'Sessions Delete User 2',
        ]);
        $this->assertSame(201, $user2['headers']['status-code']);

        $session1 = $login($email1);
        $session2 = $login($email2);

        /**
         * Test for SUCCESS
         */

        // Sessions work before the deletion
        $this->assertSame(200, $getAccount($session1)['headers']['status-code']);
        $this->assertSame(200, $getAccount($session2)['headers']['status-code']);

        // Mint a JWT before the deletion
        $jwtResponse = $this->client->call(Client::METHOD_POST, '/account/jwt', $accountHeaders($session1), []);
        $this->assertSame(201, $jwtResponse['headers']['status-code']);
        $jwt = $jwtResponse['body']['jwt'];

        // Delete every session in the project
        $response = $this->client->call(Client::METHOD_DELETE, '/project/sessions', $projectHeaders, []);
        $this->assertSame(204, $response['headers']['status-code']);

        // Every pre-existing session stops authenticating immediately, before the
        // worker deletes the session documents
        $this->assertEventually(function () use ($getAccount, $session1, $session2) {
            $this->assertSame(401, $getAccount($session1)['headers']['status-code']);
            $this->assertSame(401, $getAccount($session2)['headers']['status-code']);
        }, 15_000, 500);

        // JWTs minted from the deleted sessions are rejected too
        $jwtHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-jwt' => $jwt,
        ];
        $this->assertEventually(function () use ($jwtHeaders) {
            $response = $this->client->call(Client::METHOD_GET, '/account', $jwtHeaders, []);
            $this->assertSame(401, $response['headers']['status-code']);
        }, 15_000, 500);

        // The project keeps working: users can sign in again right away
        $newSession = $login($email1);
        $this->assertSame(200, $getAccount($newSession)['headers']['status-code']);

        // The worker removes the deleted session documents, so they stop being listed
        $this->assertEventually(function () use ($projectHeaders, $userId1) {
            $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $userId1 . '/sessions', $projectHeaders, []);
            $this->assertSame(200, $sessions['headers']['status-code']);
            $this->assertSame(1, $sessions['body']['total']);
        }, 60_000, 1000);
    }

    public function testDeleteSessionsScopeFailures(): void
    {
        self::$project = $this->getProject(true);
        $projectId = $this->getProject()['$id'];

        $projectHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        /**
         * Test for FAILURE
         */

        // A key without the users.write scope is rejected
        $readKey = $this->getNewKey(['sessions.read']);
        $readKeyHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $readKey,
        ];
        $response = $this->client->call(Client::METHOD_DELETE, '/project/sessions', $readKeyHeaders, []);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);

        // An ordinary user session is rejected
        $email = 'sessions-delete-' . uniqid() . '@localhost.test';
        $password = 'Password123!';

        $this->client->call(Client::METHOD_POST, '/users', $projectHeaders, [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Sessions Delete Member',
        ]);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(201, $session['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/project/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session['cookies']['a_session_' . $projectId],
        ], []);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);

        // No credentials at all are rejected
        $response = $this->client->call(Client::METHOD_DELETE, '/project/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], []);
        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testDeleteSessionsCrossProjectIsolation(): void
    {
        self::$project = $this->getProject(true);
        $projectId = $this->getProject()['$id'];
        $password = 'Password123!';

        $otherProject = $this->getProject(true);
        $otherProjectId = $otherProject['$id'];
        $otherProjectHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $otherProjectId,
            'x-appwrite-key' => $otherProject['apiKey'],
        ];

        $projectHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        $login = function (string $email, array $headers, string $cookieProjectId) use ($password): string {
            $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $headers, [
                'email' => $email,
                'password' => $password,
            ]);
            $this->assertSame(201, $session['headers']['status-code']);

            return $session['cookies']['a_session_' . $cookieProjectId];
        };

        $getAccount = function (string $sessionSecret, string $cookieProjectId): array {
            return $this->client->call(Client::METHOD_GET, '/account', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $cookieProjectId,
                'cookie' => 'a_session_' . $cookieProjectId . '=' . $sessionSecret,
            ], []);
        };

        // Setup: a user with a session in each project
        $email = 'sessions-delete-' . uniqid() . '@localhost.test';
        $otherEmail = 'sessions-delete-' . uniqid() . '@localhost.test';

        $this->client->call(Client::METHOD_POST, '/users', $projectHeaders, [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Sessions Delete Isolated User',
        ]);

        $this->client->call(Client::METHOD_POST, '/users', $otherProjectHeaders, [
            'userId' => Id::unique(),
            'email' => $otherEmail,
            'password' => $password,
            'name' => 'Sessions Delete Other User',
        ]);

        $session = $login($email, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $projectId);

        $otherSession = $login($otherEmail, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $otherProjectId,
        ], $otherProjectId);

        /**
         * Test for SUCCESS
         */

        $this->assertSame(200, $getAccount($session, $projectId)['headers']['status-code']);
        $this->assertSame(200, $getAccount($otherSession, $otherProjectId)['headers']['status-code']);

        // Delete every session of the first project only
        $response = $this->client->call(Client::METHOD_DELETE, '/project/sessions', $projectHeaders, []);
        $this->assertSame(204, $response['headers']['status-code']);

        // The first project's session is gone, the other project's session survives
        $this->assertEventually(function () use ($getAccount, $session, $projectId, $otherSession, $otherProjectId) {
            $this->assertSame(401, $getAccount($session, $projectId)['headers']['status-code']);
            $this->assertSame(200, $getAccount($otherSession, $otherProjectId)['headers']['status-code']);
        }, 15_000, 500);
    }

    public function testDeleteSessionsKeepsConsoleSessions(): void
    {
        self::$project = $this->getProject(true);
        $projectId = $this->getProject()['$id'];

        $consoleHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
        ];

        /**
         * Test for SUCCESS
         */

        // The console session works on the project before the deletion
        $before = $this->client->call(Client::METHOD_GET, '/project', $consoleHeaders, []);
        $this->assertSame(200, $before['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/project/sessions', $consoleHeaders, []);
        $this->assertSame(204, $response['headers']['status-code']);

        // A project-wide sign-out must not end the console members' own sessions:
        // those belong to the console project, not to the project being wiped
        $after = $this->client->call(Client::METHOD_GET, '/project', $consoleHeaders, []);
        $this->assertSame(200, $after['headers']['status-code']);
    }
}
