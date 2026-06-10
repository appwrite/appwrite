<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;

trait ServicesBase
{
    /**
     * Optional services that can be toggled.
     */
    protected static array $optionalServices = [
        'account',
        'avatars',
        'databases',
        'tablesdb',
        'locale',
        'health',
        'project',
        'storage',
        'teams',
        'users',
        'vcs',
        'sites',
        'functions',
        'proxy',
        'migrations',
        'messaging',
    ];

    // Success flow

    public function testDisableService(): void
    {
        foreach (self::$optionalServices as $service) {
            $response = $this->updateServiceStatus($service, false);

            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertSame(false, $response['body']['serviceStatusFor' . ucfirst($service)]);
        }

        // Cleanup
        foreach (self::$optionalServices as $service) {
            $this->updateServiceStatus($service, true);
        }
    }

    public function testEnableService(): void
    {
        // Disable first
        foreach (self::$optionalServices as $service) {
            $this->updateServiceStatus($service, false);
        }

        // Re-enable
        foreach (self::$optionalServices as $service) {
            $response = $this->updateServiceStatus($service, true);

            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);
            $this->assertSame(true, $response['body']['serviceStatusFor' . ucfirst($service)]);
        }
    }

    public function testDisableServiceIdempotent(): void
    {
        $first = $this->updateServiceStatus('teams', false);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(false, $first['body']['serviceStatusForTeams']);

        $second = $this->updateServiceStatus('teams', false);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(false, $second['body']['serviceStatusForTeams']);

        // Cleanup
        $this->updateServiceStatus('teams', true);
    }

    public function testEnableServiceIdempotent(): void
    {
        $first = $this->updateServiceStatus('teams', true);
        $this->assertSame(200, $first['headers']['status-code']);
        $this->assertSame(true, $first['body']['serviceStatusForTeams']);

        $second = $this->updateServiceStatus('teams', true);
        $this->assertSame(200, $second['headers']['status-code']);
        $this->assertSame(true, $second['body']['serviceStatusForTeams']);
    }

    public function testDisabledServiceBlocksClientRequest(): void
    {
        $this->updateServiceStatus('teams', false);

        $response = $this->client->call(Client::METHOD_GET, '/teams', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertSame(403, $response['headers']['status-code']);
        $this->assertSame('general_service_disabled', $response['body']['type']);

        // Cleanup
        $this->updateServiceStatus('teams', true);
    }

    public function testEnabledServiceAllowsClientRequest(): void
    {
        $this->updateServiceStatus('teams', false);
        $this->updateServiceStatus('teams', true);

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertSame(200, $response['headers']['status-code']);
    }

    public function testDisableOneServiceDoesNotAffectOther(): void
    {
        $this->updateServiceStatus('teams', false);

        $response = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertSame(200, $response['headers']['status-code']);

        // Cleanup
        $this->updateServiceStatus('teams', true);
    }

    public function testEachDisabledServiceBlocksItsEndpoint(): void
    {
        $serviceEndpoints = [
            'account'    => ['method' => Client::METHOD_GET,  'path' => '/account'],
            'avatars'    => ['method' => Client::METHOD_GET,  'path' => '/avatars/initials'],
            'databases'  => ['method' => Client::METHOD_GET,  'path' => '/databases'],
            'tablesdb'   => ['method' => Client::METHOD_GET,  'path' => '/tablesdb'],
            'locale'     => ['method' => Client::METHOD_GET,  'path' => '/locale'],
            'health'     => ['method' => Client::METHOD_GET,  'path' => '/health'],
            'project'    => ['method' => Client::METHOD_GET,  'path' => '/project/platforms'],
            'storage'    => ['method' => Client::METHOD_GET,  'path' => '/storage/buckets'],
            'teams'      => ['method' => Client::METHOD_GET,  'path' => '/teams'],
            'users'      => ['method' => Client::METHOD_GET,  'path' => '/users'],
            'vcs'        => ['method' => Client::METHOD_GET,  'path' => '/vcs/installations'],
            'sites'      => ['method' => Client::METHOD_GET,  'path' => '/sites'],
            'functions'  => ['method' => Client::METHOD_GET,  'path' => '/functions'],
            'proxy'      => ['method' => Client::METHOD_GET,  'path' => '/proxy/rules'],
            'migrations' => ['method' => Client::METHOD_GET,  'path' => '/migrations'],
            'messaging'  => ['method' => Client::METHOD_GET,  'path' => '/messaging/providers'],
        ];

        foreach ($serviceEndpoints as $service => $endpoint) {
            $this->updateServiceStatus($service, false);

            $response = $this->client->call($endpoint['method'], $endpoint['path'], [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]);

            $this->assertSame(403, $response['headers']['status-code'], 'Service ' . $service . ' should block requests when disabled (got ' . $response['headers']['status-code'] . ')');
            $this->assertSame('general_service_disabled', $response['body']['type'], 'Service ' . $service . ' should return general_service_disabled error type');

            // Cleanup
            $this->updateServiceStatus($service, true);
        }
    }

    public function testEachReenabledServiceAllowsRequest(): void
    {
        $serviceEndpoints = [
            'account'    => ['method' => Client::METHOD_GET,  'path' => '/account'],
            'avatars'    => ['method' => Client::METHOD_GET,  'path' => '/avatars/initials'],
            'databases'  => ['method' => Client::METHOD_GET,  'path' => '/databases'],
            'tablesdb'   => ['method' => Client::METHOD_GET,  'path' => '/tablesdb'],
            'locale'     => ['method' => Client::METHOD_GET,  'path' => '/locale'],
            'health'     => ['method' => Client::METHOD_GET,  'path' => '/health'],
            'project'    => ['method' => Client::METHOD_GET,  'path' => '/project/platforms'],
            'storage'    => ['method' => Client::METHOD_GET,  'path' => '/storage/buckets'],
            'teams'      => ['method' => Client::METHOD_GET,  'path' => '/teams'],
            'users'      => ['method' => Client::METHOD_GET,  'path' => '/users'],
            'vcs'        => ['method' => Client::METHOD_GET,  'path' => '/vcs/installations'],
            'sites'      => ['method' => Client::METHOD_GET,  'path' => '/sites'],
            'functions'  => ['method' => Client::METHOD_GET,  'path' => '/functions'],
            'proxy'      => ['method' => Client::METHOD_GET,  'path' => '/proxy/rules'],
            'migrations' => ['method' => Client::METHOD_GET,  'path' => '/migrations'],
            'messaging'  => ['method' => Client::METHOD_GET,  'path' => '/messaging/providers'],
        ];

        foreach ($serviceEndpoints as $service => $endpoint) {
            $this->updateServiceStatus($service, false);
            $this->updateServiceStatus($service, true);

            $response = $this->client->call($endpoint['method'], $endpoint['path'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertNotEquals(403, $response['headers']['status-code'], 'Service ' . $service . ' should allow requests after re-enabling');
        }
    }

    public function testResponseModel(): void
    {
        $response = $this->updateServiceStatus('teams', false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('name', $response['body']);
        $this->assertArrayHasKey('serviceStatusForTeams', $response['body']);

        // Cleanup
        $this->updateServiceStatus('teams', true);
    }

    // Failure flow

    public function testUpdateServiceWithoutAuthentication(): void
    {
        $response = $this->updateServiceStatus('teams', false, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateServiceInvalidServiceId(): void
    {
        $response = $this->updateServiceStatus('invalid', false);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateServiceEmptyServiceId(): void
    {
        $response = $this->updateServiceStatus('', false);

        $this->assertSame(404, $response['headers']['status-code']);
    }

    // Backwards compatibility

    public function testUpdateServiceLegacyStatusPath(): void
    {
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-response-format' => '1.9.4',
        ], $this->getHeaders());

        // Disable via the legacy `/status` alias
        $response = $this->client->call(Client::METHOD_PATCH, '/project/services/teams/status', $headers, [
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertSame(false, $response['body']['serviceStatusForTeams']);

        // Re-enable via the legacy `/status` alias
        $response = $this->client->call(Client::METHOD_PATCH, '/project/services/teams/status', $headers, [
            'enabled' => true,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['serviceStatusForTeams']);
    }

    // Helpers

    protected function updateServiceStatus(string $serviceId, bool $enabled, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders(), [
                'x-appwrite-response-format' => '1.9.4',
            ]);
        }

        return $this->client->call(Client::METHOD_PATCH, '/project/services/' . $serviceId, $headers, [
            'enabled' => $enabled,
        ]);
    }
}
