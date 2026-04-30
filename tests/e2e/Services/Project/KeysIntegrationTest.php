<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class KeysIntegrationTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testEphemeralKeyScopeEnforcement(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ];

        $consoleHeaders = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
            'x-appwrite-project' => $projectId,
        ];

        // Step 1: Create an ephemeral key scoped to users.read only.
        $ephemeralKey = $this->client->call(
            Client::METHOD_POST,
            '/project/keys/ephemeral',
            $serverHeaders,
            [
                'scopes' => ['users.read'],
                'duration' => 900,
            ]
        );
        $this->assertSame(201, $ephemeralKey['headers']['status-code']);
        $this->assertNotEmpty($ephemeralKey['body']['secret']);

        $ephemeralKeySecret = $ephemeralKey['body']['secret'];

        $ephemeralHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $ephemeralKeySecret,
        ];

        // Step 2: Create a project user using console headers.
        $user = $this->client->call(
            Client::METHOD_POST,
            '/users',
            $consoleHeaders,
            [
                'userId' => ID::unique(),
                'email' => 'ephemeral_key_' . \uniqid() . '@localhost.test',
                'password' => 'password1234',
                'name' => 'Ephemeral Key Test User',
            ]
        );
        $this->assertSame(201, $user['headers']['status-code']);
        $userId = $user['body']['$id'];

        // Step 3: Ephemeral key can list users.
        $list = $this->client->call(
            Client::METHOD_GET,
            '/users',
            $ephemeralHeaders
        );
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $list['body']['total']);

        // Step 4: Ephemeral key can get the specific user.
        $get = $this->client->call(
            Client::METHOD_GET,
            '/users/' . $userId,
            $ephemeralHeaders
        );
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($userId, $get['body']['$id']);

        // Step 5: Ephemeral key cannot create users (missing users.write scope).
        $createAttempt = $this->client->call(
            Client::METHOD_POST,
            '/users',
            $ephemeralHeaders,
            [
                'userId' => ID::unique(),
                'email' => 'should_fail_' . \uniqid() . '@localhost.test',
                'password' => 'password1234',
                'name' => 'Should Fail',
            ]
        );
        $this->assertSame(401, $createAttempt['headers']['status-code']);
    }
}
