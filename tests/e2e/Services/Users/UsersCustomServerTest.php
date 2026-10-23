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

    /**
     * A JWT cannot mint a user's JWT, so it cannot outlive itself through the next one.
     */
    public function testCreateUserJWTRefusesJWTCallers(): void
    {
        $project = $this->getProject();
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
        ], $this->getHeaders()), [
            'userId' => ID::unique(),
            'email' => 'jwt-caller-' . ID::unique() . '@appwrite.io',
            'password' => 'password',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

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

        /**
         * Test for SUCCESS
         */
        $minted = $this->client->call(Client::METHOD_POST, '/users/' . $user['body']['$id'] . '/jwts', array_merge($admin, [
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]));
        $this->assertSame(201, $minted['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $refused = $this->client->call(Client::METHOD_POST, '/users/' . $user['body']['$id'] . '/jwts', array_merge($admin, [
            'x-appwrite-jwt' => $jwt['body']['jwt'],
        ]));
        $this->assertSame(403, $refused['headers']['status-code']);
        $this->assertSame('user_jwt_creation_denied', $refused['body']['type']);
        $this->assertArrayNotHasKey('jwt', $refused['body']);
    }

    public function testGetMFARecoveryCodesRequiresWriteScope(): void
    {
        $projectId = $this->getProject()['$id'];

        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'userId' => ID::unique(),
            'email' => 'recovery-codes-' . \uniqid() . '@appwrite.io',
            'password' => 'password',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $recoveryCodes = $this->client->call(Client::METHOD_GET, '/users/' . $user['body']['$id'] . '/mfa/recovery-codes', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getNewKey(['users.read']),
        ]);
        $this->assertSame(401, $recoveryCodes['headers']['status-code']);
    }
}
