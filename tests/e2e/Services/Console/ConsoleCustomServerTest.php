<?php

namespace Tests\E2E\Services\Console;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class ConsoleCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testGetVariables(): void
    {
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/console/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    public function testListOAuth2Providers(): void
    {
        // Public endpoint: must succeed without admin authentication. Drop the
        // headers from getHeaders() and only pass project + content-type.
        $response = $this->client->call(Client::METHOD_GET, '/console/oauth2-providers', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertIsArray($response['body']['oAuth2Providers']);
        $this->assertGreaterThan(0, $response['body']['total']);

        $providerIds = \array_column($response['body']['oAuth2Providers'], '$id');
        $this->assertContains('github', $providerIds);
        $this->assertNotContains('mock', $providerIds);
    }

    public function testListKeyScopes(): void
    {
        // Public endpoint: must succeed without admin authentication. Drop the
        // headers from getHeaders() and only pass project + content-type.
        $response = $this->client->call(Client::METHOD_GET, '/console/scopes/key', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertIsArray($response['body']['scopes']);
        $this->assertGreaterThan(0, $response['body']['total']);

        $scopeIds = \array_column($response['body']['scopes'], '$id');
        $this->assertContains('users.read', $scopeIds);
    }
}
