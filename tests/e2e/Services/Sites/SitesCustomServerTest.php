<?php

namespace Tests\E2E\Services\Sites;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

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
            'buildRuntime' => 'node-22',
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

    public function testListSites(): void
    {
        /**
         * Test for SUCCESS
         */
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
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
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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

    // public function testCreateSiteAndDeploymentFromTemplate()
    // {
    //     $starterTemplate = $this->getTemplate('nextjs-starter');
    //     $this->assertEquals(200, $starterTemplate['headers']['status-code']);

    //     $nextjsFramework = array_values(array_filter($starterTemplate['body']['frameworks'], function ($framework) {
    //         return $framework['key'] === 'nextjs';
    //     }))[0];

    //     // If this fails, the template has variables, and this test needs to be updated
    //     $this->assertEmpty($starterTemplate['body']['variables']);

    //     var_dump("creating site");

    //     $site = $this->createSite(
    //         [
    //             'siteId' => ID::unique(),
    //             'name' => $starterTemplate['body']['name'],
    //             'framework' => $nextjsFramework['key'],
    //             'adapter' => $nextjsFramework['adapter'],
    //             'buildCommand' => $nextjsFramework['buildCommand'],
    //             'buildRuntime' => $nextjsFramework['buildRuntime'],
    //             'fallbackFile' => $nextjsFramework['fallbackFile'],
    //             'installCommand' => $nextjsFramework['installCommand'],
    //             'outputDirectory' => $nextjsFramework['outputDirectory'],
    //             'providerRootDirectory' => $nextjsFramework['providerRootDirectory'],
    //             'templateOwner' => $starterTemplate['body']['providerOwner'],
    //             'templateRepository' => $starterTemplate['body']['providerRepositoryId'],
    //             'templateRootDirectory' => $nextjsFramework['providerRootDirectory'],
    //             'templateVersion' => $starterTemplate['body']['providerVersion'],
    //             'providerBranch' => 'main',
    //         ]
    //     );

    //     $this->assertEquals(201, $site['headers']['status-code']);
    //     $this->assertNotEmpty($site['body']['$id']);

    //     $siteId = $site['body']['$id'] ?? '';
    //     var_dump("Site id");

    //     $deployments = $this->listDeployments($siteId);

    //     var_dump($deployments);

    //     $this->assertEquals(200, $deployments['headers']['status-code']);
    //     $this->assertEquals(1, $deployments['body']['total']);

    //     $lastDeployment = $deployments['body']['deployments'][0];

    //     $this->assertNotEmpty($lastDeployment['$id']);
    //     $this->assertEquals(0, $lastDeployment['size']);

    //     $deploymentId = $lastDeployment['$id'];
    //     var_dump("flow reached here");

    //     $this->assertEventually(function () use ($siteId, $deploymentId) {
    //         $deployment = $this->getDeployment($siteId, $deploymentId);

    //         $this->assertEquals(200, $deployment['headers']['status-code']);
    //         // assert that deployment is ready or failed
    //         $this->assertContains($deployment['body']['status'], ['ready', 'failed']);
    //     }, 300000, 1000);

    //     var_dump("flow reached here 2");

    //     $site = $this->getSite($siteId);
    //     $deployment = $this->getDeployment($siteId, $deploymentId);

    //     $this->assertEquals(200, $site['headers']['status-code']);
    //     var_dump($deployment);

    //     $this->cleanupSite($siteId);
    // }

    public function testCreateDeployment()
    {
        $siteId = $this->setupSite([
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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
            'code' => $this->packageSite('other'),
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
            'code' => $this->packageSite('other'),
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

    public function testCancelDeploymentBuild(): void
    {
        $siteId = $this->setupSite([
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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
            'code' => $this->packageSite('other'),
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
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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
            'code' => $this->packageSite('other'),
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
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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
            'code' => $this->packageSite('other'),
            'activate' => 'false'
        ]);

        $deploymentIdActive = $deployment['body']['$id'] ?? '';
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('other'),
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
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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
            'code' => $this->packageSite('other'),
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

    // public function testLoadSite(): void
    // {
    //     $site = $this->createSite([
    //         'adapter' => 'static',
    //         'buildRuntime' => 'node-22',
    //         'fallbackFile' => null,
    //         'framework' => 'other',
    //         'name' => 'Test Site',
    //         'outputDirectory' => './',
    //         'providerBranch' => 'main',
    //         'providerRootDirectory' => './',
    //         'siteId' => ID::unique()
    //     ]);

    //     $siteId = $site['body']['$id'] ?? '';
    //     $this->assertNotEmpty($siteId);

    //     var_dump($site);

    //     $deployment = $this->createDeployment($siteId, [
    //         'code' => $this->packageSite('other'),
    //         'activate' => 'false'
    //     ]);

    //     $deploymentId = $deployment['body']['$id'] ?? '';

    //     $this->assertEventually(function () use ($siteId, $deploymentId) {
    //         $deployment = $this->getDeployment($siteId, $deploymentId);

    //         $this->assertEquals('ready', $deployment['body']['status']);
    //     }, 50000, 500);

    //     $domain = $site['body']['domain'];

    //     $response = $this->client->call(Client::METHOD_GET, $domain);
    //     var_dump($response);
    // }

    // public function testUpdateSpecs(): void
    // {
    //     $siteId = $this->setupSite([
    //         'adapter' => 'static',
    //         'buildRuntime' => 'node-22',
    //         'fallbackFile' => null,
    //         'framework' => 'other',
    //         'name' => 'Test Site',
    //         'outputDirectory' => './',
    //         'providerBranch' => 'main',
    //         'providerRootDirectory' => './',
    //         'siteId' => ID::unique()
    //     ]);

    //     $this->assertNotNull($siteId);

    //     /**
    //      * Test for SUCCESS
    //      */
    //     // Change the function specs
    //     $site = $this->updateSite([
    //         'adapter' => 'static',
    //         'buildRuntime' => 'node-22',
    //         'fallbackFile' => null,
    //         'framework' => 'other',
    //         'name' => 'Test Site',
    //         'outputDirectory' => './',
    //         'providerBranch' => 'main',
    //         'providerRootDirectory' => './',
    //         '$id' => $siteId,
    //         'specification' => Specification::S_1VCPU_1GB,
    //     ]);

    //     $this->assertEquals(200, $site['headers']['status-code']);
    //     $this->assertNotEmpty($site['body']['$id']);
    //     $this->assertEquals(Speci::S_1VCPU_1GB, $site['body']['specification']);

    //     // Change the specs to 1vcpu 512mb
    //     $site = $this->updateSite([
    //         'adapter' => 'static',
    //         'buildRuntime' => 'node-22',
    //         'fallbackFile' => null,
    //         'framework' => 'other',
    //         'name' => 'Test Site',
    //         'outputDirectory' => './',
    //         'providerBranch' => 'main',
    //         'providerRootDirectory' => './',
    //         '$id' => $siteId,
    //         'specification' => Specification::S_1VCPU_512MB,
    //     ]);

    //     $this->assertEquals(200, $site['headers']['status-code']);
    //     $this->assertNotEmpty($site['body']['$id']);
    //     $this->assertEquals(Specification::S_1VCPU_512MB, $site['body']['specification']);

    //     /**
    //      * Test for FAILURE
    //      */

    //     $site = $this->updateSite([
    //         'adapter' => 'static',
    //         'buildRuntime' => 'node-22',
    //         'fallbackFile' => null,
    //         'framework' => 'other',
    //         'name' => 'Test Site',
    //         'outputDirectory' => './',
    //         'providerBranch' => 'main',
    //         'providerRootDirectory' => './',
    //         '$id' => $siteId,
    //         'specification' => 's-2vcpu-512mb', // Invalid specification
    //     ]);

    //     var_dump($site);

    //     $this->assertEquals(400, $site['headers']['status-code']);
    //     $this->assertStringStartsWith('Invalid `specification` param: Specification must be one of:', $site['body']['message']);

    //     $this->cleanupSite($siteId);
    // }

    public function testDeleteDeployment(): void
    {
        $siteId = $this->setupSite([
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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
            'code' => $this->packageSite('other'),
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
            'adapter' => 'static',
            'buildRuntime' => 'node-22',
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
}
