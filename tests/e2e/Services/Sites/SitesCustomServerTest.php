<?php

namespace Tests\E2E\Services\Sites;

use Ahc\Jwt\JWT;
use Appwrite\Platform\Modules\Compute\Specification;
use Appwrite\Tests\Retry;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\System\System;

class SitesCustomServerTest extends Scope
{
    use SitesBase;
    use ProjectCustom;
    use SideServer;

    public function testListSpecs(): void
    {
        $specifications = $this->listSpecifications();
        $this->assertEquals(200, $specifications['headers']['status-code']);
        $this->assertGreaterThan(0, $specifications['body']['total']);
        $this->assertArrayHasKey(0, $specifications['body']['specifications']);
        $this->assertArrayHasKey('memory', $specifications['body']['specifications'][0]);
        $this->assertArrayHasKey('cpus', $specifications['body']['specifications'][0]);
        $this->assertArrayHasKey('enabled', $specifications['body']['specifications'][0]);
        $this->assertArrayHasKey('slug', $specifications['body']['specifications'][0]);

        $site = $this->createSite([
            'buildRuntime' => 'node-22',
            'framework' => 'other',
            'name' => 'Specs site',
            'siteId' => ID::unique(),
            'specification' => $specifications['body']['specifications'][0]['slug']
        ]);
        $this->assertEquals(201, $site['headers']['status-code']);
        $this->assertEquals($specifications['body']['specifications'][0]['slug'], $site['body']['specification']);

        $site = $this->getSite($site['body']['$id']);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($specifications['body']['specifications'][0]['slug'], $site['body']['specification']);

        $this->cleanupSite($site['body']['$id']);

        $site = $this->createSite([
            'buildRuntime' => 'node-22',
            'framework' => 'other',
            'name' => 'Specs site',
            'siteId' => ID::unique(),
            'specification' => 'cheap-please'
        ]);
        $this->assertEquals(400, $site['headers']['status-code']);
    }

    public function testCreateSite(): void
    {
        /**
         * Test for SUCCESS
         */
        $site = $this->createSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $siteId = $site['body']['$id'] ?? '';

        $dateValidator = new DateTimeValidator();
        $this->assertEquals(201, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['$id']);
        $this->assertEquals('Test Site', $site['body']['name']);
        $this->assertEquals('other', $site['body']['framework']);
        $this->assertEquals(true, $dateValidator->isValid($site['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($site['body']['$updatedAt']));
        $this->assertEquals('node-22', $site['body']['buildRuntime']);
        $this->assertEquals(null, $site['body']['fallbackFile']);
        $this->assertEquals('./', $site['body']['outputDirectory']);

        $variable = $this->createVariable($siteId, [
            'key' => 'siteKey1',
            'value' => 'siteValue1',
        ]);
        $variable2 = $this->createVariable($siteId, [
            'key' => 'siteKey2',
            'value' => 'siteValue2',
        ]);
        $variable3 = $this->createVariable($siteId, [
            'key' => 'siteKey3',
            'value' => 'siteValue3',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals(201, $variable2['headers']['status-code']);
        $this->assertEquals(201, $variable3['headers']['status-code']);

        $this->cleanupSite($siteId);
    }

    public function testConsoleAvailabilityEndpoint(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Test Site',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './',
            'fallbackFile' => '',
        ]);

        $this->assertNotEmpty($siteId);

        $domain = $this->setupSiteDomain($siteId);

        $response = $this->client->call(Client::METHOD_GET, '/console/resources', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'type' => 'rules',
            'value' => $domain,
        ]);

        $this->assertEquals(409, $response['headers']['status-code']); // domain unavailable

        $nonExistingDomain = "non-existent-subdomain.sites.localhost";

        $response = $this->client->call(Client::METHOD_GET, '/console/resources', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'type' => 'rules',
            'value' => $nonExistingDomain,
        ]);

        $this->assertEquals(204, $response['headers']['status-code']); // domain available

        $this->cleanupSite($siteId);

        $this->assertEventually(function () use ($siteId) {
            $rule = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'queries' => [
                    Query::equal('deploymentResourceId', [$siteId])
                ]
            ]);

            $this->assertEquals(200, $rule['headers']['status-code']);
            $this->assertEquals(0, $rule['body']['total']);
        }, 5000, 500);

