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
}
