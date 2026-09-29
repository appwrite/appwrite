<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Users;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

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
}
