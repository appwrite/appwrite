<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Id;

final class SessionsDeleteIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testDeleteProjectSessions(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        $publicHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        $accountHeaders = function (string $sessionCookie) use ($projectId): array {
            return [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'cookie' => 'a_session_' . $projectId . '=' . $sessionCookie,
            ];
        };

        $getAccount = function (string $sessionCookie) use ($accountHeaders): array {
            return $this->client->call(Client::METHOD_GET, '/account', $accountHeaders($sessionCookie));
        };

        // --- Setup: create two users each with a cookie session ---
        $email1 = 'sessdelete_' . uniqid() . '@localhost.test';
        $email2 = 'sessdelete_' . uniqid() . '@localhost.test';
        $password = 'Password123!';

        $user1 = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => Id::unique(),
            'email' => $email1,
            'password' => $password,
            'name' => 'SessionDelete User1',
        ]);
        $this->assertSame(201, $user1['headers']['status-code']);
        $userId1 = $user1['body']['$id'];

        $user2 = $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => Id::unique(),
            'email' => $email2,
            'password' => $password,
            'name' => 'SessionDelete User2',
        ]);
        $this->assertSame(201, $user2['headers']['status-code']);

        // Create client-side sessions via email+password login
        $loginSession1 = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
            'email' => $email1,
            'password' => $password,
        ]);
        $this->assertSame(201, $loginSession1['headers']['status-code']);
        $cookie1 = $loginSession1['cookies']['a_session_' . $projectId];

        $loginSession2 = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
            'email' => $email2,
            'password' => $password,
        ]);
        $this->assertSame(201, $loginSession2['headers']['status-code']);
        $cookie2 = $loginSession2['cookies']['a_session_' . $projectId];

        // Verify sessions work before the bump
        $this->assertSame(200, $getAccount($cookie1)['headers']['status-code']);
        $this->assertSame(200, $getAccount($cookie2)['headers']['status-code']);

        // Mint a JWT from session1 before the bump
        $jwtResponse = $this->client->call(Client::METHOD_POST, '/account/jwt', $accountHeaders($cookie1), []);
        $this->assertSame(201, $jwtResponse['headers']['status-code']);
        $jwt = $jwtResponse['body']['jwt'];

        // --- Test SUCCESS: force-signout all sessions ---
        $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/project/sessions', $serverHeaders, []);
        $this->assertSame(204, $deleteResponse['headers']['status-code']);

        // --- Test: pre-bump sessions rejected immediately (no worker dependency) ---
        $this->assertEventually(function () use ($getAccount, $cookie1, $cookie2) {
            $this->assertSame(401, $getAccount($cookie1)['headers']['status-code']);
            $this->assertSame(401, $getAccount($cookie2)['headers']['status-code']);
        }, 15_000, 500);

        // --- Test: pre-bump JWT rejected ---
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

        // --- Test SUCCESS: re-login works (project is not bricked) ---
        $newLogin = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
            'email' => $email1,
            'password' => $password,
        ]);
        $this->assertSame(201, $newLogin['headers']['status-code']);
        $newCookie = $newLogin['cookies']['a_session_' . $projectId];
        $this->assertSame(200, $getAccount($newCookie)['headers']['status-code']);

        // --- Test: worker eventually deletes dead session documents ---
        $this->assertEventually(function () use ($serverHeaders, $userId1) {
            $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $userId1 . '/sessions', $serverHeaders, []);
            $this->assertSame(200, $sessions['headers']['status-code']);
            // Only the newly-created session survives; the pre-bump one should be gone
            $this->assertSame(1, $sessions['body']['total']);
        }, 60_000, 1000);
    }

    public function testDeleteProjectSessionsScopeFailures(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        // --- Test FAILURE: read-only key is rejected ---
        $readKey = $this->getNewKey(['sessions.read']);
        $readKeyHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $readKey,
        ];
        $response = $this->client->call(Client::METHOD_DELETE, '/project/sessions', $readKeyHeaders, []);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);

        // --- Test FAILURE: no auth is rejected ---
        $noAuthHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];
        $response = $this->client->call(Client::METHOD_DELETE, '/project/sessions', $noAuthHeaders, []);
        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testDeleteProjectSessionsCrossProjectIsolation(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];
        $password = 'Password123!';

        $email = 'crossproj_' . uniqid() . '@localhost.test';

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        $publicHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ];

        // Create a user and session in project A
        $this->client->call(Client::METHOD_POST, '/users', $serverHeaders, [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'CrossProject User',
        ]);

        $sessionA = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $publicHeaders, [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertSame(201, $sessionA['headers']['status-code']);
        $cookieA = $sessionA['cookies']['a_session_' . $projectId];

        // Bump project A sessions
        $deleteResponse = $this->client->call(Client::METHOD_DELETE, '/project/sessions', $serverHeaders, []);
        $this->assertSame(204, $deleteResponse['headers']['status-code']);

        // Session in project A is dead
        $this->assertEventually(function () use ($projectId, $cookieA) {
            $response = $this->client->call(Client::METHOD_GET, '/account', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'cookie' => 'a_session_' . $projectId . '=' . $cookieA,
            ], []);
            $this->assertSame(401, $response['headers']['status-code']);
        }, 15_000, 500);
    }
}
