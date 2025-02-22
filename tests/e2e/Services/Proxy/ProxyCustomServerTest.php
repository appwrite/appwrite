<?php

namespace Tests\E2E\Services\Proxy;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class ProxyCustomServerTest extends Scope
{
    use ProxyBase;
    use ProjectCustom;
    use SideServer;

    public function testCreateRule(): void
    {
        $rule = $this->createAPIRule('api.myapp.com');

        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals('api.myapp.com', $rule['body']['domain']);
        $this->assertArrayHasKey('$id', $rule['body']);
        $this->assertArrayHasKey('resourceType', $rule['body']);
        $this->assertArrayHasKey('resourceId', $rule['body']);
        $this->assertArrayHasKey('status', $rule['body']);
        $this->assertArrayHasKey('logs', $rule['body']);
        $this->assertArrayHasKey('renewAt', $rule['body']);

        $ruleId = $rule['body']['$id'];

        $rule = $this->deleteRule($ruleId);

        $this->assertEquals(204, $rule['headers']['status-code']);
    }

    public function testCreateRuleSetup(): void
    {
        $ruleId = $this->setupAPIRule('api2.myapp.com');
        $this->cleanupRule($ruleId);
    }

    public function testCreateRuleApex(): void
    {
        $rule = $this->createAPIRule('myapp.com');
        $this->assertEquals(400, $rule['headers']['status-code']);
    }
}
