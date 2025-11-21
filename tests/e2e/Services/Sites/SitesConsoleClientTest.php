<?php

namespace Tests\E2E\Services\Sites;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;

class SitesConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;
    use SitesBase;

    /**
     * @group screenshots
    */
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

        $site = $this->getSite($siteId);
        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($deployment['body']['screenshotLight'], $site['body']['deploymentScreenshotLight']);
        $this->assertEquals($deployment['body']['screenshotDark'], $site['body']['deploymentScreenshotDark']);

        $screenshotId = $deployment['body']['screenshotLight'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/view?project=console", array_merge($this->getHeaders(), [
            'x-appwrite-mode' => 'default' // NOT ADMIN!
        ]));

        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertNotEmpty(200, $file['body']);
        $this->assertGreaterThan(1, $file['headers']['content-length']);
        $this->assertEquals('image/png', $file['headers']['content-type']);

        // Compare with reference screenshots
        $referencePath = \realpath(__DIR__ . '/../../../resources/sites/static-themed');
        $referenceScreenshotLight = $referencePath . '/screenshot-light.png';
        $this->assertFileExists($referenceScreenshotLight, 'Reference light screenshot not found');
        $this->assertSamePixels($referenceScreenshotLight, $file['body']);

        $screenshotId = $deployment['body']['screenshotDark'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/view?project=console", array_merge($this->getHeaders(), [
            'x-appwrite-mode' => 'default' // NOT ADMIN!
        ]));

        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertNotEmpty(200, $file['body']);
        $this->assertGreaterThan(1, $file['headers']['content-length']);
        $this->assertEquals('image/png', $file['headers']['content-type']);

        $referenceScreenshotDark = $referencePath . '/screenshot-dark.png';
        $this->assertFileExists($referenceScreenshotDark, 'Reference dark screenshot not found');
        $this->assertSamePixels($referenceScreenshotDark, $file['body']);

        $screenshotId = $deployment['body']['screenshotLight'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/view?project=console");
        $this->assertEquals(404, $file['headers']['status-code']);

        $screenshotId = $deployment['body']['screenshotDark'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/view?project=console");
        $this->assertEquals(404, $file['headers']['status-code']);

        // Verify previews
        $screenshotId = $deployment['body']['screenshotLight'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/preview?project=console", array_merge($this->getHeaders(), [
            'x-appwrite-mode' => 'default' // NOT ADMIN!
        ]));

        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertNotEmpty(200, $file['body']);
        $this->assertGreaterThan(1, $file['headers']['content-length']);
        $this->assertEquals('image/png', $file['headers']['content-type']);

        $screenshotHash = \md5($file['body']);
        $this->assertNotEmpty($screenshotHash);

        $screenshotId = $deployment['body']['screenshotDark'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/preview?project=console", array_merge($this->getHeaders(), [
            'x-appwrite-mode' => 'default' // NOT ADMIN!
        ]));

        $this->assertEquals(200, $file['headers']['status-code']);
        $this->assertNotEmpty(200, $file['body']);
        $this->assertGreaterThan(1, $file['headers']['content-length']);
        $this->assertEquals('image/png', $file['headers']['content-type']);

        $screenshotDarkHash = \md5($file['body']);
        $this->assertNotEmpty($screenshotDarkHash);

        $this->assertNotEquals($screenshotDarkHash, $screenshotHash);

        $screenshotId = $deployment['body']['screenshotLight'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/preview?project=console");
        $this->assertEquals(404, $file['headers']['status-code']);

        $screenshotId = $deployment['body']['screenshotDark'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/preview?project=console");
        $this->assertEquals(404, $file['headers']['status-code']);

        $this->cleanupSite($siteId);
    }
}
