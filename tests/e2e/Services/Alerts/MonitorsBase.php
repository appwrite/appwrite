<?php

namespace Tests\E2E\Services\Alerts;

use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Query;

trait MonitorsBase
{
    public function testCreateUsageMonitor(): void
    {
        $name = 'Egress spike ' . \uniqid();

        $created = $this->createMonitor([
            'name' => $name,
            'rules' => [Query::greaterThan('network.outbound', 2.5e9)->toString()],
            'window' => 600,
            'cooldown' => 1800,
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        // A monitor only decides when to fire; delivery is up to project events and the team's notifications.
        $this->assertArrayNotHasKey('channels', $created['body']);
        $this->assertArrayNotHasKey('webhookUrl', $created['body']);
        $this->assertArrayNotHasKey('webhookSecret', $created['body']);

        $monitor = $this->getMonitor($created['body']['$id']);
        $this->assertSame(200, $monitor['headers']['status-code']);
        $this->assertSame($name, $monitor['body']['name']);
        $this->assertSame('usage', $monitor['body']['type']);
        $this->assertSame($this->getProject()['$id'], $monitor['body']['projectId']);
        $this->assertSame('project', $monitor['body']['resourceType']);
        $this->assertSame($this->getProject()['$id'], $monitor['body']['resourceId']);
        $this->assertSame([Query::greaterThan('network.outbound', 2.5e9)->toString()], $monitor['body']['rules']);
        $this->assertSame(600, $monitor['body']['window']);
        $this->assertSame(1800, $monitor['body']['cooldown']);
        $this->assertTrue($monitor['body']['enabled']);
        $this->assertSame(['owner'], $monitor['body']['notify']);
        $this->assertSame('ok', $monitor['body']['status']);
        $this->assertSame('', $monitor['body']['alertId']);
        $this->assertNull($monitor['body']['evaluatedAt']);
        $this->assertNull($monitor['body']['triggeredAt']);
    }

    public function testCreateDatabaseScopedMonitor(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', $this->headers(), [
            'databaseId' => 'unique()',
            'name' => 'Monitored database',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $created = $this->createMonitor([
            'rules' => [
                Query::greaterThan('databases.operations.reads', 1000)->toString(),
                Query::equal('resourceType', ['database'])->toString(),
                Query::equal('resourceId', [$databaseId])->toString(),
            ],
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('database', $created['body']['resourceType']);
        $this->assertSame($databaseId, $created['body']['resourceId']);

        $list = $this->listMonitors([
            Query::equal('type', ['usage'])->toString(),
            Query::equal('resourceType', ['database'])->toString(),
            Query::equal('resourceId', [$databaseId])->toString(),
        ]);
        $this->assertSame(1, $list['body']['total']);
        $this->assertSame($created['body']['$id'], $list['body']['monitors'][0]['$id']);
    }

    public function testCreateMonitorFailures(): void
    {
        $before = $this->listMonitors()['body']['total'];

        /**
         * Test for FAILURE
         */
        $failures = [
            'metric not recorded for the resource' => [[
                Query::greaterThan('network.inbound', 1)->toString(),
                Query::equal('resourceType', ['bucket'])->toString(),
                Query::equal('resourceId', ['anything'])->toString(),
            ], "not recorded for resource type 'bucket'"],
            'unknown metric' => [[Query::greaterThan('functions.executions', 1)->toString()], 'which is not a metric'],
            'no comparison' => [[Query::equal('resourceType', ['bucket'])->toString()], 'needs one rule comparing a metric'],
            'two comparisons' => [[Query::greaterThan('files.storage', 1)->toString(), Query::lessThan('files.storage', 9)->toString()], 'one metric comparison'],
            'unsupported method' => [[Query::between('files.storage', 1, 2)->toString()], "uses 'between'"],
            'resource without its ID' => [[Query::greaterThan('databases.storage', 1)->toString(), Query::equal('resourceType', ['database'])->toString()], "A rule on 'resourceId' is required"],
            'not a query' => [['greaterThan(files.storage)'], 'is not a valid query'],
        ];
        foreach ($failures as $case => [$rules, $message]) {
            $refused = $this->createMonitor(['rules' => $rules]);
            $this->assertSame(400, $refused['headers']['status-code'], $case);
            $this->assertSame('monitor_rules_invalid', $refused['body']['type'], $case);
            $this->assertStringContainsString($message, $refused['body']['message'], $case);
        }

        $missing = $this->createMonitor(['rules' => [
            Query::greaterThan('storage', 1)->toString(),
            Query::equal('resourceType', ['bucket'])->toString(),
            Query::equal('resourceId', ['missing'])->toString(),
        ]]);
        $this->assertSame(400, $missing['headers']['status-code']);
        $this->assertSame('monitor_resource_not_found', $missing['body']['type']);

        $window = $this->createMonitor(['window' => 59]);
        $this->assertSame(400, $window['headers']['status-code']);

        $role = $this->createMonitor(['notify' => ['guests']]);
        $this->assertSame(400, $role['headers']['status-code']);

        $this->assertSame($before, $this->listMonitors()['body']['total']);

        $first = $this->createMonitor();
        $this->assertSame(201, $first['headers']['status-code']);

        $duplicate = $this->createMonitor(['monitorId' => $first['body']['$id']]);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('monitor_already_exists', $duplicate['body']['type']);

        $this->assertSame($before + 1, $this->listMonitors()['body']['total']);
    }

    public function testUpdateUsageMonitor(): void
    {
        $created = $this->createMonitor(['name' => 'Before', 'cooldown' => 900]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        $updated = $this->updateMonitor($monitorId, [
            'rules' => [Query::lessThanEqual('files.storage', 42)->toString()],
            'enabled' => false,
            'notify' => ['owner', 'developer'],
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(200, $updated['headers']['status-code']);

        $monitor = $this->getMonitor($monitorId);
        $this->assertSame([Query::lessThanEqual('files.storage', 42)->toString()], $monitor['body']['rules']);
        $this->assertFalse($monitor['body']['enabled']);
        $this->assertSame(['owner', 'developer'], $monitor['body']['notify']);
        $this->assertSame('Before', $monitor['body']['name']);
        $this->assertSame(900, $monitor['body']['cooldown']);

        $renamed = $this->updateMonitor($monitorId, ['name' => 'After ' . $monitorId]);
        $this->assertSame(200, $renamed['headers']['status-code']);
        // Rules left out are kept.
        $this->assertSame([Query::lessThanEqual('files.storage', 42)->toString()], $renamed['body']['rules']);
        $this->assertSame(['owner', 'developer'], $renamed['body']['notify']);

        $found = $this->listMonitors([Query::search('search', 'After ' . $monitorId)->toString()]);
        $this->assertSame([$monitorId], \array_column($found['body']['monitors'], '$id'));

        /**
         * Test for FAILURE
         */
        $outside = $this->updateMonitor($monitorId, ['rules' => [
            Query::greaterThan('network.inbound', 1)->toString(),
            Query::equal('resourceType', ['bucket'])->toString(),
            Query::equal('resourceId', ['anything'])->toString(),
        ]]);
        $this->assertSame(400, $outside['headers']['status-code']);
        $this->assertSame('monitor_rules_invalid', $outside['body']['type']);

        $monitor = $this->getMonitor($monitorId);
        $this->assertSame('project', $monitor['body']['resourceType']);
        $this->assertSame([Query::lessThanEqual('files.storage', 42)->toString()], $monitor['body']['rules']);

        $role = $this->updateMonitor($monitorId, ['notify' => ['users']]);
        $this->assertSame(400, $role['headers']['status-code']);

        $silenced = $this->updateMonitor($monitorId, ['notify' => []]);
        $this->assertSame([], $silenced['body']['notify']);

        $missing = $this->updateMonitor('missing', ['name' => 'Missing']);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('monitor_not_found', $missing['body']['type']);
    }

    public function testDeleteMonitor(): void
    {
        $created = $this->createMonitor();
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        $deleted = $this->client->call(Client::METHOD_DELETE, '/monitors/' . $monitorId, $this->headers());

        /**
         * Test for SUCCESS
         */
        $this->assertSame(204, $deleted['headers']['status-code']);

        $monitor = $this->getMonitor($monitorId);
        $this->assertSame(404, $monitor['headers']['status-code']);
        $this->assertSame('monitor_not_found', $monitor['body']['type']);

        /**
         * Test for FAILURE
         */
        $again = $this->client->call(Client::METHOD_DELETE, '/monitors/' . $monitorId, $this->headers());
        $this->assertSame(404, $again['headers']['status-code']);
    }

    public function testListMonitors(): void
    {
        $name = 'Listed ' . \uniqid();
        $first = $this->createMonitor(['name' => $name . ' one']);
        $second = $this->createMonitor(['name' => $name . ' two', 'rules' => [Query::greaterThan('files.storage', 1)->toString()]]);
        $this->assertSame(201, $first['headers']['status-code']);
        $this->assertSame(201, $second['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $searched = $this->listMonitors([Query::search('search', $name)->toString()]);
        $this->assertSame(200, $searched['headers']['status-code']);
        $this->assertSame(2, $searched['body']['total']);

        $filtered = $this->listMonitors([
            Query::search('search', $name)->toString(),
            Query::equal('metric', ['files.storage'])->toString(),
        ]);
        $this->assertSame([$second['body']['$id']], \array_column($filtered['body']['monitors'], '$id'));

        $paged = $this->listMonitors([
            Query::search('search', $name)->toString(),
            Query::orderAsc('$sequence')->toString(),
            Query::cursorAfter(new Document(['$id' => $first['body']['$id']]))->toString(),
        ]);
        $this->assertSame([$second['body']['$id']], \array_column($paged['body']['monitors'], '$id'));

        $untotaled = $this->client->call(Client::METHOD_GET, '/monitors', $this->headers(), [
            'queries' => [Query::search('search', $name)->toString()],
            'total' => false,
        ]);
        $this->assertSame(0, $untotaled['body']['total']);
        $this->assertCount(2, $untotaled['body']['monitors']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->listMonitors([Query::equal('channels', ['email'])->toString()]);
        $this->assertSame(400, $unknown['headers']['status-code']);

        $cursor = $this->listMonitors([Query::cursorAfter(new Document(['$id' => 'missing']))->toString()]);
        $this->assertSame(400, $cursor['headers']['status-code']);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createMonitor(array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), \array_merge([
            'monitorId' => 'unique()',
            'name' => 'Monitor ' . \uniqid(),
            'rules' => [Query::greaterThan('network.outbound', 1000000)->toString()],
        ], $params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function updateMonitor(string $monitorId, array $params): array
    {
        return $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $this->headers(), $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getMonitor(string $monitorId): array
    {
        return $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers());
    }

    /**
     * @param array<string> $queries
     * @return array<string, mixed>
     */
    protected function listMonitors(array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/monitors', $this->headers(), [
            'queries' => $queries,
        ]);
    }

    /**
     * @param array<string> $queries
     * @return array<string, mixed>
     */
    protected function listAlerts(array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/alerts', $this->headers(), [
            'queries' => $queries,
        ]);
    }
}
