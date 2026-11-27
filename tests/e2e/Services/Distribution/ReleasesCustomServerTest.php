<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class ReleasesCustomServerTest extends Scope
{
    use DistributionBase;
    use ReleasesBase;
    use ProjectCustom;
    use SideServer;

    public function testReleaseOfAnotherProjectIsNotFound(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createRelease($appId);
        $this->assertSame(201, $created['headers']['status-code']);
        $releaseId = $created['body']['$id'];

        $other = $this->getProject(true);
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ];

        $mirror = $this->client->call(Client::METHOD_POST, '/distribution/apps', $headers, [
            'appId' => $appId,
            'name' => 'Mirror ' . \uniqid(),
            'framework' => 'flutter',
        ]);
        $this->assertSame(201, $mirror['headers']['status-code']);

        $path = '/distribution/apps/' . $appId . '/releases';

        /**
         * Test for FAILURE
         */
        $release = $this->client->call(Client::METHOD_GET, $path . '/' . $releaseId, $headers);
        $this->assertSame(404, $release['headers']['status-code']);
        $this->assertSame('distribution_release_not_found', $release['body']['type']);

        $updated = $this->client->call(Client::METHOD_PATCH, $path . '/' . $releaseId, $headers, ['version' => '9.9.9']);
        $this->assertSame(404, $updated['headers']['status-code']);
        $this->assertSame('distribution_release_not_found', $updated['body']['type']);

        $deleted = $this->client->call(Client::METHOD_DELETE, $path . '/' . $releaseId, $headers);
        $this->assertSame(404, $deleted['headers']['status-code']);
        $this->assertSame('distribution_release_not_found', $deleted['body']['type']);

        $list = $this->client->call(Client::METHOD_GET, $path, $headers);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertSame([], $list['body']['releases']);

        $original = $this->getRelease($appId, $releaseId);
        $this->assertSame(200, $original['headers']['status-code']);
        $this->assertSame($created['body']['version'], $original['body']['version']);
    }
}
