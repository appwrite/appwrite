<?php

namespace Tests\E2E\Services\Sites;

use Appwrite\Sites\Specification;
use Appwrite\Tests\Retry;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
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

    public function testCreateSite(): void
    {
        /**
         * Test for SUCCESS
         */
        $site = $this->createSite([
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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
        $this->assertEquals('ssr-22', $site['body']['buildRuntime']);
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
            'buildRuntime' => 'ssr-22',
            'outputDirectory' => './',
            'fallbackFile' => null,
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
                    Query::equal('automation', ['site=' . $siteId])
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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
            'buildRuntime' => 'ssr-22',
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

        $this->cleanupSite($siteId);
    }

    public function testListSites(): void
    {
        /**
         * Test for SUCCESS
         */
        $siteId = $this->setupSite([
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $sites = $this->listSites([
            'search' => $siteId,
        ]);

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertCount(1, $sites['body']['sites']);
        $this->assertEquals($sites['body']['sites'][0]['name'], 'Test Site');

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
            'search' => 'Test'
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
            'framework' => 'other',
            'name' => 'Test Site 2',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $sites = $this->listSites();

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertEquals($sites['body']['total'], 2);
        $this->assertIsArray($sites['body']['sites']);
        $this->assertCount(2, $sites['body']['sites']);
        $this->assertEquals($sites['body']['sites'][0]['name'], 'Test Site');
        $this->assertEquals($sites['body']['sites'][1]['name'], 'Test Site 2');

        $sites1 = $this->listSites([
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $sites['body']['sites'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals($sites1['headers']['status-code'], 200);
        $this->assertCount(1, $sites1['body']['sites']);
        $this->assertEquals($sites1['body']['sites'][0]['name'], 'Test Site 2');

        $sites2 = $this->listSites([
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $sites['body']['sites'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertEquals($sites2['headers']['status-code'], 200);
        $this->assertCount(1, $sites2['body']['sites']);
        $this->assertEquals($sites2['body']['sites'][0]['name'], 'Test Site');

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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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

    public function testCreateSiteAndDeploymentFromTemplate()
    {
        $starterTemplate = $this->getTemplate('nextjs-starter');
        $this->assertEquals(200, $starterTemplate['headers']['status-code']);

        $nextjsFramework = array_values(array_filter($starterTemplate['body']['frameworks'], function ($framework) {
            return $framework['key'] === 'nextjs';
        }))[0];

        // If this fails, the template has variables, and this test needs to be updated
        $this->assertEmpty($starterTemplate['body']['variables']);

        $site = $this->createSite(
            [
                'siteId' => ID::unique(),
                'name' => $starterTemplate['body']['name'],
                'framework' => $nextjsFramework['key'],
                'adapter' => $nextjsFramework['adapter'],
                'buildCommand' => $nextjsFramework['buildCommand'],
                'buildRuntime' => $nextjsFramework['buildRuntime'],
                'fallbackFile' => $nextjsFramework['fallbackFile'],
                'installCommand' => $nextjsFramework['installCommand'],
                'outputDirectory' => $nextjsFramework['outputDirectory'],
                'providerRootDirectory' => $nextjsFramework['providerRootDirectory'],
            ]
        );

        $this->assertEquals(201, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['$id']);

        $siteId = $site['body']['$id'] ?? '';

        $deployment = $this->createTemplateDeployment(
            $siteId,
            [
                'owner' => $starterTemplate['body']['providerOwner'],
                'repository' => $starterTemplate['body']['providerRepositoryId'],
                'rootDirectory' => $nextjsFramework['providerRootDirectory'],
                'version' => $starterTemplate['body']['providerVersion'],
                'activate' => true,
            ]
        );

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deployments = $this->listDeployments($siteId);

        $this->assertEquals(200, $deployments['headers']['status-code']);
        $this->assertEquals(1, $deployments['body']['total']);

        $lastDeployment = $deployments['body']['deployments'][0];

        $this->assertNotEmpty($lastDeployment['$id']);
        $this->assertEquals(0, $lastDeployment['size']);

        $deploymentId = $lastDeployment['$id'];

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);

            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertEquals('ready', $deployment['body']['status']);
        }, 300000, 1000);

        $site = $this->getSite($siteId);
        $this->assertEquals(200, $site['headers']['status-code']);

        $this->cleanupSite($siteId);
    }

    public function testCreateDeployment()
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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
            'code' => $this->packageSite('static'),
            'activate' => true,
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));

        $deploymentIdActive = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentIdActive) {
            $deployment = $this->getDeployment($siteId, $deploymentIdActive);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static'),
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static'),
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

        // Cancel the deployment
        $cancel = $this->client->call(Client::METHOD_PATCH, '/sites/' . $siteId . '/deployments/' . $deploymentId . '/build', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $cancel['headers']['status-code']);
        $this->assertEquals('canceled', $cancel['body']['status']);

        /**
         * Build worker still runs the build.
         * 30s sleep gives worker enough time to finish build.
         * After build finished, it should still be canceled, not ready.
         */
        \sleep(30);

        $deployment = $this->getDeployment($siteId, $deploymentId);

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertEquals('canceled', $deployment['body']['status']);

        $this->cleanupDeployment($siteId, $deploymentId);
        $this->cleanupSite($siteId);
    }

    public function testUpdateDeployment(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static'),
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

        $response = $this->client->call(Client::METHOD_PATCH, '/sites/' . $siteId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => 'false'
        ]);

        $deploymentIdActive = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static'),
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
        $this->assertArrayHasKey('size', $deployments['body']['deployments'][0]);
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
                    Query::greaterThan('size', 10000)->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(0, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $siteId,
            [
                'queries' => [
                    Query::greaterThan('size', 0)->toString(),
                ],
            ]
        );

        $this->assertEquals($deployments['headers']['status-code'], 200);
        $this->assertEquals(2, $deployments['body']['total']);

        $deployments = $this->listDeployments(
            $siteId,
            [
                'queries' => [
                    Query::greaterThan('size', -100)->toString(),
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
        $this->assertNotEmpty($deployments['body']['deployments'][0]['size']);

        $deploymentId = $deployments['body']['deployments'][0]['$id'];
        $deploymentSize = $deployments['body']['deployments'][0]['size'];

        $deployments = $this->listDeployments(
            $siteId,
            [
                'queries' => [
                    Query::equal('size', [$deploymentSize])->toString(),
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
            $this->assertEquals($deploymentSize, $deployment['size']);
        }

        $this->cleanupDeployment($siteId, $deploymentIdActive);
        $this->cleanupDeployment($siteId, $deploymentIdInactive);
        $this->cleanupSite($siteId);
    }

    public function testGetDeployment(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static'),
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
        $this->assertGreaterThan(0, $deployment['body']['buildTime']);
        $this->assertNotEmpty($deployment['body']['status']);
        $this->assertNotEmpty($deployment['body']['buildLogs']);
        $this->assertArrayHasKey('size', $deployment['body']);
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static'),
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
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
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
        ], $this->getHeaders()));

        $this->assertEquals(200, $frameworks['headers']['status-code']);
        $this->assertGreaterThan(0, $frameworks['body']['total']);

        $framework = $frameworks['body']['frameworks'][0];

        $this->assertArrayHasKey('name', $framework);
        $this->assertArrayHasKey('key', $framework);
        $this->assertArrayHasKey('buildRuntime', $framework);
        $this->assertArrayHasKey('runtimes', $framework);
        $this->assertArrayHasKey('adapters', $framework);
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
        $this->assertStringContainsString("Powered by", $response['body']); // Brand

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
        $this->assertStringNotContainsString("Powered by", $response['body']); // Brand

        $this->cleanupSite($siteId);
    }

    public function testSiteTemplate(): void
    {
        $template = $this->getTemplate('astro-starter');
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
            'version' => $template['providerVersion'],
            'activate' => true
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

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

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertStringContainsString("This domain is not connected to any Appwrite resource yet", $response['body']);

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

        $domain = $this->setupSiteDomain($siteId);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => 'true'
        ]);

        $this->assertNotEmpty($deploymentId);

        $domain = $this->getSiteDomain($siteId);
        $previewDomain = $this->getDeploymentDomain($deploymentId);

        $this->assertNotEmpty($domain);
        $this->assertNotEmpty($previewDomain);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Hello Appwrite", $response['body']);
        $this->assertStringNotContainsString("Preview by", $response['body']);

        $contentLength = $response['headers']['content-length'];

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $previewDomain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Hello Appwrite", $response['body']);
        $this->assertStringContainsString("Preview by", $response['body']);
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
            'origin' => $url
        ]));

        $this->assertEquals($url, $response['headers']['access-control-allow-origin']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'unknown',
            'referer' => $url,
            'origin' => $url
        ]));

        $this->assertNotEquals($url, $response['headers']['access-control-allow-origin']);
        $this->assertEquals('http://localhost', $response['headers']['access-control-allow-origin']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'referer' => 'http://unknown.com',
            'origin' => 'http://unknown.com'
        ]));

        $this->assertNotEquals($url, $response['headers']['access-control-allow-origin']);
        $this->assertEquals('http://localhost', $response['headers']['access-control-allow-origin']);
    }

    public function testSiteScreenshot(): void
    {
        $siteId = $this->setupSite([
            'siteId' => ID::unique(),
            'name' => 'Themed site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $this->assertNotEmpty($siteId);

        $domain = $this->setupSiteDomain($siteId);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static-themed'),
            'activate' => 'true'
        ]);

        $this->assertNotEmpty($deploymentId);

        $domain = $this->getSiteDomain($siteId);
        $this->assertNotEmpty($domain);

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertStringContainsString("Themed website", $response['body']);
        $this->assertStringContainsString("@media (prefers-color-scheme: dark)", $response['body']);

        $deployment = $this->getDeployment($siteId, $deploymentId);

        $this->assertEquals(200, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['screenshotLight']);
        $this->assertNotEmpty($deployment['body']['screenshotDark']);

        $screenshotId = $deployment['body']['screenshotLight'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/view?project=console&mode=admin", array_merge([
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertNotEmpty(200, $file['body']);
        $this->assertGreaterThan(1, $file['headers']['content-length']);
        $this->assertEquals('image/png', $file['headers']['content-type']);

        $screenshotHash = \md5($file['body']);
        $this->assertNotEmpty($screenshotHash);

        $screenshotId = $deployment['body']['screenshotDark'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/view?project=console&mode=admin", array_merge([
        ], $this->getHeaders()));

        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertNotEmpty(200, $file['body']);
        $this->assertGreaterThan(1, $file['headers']['content-length']);
        $this->assertEquals('image/png', $file['headers']['content-type']);

        $screenshotDarkHash = \md5($file['body']);
        $this->assertNotEmpty($screenshotDarkHash);

        $this->assertNotEquals($screenshotDarkHash, $screenshotHash);

        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/view?project=console&mode=admin");
        $this->assertEquals(404, $file['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/view?project=console&mode=admin");
        $this->assertEquals(404, $file['headers']['status-code']);

        $this->cleanupSite($siteId);
    }

    public function testSiteDownload(): void
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'ssr-22',
            'fallbackFile' => null,
            'framework' => 'other',
            'name' => 'Test Site',
            'adapter' => 'static',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $deploymentId = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static'),
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
            'buildRuntime' => 'ssr-22',
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
        $this->assertStringContainsString("GET", $logs['body']['executions'][0]['requestMethod']);
        $this->assertStringContainsString("/logs-inline", $logs['body']['executions'][0]['requestPath']);
        $this->assertStringContainsString("Log1", $logs['body']['executions'][0]['logs']);
        $this->assertStringContainsString("Log2", $logs['body']['executions'][0]['logs']);
        $this->assertStringContainsString("Error1", $logs['body']['executions'][0]['errors']);
        $this->assertStringContainsString("Error2", $logs['body']['executions'][0]['errors']);
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
        $this->assertStringContainsString("GET", $logs['body']['executions'][0]['requestMethod']);
        $this->assertStringContainsString("/logs-action", $logs['body']['executions'][0]['requestPath']);
        $this->assertStringContainsString("Log1", $logs['body']['executions'][0]['logs']);
        $this->assertStringContainsString("Log2", $logs['body']['executions'][0]['logs']);
        $this->assertStringContainsString("Error1", $logs['body']['executions'][0]['errors']);
        $this->assertStringContainsString("Error2", $logs['body']['executions'][0]['errors']);
        $log2Id = $logs['body']['executions'][0]['$id'];
        $this->assertNotEmpty($log2Id);

        $this->assertNotEquals($log1Id, $log2Id);

        $this->cleanupSite($siteId);
    }

    public function testUpdateDeploymentStatus(): void
    {
        // TODO: Create site, create deployment A, ensure site A. create dpeloyment B, ensure site B. Activate deploymnt A, ensure site A. Cleanup
    }
}
