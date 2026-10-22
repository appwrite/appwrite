<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Utopia\Database\Id;

/**
 * Smoke coverage for the project-scoped keys endpoints kept for a zero-downtime transition.
 * Full coverage lives in Tests\E2E\Services\Organization\KeysBase.
 *
 * TODO: Remove with /v1/project/keys once the Console, CLI and SDKs use the Organization API.
 */
trait KeysBase
{
    public function testKeysScopesBeyondCaller(): void
    {
        $projectHeaders = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        // A key that may manage keys, and nothing else
        $manager = $this->client->call(Client::METHOD_POST, '/project/keys/ephemeral', $projectHeaders, [
            'scopes' => ['keys.read', 'keys.write'],
            'duration' => 900,
        ]);
        $this->assertSame(201, $manager['headers']['status-code']);

        $managerHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $manager['body']['secret'],
        ];

        $consoleHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
        ];

        $key = $this->client->call(Client::METHOD_POST, '/project/keys', $consoleHeaders, [
            'keyId' => Id::unique(),
            'name' => 'Narrow Key',
            'scopes' => ['users.read'],
        ]);
        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        /**
         * Test for FAILURE
         */
        // A key acts with its scopes, so the manager cannot hand out more than it holds
        $ephemeral = $this->client->call(Client::METHOD_POST, '/project/keys/ephemeral', $managerHeaders, [
            'scopes' => ['keys.read', 'users.write'],
            'duration' => 900,
        ]);
        $this->assertSame(401, $ephemeral['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $ephemeral['body']['type']);

        $widened = $this->client->call(Client::METHOD_PUT, '/project/keys/' . $keyId, $managerHeaders, [
            'name' => 'Narrow Key',
            'scopes' => ['users.read', 'databases.write'],
        ]);
        $this->assertSame(401, $widened['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $widened['body']['type']);

        /**
         * Test for SUCCESS
         */
        // Scopes the key already holds stay, and scopes the manager holds may be added
        $renamed = $this->client->call(Client::METHOD_PUT, '/project/keys/' . $keyId, $managerHeaders, [
            'name' => 'Narrow Key Renamed',
            'scopes' => ['users.read', 'keys.read'],
        ]);
        $this->assertSame(200, $renamed['headers']['status-code']);
        $this->assertSame(['users.read', 'keys.read'], $renamed['body']['scopes']);

        $ephemeral = $this->client->call(Client::METHOD_POST, '/project/keys/ephemeral', $managerHeaders, [
            'scopes' => ['keys.read'],
            'duration' => 900,
        ]);
        $this->assertSame(201, $ephemeral['headers']['status-code']);

        $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $keyId, $consoleHeaders);
    }

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
            'keyId' => Id::unique(),
            'name' => 'Legacy Key',
            'scopes' => ['users.read'],
            'description' => 'Used by the nightly backup job.',
        ]);

        $this->assertSame(201, $key['headers']['status-code']);
        $this->assertNotEmpty($key['body']['secret']);
        $this->assertSame('Used by the nightly backup job.', $key['body']['description']);
        $keyId = $key['body']['$id'];

        $get = $this->client->call(Client::METHOD_GET, '/project/keys/' . $keyId, $projectHeaders);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('Legacy Key', $get['body']['name']);
        $this->assertSame('Used by the nightly backup job.', $get['body']['description']);
        $this->assertSame($key['body']['secret'], $get['body']['secret']);

        $list = $this->client->call(Client::METHOD_GET, '/project/keys', $projectHeaders);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertContains($keyId, \array_column($list['body']['keys'], '$id'));
        $this->assertNotContains('', \array_column($list['body']['keys'], 'secret'));

        // A key that can only read keys does not see their secrets
        $reader = $this->client->call(Client::METHOD_POST, '/project/keys/ephemeral', $projectHeaders, [
            'scopes' => ['keys.read'],
            'duration' => 900,
        ]);

        $this->assertSame(201, $reader['headers']['status-code']);

        $readerHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $reader['body']['secret'],
        ];

        $get = $this->client->call(Client::METHOD_GET, '/project/keys/' . $keyId, $readerHeaders);

        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('', $get['body']['secret']);

        $list = $this->client->call(Client::METHOD_GET, '/project/keys', $readerHeaders);

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame([''], \array_unique(\array_column($list['body']['keys'], 'secret')));

        $updated = $this->client->call(Client::METHOD_PUT, '/project/keys/' . $keyId, $projectHeaders, [
            'name' => 'Legacy Key Updated',
            'scopes' => ['users.read', 'users.write'],
        ]);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame(['users.read', 'users.write'], $updated['body']['scopes']);
        $this->assertSame('Used by the nightly backup job.', $updated['body']['description']);

        $updated = $this->client->call(Client::METHOD_PUT, '/project/keys/' . $keyId, $projectHeaders, [
            'name' => 'Legacy Key Updated',
            'scopes' => ['users.read', 'users.write'],
            'description' => 'Rotated every quarter.',
        ]);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('Rotated every quarter.', $updated['body']['description']);

        $updated = $this->client->call(Client::METHOD_PUT, '/project/keys/' . $keyId, $projectHeaders, [
            'name' => 'Legacy Key Updated',
            'scopes' => ['users.read', 'users.write'],
            'description' => '',
        ]);

        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('', $updated['body']['description']);

        $undescribed = $this->client->call(Client::METHOD_POST, '/project/keys', $consoleHeaders, [
            'keyId' => ID::unique(),
            'name' => 'Legacy Key Without Description',
            'scopes' => ['users.read'],
        ]);

        $this->assertSame(201, $undescribed['headers']['status-code']);
        $this->assertSame('', $undescribed['body']['description']);

        $delete = $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $undescribed['body']['$id'], $projectHeaders);

        $this->assertSame(204, $delete['headers']['status-code']);

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

        $key = $this->client->call(Client::METHOD_POST, '/project/keys', $consoleHeaders, [
            'keyId' => ID::unique(),
            'name' => 'Legacy Key',
            'scopes' => ['users.read'],
            'description' => \str_repeat('a', 1025),
        ]);

        $this->assertSame(400, $key['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $key['body']['type']);

        $key = $this->client->call(Client::METHOD_POST, '/project/keys', $consoleHeaders, [
            'keyId' => ID::unique(),
            'name' => 'Legacy Key',
            'scopes' => ['users.read'],
        ]);

        $this->assertSame(201, $key['headers']['status-code']);
        $keyId = $key['body']['$id'];

        $updated = $this->client->call(Client::METHOD_PUT, '/project/keys/' . $keyId, $projectHeaders, [
            'name' => 'Legacy Key',
            'scopes' => ['users.read'],
            'description' => \str_repeat('a', 1025),
        ]);

        $this->assertSame(400, $updated['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $updated['body']['type']);

        $this->client->call(Client::METHOD_DELETE, '/project/keys/' . $keyId, $projectHeaders);
    }
}
