<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class TargetsCustomServerTest extends Scope
{
    use DistributionBase;
    use TargetsBase;
    use ProjectCustom;
    use SideServer;

    public function testTargetOfAnotherProjectIsNotFound(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createTarget($appId);
        $this->assertSame(201, $created['headers']['status-code']);
        $targetId = $created['body']['$id'];

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

        $path = '/distribution/apps/' . $appId . '/targets';

        /**
         * Test for FAILURE
         */
        $target = $this->client->call(Client::METHOD_GET, $path . '/' . $targetId, $headers);
        $this->assertSame(404, $target['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $target['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, $path . '/' . $targetId, $headers, ['automatic' => true]);
        $this->assertSame(404, $update['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $update['body']['type']);

        $deleted = $this->client->call(Client::METHOD_DELETE, $path . '/' . $targetId, $headers);
        $this->assertSame(404, $deleted['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $deleted['body']['type']);

        $list = $this->client->call(Client::METHOD_GET, $path, $headers);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(0, $list['body']['total']);
        $this->assertSame([], $list['body']['targets']);

        $fetched = $this->getTarget($appId, $targetId);
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertFalse($fetched['body']['automatic']);
    }
}
