<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

/**
 * Smoke coverage for the project-scoped keys endpoints kept for a zero-downtime transition.
 * Full coverage lives in Tests\E2E\Services\Organization\KeysBase.
 *
 * TODO: Remove with /v1/project/keys once the Console, CLI and SDKs use the Organization API.
 */
trait KeysBase
{
    public function testKeysLifecycle(): void
    {
        $projectHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        // Key creation is denied for key-authorized requests, so always use a console session
        $consoleHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
        ];

        /**
         * Test for SUCCESS
         */
        $key = $this->client->call(Client::METHOD_POST, '/project/keys', $consoleHeaders, [
            'keyId' => ID::unique(),
            'name' => 'Legacy Key',
            'scopes' => ['users.read'],
        ]);

        $this->assertSame(201, $key['headers']['status-code']);
        $this->assertNotEmpty($key['body']['secret']);
        $keyId = $key['body']['$id'];

        $get = $this->client->call(Client::METHOD_GET, '/project/keys/' . $keyId, $projectHeaders);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Legacy Key', $get['body']['name']);

        $list = $this->client->call(Client::METHOD_GET, '/project/keys', $projectHeaders);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertContains($keyId, \array_column($list['body']['keys'], '$id'));

        $updated = $this->client->call(Client::METHOD_PUT, '/project/keys/' . $keyId, $projectHeaders, [
            'name' => 'Legacy Key Updated',
            'scopes' => ['users.read', 'users.write'],
        ]);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame(['users.read', 'users.write'], $updated['body']['scopes']);

        $ephemeral = $this->client->call(Client::METHOD_POST, '/project/keys/ephemeral', $projectHeaders, [
            'scopes' => ['users.read'],
            'duration' => 900,
        ]);

        $this->assertSame(201, $ephemeral['headers']['status-code']);
        $this->assertStringStartsWith(API_KEY_EPHEMERAL . '_', $ephemeral['body']['secret']);

        $delete = $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $keyId, $projectHeaders);

        $this->assertSame(204, $delete['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $get = $this->client->call(Client::METHOD_GET, '/project/keys/' . $keyId, $projectHeaders);

        $this->assertSame(404, $get['headers']['status-code']);
        $this->assertSame('key_not_found', $get['body']['type']);
    }

    public function testHideKeySecretsFromApiKey(): void
    {
        $projectId = $this->getProject()['$id'];
        $consoleHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
        ];

        $usersKey = $this->client->call(Client::METHOD_POST, '/project/keys', $consoleHeaders, [
            'keyId' => ID::unique(),
            'name' => 'Users Read Key',
            'scopes' => ['users.read'],
        ]);
        $this->assertSame(201, $usersKey['headers']['status-code']);
        $this->assertNotEmpty($usersKey['body']['secret']);
        $usersKeyId = $usersKey['body']['$id'];
        $usersSecret = $usersKey['body']['secret'];

        $readKey = $this->client->call(Client::METHOD_POST, '/project/keys', $consoleHeaders, [
            'keyId' => ID::unique(),
            'name' => 'Keys Read Key',
            'scopes' => ['keys.read'],
        ]);
        $this->assertSame(201, $readKey['headers']['status-code']);
        $this->assertNotEmpty($readKey['body']['secret']);
        $readSecret = $readKey['body']['secret'];
        $readKeyId = $readKey['body']['$id'];

        $writeKey = $this->client->call(Client::METHOD_POST, '/project/keys', $consoleHeaders, [
            'keyId' => ID::unique(),
            'name' => 'Keys Write Key',
            'scopes' => ['keys.write'],
        ]);
        $this->assertSame(201, $writeKey['headers']['status-code']);
        $this->assertNotEmpty($writeKey['body']['secret']);
        $writeSecret = $writeKey['body']['secret'];
        $writeKeyId = $writeKey['body']['$id'];

        $readHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $readSecret,
        ];
        $writeHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $writeSecret,
        ];

        $routes = [
            '/project/keys',
            '/projects/' . $projectId . '/keys',
        ];

        /**
         * Test for SUCCESS
         */
        foreach ($routes as $path) {
            $list = $this->client->call(Client::METHOD_GET, $path, $readHeaders, [
                'total' => false,
            ]);
            $this->assertSame(200, $list['headers']['status-code']);
            $this->assertContains($usersKeyId, \array_column($list['body']['keys'], '$id'));
            foreach ($list['body']['keys'] as $key) {
                $this->assertSame('', $key['secret']);
            }

            $get = $this->client->call(Client::METHOD_GET, $path . '/' . $usersKeyId, $readHeaders);
            $this->assertSame(200, $get['headers']['status-code']);
            $this->assertSame($usersKeyId, $get['body']['$id']);
            $this->assertSame('', $get['body']['secret']);
        }

        $updated = $this->client->call(Client::METHOD_PUT, '/project/keys/' . $usersKeyId, $writeHeaders, [
            'name' => 'Users Read Key',
            'scopes' => ['users.read'],
        ]);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('', $updated['body']['secret']);

        $consoleGet = $this->client->call(Client::METHOD_GET, '/project/keys/' . $usersKeyId, $consoleHeaders);
        $this->assertSame(200, $consoleGet['headers']['status-code']);
        $this->assertSame($usersSecret, $consoleGet['body']['secret']);

        $consoleList = $this->client->call(Client::METHOD_GET, '/project/keys', $consoleHeaders, [
            'total' => false,
        ]);
        $this->assertSame(200, $consoleList['headers']['status-code']);
        $consoleUsersKey = null;
        foreach ($consoleList['body']['keys'] as $key) {
            if ($key['$id'] === $usersKeyId) {
                $consoleUsersKey = $key;
                break;
            }
        }
        $this->assertNotNull($consoleUsersKey);
        $this->assertSame($usersSecret, $consoleUsersKey['secret']);

        $created = $this->client->call(Client::METHOD_POST, '/project/keys', $consoleHeaders, [
            'keyId' => ID::unique(),
            'name' => 'Create Still Returns Secret',
            'scopes' => ['users.read'],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertNotEmpty($created['body']['secret']);

        $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $created['body']['$id'], $consoleHeaders);
        $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $usersKeyId, $consoleHeaders);
        $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $readKeyId, $consoleHeaders);
        $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $writeKeyId, $consoleHeaders);
    }
}
