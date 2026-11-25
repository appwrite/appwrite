<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Alerts;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class MonitorsCustomServerTest extends Scope
{
    use MonitorsBase;
    use ProjectCustom;
    use SideServer;

    public function testMonitorScopes(): void
    {
        $created = $this->createMonitor();
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        $read = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getNewKey(['monitors.read']),
        ];

        /**
         * Test for SUCCESS
         */
        $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $read);
        $this->assertSame(200, $monitor['headers']['status-code']);

        // Reading monitors does not read the alerts they raise.
        $alerts = $this->client->call(Client::METHOD_GET, '/alerts', $read);
        $this->assertSame(401, $alerts['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $update = $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $read, ['name' => 'Renamed']);
        $this->assertSame(401, $update['headers']['status-code']);

        $none = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getNewKey(['users.read']),
        ];
        $list = $this->client->call(Client::METHOD_GET, '/monitors', $none);
        $this->assertSame(401, $list['headers']['status-code']);

        $this->assertSame($created['body']['name'], $this->getMonitor($monitorId)['body']['name']);
    }

    public function testMonitorOfAnotherProjectIsNotFound(): void
    {
        $created = $this->createMonitor();
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        $other = $this->getProject(true);
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ];

        /**
         * Test for FAILURE
         */
        $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $headers);
        $this->assertSame(404, $monitor['headers']['status-code']);
        $this->assertSame('monitor_not_found', $monitor['body']['type']);

        $update = $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $headers, ['name' => 'Taken']);
        $this->assertSame(404, $update['headers']['status-code']);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/monitors/' . $monitorId, $headers);
        $this->assertSame(404, $deleted['headers']['status-code']);

        $list = $this->client->call(Client::METHOD_GET, '/monitors', $headers);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertNotContains($monitorId, \array_column($list['body']['monitors'], '$id'));

        $this->assertSame(200, $this->getMonitor($monitorId)['headers']['status-code']);
        $this->assertSame($created['body']['name'], $this->getMonitor($monitorId)['body']['name']);
    }
}
