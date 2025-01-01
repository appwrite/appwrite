<?php

namespace Tests\E2E\Services\Sites;

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
            'adapter' => 'ssr',
            'buildCommand' => 'npm run build',
            'buildRuntime' => 'node-22',
            'fallbackFile' => null,
            'framework' => 'nextjs',
            'installCommand' => 'npm install',
            'name' => 'Test Site',
            'outputDirectory' => './.next',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique(),
            'templateOwner' => 'appwrite',
            'templateRepository' => 'templates-for-sites',
            'templateRootDirectory' => './nextjs/starter',
            'templateVersion' => '0.2.*'
        ]);

        $siteId = $site['body']['$id'] ?? '';

        $dateValidator = new DateTimeValidator();
        $this->assertEquals(201, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['$id']);
        $this->assertEquals('Test Site', $site['body']['name']);
        $this->assertEquals('nextjs', $site['body']['framework']);
        $this->assertEquals(true, $dateValidator->isValid($site['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($site['body']['$updatedAt']));
        $this->assertEquals('ssr', $site['body']['adapter']);
        $this->assertEquals('npm run build', $site['body']['buildCommand']);
        $this->assertEquals('node-22', $site['body']['buildRuntime']);
        $this->assertEquals(null, $site['body']['fallbackFile']);
        $this->assertEquals('npm install', $site['body']['installCommand']);
        $this->assertEquals('./.next', $site['body']['outputDirectory']);
        $this->assertEquals('main', $site['body']['providerBranch']);
        $this->assertEquals('./', $site['body']['providerRootDirectory']);

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
            'adapter' => 'ssr',
            'buildCommand' => 'npm run build',
            'buildRuntime' => 'node-22',
            'fallbackFile' => null,
            'framework' => 'nextjs',
            'installCommand' => 'npm install',
            'name' => 'Test Site',
            'outputDirectory' => './.next',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique(),
            'templateOwner' => 'appwrite',
            'templateRepository' => 'templates-for-sites',
            'templateRootDirectory' => './nextjs/starter',
            'templateVersion' => '0.2.*'
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
            'search' => 'nextjs'
        ]);

        $this->assertEquals($sites['headers']['status-code'], 200);
        $this->assertCount(1, $sites['body']['sites']);
        $this->assertEquals($sites['body']['sites'][0]['$id'], $siteId);

        /**
         * Test pagination
         */
        $siteId2 = $this->setupSite([
            'adapter' => 'ssr',
            'buildCommand' => 'npm run build',
            'buildRuntime' => 'node-22',
            'fallbackFile' => null,
            'framework' => 'nextjs',
            'installCommand' => 'npm install',
            'name' => 'Test Site 2',
            'outputDirectory' => './.next',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique(),
            'templateOwner' => 'appwrite',
            'templateRepository' => 'templates-for-sites',
            'templateRootDirectory' => './nextjs/starter',
            'templateVersion' => '0.2.*'
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
            'adapter' => 'ssr',
            'buildCommand' => 'npm run build',
            'buildRuntime' => 'node-22',
            'fallbackFile' => null,
            'framework' => 'nextjs',
            'installCommand' => 'npm install',
            'name' => 'Test Site',
            'outputDirectory' => './.next',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique(),
            'templateOwner' => 'appwrite',
            'templateRepository' => 'templates-for-sites',
            'templateRootDirectory' => './nextjs/starter',
            'templateVersion' => '0.2.*'
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
}
