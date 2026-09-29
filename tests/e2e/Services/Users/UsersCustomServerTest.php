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
}
