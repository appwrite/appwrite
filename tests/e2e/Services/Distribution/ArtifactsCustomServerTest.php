<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class ArtifactsCustomServerTest extends Scope
{
    use DistributionBase;
    use ArtifactsBase;
    use ProjectCustom;
    use SideServer;

    public function testArtifactOfAnotherProjectIsNotFound(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->uploadArtifact($appId, $this->fixture(), 'app-unsigned.aab')[0];
        $this->assertSame(202, $created['headers']['status-code']);
        $artifactId = $created['body']['$id'];

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

        $path = '/distribution/apps/' . $appId . '/artifacts';

        /**
         * Test for FAILURE
         */
        $artifact = $this->client->call(Client::METHOD_GET, $path . '/' . $artifactId, $headers);
        $this->assertSame(404, $artifact['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $artifact['body']['type']);

        $download = $this->client->call(Client::METHOD_GET, $path . '/' . $artifactId . '/download', $headers);
        $this->assertSame(404, $download['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $download['body']['type']);

        $deleted = $this->client->call(Client::METHOD_DELETE, $path . '/' . $artifactId, $headers);
        $this->assertSame(404, $deleted['headers']['status-code']);
        $this->assertSame('distribution_artifact_not_found', $deleted['body']['type']);

        $list = $this->client->call(Client::METHOD_GET, $path, $headers);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertSame([], $list['body']['artifacts']);

        $fetched = $this->getArtifact($appId, $artifactId);
        $this->assertSame(200, $fetched['headers']['status-code']);
    }
}
