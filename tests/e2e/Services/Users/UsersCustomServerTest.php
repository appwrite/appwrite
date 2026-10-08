<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Users;

use Ahc\Jwt\JWT;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

final class UsersCustomServerTest extends Scope
{
    use UsersBase;
    use ProjectCustom;
    use SideServer;

    public function testUserJWTIsBoundToItsProject(): void
    {
        $victimProject = $this->getProject();
        $attackerProject = $this->getProject(true);

        $victimId = ID::unique();
        $victim = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $victimProject['$id'],
            'x-appwrite-key' => $victimProject['apiKey'],
        ], [
            'userId' => $victimId,
            'email' => 'victim-' . $victimId . '@appwrite.io',
            'password' => 'password',
        ]);
        $this->assertSame(201, $victim['headers']['status-code']);

        // The attacker's own project holds users with the victim's ID and the console root's ID.
        $jwts = [];
        foreach ([$victimId, $this->getRoot()['$id']] as $userId) {
            $user = $this->client->call(Client::METHOD_POST, '/users', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $attackerProject['$id'],
                'x-appwrite-key' => $attackerProject['apiKey'],
            ], [
                'userId' => $userId,
                'email' => 'attacker-' . ID::unique() . '@appwrite.io',
                'password' => 'password',
            ]);
            $this->assertSame(201, $user['headers']['status-code']);

            // No sessions, so the JWT carries no session to check.
            $jwt = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/jwts', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $attackerProject['$id'],
                'x-appwrite-key' => $attackerProject['apiKey'],
            ]);
            $this->assertSame(201, $jwt['headers']['status-code']);
            $jwts[$userId] = $jwt['body']['jwt'];
        }

        $account = fn (string $projectId, string $jwt) => $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-jwt' => $jwt,
        ]);

        // Still good where it was minted.
        $own = $account($attackerProject['$id'], $jwts[$victimId]);
        $this->assertSame(200, $own['headers']['status-code']);
        $this->assertSame($victimId, $own['body']['$id']);

        $crossProject = $account($victimProject['$id'], $jwts[$victimId]);
        $this->assertSame(401, $crossProject['headers']['status-code']);
        $this->assertArrayNotHasKey('email', $crossProject['body']);

        $console = $account('console', $jwts[$this->getRoot()['$id']]);
        $this->assertSame(401, $console['headers']['status-code']);
        $this->assertArrayNotHasKey('email', $console['body']);
    }

    /**
     * JWTs minted before the projectId claim existed are still in flight at deploy. They are
     * accepted only when they name a session, and only while that session lives in the project.
     */
    public function testLegacyJWTWithoutProjectClaimIsBoundBySession(): void
    {
        $project = $this->getProject();
        $otherProject = $this->getProject(true);
        $userId = ID::unique();

        foreach ([$project, $otherProject] as $p) {
            $user = $this->client->call(Client::METHOD_POST, '/users', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $p['$id'],
                'x-appwrite-key' => $p['apiKey'],
            ], [
                'userId' => $userId,
                'email' => 'legacy-' . ID::unique() . '@appwrite.io',
                'password' => 'password',
            ]);
            $this->assertSame(201, $user['headers']['status-code']);
        }

        $session = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/sessions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $project['apiKey'],
        ]);
        $this->assertSame(201, $session['headers']['status-code']);
        $sessionId = $session['body']['$id'];

        // The payload shape every minter produced before this change.
        $encoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 0);
        $withSession = $encoder->encode(['userId' => $userId, 'sessionId' => $sessionId]);
        $withoutSession = $encoder->encode(['userId' => $userId, 'sessionId' => '']);

        $account = fn (string $projectId, string $jwt) => $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-jwt' => $jwt,
        ]);

        $own = $account($project['$id'], $withSession);
        $this->assertSame(200, $own['headers']['status-code']);
        $this->assertSame($userId, $own['body']['$id']);

        // The other project's user has the same ID but not the session.
        $this->assertSame(401, $account($otherProject['$id'], $withSession)['headers']['status-code']);

        // Without a session nothing ties it to a project.
        $this->assertSame(401, $account($project['$id'], $withoutSession)['headers']['status-code']);
        $this->assertSame(401, $account($otherProject['$id'], $withoutSession)['headers']['status-code']);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId . '/sessions/' . $sessionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $project['apiKey'],
        ]);
        $this->assertSame(204, $deleted['headers']['status-code']);

        $this->assertSame(401, $account($project['$id'], $withSession)['headers']['status-code']);
    }

    /**
     * In admin mode the caller holds a console session, so a JWT created there belongs to the
     * console and authenticates admin-mode requests for any project the console user manages.
     */
    public function testAdminModeJWTIsBoundToConsole(): void
    {
        $project = $this->getProject();
        $admin = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-mode' => 'admin',
        ];

        $jwt = $this->client->call(Client::METHOD_POST, '/account/jwts', array_merge($admin, [
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]));
        $this->assertSame(201, $jwt['headers']['status-code']);

        $account = $this->client->call(Client::METHOD_GET, '/account', array_merge($admin, [
            'x-appwrite-jwt' => $jwt['body']['jwt'],
        ]));
        $this->assertSame(200, $account['headers']['status-code']);
        $this->assertSame($this->getRoot()['$id'], $account['body']['$id']);

        // Outside admin mode the same token would resolve the console user's ID in the project's own users.
        $client = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-jwt' => $jwt['body']['jwt'],
        ]);
        $this->assertSame(401, $client['headers']['status-code']);
    }

    /**
     * Minting credentials for an existing user takes users.write; a key scoped
     * to sessions.write cannot reach those routes.
     */
    public function testCreateTokenWithSessionsKey(): void
    {
        $projectId = $this->getProject()['$id'];
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => ID::unique(),
            'email' => 'sessions-key-' . ID::unique() . '@appwrite.io',
            'password' => 'password',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);
        $userId = $user['body']['$id'];

        $sessionsHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getNewKey(['sessions.write']),
        ];
        $usersHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getNewKey(['users.write']),
        ];

        /**
         * Test for SUCCESS
         */
        $token = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/tokens', $usersHeaders);
        $this->assertSame(201, $token['headers']['status-code']);
        $this->assertNotEmpty($token['body']['secret']);

        $jwt = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/jwts', $usersHeaders);
        $this->assertSame(201, $jwt['headers']['status-code']);
        $this->assertNotEmpty($jwt['body']['jwt']);

        /**
         * Test for FAILURE
         */
        $requests = [
            [Client::METHOD_POST, '/users/' . $userId . '/tokens', []],
            [Client::METHOD_POST, '/users/' . $userId . '/jwts', []],
            [Client::METHOD_PATCH, '/users/' . $userId . '/password', ['password' => 'new-password']],
            [Client::METHOD_PATCH, '/users/' . $userId . '/email', ['email' => 'changed-' . ID::unique() . '@appwrite.io']],
            [Client::METHOD_GET, '/users/' . $userId . '/mfa/recovery-codes', []],
            [Client::METHOD_GET, '/account', []],
            [Client::METHOD_POST, '/account/jwts', []],
            [Client::METHOD_GET, '/account/sessions', []],
        ];

        foreach ($requests as [$method, $path, $params]) {
            $response = $this->client->call($method, $path, $sessionsHeaders, $params);
            $this->assertSame(401, $response['headers']['status-code'], "{$method} {$path} should need more than sessions.write");
            $this->assertArrayNotHasKey('secret', $response['body']);
            $this->assertArrayNotHasKey('jwt', $response['body']);
        }

        $account = $this->client->call(Client::METHOD_GET, '/users/' . $userId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));
        $this->assertSame(200, $account['headers']['status-code']);
        $this->assertSame($user['body']['email'], $account['body']['email']);
    }

    /**
     * Listing sessions does not hand back a secret that signs in as the user.
     */
    public function testListSessionsWithoutSecret(): void
    {
        $projectId = $this->getProject()['$id'];
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'userId' => ID::unique(),
            'email' => 'list-sessions-' . ID::unique() . '@appwrite.io',
            'password' => 'password',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);
        $userId = $user['body']['$id'];

        $session = $this->client->call(Client::METHOD_POST, '/users/' . $userId . '/sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));
        $this->assertSame(201, $session['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $account = $this->client->call(Client::METHOD_GET, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-session' => $session['body']['secret'],
        ]);
        $this->assertSame(200, $account['headers']['status-code']);

        $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $userId . '/sessions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getNewKey(['sessions.read']),
        ]);
        $this->assertSame(200, $sessions['headers']['status-code']);
        $this->assertSame(1, $sessions['body']['total']);
        $this->assertSame($session['body']['$id'], $sessions['body']['sessions'][0]['$id']);

        /**
         * Test for FAILURE
         */
        $listed = $sessions['body']['sessions'][0]['secret'] ?? '';
        $this->assertNotSame($session['body']['secret'], $listed);

        $replays = [
            $listed,
            \base64_encode(\json_encode(['id' => $userId, 'secret' => $listed])),
        ];
        foreach ($replays as $replay) {
            $account = $this->client->call(Client::METHOD_GET, '/account', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-session' => $replay,
            ]);
            $this->assertSame(401, $account['headers']['status-code']);
        }
    }
}
