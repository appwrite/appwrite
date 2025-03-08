<?php

namespace Tests\E2E\Services\Sites;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

class SitesCustomServerTest extends Scope
{
    use SitesBase;
    use ProjectCustom;
    use SideServer;


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

    public function testListSites(): void
    {
        /**
         * Test for SUCCESS
         */
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
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
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
}
