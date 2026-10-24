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
