<?php

namespace Tests\E2E\Services\Badge;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;

class BadgeTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testGetSiteBadgeNotFound(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/badge/sites/nonexistentid1234567', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);
        $this->assertStringContainsString('<svg', $response['body']);
        $this->assertStringContainsString('not found', $response['body']);
        $this->assertStringContainsString('appwrite sites', $response['body']);
    }

    public function testGetFunctionBadgeNotFound(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/badge/functions/nonexistentid1234567', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);
        $this->assertStringContainsString('<svg', $response['body']);
        $this->assertStringContainsString('not found', $response['body']);
        $this->assertStringContainsString('appwrite functions', $response['body']);
    }

    /**
     * deploymentBadge defaults to false — a new site shows 'disabled' without any extra step.
     */
    public function testGetSiteBadgeDisabledByDefault(): void
    {
        $site = $this->client->call(Client::METHOD_POST, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'siteId' => ID::unique(),
            'name' => 'Badge Disabled Site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $this->assertEquals(201, $site['headers']['status-code']);
        $siteId = $site['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/badge/sites/' . $siteId, [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);
        $this->assertStringContainsString('<svg', $response['body']);
        $this->assertStringContainsString('disabled', $response['body']);
        $this->assertStringContainsString('appwrite sites', $response['body']);

        $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
    }

    /**
     * deploymentBadge defaults to false — a new function shows 'disabled' without any extra step.
     */
    public function testGetFunctionBadgeDisabledByDefault(): void
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Badge Disabled Function',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $functionId = $function['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/badge/functions/' . $functionId, [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);
        $this->assertStringContainsString('<svg', $response['body']);
        $this->assertStringContainsString('disabled', $response['body']);
        $this->assertStringContainsString('appwrite functions', $response['body']);

        $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
    }

    /**
     * When badge is explicitly enabled on a site with no deployments, it shows 'no deployment'.
     */
    public function testGetSiteBadgeNoDeployment(): void
    {
        $site = $this->client->call(Client::METHOD_POST, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'siteId' => ID::unique(),
            'name' => 'No Deployment Badge Site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $this->assertEquals(201, $site['headers']['status-code']);
        $siteId = $site['body']['$id'];

        $update = $this->client->call(Client::METHOD_PUT, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'No Deployment Badge Site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
            'deploymentBadge' => true,
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['deploymentBadge']);

        $response = $this->client->call(Client::METHOD_GET, '/badge/sites/' . $siteId, [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);
        $this->assertStringContainsString('<svg', $response['body']);
        $this->assertStringContainsString('no deployment', $response['body']);
        $this->assertStringContainsString('appwrite sites', $response['body']);
        $this->assertStringContainsString('no-cache', $response['headers']['cache-control'] ?? '');

        $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
    }

    /**
     * When badge is explicitly enabled on a function with no deployments, it shows 'no deployment'.
     */
    public function testGetFunctionBadgeNoDeployment(): void
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'No Deployment Badge Function',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $functionId = $function['body']['$id'];

        $update = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'No Deployment Badge Function',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'deploymentBadge' => true,
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['deploymentBadge']);

        $response = $this->client->call(Client::METHOD_GET, '/badge/functions/' . $functionId, [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);
        $this->assertStringContainsString('<svg', $response['body']);
        $this->assertStringContainsString('no deployment', $response['body']);
        $this->assertStringContainsString('appwrite functions', $response['body']);
        $this->assertStringContainsString('no-cache', $response['headers']['cache-control'] ?? '');

        $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
    }

    public function testBadgeEndpointsArePublic(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/badge/sites/nonexistentid1234567', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);

        $response = $this->client->call(Client::METHOD_GET, '/badge/functions/nonexistentid1234567', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);
    }
}