        $response = $this->client->call(Client::METHOD_GET, '/console/resources', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'type' => 'rules',
            'value' => $domain,
        ]);

        $this->assertEquals(204, $response['headers']['status-code']); // domain available as site is deleted
    }

    public function testVariables(): void
    {
        $site = $this->createSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $siteId = $site['body']['$id'] ?? '';

        $this->assertEquals(201, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['$id']);
        $this->assertEquals('Test Site', $site['body']['name']);

        $variable = $this->createVariable($siteId, [
            'key' => 'siteKey1',
            'value' => 'siteValue1',
            'secret' => false,
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertNotEmpty($variable['body']['$id']);
        $this->assertEquals('siteKey1', $variable['body']['key']);
        $this->assertEquals('siteValue1', $variable['body']['value']);
        $this->assertEquals(false, $variable['body']['secret']);

        $variable2 = $this->createVariable($siteId, [
            'key' => 'siteKey2',
            'value' => 'siteValue2',
            'secret' => false,
        ]);

        $this->assertEquals(201, $variable2['headers']['status-code']);
        $this->assertNotEmpty($variable2['body']['$id']);
        $this->assertEquals('siteKey2', $variable2['body']['key']);
        $this->assertEquals('siteValue2', $variable2['body']['value']);
        $this->assertEquals(false, $variable2['body']['secret']);

        $secretVariable = $this->createVariable($siteId, [
            'key' => 'siteKey3',
            'value' => 'siteValue3',
            'secret' => true,
        ]);

        $this->assertEquals(201, $secretVariable['headers']['status-code']);
        $this->assertNotEmpty($secretVariable['body']['$id']);
        $this->assertEquals('siteKey3', $secretVariable['body']['key']);
        $this->assertEquals('', $secretVariable['body']['value']);
        $this->assertEquals(true, $secretVariable['body']['secret']);

        $variable = $this->getVariable($siteId, $variable['body']['$id']);

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertNotEmpty($variable['body']['$id']);
        $this->assertEquals('siteKey1', $variable['body']['key']);
        $this->assertEquals('siteValue1', $variable['body']['value']);
        $this->assertEquals(false, $variable['body']['secret']);

        $secretVariable = $this->getVariable($siteId, $secretVariable['body']['$id']);

        $this->assertEquals(200, $secretVariable['headers']['status-code']);
        $this->assertNotEmpty($secretVariable['body']['$id']);
        $this->assertEquals('siteKey3', $secretVariable['body']['key']);
        $this->assertEquals('', $secretVariable['body']['value']);
        $this->assertEquals(true, $secretVariable['body']['secret']);

        $variable = $this->updateVariable($siteId, $variable['body']['$id'], [
            'key' => 'siteKey1Updated',
            'value' => 'siteValue1Updated',
        ]);

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertNotEmpty($variable['body']['$id']);
        $this->assertEquals('siteKey1Updated', $variable['body']['key']);
        $this->assertEquals('siteValue1Updated', $variable['body']['value']);
        $this->assertEquals(false, $variable['body']['secret']);

        $variable = $this->updateVariable($siteId, $variable['body']['$id'], [
            'key' => 'siteKey1Updated',
            'secret' => true,
        ]);

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertNotEmpty($variable['body']['$id']);
        $this->assertEquals('siteKey1Updated', $variable['body']['key']);
        $this->assertEquals('', $variable['body']['value']);
        $this->assertEquals(true, $variable['body']['secret']);

        $secretVariable = $this->updateVariable($siteId, $secretVariable['body']['$id'], [
            'key' => 'siteKey3',
            'value' => 'siteValue3Updated',
        ]);

        $this->assertEquals(200, $secretVariable['headers']['status-code']);
        $this->assertNotEmpty($secretVariable['body']['$id']);
        $this->assertEquals('siteKey3', $secretVariable['body']['key']);
        $this->assertEquals('', $secretVariable['body']['value']);
        $this->assertEquals(true, $secretVariable['body']['secret']);

        $response = $this->updateVariable($siteId, $secretVariable['body']['$id'], [
            'key' => 'siteKey3',
            'secret' => false,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $secretVariable = $this->getVariable($siteId, $secretVariable['body']['$id']);

        $this->assertEquals(200, $secretVariable['headers']['status-code']);
        $this->assertNotEmpty($secretVariable['body']['$id']);
        $this->assertEquals('siteKey3', $secretVariable['body']['key']);
        $this->assertEquals('', $secretVariable['body']['value']);
        $this->assertEquals(true, $secretVariable['body']['secret']);

        $variables = $this->listVariables($siteId);

        $this->assertEquals(200, $variables['headers']['status-code']);
        $this->assertCount(3, $variables['body']['variables']);

        $response = $this->deleteVariable($siteId, $variable['body']['$id']);
        $this->assertEquals(204, $response['headers']['status-code']);
        $response = $this->deleteVariable($siteId, $variable2['body']['$id']);
        $this->assertEquals(204, $response['headers']['status-code']);
        $response = $this->deleteVariable($siteId, $secretVariable['body']['$id']);
        $this->assertEquals(204, $response['headers']['status-code']);

        $variables = $this->listVariables($siteId);

        $this->assertEquals(200, $variables['headers']['status-code']);
        $this->assertCount(0, $variables['body']['variables']);

        $this->cleanupSite($siteId);
    }

    // This is first Sites test with Proxy
    // If this fails, it may not be related to variables; but Router flow failing
    public function testVariablesE2E(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Astro site',
            'framework' => 'astro',
            'adapter' => 'ssr',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'npm run build',
            'installCommand' => 'npm install',
            'fallbackFile' => '',
        ]);

        $this->assertNotEmpty($siteId);

        $domain = $this->setupSiteDomain($siteId);

        $secretVariable = $this->createVariable($siteId, [
            'key' => 'name',
            'value' => 'Appwrite',
        ]);

        $this->assertEquals(201, $secretVariable['headers']['status-code']);
        $this->assertNotEmpty($secretVariable['body']['$id']);
        $this->assertEquals('name', $secretVariable['body']['key']);
        $this->assertEquals('', $secretVariable['body']['value']);
        $this->assertEquals(true, $secretVariable['body']['secret']);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('astro'),
            'activate' => 'true'
        ]);

        $this->assertNotEmpty($deploymentId);

        $domain = $this->getSiteDomain($siteId);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Env variable is Appwrite", $response['body']);
        $this->assertStringNotContainsString("Variable not found", $response['body']);

        $deployment = $this->getDeployment($siteId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $site = $this->getSite($siteId);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['deploymentId']);
        $this->assertNotEmpty($site['body']['deploymentCreatedAt']);
        $this->assertEquals($deployment['body']['$id'], $site['body']['deploymentId']);
        $this->assertEquals($deployment['body']['$createdAt'], $site['body']['deploymentCreatedAt']);

        $this->cleanupSite($siteId);
    }

    public function testAdapterDetectionAstroSSR(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Astro SSR site',
            'framework' => 'astro',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'npm run build',
            'installCommand' => 'npm install',
        ]);
        $this->assertNotEmpty($siteId);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertArrayHasKey('adapter', $site['body']);
        $this->assertEmpty($site['body']['adapter']);

        $domain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($domain);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('astro'),
            'activate' => 'true'
        ]);
        $this->assertNotEmpty($deploymentId);

        $this->assertEventually(function () use ($siteId, &$site) {
            $site = $this->getSite($siteId);
            $this->assertEquals('ssr', $site['body']['adapter']);
        });

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);
        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);

        $this->cleanupSite($siteId);
    }

    #[Retry(count: 3)]
    public function testAdapterDetectionAstroStatic(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Astro static site',
            'framework' => 'astro',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'npm run build',
            'installCommand' => 'npm install',
        ]);
        $this->assertNotEmpty($siteId);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertArrayHasKey('adapter', $site['body']);
        $this->assertEmpty($site['body']['adapter']);

        $domain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($domain);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('astro-static'),
            'activate' => 'true'
        ]);
        $this->assertNotEmpty($deploymentId);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertEquals('static', $site['body']['adapter']);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);
        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);

        $this->cleanupSite($siteId);
    }

    public function testAdapterDetectionStatic(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Static site',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'outputDirectory' => '',
            'buildCommand' => '',
            'installCommand' => '',
        ]);
        $this->assertNotEmpty($siteId);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertArrayHasKey('adapter', $site['body']);
        $this->assertEmpty($site['body']['adapter']);

        $domain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($domain);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'true'
        ]);
        $this->assertNotEmpty($deploymentId);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertEquals('static', $site['body']['adapter']);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);
        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);

        $this->cleanupSite($siteId);
    }

    public function testAdapterDetectionStaticSPA(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Static site',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'outputDirectory' => '',
            'buildCommand' => '',
            'installCommand' => '',
        ]);
        $this->assertNotEmpty($siteId);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertArrayHasKey('adapter', $site['body']);
        $this->assertArrayHasKey('fallbackFile', $site['body']);
        $this->assertEmpty($site['body']['adapter']);
        $this->assertEmpty($site['body']['fallbackFile']);

        $domain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($domain);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'true'
        ]);
        $this->assertNotEmpty($deploymentId);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertEquals('static', $site['body']['adapter']);
        $this->assertEquals('main.html', $site['body']['fallbackFile']);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);
        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('Main page', $response['body']);
        $response = $proxyClient->call(Client::METHOD_GET, '/something');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('Main page', $response['body']);

        $this->cleanupSite($siteId);
    }

    public function testSettingsForRollback(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Static site',
            'framework' => 'astro',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'npm run build',
            'installCommand' => 'npm install',
        ]);
        $this->assertNotEmpty($siteId);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertEmpty($site['body']['adapter']);
        $this->assertEmpty($site['body']['fallbackFile']);

        $domain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($domain);

        $deploymentId1 = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('astro-static'),
            'activate' => 'true'
        ]);
        $this->assertNotEmpty($deploymentId1);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertEquals('static', $site['body']['adapter']);
        $this->assertEquals('index.html', $site['body']['fallbackFile']);

        $site = $this->updateSite([
            'name' => 'SSR site',
            'framework' => 'astro',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'npm run build',
            'installCommand' => 'npm install',
            'adapter' => 'ssr',
            'fallbackFile' => '',
            '$id' => $siteId,
        ]);

        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertEquals('ssr', $site['body']['adapter']);
        $this->assertEmpty($site['body']['fallbackFile']);

        $deploymentId2 = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('astro'),
            'activate' => 'true'
        ]);
        $this->assertNotEmpty($deploymentId2);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);
        $this->assertEquals('ssr', $site['body']['adapter']);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);
        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Astro SSR", $response['body']);
        $response = $proxyClient->call(Client::METHOD_GET, '/not-found');
        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->updateSiteDeployment($siteId, $deploymentId1);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);
        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Astro static", $response['body']);
        $response = $proxyClient->call(Client::METHOD_GET, '/not-found');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Astro static", $response['body']);

        $this->cleanupSite($siteId);
    }

    public function testListSites(): void
    {
        /**
         * Test for SUCCESS
         */
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test List Sites',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $sites = $this->listSites([
            'search' => 'Test List Sites',
        ]);

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertCount(1, $sites['body']['sites']);
        $this->assertEquals($sites['body']['sites'][0]['name'], 'Test List Sites');

        // Test pagination limit
        $sites = $this->listSites([
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertCount(1, $sites['body']['sites']);

        // Test pagination offset
        $sites = $this->listSites([
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertCount(0, $sites['body']['sites']);

        // Test filter enabled
        $sites = $this->listSites([
            'queries' => [
                Query::equal('enabled', [true])->toString(),
            ],
        ]);

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertCount(1, $sites['body']['sites']);

        // Test filter disabled
        $sites = $this->listSites([
            'queries' => [
                Query::equal('enabled', [false])->toString(),
            ],
        ]);

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertCount(0, $sites['body']['sites']);

        // Test search name
        $sites = $this->listSites([
            'search' => 'Test List Sites'
        ]);

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertCount(1, $sites['body']['sites']);
        $this->assertEquals($sites['body']['sites'][0]['$id'], $siteId);

        // Test search framework
        $sites = $this->listSites([
            'search' => 'other'
        ]);

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertCount(1, $sites['body']['sites']);
        $this->assertEquals($sites['body']['sites'][0]['$id'], $siteId);

        /**
         * Test pagination
         */
        $siteId2 = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test List Sites 2',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $sites = $this->listSites([
            'search' => 'Test List Sites',
        ]);

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertEquals($sites['body']['total'], 2);
        $this->assertIsArray($sites['body']['sites']);
        $this->assertCount(2, $sites['body']['sites']);
        $this->assertEquals($sites['body']['sites'][0]['name'], 'Test List Sites');
        $this->assertEquals($sites['body']['sites'][1]['name'], 'Test List Sites 2');

        $sites1 = $this->listSites([
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $sites['body']['sites'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals($sites1['headers']['status-code'], 200);
        $this->assertCount(1, $sites1['body']['sites']);
        $this->assertEquals($sites1['body']['sites'][0]['name'], 'Test List Sites 2');

        $sites2 = $this->listSites([
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $sites['body']['sites'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals($sites2['headers']['status-code'], 200);
        $this->assertCount(1, $sites2['body']['sites']);
        $this->assertEquals($sites2['body']['sites'][0]['name'], 'Test List Sites');

        /**
         * Test for FAILURE
         */
        $sites = $this->listSites([
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);
        $this->assertEquals($sites['headers']['status-code'], 400);

        $this->cleanupSite($siteId);
        $this->cleanupSite($siteId2);
    }

    public function testGetSite(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        /**
         * Test for SUCCESS
         */
        $site = $this->getSite($siteId);

        $this->assertEquals($site['headers']['status-code'], 200);
        $this->assertEquals($site['body']['name'], 'Test Site');

        /**
         * Test for FAILURE
         */
        $site = $this->getSite('x');

        $this->assertEquals($site['headers']['status-code'], 404);

        $this->cleanupSite($siteId);
    }

    public function testUpdateSite(): void
    {
        $site = $this->createSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $siteId = $site['body']['$id'] ?? '';

        $this->assertEquals(201, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['$id']);
        $this->assertEquals('Test Site', $site['body']['name']);

        $site = $this->updateSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site Updated',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            '$id' => $siteId,
            'installCommand' => 'npm install'
        ]);

        $dateValidator = new DatetimeValidator();

        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['$id']);
        $this->assertEquals('Test Site Updated', $site['body']['name']);
        $this->assertEquals(true, $dateValidator->isValid($site['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($site['body']['$updatedAt']));
        $this->assertEquals('npm install', $site['body']['installCommand']);

        $this->cleanupSite($siteId);
    }

    // public function testCreateDeploymentFromCLI() {
    //     // TODO: Implement testCreateDeploymentFromCLI() later
    // }

    public function testCreateDeployment()
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'siteId' => $siteId,
            'code' => $this->packageSite('static-single-file'),
            'activate' => true,
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals('waiting', $deployment['body']['status']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));

        $deploymentIdActive = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentIdActive) {
            $deployment = $this->getDeployment($siteId, $deploymentIdActive);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'false'
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deploymentIdInactive = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentIdInactive) {
            $deployment = $this->getDeployment($siteId, $deploymentIdInactive);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        $site = $this->getSite($siteId);

        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($deploymentIdActive, $site['body']['deploymentId']);
        $this->assertNotEquals($deploymentIdInactive, $site['body']['deploymentId']);

        $this->cleanupDeployment($siteId, $deploymentIdActive);
        $this->cleanupDeployment($siteId, $deploymentIdInactive);
        $this->cleanupSite($siteId);
    }

    #[Retry(count: 3)]
    public function testCancelDeploymentBuild(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'false'
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);

            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertEquals('building', $deployment['body']['status']);
        }, 100000, 250);

        $deployment = $this->cancelDeployment($siteId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals('canceled', $deployment['body']['status']);

        // Ensures worker got eventually aware of cancellation and reacted properly
        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertStringContainsString('Build has been canceled.', $deployment['body']['buildLogs']);
        });

        $deployment = $this->getDeployment($siteId, $deploymentId);

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals('canceled', $deployment['body']['status']);

        $this->cleanupDeployment($siteId, $deploymentId);
        $this->cleanupSite($siteId);
    }

    public function testUpdateDeployment(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'false'
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        /**
         * Test for SUCCESS
         */
        $dateValidator = new DatetimeValidator();

        $response = $this->updateSiteDeployment($siteId, $deploymentId);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, $dateValidator->isValid($response['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($response['body']['$updatedAt']));
        $this->assertEquals($deploymentId, $response['body']['deploymentId']);

        $this->cleanupDeployment($siteId, $deploymentId);
        $this->cleanupSite($siteId);
    }

    public function testListDeployments(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'false'
        ]);

        $deploymentIdActive = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'false'
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deploymentIdInactive = $deployment['body']['$id'] ?? '';

        $deployments = $this->listDeployments($siteId);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals($deployments['body']['total'], 2);
        $this->assertIsArray($deployments['body']['deployments']);
        $this->assertCount(2, $deployments['body']['deployments']);
        $this->assertArrayHasKey('sourceSize', $deployments['body']['deployments'][0]);
        $this->assertArrayHasKey('buildSize', $deployments['body']['deployments'][0]);

        $deployments = $this->listDeployments($siteId, [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertCount(1, $deployments['body']['deployments']);

        $deployments = $this->listDeployments($siteId, [
            'queries' => [
                Query::select(['status'])->toString(),
            ],
        ]);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertArrayHasKey('status', $deployments['body']['deployments'][0]);
        $this->assertArrayHasKey('status', $deployments['body']['deployments'][1]);
        $this->assertArrayNotHasKey('sourceSize', $deployments['body']['deployments'][0]);
        $this->assertArrayNotHasKey('sourceSize', $deployments['body']['deployments'][1]);

        // Extra select query check, for attribute not allowed by filter queries
        $deployments = $this->listDeployments($siteId, [
            'queries' => [
                Query::select(['buildLogs'])->toString(),
            ],
        ]);
        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertArrayHasKey('buildLogs', $deployments['body']['deployments'][0]);
        $this->assertArrayHasKey('buildLogs', $deployments['body']['deployments'][1]);
        $this->assertArrayNotHasKey('sourceSize', $deployments['body']['deployments'][0]);
        $this->assertArrayNotHasKey('sourceSize', $deployments['body']['deployments'][1]);

        $deployments = $this->listDeployments($siteId, [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertCount(1, $deployments['body']['deployments']);

        $deployments = $this->listDeployments(
            $siteId,
            [
                'queries' => [
                    Query::equal('type', ['manual'])->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(2, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $siteId,
            [
                'queries' => [
                    Query::equal('type', ['vcs'])->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(0, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $siteId,
            [
                'queries' => [
                    Query::equal('type', ['invalid-string'])->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(0, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $siteId,
            [
                'queries' => [
                    Query::greaterThan('sourceSize', 10000)->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(0, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $siteId,
            [
                'queries' => [
                    Query::greaterThan('sourceSize', 0)->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(2, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $siteId,
            [
                'queries' => [
                    Query::greaterThan('sourceSize', -100)->toString(),
                ],
            ]
        );
        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(2, $deployments['body']['total']);

        /**
         * Ensure size output and size filters work exactly.
         * Prevents buildSize being counted towards deployment size
         */
        $deployments = $this->listDeployments(
            $siteId,
            [
                Query::limit(1)->toString(),
            ]
        );

        $this->assertEquals(200, $deployments['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $deployments['body']['total']);
        $this->assertNotEmpty($deployments['body']['deployments'][0]['$id']);
        $this->assertNotEmpty($deployments['body']['deployments'][0]['sourceSize']);

        $deploymentId = $deployments['body']['deployments'][0]['$id'];
        $deploymentSize = $deployments['body']['deployments'][0]['sourceSize'];

        $deployments = $this->listDeployments(
            $siteId,
            [
                'queries' => [
                    Query::equal('sourceSize', [$deploymentSize])->toString(),
                ],
            ]
        );

        $this->assertEquals(200, $deployments['headers']['status-code']);
        $this->assertGreaterThan(0, $deployments['body']['total']);

        $matchingDeployment = array_filter(
            $deployments['body']['deployments'],
            fn ($deployment) => $deployment['$id'] === $deploymentId
        );

        $this->assertNotEmpty($matchingDeployment, "Deployment with ID {$deploymentId} not found");

        if (!empty($matchingDeployment)) {
            $deployment = reset($matchingDeployment);
            $this->assertEquals($deploymentSize, $deployment['sourceSize']);
        }

        $this->cleanupDeployment($siteId, $deploymentIdActive);
        $this->cleanupDeployment($siteId, $deploymentIdInactive);
        $this->cleanupSite($siteId);
    }

    public function testGetDeployment(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'false'
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        /**
         * Test for SUCCESS
         */
        $deployment = $this->getDeployment($siteId, $deploymentId);

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['buildDuration']);
        $this->assertNotEmpty($deployment['body']['status']);
        $this->assertNotEmpty($deployment['body']['buildLogs']);
        $this->assertArrayHasKey('sourceSize', $deployment['body']);
        $this->assertArrayHasKey('buildSize', $deployment['body']);

        /**
         * Test for FAILURE
         */
        $deployment = $this->getDeployment($siteId, 'x');

        $this->assertEquals($deployment['headers']['status-code'], 404);

        $this->cleanupDeployment($siteId, $deploymentId);
        $this->cleanupSite($siteId);
    }

    public function testUpdateSpecs(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        /**
         * Test for SUCCESS
         */
        // Change the function specs
        $site = $this->updateSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            '$id' => $siteId,
            'specification' => Specification::S_1VCPU_1GB,
        ]);

        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['$id']);
        $this->assertEquals(Specification::S_1VCPU_1GB, $site['body']['specification']);

        // Change the specs to 1vcpu 512mb
        $site = $this->updateSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            '$id' => $siteId,
            'specification' => Specification::S_1VCPU_512MB,
        ]);

        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['$id']);
        $this->assertEquals(Specification::S_1VCPU_512MB, $site['body']['specification']);

        /**
         * Test for FAILURE
         */

        $site = $this->updateSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            '$id' => $siteId,
            'specification' => 's-2vcpu-512mb', // Invalid specification
        ]);

        $this->assertEquals(400, $site['headers']['status-code']);
        $this->assertStringStartsWith('Invalid `specification` param: Specification must be one of:', $site['body']['message']);

        $this->cleanupSite($siteId);
    }

    public function testDeleteDeployment(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'false'
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        /**
         * Test for SUCCESS
         */
        $deployment = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $deployment['headers']['status-code']);
        $this->assertEmpty($deployment['body']);

        $deployment = $this->getDeployment($siteId, $deploymentId);

        $this->assertEquals(404, $deployment['headers']['status-code']);
    }

    public function testDeleteSite(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $site = $this->deleteSite($siteId);

        $this->assertEquals(204, $site['headers']['status-code']);
        $this->assertEmpty($site['body']);

        $function = $this->getSite($siteId);

        $this->assertEquals(404, $function['headers']['status-code']);
    }

    public function testGetFrameworks(): void
    {
        $frameworks = $this->client->call(Client::METHOD_GET, '/sites/frameworks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(200, $frameworks['headers']['status-code']);
        $this->assertGreaterThan(0, $frameworks['body']['total']);

        $framework = $frameworks['body']['frameworks'][0];

        $this->assertArrayHasKey('name', $framework);
        $this->assertArrayHasKey('key', $framework);
        $this->assertArrayHasKey('buildRuntime', $framework);
        $this->assertArrayHasKey('runtimes', $framework);
        $this->assertArrayHasKey('adapters', $framework);
        $this->assertIsArray($framework['adapters']);
        $this->assertArrayHasKey('key', $framework['adapters'][0]);
        $this->assertArrayHasKey('installCommand', $framework['adapters'][0]);
        $this->assertArrayHasKey('buildCommand', $framework['adapters'][0]);
        $this->assertArrayHasKey('outputDirectory', $framework['adapters'][0]);
    }

    public function testSiteStatic(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Non-SPA site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $this->assertNotEmpty($siteId);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static-spa'),
            'activate' => 'true'
        ]);

        $this->assertNotEmpty($deploymentId);

        $domain = $this->setupSiteDomain($siteId);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Index page", $response['body']);

        $this->assertArrayHasKey('x-appwrite-log-id', $response['headers']);
        $this->assertNotEmpty($response['headers']['x-appwrite-log-id']);

        $response = $proxyClient->call(Client::METHOD_GET, '/contact', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Contact page", $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/non-existing', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertStringContainsString("Page not found", $response['body']); // Title
        $this->assertStringContainsString("Go to homepage", $response['body']); // Button
        $this->assertStringNotContainsString("Powered by", $response['body']); // Brand

        $this->cleanupSite($siteId);
    }

    public function testSiteStaticSPA(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'SPA site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '404.html',
        ]);

        $this->assertNotEmpty($siteId);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static-spa'),
            'activate' => 'true'
        ]);

        $this->assertNotEmpty($deploymentId);

        $domain = $this->setupSiteDomain($siteId);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Index page", $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/contact', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Contact page", $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/non-existing', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Customized 404 page", $response['body']);
        $this->assertStringNotContainsString("Powered by", $response['body']); //brand

        $this->cleanupSite($siteId);
    }

    #[Retry(count: 3)]
    public function testSiteTemplate(): void
    {
        $template = $this->getTemplate('playground-for-astro');
        $this->assertEquals(200, $template['headers']['status-code']);

        $template = $template['body'];

        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Template site',
            'framework' => $template['frameworks'][0]['key'],
            'adapter' => $template['frameworks'][0]['adapter'],
            'buildRuntime' => $template['frameworks'][0]['buildRuntime'],
            'outputDirectory' => $template['frameworks'][0]['outputDirectory'],
            'buildCommand' => $template['frameworks'][0]['buildCommand'],
            'installCommand' => $template['frameworks'][0]['installCommand'],
            'fallbackFile' => $template['frameworks'][0]['fallbackFile'],
        ]);

        $this->assertNotEmpty($siteId);

        $deployment = $this->createTemplateDeployment($siteId, [
            'repository' => $template['providerRepositoryId'],
            'owner' => $template['providerOwner'],
            'rootDirectory' => $template['frameworks'][0]['providerRootDirectory'],
            'type' => 'tag',
            'reference' => $template['providerVersion'],
            'activate' => true
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deployment = $this->getDeployment($siteId, $deployment['body']['$id']);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals(0, $deployment['body']['sourceSize']);
        $this->assertEquals(0, $deployment['body']['buildSize']);
        $this->assertEquals(0, $deployment['body']['totalSize']);

        $this->assertEventually(function () use ($siteId) {
            $site = $this->getSite($siteId);
            $this->assertNotEmpty($site['body']['deploymentId']);
        }, 50000, 500);

        $domain = $this->setupSiteDomain($siteId);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Astro Blog", $response['body']);
        $this->assertStringContainsString("Hello, Astronaut!", $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/about');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Astro Blog", $response['body']);
        $this->assertStringContainsString("About Me", $response['body']);

        $deployment = $this->getDeployment($siteId, $deployment['body']['$id']);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $this->cleanupSite($siteId);
    }

    public function testCreateSiteFromTemplateBranch()
    {
        $template = $this->getTemplate('playground-for-astro');
        $this->assertEquals(200, $template['headers']['status-code']);

        $template = $template['body'];

        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Astro Blog - Branch Test',
            'framework' => $template['frameworks'][0]['key'],
            'adapter' => $template['frameworks'][0]['adapter'],
            'buildRuntime' => $template['frameworks'][0]['buildRuntime'],
            'outputDirectory' => $template['frameworks'][0]['outputDirectory'],
            'buildCommand' => $template['frameworks'][0]['buildCommand'],
            'installCommand' => $template['frameworks'][0]['installCommand'],
            'fallbackFile' => $template['frameworks'][0]['fallbackFile'],
        ]);

        $this->assertNotEmpty($siteId);

        // Deploy using branch
        $deployment = $this->createTemplateDeployment($siteId, [
            'repository' => $template['providerRepositoryId'],
            'owner' => $template['providerOwner'],
            'rootDirectory' => $template['frameworks'][0]['providerRootDirectory'],
            'type' => 'branch',
            'reference' => 'main',
            'activate' => true
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deployment = $this->getDeployment($siteId, $deployment['body']['$id']);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals(0, $deployment['body']['sourceSize']);
        $this->assertEquals(0, $deployment['body']['buildSize']);
        $this->assertEquals(0, $deployment['body']['totalSize']);

        $this->assertEventually(function () use ($siteId) {
            $site = $this->getSite($siteId);
            $this->assertNotEmpty($site['body']['deploymentId']);
        }, 50000, 500);

        $domain = $this->setupSiteDomain($siteId);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Astro Blog", $response['body']);
        $this->assertStringContainsString("Hello, Astronaut!", $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/about');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Astro Blog", $response['body']);
        $this->assertStringContainsString("About Me", $response['body']);

        $deployment = $this->getDeployment($siteId, $deployment['body']['$id']);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $this->cleanupSite($siteId);
    }

    public function testCreateSiteFromTemplateCommit()
    {
        $template = $this->getTemplate('playground-for-astro');
        $this->assertEquals(200, $template['headers']['status-code']);

        // Get latest commit using helper function
        $latestCommit = $this->helperGetLatestCommit(
            $template['body']['providerOwner'],
            $template['body']['providerRepositoryId']
        );
        $this->assertNotNull($latestCommit);

        $template = $template['body'];

        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Astro Blog - Commit Test',
            'framework' => $template['frameworks'][0]['key'],
            'adapter' => $template['frameworks'][0]['adapter'],
            'buildRuntime' => $template['frameworks'][0]['buildRuntime'],
            'outputDirectory' => $template['frameworks'][0]['outputDirectory'],
            'buildCommand' => $template['frameworks'][0]['buildCommand'],
            'installCommand' => $template['frameworks'][0]['installCommand'],
            'fallbackFile' => $template['frameworks'][0]['fallbackFile'],
        ]);

        $this->assertNotEmpty($siteId);

        // Deploy using commit
        $deployment = $this->createTemplateDeployment($siteId, [
            'repository' => $template['providerRepositoryId'],
            'owner' => $template['providerOwner'],
            'rootDirectory' => $template['frameworks'][0]['providerRootDirectory'],
            'type' => 'commit',
            'reference' => $latestCommit,
            'activate' => true
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deployment = $this->getDeployment($siteId, $deployment['body']['$id']);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals(0, $deployment['body']['sourceSize']);
        $this->assertEquals(0, $deployment['body']['buildSize']);
        $this->assertEquals(0, $deployment['body']['totalSize']);

        $this->assertEventually(function () use ($siteId) {
            $site = $this->getSite($siteId);
            $this->assertNotEmpty($site['body']['deploymentId']);
        }, 50000, 500);

        $domain = $this->setupSiteDomain($siteId);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Astro Blog", $response['body']);
        $this->assertStringContainsString("Hello, Astronaut!", $response['body']);

        $response = $proxyClient->call(Client::METHOD_GET, '/about');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Astro Blog", $response['body']);
        $this->assertStringContainsString("About Me", $response['body']);

        $deployment = $this->getDeployment($siteId, $deployment['body']['$id']);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $this->cleanupSite($siteId);
    }

    public function testSiteDomainReclaiming(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Startup site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $this->assertNotEmpty($siteId);

        $subdomain = 'startup' . \uniqid();
        $domain = $this->setupSiteDomain($siteId, $subdomain);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => 'true'
        ]);

        $this->assertNotEmpty($deploymentId);

        $domain = $this->getSiteDomain($siteId);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringNotContainsString("This domain is not connected to any Appwrite resource yet", $response['body']);

        $site2 = $this->createSite([
            'siteId' => ID::unique(),
            'name' => 'Startup 2 site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $siteId2 = $site2['body']['$id'];

        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/site', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $subdomain . '.' . System::getEnv('_APP_DOMAIN_SITES', ''),
            'siteId' => $siteId2,
        ]);

        $this->assertEquals(409, $rule['headers']['status-code']);

        $this->cleanupSite($siteId);

        $this->assertEventually(function () use ($domain) {
            $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'queries' => [
                    Query::equal('domain', [$domain])->toString(),
                ],
            ]);

            $this->assertEquals(200, $rules['headers']['status-code']);
            $this->assertEquals(0, $rules['body']['total']);
        }, 50000, 500);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertStringContainsString("This page is empty, but you can make it yours.", $response['body']);

        $site = $this->createSite([
            'siteId' => ID::unique(),
            'name' => 'Startup 2 site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $this->assertEquals(201, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['$id']);

        $siteId = $site['body']['$id'];

        $domain = $this->setupSiteDomain($siteId, $subdomain);

        $this->assertNotEmpty($domain);

        $this->cleanupSite($site['body']['$id']);
    }

    public function testSitePreviewBranding(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'A site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $this->assertNotEmpty($siteId);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => 'true'
        ]);
        $this->assertNotEmpty($deploymentId);

        $siteDomain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($siteDomain);

        $deploymentDomain = $this->getDeploymentDomain($deploymentId);
        $this->assertNotEmpty($deploymentDomain);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $siteDomain);
        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Hello Appwrite", $response['body']);
        $this->assertStringNotContainsString("Preview by", $response['body']);
        $contentLength = $response['headers']['content-length'];

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $deploymentDomain);
        $response = $proxyClient->call(Client::METHOD_GET, '/', followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringContainsString('/console/auth/preview', $response['headers']['location']);

        $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 0);
        $apiKey = $jwtObj->encode([
            'projectCheckDisabled' => true,
            'previewAuthDisabled' => true,
        ]);
        $response = $proxyClient->call(Client::METHOD_GET, '/', followRedirects: false, headers: [
            'x-appwrite-key' => API_KEY_DYNAMIC . '_' . $apiKey,
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Hello Appwrite", $response['body']);
        $this->assertStringContainsString("Preview by", $response['body']);
        $this->assertGreaterThan($contentLength, $response['headers']['content-length']);

        $response = $proxyClient->call(Client::METHOD_GET, '/non-existing-path', followRedirects: false, headers: [
            'x-appwrite-key' => API_KEY_DYNAMIC . '_' . $apiKey,
        ]);
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertStringContainsString("Page not found", $response['body']);
        $this->assertStringNotContainsString("Preview by", $response['body']);
        $this->assertGreaterThan($contentLength, $response['headers']['content-length']);

        $this->cleanupSite($siteId);
    }

    public function testSiteCors(): void
    {
        // Create rule together with site
        $subdomain = 'startup' . \uniqid();

        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Startup site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
            'subdomain' => $subdomain
        ]);

        $this->assertNotEmpty($siteId);

        $this->setupSiteDomain($siteId, $subdomain);
        $domain = $this->getSiteDomain($siteId);

        $this->assertNotEmpty($domain);

        $url = 'http://' . $domain;

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'referer' => $url,
            'origin' => $url,
        ]));

        $this->assertEquals($url, $response['headers']['access-control-allow-origin']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'unknown',
            'referer' => $url,
            'origin' => $url,
        ]));

        $this->assertArrayNotHasKey('access-control-allow-origin', $response['headers']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'referer' => 'http://unknown.com',
            'origin' => 'http://unknown.com'
        ]));

        $this->assertArrayNotHasKey('access-control-allow-origin', $response['headers']);
    }

    public function testSiteDownload(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'adapter' => 'static',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => true
        ]);

        $this->assertNotEmpty($deploymentId);

        $response = $this->getDeploymentDownload($siteId, $deploymentId, 'source');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('application/gzip', $response['headers']['content-type']);
        $this->assertGreaterThan(0, $response['headers']['content-length']);
        $this->assertGreaterThan(0, \strlen($response['body']));

        $deploymentMd5 = \md5($response['body']);

        $response = $this->getDeploymentDownload($siteId, $deploymentId, 'output');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('application/gzip', $response['headers']['content-type']);
        $this->assertGreaterThan(0, $response['headers']['content-length']);
        $this->assertGreaterThan(0, \strlen($response['body']));

        $buildMd5 = \md5($response['body']);

        $this->assertNotEquals($deploymentMd5, $buildMd5);

        $this->cleanupSite($siteId);
    }

    public function testSSRLogs(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'SSR site',
            'framework' => 'astro',
            'adapter' => 'ssr',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'npm run build',
            'installCommand' => 'npm install',
            'fallbackFile' => '',
        ]);

        $this->assertNotEmpty($siteId);

        $domain = $this->setupSiteDomain($siteId);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('astro'),
            'activate' => 'true'
        ]);

        $this->assertNotEmpty($deploymentId);

        $domain = $this->getSiteDomain($siteId);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/logs-inline');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Inline logs printed.", $response['body']);

        $logs = $this->listLogs($siteId, [
            Query::orderDesc('$createdAt')->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertStringContainsString($deploymentId, $logs['body']['executions'][0]['deploymentId']);
        $this->assertStringContainsString("GET", $logs['body']['executions'][0]['requestMethod']);
        $this->assertStringContainsString("/logs-inline", $logs['body']['executions'][0]['requestPath']);
        $this->assertStringContainsString("Log1", $logs['body']['executions'][0]['logs']);
        $this->assertStringContainsString("Log2", $logs['body']['executions'][0]['logs']);
        $this->assertStringContainsString("Error1", $logs['body']['executions'][0]['errors']);
        $this->assertStringContainsString("Error2", $logs['body']['executions'][0]['errors']);
        $log1Id = $logs['body']['executions'][0]['$id'];
        $this->assertNotEmpty($log1Id);

        $logs = $this->listLogs($siteId, [
            Query::orderDesc('$createdAt')->toString(),
            Query::limit(1)->toString(),
            Query::equal('deploymentId', [$deploymentId])->toString()
        ]);
        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $logs['body']['total']);
        $this->assertCount(1, $logs['body']['executions']);

        $logs = $this->listLogs($siteId, [
            Query::orderDesc('$createdAt')->toString(),
            Query::limit(1)->toString(),
            Query::equal('deploymentId', ['some-random-id'])->toString()
        ]);
        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertEquals(0, $logs['body']['total']);
        $this->assertCount(0, $logs['body']['executions']);

        $response = $proxyClient->call(Client::METHOD_GET, '/logs-action');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Action logs printed.", $response['body']);

        $logs = $this->listLogs($siteId, [
            Query::orderDesc('$createdAt')->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertStringContainsString($deploymentId, $logs['body']['executions'][0]['deploymentId']);
        $this->assertStringContainsString("GET", $logs['body']['executions'][0]['requestMethod']);
        $this->assertStringContainsString("/logs-action", $logs['body']['executions'][0]['requestPath']);
        $this->assertStringContainsString("Log1", $logs['body']['executions'][0]['logs']);
        $this->assertStringContainsString("Log2", $logs['body']['executions'][0]['logs']);
        $this->assertStringContainsString("Error1", $logs['body']['executions'][0]['errors']);
        $this->assertStringContainsString("Error2", $logs['body']['executions'][0]['errors']);
        $log2Id = $logs['body']['executions'][0]['$id'];
        $this->assertNotEmpty($log2Id);

        $this->assertNotEquals($log1Id, $log2Id);

        $site = $this->updateSite(
            [
                '$id' => $siteId,
                'name' => 'SSR site',
                'framework' => 'astro',
                'adapter' => 'ssr',
                'buildRuntime' => 'node-22',
                'outputDirectory' => './dist',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'fallbackFile' => '',
                'logging' => false // set logging to false
            ]
        );
        $this->assertEquals(200, $site['headers']['status-code']);
        $response = $proxyClient->call(Client::METHOD_GET, '/logs-inline');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Inline logs printed.", $response['body']);

        $logs = $this->listLogs($siteId, [
            Query::orderDesc('$createdAt')->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertEquals("GET", $logs['body']['executions'][0]['requestMethod']);
        $this->assertEquals("/logs-inline", $logs['body']['executions'][0]['requestPath']);
        $this->assertEmpty($logs['body']['executions'][0]['logs']);
        $this->assertEmpty($logs['body']['executions'][0]['logs']);
        $this->assertEmpty($logs['body']['executions'][0]['errors']);
        $this->assertEmpty($logs['body']['executions'][0]['errors']);
        $log1Id = $logs['body']['executions'][0]['$id'];
        $this->assertNotEmpty($log1Id);

        $response = $proxyClient->call(Client::METHOD_GET, '/logs-action');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Action logs printed.", $response['body']);

        $logs = $this->listLogs($siteId, [
            Query::orderDesc('$createdAt')->toString(),
            Query::limit(1)->toString(),
        ]);
        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertEquals("GET", $logs['body']['executions'][0]['requestMethod']);
        $this->assertEquals("/logs-action", $logs['body']['executions'][0]['requestPath']);
        $this->assertEmpty($logs['body']['executions'][0]['logs']);
        $this->assertEmpty($logs['body']['executions'][0]['logs']);
        $this->assertEmpty($logs['body']['executions'][0]['errors']);
        $this->assertEmpty($logs['body']['executions'][0]['errors']);
        $log2Id = $logs['body']['executions'][0]['$id'];
        $this->assertNotEmpty($log2Id);

        $this->cleanupSite($siteId);
    }

    public function testDuplicateDeployment(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'framework' => 'other',
            'name' => 'Duplicate deployment Site',
            'adapter' => 'static',
            'fallbackFile' => '404.html',
            'siteId' => ID::unique()
        ]);
        $this->assertNotEmpty($siteId);

        $domain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($domain);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $deploymentId1 = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static-spa'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentId1);

        $response = $proxyClient->call(Client::METHOD_GET, '/not-found');
        $this->assertStringContainsString("Customized 404 page", $response['body']);

        $site = $this->updateSite([
            '$id' => $siteId,
            'buildRuntime' => 'node-22',
            'framework' => 'other',
            'name' => 'Duplicate deployment Site',
            'adapter' => 'static',
            'fallbackFile' => 'index.html',
        ]);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals('index.html', $site['body']['fallbackFile']);

        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments/duplicate', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-sdk-language' => 'cli'
        ], $this->getHeaders()), [
            'deploymentId' => $deploymentId1,
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId2 = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId2);

        $deployment = $this->getDeployment($siteId, $deploymentId2);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertEquals(0, $deployment['body']['buildSize']);
        $this->assertEquals($deployment['body']['sourceSize'], $deployment['body']['totalSize']);
        $this->assertEquals('cli', $deployment['body']['type']);

        // create another duplicate deployment with manual trigger
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments/duplicate', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'deploymentId' => $deploymentId1,
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId2 = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId2);

        $deployment = $this->getDeployment($siteId, $deploymentId2);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertEquals(0, $deployment['body']['buildSize']);
        $this->assertEquals($deployment['body']['sourceSize'], $deployment['body']['totalSize']);
        $this->assertEquals('manual', $deployment['body']['type']);

        $this->assertEventually(function () use ($siteId, $deploymentId2) {
            $site = $this->getSite($siteId);
            $this->assertEquals($deploymentId2, $site['body']['deploymentId']);
        }, 50000, 500);

        $response = $proxyClient->call(Client::METHOD_GET, '/not-found');
        $this->assertStringContainsString("Index page", $response['body']);

        $deployment = $this->getDeployment($siteId, $deploymentId2);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertGreaterThan(0, $deployment['body']['sourceSize']);
        $this->assertGreaterThan(0, $deployment['body']['buildSize']);
        $totalSize = $deployment['body']['sourceSize'] + $deployment['body']['buildSize'];
        $this->assertEquals($totalSize, $deployment['body']['totalSize']);

        $this->cleanupSite($siteId);
    }

    public function testUpdateDeploymentStatus(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'framework' => 'other',
            'name' => 'Activate test Site',
            'siteId' => ID::unique(),
            'adapter' => 'static',
        ]);
        $this->assertNotEmpty($siteId);

        $site = $this->getSite($siteId);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertArrayHasKey('latestDeploymentId', $site['body']);
        $this->assertArrayHasKey('latestDeploymentCreatedAt', $site['body']);
        $this->assertArrayHasKey('latestDeploymentStatus', $site['body']);
        $this->assertEmpty($site['body']['latestDeploymentId']);
        $this->assertEmpty($site['body']['latestDeploymentCreatedAt']);
        $this->assertEmpty($site['body']['latestDeploymentStatus']);

        $domain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($domain);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $deploymentId1 = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentId1);

        $site = $this->getSite($siteId);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($deploymentId1, $site['body']['latestDeploymentId']);
        $this->assertEquals('ready', $site['body']['latestDeploymentStatus']);

        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('Hello Appwrite', $response['body']);

        $deploymentId2 = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static-spa'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentId2);

        $site = $this->getSite($siteId);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($deploymentId2, $site['body']['latestDeploymentId']);
        $this->assertEquals('ready', $site['body']['latestDeploymentStatus']);

        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('Index page', $response['body']);

        $site = $this->getSite($siteId);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($deploymentId2, $site['body']['deploymentId']);
        $this->assertEquals($deploymentId2, $site['body']['latestDeploymentId']);
        $this->assertEquals('ready', $site['body']['latestDeploymentStatus']);

        $site = $this->updateSiteDeployment($siteId, $deploymentId1);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($deploymentId1, $site['body']['deploymentId']);

        $site = $this->getSite($siteId);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($deploymentId1, $site['body']['deploymentId']);
        $this->assertEquals($deploymentId2, $site['body']['latestDeploymentId']);
        $this->assertEquals('ready', $site['body']['latestDeploymentStatus']);

        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('Hello Appwrite', $response['body']);

        $deployment = $this->deleteDeployment($siteId, $deploymentId2);
        $this->assertEquals(204, $deployment['headers']['status-code']);

        $site = $this->getSite($siteId);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($deploymentId1, $site['body']['latestDeploymentId']);
        $this->assertEquals('ready', $site['body']['latestDeploymentStatus']);

        $this->cleanupSite($siteId);
    }

    public function testPreviewDomain(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'framework' => 'other',
            'name' => 'Authorized preview site',
            'siteId' => ID::unique(),
            'adapter' => 'static',
        ]);
        $this->assertNotEmpty($siteId);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentId);

        $domain = $this->getDeploymentDomain($deploymentId);
        $this->assertNotEmpty($domain);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/contact', followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringContainsString('/console/auth/preview', $response['headers']['location']);
        $this->assertStringContainsString('projectId=' . $this->getProject()['$id'], $response['headers']['location']);
        $this->assertStringContainsString('origin=', $response['headers']['location']);
        $this->assertStringContainsString('path=%2Fcontact', $response['headers']['location']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ]), [
            'email' => $this->getRoot()['email'],
            'password' => 'password'
        ]);
        $this->assertEquals(201, $session['headers']['status-code']);
        $this->assertNotEmpty($session['cookies']['a_session_console']);
        $this->assertNotEmpty($session['body']['$id']);
        $cookie = 'a_session_console=' . $session['cookies']['a_session_console'];

        $jwt = $this->client->call(Client::METHOD_POST, '/account/jwts', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $cookie,
            'x-appwrite-project' => 'console',
        ]), []);
        $this->assertEquals(201, $jwt['headers']['status-code']);
        $this->assertNotEmpty($jwt['body']['jwt']);

        $response = $proxyClient->call(Client::METHOD_GET, '/_appwrite/authorize', params: [
            'jwt' => $jwt['body']['jwt'],
            'path' => '/contact'
        ], followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertArrayHasKey('set-cookie', $response['headers']);
        $this->assertStringContainsString('a_jwt_console=', $response['headers']['set-cookie']);
        $this->assertStringContainsString('httponly', $response['headers']['set-cookie']);
        $this->assertStringContainsString('domain=' . $domain, $response['headers']['set-cookie']);
        $this->assertStringContainsString('path=/', $response['headers']['set-cookie']);
        $this->assertNotEmpty($response['cookies']['a_jwt_console']);
        $this->assertEquals($jwt['body']['jwt'], $response['cookies']['a_jwt_console']);

        $response = $proxyClient->call(Client::METHOD_GET, '/contact', headers: [
            'cookie' => 'a_jwt_console=' . $jwt['body']['jwt']
        ], followRedirects: false);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Contact page", $response['body']);
        $this->assertStringContainsString("Preview by", $response['body']);

        // Failure: Session missing (old bad, new ok)
        $session = $this->client->call(Client::METHOD_DELETE, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $cookie,
            'x-appwrite-project' => 'console',
        ]), []);
        $this->assertEquals(204, $session['headers']['status-code']);

        $response = $proxyClient->call(Client::METHOD_GET, '/contact', headers: [
            'cookie' => 'a_jwt_console=' . $jwt['body']['jwt']
        ], followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringContainsString('/console/auth/preview', $response['headers']['location']);

        // Failure: User missing
        $cookie = 'a_session_console=' .$this->getRoot()['session'];
        $jwt = $this->client->call(Client::METHOD_POST, '/account/jwts', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $cookie,
            'x-appwrite-project' => 'console',
        ]), []);
        $this->assertEquals(201, $jwt['headers']['status-code']);
        $this->assertNotEmpty($jwt['body']['jwt']);

        $response = $proxyClient->call(Client::METHOD_GET, '/contact', headers: [
            'cookie' => 'a_jwt_console=' . $jwt['body']['jwt']
        ], followRedirects: false);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Contact page", $response['body']);
        $this->assertStringContainsString("Preview by", $response['body']);

        $user = $this->client->call(Client::METHOD_PATCH, '/account/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $cookie,
            'x-appwrite-project' => 'console',
        ]), []);
        $this->assertEquals(200, $user['headers']['status-code']);
        $this->assertFalse($user['body']['status']);

        $response = $proxyClient->call(Client::METHOD_GET, '/contact', headers: [
            'cookie' => 'a_jwt_console=' . $jwt['body']['jwt']
        ], followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringContainsString('/console/auth/preview', $response['headers']['location']);

        // Failure: Membership missing
        $email = \uniqid() . 'newuser@appwrite.io';
        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => 'password'
        ]);
        $this->assertEquals(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => 'password',
        ]);
        $this->assertEquals(201, $session['headers']['status-code']);
        $this->assertNotEmpty($session['cookies']['a_session_console']);
        $cookie = 'a_session_console=' . $session['cookies']['a_session_console'];

        $jwt = $this->client->call(Client::METHOD_POST, '/account/jwts', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $cookie,
            'x-appwrite-project' => 'console',
        ]), []);
        $this->assertEquals(201, $jwt['headers']['status-code']);
        $this->assertNotEmpty($jwt['body']['jwt']);

        $response = $proxyClient->call(Client::METHOD_GET, '/contact', headers: [
            'cookie' => 'a_jwt_console=' . $jwt['body']['jwt']
        ], followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringContainsString('/console/auth/preview', $response['headers']['location']);

        $this->cleanupSite($siteId);
    }

    public function testInvalidSSRSource(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Astro SSR Site',
            'framework' => 'astro',
            'adapter' => 'ssr',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'npm run build',
            'installCommand' => 'npm install',
        ]);

        $this->assertNotEmpty($siteId);

        $site = $this->getSite($siteId);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertArrayHasKey('adapter', $site['body']);
        $this->assertEquals('ssr', $site['body']['adapter']);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('astro-static'),
            'activate' => true
        ]);
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertEquals('failed', $deployment['body']['status'], 'Deployment status is failed, deployment: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        $this->cleanupSite($siteId);
    }

    public function testDomainForFailedDeployment(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Test Site',
            'framework' => 'astro',
            'buildRuntime' => 'node-22',
            'buildCommand' => 'cd random'
        ]);

        $this->assertNotEmpty($siteId);

        $domain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($domain);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('astro'),
            'activate' => true
        ]);
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertEquals('failed', $deployment['body']['status'], json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertStringContainsString('This page is empty, activate a deployment to make it live.', $response['body']);

        $this->cleanupSite($siteId);
    }

    public function testPermanentRedirect(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Sub project site',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './'
        ]);
        $this->assertNotEmpty($siteId);

        $domain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($domain);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('sub-directories'),
            'activate' => 'true'
        ]);
        $this->assertNotEmpty($deploymentId);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);
        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString('Sub-directory index', $response['body']);
        $response1 = $proxyClient->call(Client::METHOD_GET, '/project1');
        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertStringContainsString('Sub-directory project1', $response1['body']);
        $response2 = $proxyClient->call(Client::METHOD_GET, '/project1/');
        $this->assertEquals(200, $response2['headers']['status-code']);
        $this->assertStringContainsString('Sub-directory project1', $response2['body']);
        $this->cleanupSite($siteId);
    }

    public function testDeploymentCommandEscaping(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'A site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => "echo 'Hello two'",
            'installCommand' => 'echo "Hello one"',
            'fallbackFile' => '',
        ]);

        $this->assertNotEmpty($siteId);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'true'
        ]);

        $this->assertNotEmpty($deploymentId);

        $deployment = $this->getDeployment($siteId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertStringContainsString('Hello one', $deployment['body']['buildLogs']);
        $this->assertStringContainsString('Hello two', $deployment['body']['buildLogs']);

        $this->cleanupSite($siteId);
    }

    #[Retry(count: 3)]
    public function testErrorPages(): void
    {
        // non-existent domain page
        $domain = 'non-existent-page.sites.localhost';
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertStringContainsString('Nothing is here yet', $response['body']);
        $this->assertStringContainsString('Start with this domain', $response['body']);

        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Static site',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './',
            'buildCommand' => 'sleep 5 && cd non-existing-directory',
        ]);
        $this->assertNotEmpty($siteId);

        $domain = $this->setupSiteDomain($siteId);

        // test canceled deployment error page
        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'true'
        ]);
        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deployment = $this->cancelDeployment($siteId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals('canceled', $deployment['body']['status']);

        $deploymentDomain = $this->getDeploymentDomain($deploymentId);
        $this->assertNotEmpty($deploymentDomain);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $deploymentDomain);
        $response = $proxyClient->call(Client::METHOD_GET, '/', followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);

        $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 0);
        $apiKey = $jwtObj->encode([
            'projectCheckDisabled' => true,
            'previewAuthDisabled' => true,
        ]);

        $response = $proxyClient->call(Client::METHOD_GET, '/', followRedirects: false, headers: [
            'x-appwrite-key' => API_KEY_DYNAMIC . '_' . $apiKey,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertStringContainsString("Deployment build canceled", $response['body']);
        $this->assertStringContainsString("View deployments", $response['body']);

        // check site domain for no active deployments
        $proxyClient->setEndpoint('http://' . $domain);
        $response = $proxyClient->call(Client::METHOD_GET, '/');
        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertStringContainsString('No active deployments', $response['body']);
        $this->assertStringContainsString('View deployments', $response['body']);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => 'true'
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';
        $this->assertNotEmpty($deploymentId);

        $deploymentDomain = $this->getDeploymentDomain($deploymentId);
        $this->assertNotEmpty($deploymentDomain);

        $proxyClient->setEndpoint('http://' . $deploymentDomain);
        $response = $proxyClient->call(Client::METHOD_GET, '/', followRedirects: false);
        $this->assertEquals(301, $response['headers']['status-code']);

        // deployment is still building error page
        $response = $proxyClient->call(Client::METHOD_GET, '/', followRedirects: false, headers: [
            'x-appwrite-key' => API_KEY_DYNAMIC . '_' . $apiKey,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertStringContainsString("Deployment is still building", $response['body']);
        $this->assertStringContainsString("View logs", $response['body']);
        $this->assertStringContainsString("Reload", $response['body']);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);

            $this->assertEquals('failed', $deployment['body']['status']);
        }, 50000, 500);

        // deployment failed error page
        $response = $proxyClient->call(Client::METHOD_GET, '/', followRedirects: false, headers: [
            'x-appwrite-key' => API_KEY_DYNAMIC . '_' . $apiKey,
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertStringContainsString("Deployment build failed", $response['body']);
        $this->assertStringContainsString("View logs", $response['body']);

        $this->cleanupSite($siteId);
    }

    public function testEmptySiteSource(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Empty source site',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './',
        ]);
        $this->assertNotEmpty($siteId);

        // Prepare empty site folder
        // We cant use .gitkeep, because that would make deployment non-empty
        $stdout = '';
        $stderr = '';
        $folderPath = realpath(__DIR__ . '/../../../resources/sites') . '/empty';
        Console::execute("mkdir -p $folderPath", '', $stdout, $stderr);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('empty'),
            'activate' => true
        ]);
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertEquals('failed', $deployment['body']['status'], 'Deployment status does not match: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
            $this->assertStringContainsString('Error:', $deployment['body']['buildLogs'], 'Deployment logs do not match: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        $this->cleanupSite($siteId);
    }

    public function testOutputDirectoryEmpty(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Empty output directory',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './empty-directory',
            'buildCommand' => 'mkdir -p ./empty-directory'
        ]);
        $this->assertNotEmpty($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => true
        ]);
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertEquals('failed', $deployment['body']['status'], 'Deployment status does not match: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
            $this->assertStringContainsString('Error:', $deployment['body']['buildLogs'], 'Deployment logs do not match: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        $this->cleanupSite($siteId);
    }

    public function testOutputDirectoryMissing(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Missing output directory',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './non-existing-directory',
        ]);
        $this->assertNotEmpty($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-single-file'),
            'activate' => true
        ]);
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertEquals('failed', $deployment['body']['status'], 'Deployment status does not match: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
            $this->assertStringContainsString('No such file or directory', $deployment['body']['buildLogs'], 'Deployment logs do not match: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        $this->cleanupSite($siteId);
    }

    public function testBuildErrorLogs(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Astro SSR site',
            'framework' => 'astro',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'npm run build',
            'installCommand' => 'echo "custom error" && npm install',
            'adapter' => 'ssr',
        ]);
        $this->assertNotEmpty($siteId);

        $site = $this->getSite($siteId);
        $this->assertEquals('200', $site['headers']['status-code']);

        $domain = $this->setupSiteDomain($siteId);
        $this->assertNotEmpty($domain);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('astro-static'),
            'activate' => true
        ]);
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId);

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertEquals('failed', $deployment['body']['status'], 'Deployment status is failed, deployment: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        $deployment = $this->getDeployment($siteId, $deploymentId);
        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertStringContainsString('custom error', $deployment['body']['buildLogs']);
        $this->assertStringContainsString('Adapter mismatch', $deployment['body']['buildLogs']);

        $this->cleanupSite($siteId);
    }

    public function testCookieHeader()
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Astro site',
            'framework' => 'astro',
            'adapter' => 'ssr',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'npm run build',
            'installCommand' => 'npm install',
            'fallbackFile' => '',
        ]);

        $this->assertNotEmpty($siteId);

        $domain = $this->setupSiteDomain($siteId);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('astro'),
            'activate' => 'true'
        ]);

        $this->assertNotEmpty($deploymentId);

        $domain = $this->getSiteDomain($siteId);
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/cookies', [
            'cookie' => 'custom-session-id=abcd123; custom-user-id=efgh456'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("abcd123;efgh456", $response['body']);
        $this->assertEquals("value-one", $response['cookies']['my-cookie-one']);
        $this->assertEquals("value-two", $response['cookies']['my-cookie-two']);

        $this->cleanupSite($siteId);
    }
}
