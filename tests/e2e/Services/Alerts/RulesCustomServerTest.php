<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Alerts;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class RulesCustomServerTest extends Scope
{
    use RulesBase;
    use ProjectCustom;
    use SideServer;

    public function testRuleScopes(): void
    {
        $created = $this->createRule();
        $this->assertSame(201, $created['headers']['status-code']);
        $ruleId = $created['body']['$id'];

        $read = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getNewKey(['alertRules.read']),
        ];

        /**
         * Test for SUCCESS
         */
        $rule = $this->client->call(Client::METHOD_GET, '/alerts/rules/' . $ruleId, $read);
        $this->assertSame(200, $rule['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $update = $this->client->call(Client::METHOD_PUT, '/alerts/rules/' . $ruleId, $read, ['name' => 'Renamed']);
        $this->assertSame(401, $update['headers']['status-code']);

        $none = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getNewKey(['users.read']),
        ];
        $list = $this->client->call(Client::METHOD_GET, '/alerts/rules', $none);
        $this->assertSame(401, $list['headers']['status-code']);

        $this->assertSame($created['body']['name'], $this->getRule($ruleId)['body']['name']);
    }

    public function testRuleOfAnotherProjectIsNotFound(): void
    {
        $created = $this->createRule();
        $this->assertSame(201, $created['headers']['status-code']);
        $ruleId = $created['body']['$id'];

        $other = $this->getProject(true);
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ];

        /**
         * Test for FAILURE
         */
        $rule = $this->client->call(Client::METHOD_GET, '/alerts/rules/' . $ruleId, $headers);
        $this->assertSame(404, $rule['headers']['status-code']);
        $this->assertSame('alert_rule_not_found', $rule['body']['type']);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/alerts/rules/' . $ruleId, $headers);
        $this->assertSame(404, $deleted['headers']['status-code']);

        $list = $this->client->call(Client::METHOD_GET, '/alerts/rules', $headers);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertNotContains($ruleId, \array_column($list['body']['rules'], '$id'));

        $this->assertSame(200, $this->getRule($ruleId)['headers']['status-code']);
    }
}
