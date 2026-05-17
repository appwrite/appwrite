<?php

namespace Tests\E2E\Services\Console;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class ConsoleConsoleClientTest extends Scope
{
    use ConsoleBase;
    use ProjectConsole;
    use SideClient;

    public function testGetVariables(): void
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/console/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsString($response['body']['_APP_DOMAIN_TARGET_CNAME']);
        $this->assertIsString($response['body']['_APP_DOMAIN_TARGET_A']);
        $this->assertIsInt($response['body']['_APP_COMPUTE_BUILD_TIMEOUT']);
        $this->assertIsString($response['body']['_APP_DOMAIN_TARGET_AAAA']);
        $this->assertIsString($response['body']['_APP_DOMAIN_TARGET_CAA']);
        $this->assertIsInt($response['body']['_APP_STORAGE_LIMIT']);
        $this->assertIsInt($response['body']['_APP_COMPUTE_SIZE_LIMIT']);
        $this->assertIsBool($response['body']['_APP_DOMAIN_ENABLED']);
        $this->assertIsBool($response['body']['_APP_VCS_ENABLED']);
        $this->assertIsBool($response['body']['_APP_ASSISTANT_ENABLED']);
        $this->assertIsString($response['body']['_APP_DOMAIN_SITES']);
        $this->assertIsString($response['body']['_APP_DOMAIN_FUNCTIONS']);
        $this->assertIsString($response['body']['_APP_OPTIONS_FORCE_HTTPS']);
        $this->assertIsString($response['body']['_APP_DOMAINS_NAMESERVERS']);
        $this->assertIsString($response['body']['_APP_DB_ADAPTER']);
        // When adding new keys, dont forget to update count a few lines above
    }

    public function testListOAuth2Providers(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/console/oauth2-providers', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertIsArray($response['body']['oAuth2Providers']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertEquals($response['body']['total'], \count($response['body']['oAuth2Providers']));

        $providerIds = \array_column($response['body']['oAuth2Providers'], '$id');
        $this->assertEquals('amazon', $providerIds[0]);
        $this->assertEquals('zoom', $providerIds[\count($providerIds) - 1]);

        // Well-known providers must be present
        $this->assertContains('github', $providerIds);
        $this->assertContains('google', $providerIds);

        // Mock providers must be excluded
        $this->assertNotContains('mock', $providerIds);
        $this->assertNotContains('mock-unverified', $providerIds);

        // Every provider has the expected shape
        foreach ($response['body']['oAuth2Providers'] as $provider) {
            $this->assertArrayHasKey('$id', $provider);
            $this->assertIsString($provider['$id']);
            $this->assertArrayHasKey('parameters', $provider);
            $this->assertIsArray($provider['parameters']);
            $this->assertGreaterThan(0, \count($provider['parameters']));

            foreach ($provider['parameters'] as $parameter) {
                $this->assertArrayHasKey('$id', $parameter);
                $this->assertIsString($parameter['$id']);
                $this->assertNotEmpty($parameter['$id']);
                $this->assertArrayHasKey('name', $parameter);
                $this->assertIsString($parameter['name']);
                $this->assertNotEmpty($parameter['name']);
                $this->assertArrayHasKey('example', $parameter);
                $this->assertIsString($parameter['example']);
                $this->assertArrayHasKey('hint', $parameter);
                $this->assertIsString($parameter['hint']);
            }
        }

        // GitHub provider has the expected metadata for clientId, including the hint
        $github = null;
        foreach ($response['body']['oAuth2Providers'] as $provider) {
            if ($provider['$id'] === 'github') {
                $github = $provider;
                break;
            }
        }
        $this->assertNotNull($github);
        $this->assertCount(2, $github['parameters']);
        $clientId = $github['parameters'][0];
        $this->assertEquals('clientId', $clientId['$id']);
        $this->assertEquals('OAuth2 app Client ID, or App ID', $clientId['name']);
        $this->assertEquals('e4d87900000000540733', $clientId['example']);
        $this->assertEquals('Example of wrong value: 370006', $clientId['hint']);
        $clientSecret = $github['parameters'][1];
        $this->assertEquals('clientSecret', $clientSecret['$id']);
        $this->assertEquals('Client Secret', $clientSecret['name']);
        $this->assertNotEmpty($clientSecret['example']);
        $this->assertEquals('', $clientSecret['hint']);

        // Multi-parameter provider (Apple) exposes its non-clientSecret fields
        $apple = null;
        foreach ($response['body']['oAuth2Providers'] as $provider) {
            if ($provider['$id'] === 'apple') {
                $apple = $provider;
                break;
            }
        }
        $this->assertNotNull($apple);
        $appleParamIds = \array_column($apple['parameters'], '$id');
        $this->assertContains('serviceId', $appleParamIds);
        $this->assertContains('keyId', $appleParamIds);
        $this->assertContains('teamId', $appleParamIds);
        $this->assertContains('p8File', $appleParamIds);
        // Apple does not expose a single clientSecret param
        $this->assertNotContains('clientSecret', $appleParamIds);

        // Sandbox providers (e.g. paypalSandbox) are included
        $this->assertContains('paypalSandbox', $providerIds);
    }

    public function testListKeyScopes(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/console/scopes/project', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertIsArray($response['body']['scopes']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertEquals($response['body']['total'], \count($response['body']['scopes']));

        $scopeIds = \array_column($response['body']['scopes'], '$id');

        // Well-known scopes must be present
        $this->assertContains('users.read', $scopeIds);
        $this->assertContains('users.write', $scopeIds);
        $this->assertContains('functions.read', $scopeIds);
        $this->assertContains('functions.write', $scopeIds);

        // Every scope has the expected shape
        foreach ($response['body']['scopes'] as $scope) {
            $this->assertArrayHasKey('$id', $scope);
            $this->assertIsString($scope['$id']);
            $this->assertNotEmpty($scope['$id']);
            $this->assertArrayHasKey('description', $scope);
            $this->assertIsString($scope['description']);
            $this->assertNotEmpty($scope['description']);
            $this->assertArrayHasKey('deprecated', $scope);
            $this->assertIsBool($scope['deprecated']);
        }

        // A specific scope has the expected description
        $usersRead = null;
        foreach ($response['body']['scopes'] as $scope) {
            if ($scope['$id'] === 'users.read') {
                $usersRead = $scope;
                break;
            }
        }
        $this->assertNotNull($usersRead);
        $this->assertEquals('Access to read users', $usersRead['description']);
    }

    public function testListOrganizationScopes(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/console/scopes/organization', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['total']);
        $this->assertIsArray($response['body']['scopes']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertEquals($response['body']['total'], \count($response['body']['scopes']));

        $scopeIds = \array_column($response['body']['scopes'], '$id');

        // Well-known scopes must be present
        $this->assertContains('projects.read', $scopeIds);
        $this->assertContains('projects.write', $scopeIds);

        // Every scope has the expected shape
        foreach ($response['body']['scopes'] as $scope) {
            $this->assertArrayHasKey('$id', $scope);
            $this->assertIsString($scope['$id']);
            $this->assertNotEmpty($scope['$id']);
            $this->assertArrayHasKey('description', $scope);
            $this->assertIsString($scope['description']);
            $this->assertNotEmpty($scope['description']);
            $this->assertArrayHasKey('deprecated', $scope);
            $this->assertIsBool($scope['deprecated']);
            $this->assertArrayHasKey('category', $scope);
            $this->assertIsString($scope['category']);
        }

        // A specific scope has the expected description
        $projectsRead = null;
        foreach ($response['body']['scopes'] as $scope) {
            if ($scope['$id'] === 'projects.read') {
                $projectsRead = $scope;
                break;
            }
        }
        $this->assertNotNull($projectsRead);
        $this->assertEquals('Access to read organization projects', $projectsRead['description']);
    }
}
