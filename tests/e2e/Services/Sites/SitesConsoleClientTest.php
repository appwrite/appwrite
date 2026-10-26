<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Sites;

use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\Database\Id;
use Utopia\Database\Query;

final class SitesConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;
    use SitesBase;

    #[Group('screenshots')]
    public function testSiteScreenshot(): void
    {
        $siteId = $this->setupSite([
            'siteId' => Id::unique(),
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
        $this->assertStringContainsString("Themed website", (string) $response['body']);
        $this->assertStringContainsString("@media (prefers-color-scheme: dark)", (string) $response['body']);

        $deployment = null;
        $site = null;
        $this->assertEventually(function () use ($siteId, $deploymentId, &$deployment, &$site) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertNotEmpty($deployment['body']['screenshotLight']);
            $this->assertNotEmpty($deployment['body']['screenshotDark']);

            $site = $this->getSite($siteId);
            $this->assertEquals(200, $site['headers']['status-code']);
            $this->assertEquals($deployment['body']['screenshotLight'], $site['body']['deploymentScreenshotLight']);
            $this->assertEquals($deployment['body']['screenshotDark'], $site['body']['deploymentScreenshotDark']);
        });

        $this->assertNotNull($site);
        $this->assertNotNull($deployment);

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

        $this->assertNotSame($screenshotDarkHash, $screenshotHash);

        $screenshotId = $deployment['body']['screenshotLight'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/preview?project=console");
        $this->assertEquals(404, $file['headers']['status-code']);

        $screenshotId = $deployment['body']['screenshotDark'];
        $file = $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId/preview?project=console");
        $this->assertEquals(404, $file['headers']['status-code']);

        $this->cleanupSite($siteId);
    }

    #[Group('screenshots')]
    public function testDeleteDeploymentScreenshots(): void
    {
        $siteId = $this->setupSite([
            'siteId' => Id::unique(),
            'name' => 'Screenshots cleanup site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $deploymentId = $this->createDeploymentAccepted($siteId, [
            'code' => $this->packageSite('static-themed'),
            'activate' => 'false'
        ]);
        $this->waitDeploymentReady($siteId, $deploymentId);

        $screenshotIds = [];
        $this->assertEventually(function () use ($siteId, $deploymentId, &$screenshotIds) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertSame(200, $deployment['headers']['status-code']);
            $this->assertNotEmpty($deployment['body']['screenshotLight']);
            $this->assertNotEmpty($deployment['body']['screenshotDark']);

            $screenshotIds = [$deployment['body']['screenshotLight'], $deployment['body']['screenshotDark']];
        });

        foreach ($screenshotIds as $screenshotId) {
            $this->assertSame(200, $this->getScreenshot($screenshotId)['headers']['status-code']);
        }

        $deployment = $this->deleteDeployment($siteId, $deploymentId);
        $this->assertSame(204, $deployment['headers']['status-code']);

        $this->assertEventually(function () use ($screenshotIds) {
            foreach ($screenshotIds as $screenshotId) {
                $this->assertSame(404, $this->getScreenshot($screenshotId)['headers']['status-code'], 'Screenshot row outlived its deployment: ' . $screenshotId);
            }
        });

        $this->cleanupSite($siteId);
    }

    #[Group('screenshots')]
    public function testDeleteDeploymentDuringScreenshotCapture(): void
    {
        $siteId = $this->setupSite([
            'siteId' => Id::unique(),
            'name' => 'Screenshots race site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $deployment = $this->createDeployment($siteId, [
            'code' => $this->packageSite('static-themed'),
            'activate' => 'false'
        ]);
        $this->assertSame(202, $deployment['headers']['status-code']);
        $deploymentId = $deployment['body']['$id'];
        $createdAt = $deployment['body']['$createdAt'];

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $deployment = $this->getDeployment($siteId, $deploymentId);
            $this->assertStringContainsString('Screenshot capturing started.', (string) $deployment['body']['buildLogs']);
        }, 120000, 100);

        $deployment = $this->deleteDeployment($siteId, $deploymentId);
        $this->assertSame(204, $deployment['headers']['status-code']);

        // Two captures take a few seconds each; anything the worker keeps lands well within this window.
        $deadline = \microtime(true) + 45;
        do {
            $files = $this->client->call(Client::METHOD_GET, '/storage/buckets/screenshots/files', array_merge($this->getHeaders(), [
                'x-appwrite-project' => 'console',
                'x-appwrite-mode' => 'default',
            ]), [
                'queries' => [Query::createdAfter($createdAt)->toString()],
            ]);
            $this->assertSame(200, $files['headers']['status-code']);
            $this->assertSame(0, $files['body']['total'], 'Screenshots of a deleted deployment were kept: ' . \json_encode($files['body']['files']));

            \usleep(500000);
        } while (\microtime(true) < $deadline);

        $this->cleanupSite($siteId);
    }

    private function getScreenshot(string $screenshotId): array
    {
        return $this->client->call(Client::METHOD_GET, "/storage/buckets/screenshots/files/$screenshotId?project=console", array_merge($this->getHeaders(), [
            'x-appwrite-mode' => 'default',
        ]));
    }

    public function testSiteDeploymentRetentionWithMaintenance(): void
    {
        $siteId = $this->setupSite([
            'siteId' => Id::unique(),
            'name' => 'Test retention site',
            'framework' => 'other',
            'deploymentRetention' => 180,
            'buildRuntime' => 'node-22',
        ]);
        $this->assertNotEmpty($siteId);

        $deploymentIdInactive = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentIdInactive);

        $deploymentIdInactiveOld = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentIdInactiveOld);

        $deploymentIdActive = $this->setupDeployment($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => true
        ]);
        $this->assertNotEmpty($deploymentIdActive);

        $stdout = '';
        $stderr = '';
        $timeTravel = (new Command('docker'))
            ->argument('exec')
            ->argument('appwrite')
            ->argument('task-time-travel')
            ->argument('--projectId=' . $this->getProject()['$id'])
            ->argument('--resourceType=deployment')
            ->argument('--resourceId=' . $deploymentIdInactiveOld)
            ->argument('--createdAt=2020-01-01T00:00:00Z');
        $code = Console::execute($timeTravel, '', $stdout, $stderr);
        $this->assertSame(0, $code, "Time-travel command failed with code $code: $stderr ($stdout)");

        $stdout = '';
        $stderr = '';
        $code = Console::execute((new Command('docker'))->argument('exec')->argument('appwrite')->argument('maintenance')->argument('--type=trigger'), '', $stdout, $stderr);
        $this->assertSame(0, $code, "Maintenance command failed with code $code: $stderr ($stdout)");

        $this->assertEventually(function () use ($siteId, $deploymentIdInactive, $deploymentIdActive) {
            $response = $this->listDeployments($siteId);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame(2, $response['body']['total']);
            $ids = \array_column($response['body']['deployments'], '$id');
            \sort($ids);
            $expected = [$deploymentIdInactive, $deploymentIdActive];
            \sort($expected);
            $this->assertSame($expected, $ids, 'only the deployment past the requested retention is retired');
        });

        $this->cleanupSite($siteId);
    }

    public function testTemplateDeploymentIsManagedFromTheConsole(): void
    {
        $this->assertTemplateDeploymentIsManageable();
    }
}
