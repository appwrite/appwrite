<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class AppsCustomServerTest extends Scope
{
    use DistributionBase;
    use AppsBase;
    use ProjectCustom;
    use SideServer;

    public function testAppOfAnotherProjectIsNotFound(): void
    {
        $created = $this->createApp();
        $this->assertSame(201, $created['headers']['status-code']);
        $appId = $created['body']['$id'];

        $other = $this->getProject(true);
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ];

        /**
         * Test for FAILURE
         */
        $app = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId, $headers);
        $this->assertSame(404, $app['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $app['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId, $headers, ['name' => 'Taken']);
        $this->assertSame(404, $update['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $update['body']['type']);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId, $headers);
        $this->assertSame(404, $deleted['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $deleted['body']['type']);

        $list = $this->client->call(Client::METHOD_GET, '/distribution/apps', $headers);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertNotContains($appId, \array_column($list['body']['apps'], '$id'));

        $this->assertSame($created['body']['name'], $this->getApp($appId)['body']['name']);
    }
}
