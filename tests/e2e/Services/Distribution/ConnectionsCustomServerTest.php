<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class ConnectionsCustomServerTest extends Scope
{
    use DistributionBase;
    use MockStores;
    use ConnectionsBase;
    use ProjectCustom;
    use SideServer;

    public function testConnectionOfAnotherProjectIsNotFound(): void
    {
        $created = $this->createGoogleConnection();
        $this->assertSame(201, $created['headers']['status-code']);
        $connectionId = $created['body']['$id'];

        $other = $this->getProject(true);
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ];

        /**
         * Test for FAILURE
         */
        $connection = $this->client->call(Client::METHOD_GET, '/distribution/connections/' . $connectionId, $headers);
        $this->assertSame(404, $connection['headers']['status-code']);
        $this->assertSame('distribution_connection_not_found', $connection['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/distribution/connections/google/' . $connectionId, $headers, ['name' => 'Taken']);
        $this->assertSame(404, $update['headers']['status-code']);
        $this->assertSame('distribution_connection_not_found', $update['body']['type']);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/distribution/connections/' . $connectionId, $headers);
        $this->assertSame(404, $deleted['headers']['status-code']);

        $list = $this->client->call(Client::METHOD_GET, '/distribution/connections', $headers);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertNotContains($connectionId, \array_column($list['body']['connections'], '$id'));

        $this->assertSame($created['body']['name'], $this->getConnection($connectionId)['body']['name']);
    }
}
