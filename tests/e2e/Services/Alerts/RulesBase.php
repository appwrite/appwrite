<?php

namespace Tests\E2E\Services\Alerts;

use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Query;

trait RulesBase
{
    public function testCreateRule(): void
    {
        $name = 'Egress spike ' . \uniqid();

        $created = $this->createRule([
            'name' => $name,
            'threshold' => 2.5e9,
            'window' => 600,
            'cooldown' => 1800,
            'channels' => ['webhook', 'console'],
            'webhookUrl' => 'http://request-catcher-webhook:5000/alerts',
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame(64, \strlen($created['body']['webhookSecret']));

        $rule = $this->getRule($created['body']['$id']);
        $this->assertSame(200, $rule['headers']['status-code']);
        $this->assertSame($name, $rule['body']['name']);
        $this->assertSame($this->getProject()['$id'], $rule['body']['projectId']);
        $this->assertSame('project', $rule['body']['resourceType']);
        $this->assertSame($this->getProject()['$id'], $rule['body']['resourceId']);
        $this->assertSame('bandwidth.outbound', $rule['body']['metric']);
        $this->assertSame('greaterThan', $rule['body']['comparator']);
        $this->assertEquals(2.5e9, $rule['body']['threshold']);
        $this->assertSame(600, $rule['body']['window']);
        $this->assertSame(1800, $rule['body']['cooldown']);
        $this->assertSame(['webhook', 'console'], $rule['body']['channels']);
        $this->assertSame('http://request-catcher-webhook:5000/alerts', $rule['body']['webhookUrl']);
        $this->assertTrue($rule['body']['enabled']);
        $this->assertSame('ok', $rule['body']['status']);
        $this->assertNull($rule['body']['evaluatedAt']);
        $this->assertNull($rule['body']['triggeredAt']);

        // The secret is only returned on creation.
        $this->assertSame('', $rule['body']['webhookSecret']);
    }

    public function testCreateDatabaseRule(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', $this->headers(), [
            'databaseId' => 'unique()',
            'name' => 'Alerted database',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $created = $this->createRule([
            'metric' => 'databases.reads',
            'resourceType' => 'database',
            'resourceId' => $databaseId,
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('database', $created['body']['resourceType']);
        $this->assertSame($databaseId, $created['body']['resourceId']);

        $list = $this->listRules([
            Query::equal('resourceType', ['database'])->toString(),
            Query::equal('resourceId', [$databaseId])->toString(),
        ]);
        $this->assertSame(1, $list['body']['total']);
        $this->assertSame($created['body']['$id'], $list['body']['rules'][0]['$id']);
    }

    public function testCreateRuleFailures(): void
    {
        $before = $this->listRules()['body']['total'];

        /**
         * Test for FAILURE
         */
        $outside = $this->createRule([
            'metric' => 'bandwidth.inbound',
            'resourceType' => 'bucket',
            'resourceId' => 'anything',
        ]);
        $this->assertSame(400, $outside['headers']['status-code']);
        $this->assertSame('alert_rule_invalid_metric', $outside['body']['type']);

        $missing = $this->createRule([
            'metric' => 'files.storage',
            'resourceType' => 'bucket',
            'resourceId' => 'missing',
        ]);
        $this->assertSame(400, $missing['headers']['status-code']);
        $this->assertSame('alert_rule_invalid_resource', $missing['body']['type']);

        $unnamed = $this->createRule([
            'metric' => 'databases.storage',
            'resourceType' => 'database',
        ]);
        $this->assertSame(400, $unnamed['headers']['status-code']);
        $this->assertSame('alert_rule_invalid_resource', $unnamed['body']['type']);

        $webhook = $this->createRule(['channels' => ['webhook']]);
        $this->assertSame(400, $webhook['headers']['status-code']);
        $this->assertSame('alert_rule_webhook_url_missing', $webhook['body']['type']);

        $unknown = $this->createRule(['metric' => 'functions.executions']);
        $this->assertSame(400, $unknown['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $unknown['body']['type']);

        $window = $this->createRule(['window' => 59]);
        $this->assertSame(400, $window['headers']['status-code']);

        $this->assertSame($before, $this->listRules()['body']['total']);

        $first = $this->createRule();
        $this->assertSame(201, $first['headers']['status-code']);

        $duplicate = $this->createRule(['ruleId' => $first['body']['$id']]);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('alert_rule_already_exists', $duplicate['body']['type']);

        $this->assertSame($before + 1, $this->listRules()['body']['total']);
    }

    public function testUpdateRule(): void
    {
        $created = $this->createRule(['name' => 'Before', 'cooldown' => 900]);
        $this->assertSame(201, $created['headers']['status-code']);
        $ruleId = $created['body']['$id'];

        $updated = $this->client->call(Client::METHOD_PUT, '/alerts/rules/' . $ruleId, $this->headers(), [
            'threshold' => 42,
            'enabled' => false,
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('', $updated['body']['webhookSecret']);

        $rule = $this->getRule($ruleId);
        $this->assertEquals(42, $rule['body']['threshold']);
        $this->assertFalse($rule['body']['enabled']);
        $this->assertSame('Before', $rule['body']['name']);
        $this->assertSame(900, $rule['body']['cooldown']);
        $this->assertSame('bandwidth.outbound', $rule['body']['metric']);

        $renamed = $this->client->call(Client::METHOD_PUT, '/alerts/rules/' . $ruleId, $this->headers(), [
            'name' => 'After ' . $ruleId,
        ]);
        $this->assertSame(200, $renamed['headers']['status-code']);

        $found = $this->listRules([Query::search('search', 'After ' . $ruleId)->toString()]);
        $this->assertSame([$ruleId], \array_column($found['body']['rules'], '$id'));

        /**
         * Test for FAILURE
         */
        $outside = $this->client->call(Client::METHOD_PUT, '/alerts/rules/' . $ruleId, $this->headers(), [
            'resourceType' => 'bucket',
            'resourceId' => 'anything',
        ]);
        $this->assertSame(400, $outside['headers']['status-code']);
        $this->assertSame('alert_rule_invalid_metric', $outside['body']['type']);

        $webhook = $this->client->call(Client::METHOD_PUT, '/alerts/rules/' . $ruleId, $this->headers(), [
            'channels' => ['webhook'],
        ]);
        $this->assertSame(400, $webhook['headers']['status-code']);
        $this->assertSame('alert_rule_webhook_url_missing', $webhook['body']['type']);

        $rule = $this->getRule($ruleId);
        $this->assertSame('project', $rule['body']['resourceType']);
        $this->assertSame(['email', 'console'], $rule['body']['channels']);

        $missing = $this->client->call(Client::METHOD_PUT, '/alerts/rules/missing', $this->headers(), [
            'name' => 'Missing',
        ]);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('alert_rule_not_found', $missing['body']['type']);
    }

    public function testDeleteRule(): void
    {
        $created = $this->createRule();
        $this->assertSame(201, $created['headers']['status-code']);
        $ruleId = $created['body']['$id'];

        $deleted = $this->client->call(Client::METHOD_DELETE, '/alerts/rules/' . $ruleId, $this->headers());

        /**
         * Test for SUCCESS
         */
        $this->assertSame(204, $deleted['headers']['status-code']);

        $rule = $this->getRule($ruleId);
        $this->assertSame(404, $rule['headers']['status-code']);
        $this->assertSame('alert_rule_not_found', $rule['body']['type']);

        /**
         * Test for FAILURE
         */
        $again = $this->client->call(Client::METHOD_DELETE, '/alerts/rules/' . $ruleId, $this->headers());
        $this->assertSame(404, $again['headers']['status-code']);
    }

    public function testListRules(): void
    {
        $name = 'Listed ' . \uniqid();
        $first = $this->createRule(['name' => $name . ' one']);
        $second = $this->createRule(['name' => $name . ' two', 'metric' => 'files.storage']);
        $this->assertSame(201, $first['headers']['status-code']);
        $this->assertSame(201, $second['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $searched = $this->listRules([Query::search('search', $name)->toString()]);
        $this->assertSame(200, $searched['headers']['status-code']);
        $this->assertSame(2, $searched['body']['total']);
        foreach ($searched['body']['rules'] as $rule) {
            $this->assertSame('', $rule['webhookSecret']);
        }

        $filtered = $this->listRules([
            Query::search('search', $name)->toString(),
            Query::equal('metric', ['files.storage'])->toString(),
        ]);
        $this->assertSame([$second['body']['$id']], \array_column($filtered['body']['rules'], '$id'));

        $paged = $this->listRules([
            Query::search('search', $name)->toString(),
            Query::orderAsc('$sequence')->toString(),
            Query::cursorAfter(new Document(['$id' => $first['body']['$id']]))->toString(),
        ]);
        $this->assertSame([$second['body']['$id']], \array_column($paged['body']['rules'], '$id'));

        $untotaled = $this->client->call(Client::METHOD_GET, '/alerts/rules', $this->headers(), [
            'queries' => [Query::search('search', $name)->toString()],
            'total' => false,
        ]);
        $this->assertSame(0, $untotaled['body']['total']);
        $this->assertCount(2, $untotaled['body']['rules']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->listRules([Query::equal('webhookSecret', ['x'])->toString()]);
        $this->assertSame(400, $unknown['headers']['status-code']);

        $cursor = $this->listRules([Query::cursorAfter(new Document(['$id' => 'missing']))->toString()]);
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
    protected function createRule(array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/alerts/rules', $this->headers(), \array_merge([
            'ruleId' => 'unique()',
            'name' => 'Rule ' . \uniqid(),
            'metric' => 'bandwidth.outbound',
            'comparator' => 'greaterThan',
            'threshold' => 1000000,
        ], $params));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getRule(string $ruleId): array
    {
        return $this->client->call(Client::METHOD_GET, '/alerts/rules/' . $ruleId, $this->headers());
    }

    /**
     * @param array<string> $queries
     * @return array<string, mixed>
     */
    protected function listRules(array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/alerts/rules', $this->headers(), [
            'queries' => $queries,
        ]);
    }
}
