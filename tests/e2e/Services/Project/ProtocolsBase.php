<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;

trait ProtocolsBase
{
    protected static array $protocols = ['rest', 'graphql', 'websocket'];

    // Success flow

    public function testDisableProtocol(): void
    {
        foreach (self::$protocols as $protocol) {
            $response = $this->updateProtocolStatus($protocol, false);

            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertSame(false, $response['body']['protocolStatusFor' . ucfirst($protocol)]);
        }

        // Cleanup
        foreach (self::$protocols as $protocol) {
            $this->updateProtocolStatus($protocol, true);
        }
    }

    public function testEnableProtocol(): void
    {
        // Disable first
        foreach (self::$protocols as $protocol) {
            $this->updateProtocolStatus($protocol, false);
        }

        // Re-enable
        foreach (self::$protocols as $protocol) {
            $response = $this->updateProtocolStatus($protocol, true);

            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertSame(true, $response['body']['protocolStatusFor' . ucfirst($protocol)]);
        }
    }

    public function testDisableProtocolIdempotent(): void
    {
        $first = $this->updateProtocolStatus('rest', false);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(false, $first['body']['protocolStatusForRest']);

        $second = $this->updateProtocolStatus('rest', false);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(false, $second['body']['protocolStatusForRest']);

        // Cleanup
        $this->updateProtocolStatus('rest', true);
    }

    public function testEnableProtocolIdempotent(): void
    {
        $first = $this->updateProtocolStatus('rest', true);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(true, $first['body']['protocolStatusForRest']);

        $second = $this->updateProtocolStatus('rest', true);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(true, $second['body']['protocolStatusForRest']);
    }

    public function testDisabledRestBlocksClientRequest(): void
    {
        $this->updateProtocolStatus('rest', false);

        $response = $this->client->call(Client::METHOD_GET, '/locale/countries', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertSame(403, $response['headers']['status-code']);
        $this->assertSame('general_api_disabled', $response['body']['type']);

        // Cleanup
        $this->updateProtocolStatus('rest', true);
    }

    public function testEnabledRestAllowsClientRequest(): void
    {
        $this->updateProtocolStatus('rest', false);
        $this->updateProtocolStatus('rest', true);

        $response = $this->client->call(Client::METHOD_GET, '/locale/countries', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
    }

    public function testDisabledGraphqlBlocksClientRequest(): void
    {
        $this->updateProtocolStatus('graphql', false);

        $response = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'query' => '{ localeListCountries { total } }',
        ]);

        $this->assertSame(403, $response['headers']['status-code']);
        $this->assertSame('general_api_disabled', $response['body']['type']);

        // Cleanup
        $this->updateProtocolStatus('graphql', true);
    }

    public function testDisableOneProtocolDoesNotAffectOther(): void
    {
        $this->updateProtocolStatus('graphql', false);

        // REST should still work
        $response = $this->client->call(Client::METHOD_GET, '/locale/countries', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertSame(200, $response['headers']['status-code']);

        // Cleanup
        $this->updateProtocolStatus('graphql', true);
    }

    public function testDisabledRestBlocksAllServiceEndpoints(): void
    {
        $endpoints = [
            'account'    => '/account',
            'teams'      => '/teams',
            'databases'  => '/databases',
            'storage'    => '/storage/buckets',
            'functions'  => '/functions',
            'sites'      => '/sites',
            'locale'     => '/locale',
            'health'     => '/health',
            'users'      => '/users',
            'messaging'  => '/messaging/providers',
            'migrations' => '/migrations',
        ];

        $this->updateProtocolStatus('rest', false);

        foreach ($endpoints as $service => $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]);

            $this->assertSame(403, $response['headers']['status-code'], 'Disabled REST protocol should block ' . $service . ' endpoint (got ' . $response['headers']['status-code'] . ')');
            $this->assertSame('general_api_disabled', $response['body']['type'], 'Disabled REST protocol should return general_api_disabled for ' . $service);
        }

        // Cleanup
        $this->updateProtocolStatus('rest', true);
    }

    public function testReenabledRestAllowsAllServiceEndpoints(): void
    {
        $endpoints = [
            'teams'      => '/teams',
            'databases'  => '/databases',
            'functions'  => '/functions',
            'locale'     => '/locale',
        ];

        $this->updateProtocolStatus('rest', false);
        $this->updateProtocolStatus('rest', true);

        foreach ($endpoints as $service => $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertNotEquals(403, $response['headers']['status-code'], 'Re-enabled REST protocol should not block ' . $service . ' endpoint');
        }
    }

    public function testDisabledGraphqlBlocksMutationRequest(): void
    {
        $this->updateProtocolStatus('graphql', false);

        $response = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'query' => 'mutation { teamsCreate(teamId: "unique()", name: "Test") { _id } }',
        ]);

        $this->assertSame(403, $response['headers']['status-code']);
        $this->assertSame('general_api_disabled', $response['body']['type']);

        // Cleanup
        $this->updateProtocolStatus('graphql', true);
    }

    public function testResponseModel(): void
    {
        $response = $this->updateProtocolStatus('rest', false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('name', $response['body']);
        $this->assertArrayHasKey('protocolStatusForRest', $response['body']);
        $this->assertArrayHasKey('protocolStatusForGraphql', $response['body']);
        $this->assertArrayHasKey('protocolStatusForWebsocket', $response['body']);

        // Cleanup
        $this->updateProtocolStatus('rest', true);
    }

    // Failure flow

    public function testUpdateProtocolWithoutAuthentication(): void
    {
        $response = $this->updateProtocolStatus('rest', false, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateProtocolInvalidProtocolId(): void
    {
        $response = $this->updateProtocolStatus('invalid', false);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateProtocolEmptyProtocolId(): void
    {
        $response = $this->updateProtocolStatus('', false);

        $this->assertSame(404, $response['headers']['status-code']);
    }

    // Helpers

    protected function updateProtocolStatus(string $protocolId, bool $enabled, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PATCH, '/project/protocols/' . $protocolId . '/status', $headers, [
            'enabled' => $enabled,
        ]);
    }
}
