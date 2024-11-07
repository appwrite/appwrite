<?php

namespace Tests\E2E\Services\Sites;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

class SitesCustomServerTest extends Scope
{
    use SitesBase;
    use ProjectCustom;
    use SideServer;

    public function testCreateSite(): array
    {
        /**
         * Test for SUCCESS
         */
        $site = $this->createSite([
            'siteId' => ID::unique(),
            'name' => 'Test',
            'framework' => 'sveltekit',
            'installCommand' => 'npm install --force',
            'buildCommand' => 'npm run build',
            'outputDirectory' => './build',
            'buildRuntime' => 'node-22',
            'serveRuntime' => 'static-1',
            'subdomain' => 'test'
        ]);

        $siteId = $site['body']['$id'] ?? '';

        $dateValidator = new DatetimeValidator();
        $this->assertEquals(201, $site['headers']['status-code']);
        $this->assertNotEmpty($site['body']['$id']);
        $this->assertEquals('Test', $site['body']['name']);
        $this->assertEquals('sveltekit', $site['body']['framework']);
        $this->assertEquals(true, $dateValidator->isValid($site['body']['$createdAt']));
        $this->assertEquals(true, $dateValidator->isValid($site['body']['$updatedAt']));
        $this->assertEquals('npm install --force', $site['body']['installCommand']);
        $this->assertEquals('npm run build', $site['body']['buildCommand']);
        $this->assertEquals('./build', $site['body']['outputDirectory']);
        $this->assertEquals('node-22', $site['body']['buildRuntime']);
        $this->assertEquals('static-1', $site['body']['serveRuntime']);

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

        return [
            'siteId' => $siteId,
        ];
    }

    /**
     * @depends testCreateSite
     */
    public function testGetSite(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $site = $this->getSite($data['siteId']);

        $this->assertEquals($site['headers']['status-code'], 200);
        $this->assertEquals($site['body']['name'], 'Test');

        /**
         * Test for FAILURE
         */
        $site = $this->getSite('x');

        $this->assertEquals($site['headers']['status-code'], 404);

        return $data;
    }

    /**
     * @depends testGetSite
     */
    public function testDeleteSite(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $site = $this->deleteSite($data['siteId']);

        $this->assertEquals(204, $site['headers']['status-code']);
        $this->assertEmpty($site['body']);

        $site = $this->getSite($data['siteId']);

        $this->assertEquals(404, $site['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testGetSite
     */
    public function testUniqueSubdomain(array $data): void
    {
        /**
         * Test for SUCCESS
         */
        $site = $this->createSite([
            'siteId' => ID::unique(),
            'name' => 'Test',
            'framework' => 'sveltekit',
            'installCommand' => 'npm install --force',
            'buildCommand' => 'npm run build',
            'outputDirectory' => './build',
            'buildRuntime' => 'node-22',
            'serveRuntime' => 'static-1',
            'subdomain' => 'test'
        ]);

        $this->assertEquals(201, $site['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $site = $this->createSite([
            'siteId' => ID::unique(),
            'name' => 'Test2',
            'framework' => 'sveltekit',
            'installCommand' => 'npm install --force',
            'buildCommand' => 'npm run build',
            'outputDirectory' => './build',
            'buildRuntime' => 'node-22',
            'serveRuntime' => 'static-1',
            'subdomain' => 'test'
        ]);

        $this->assertEquals(400, $site['headers']['status-code']);

        return;
    }
}
