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
        $domain = \uniqid() . '-api.myapp.com';
        $rule = $this->createAPIRule($domain);

        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertEquals($domain, $rule['body']['domain']);
        $this->assertArrayHasKey('$id', $rule['body']);
        $this->assertArrayHasKey('type', $rule['body']);
        $this->assertArrayHasKey('value', $rule['body']);
        $this->assertArrayHasKey('automation', $rule['body']);
        $this->assertArrayHasKey('status', $rule['body']);
        $this->assertArrayHasKey('logs', $rule['body']);
        $this->assertArrayHasKey('renewAt', $rule['body']);

        $ruleId = $rule['body']['$id'];

        $rule = $this->deleteRule($ruleId);

        $this->assertEquals(204, $rule['headers']['status-code']);
    }

    public function testCreateRuleSetup(): void
    {
        $ruleId = $this->setupAPIRule(\uniqid() . '-api2.myapp.com');
        $this->cleanupRule($ruleId);
    }

    public function testCreateRuleApex(): void
    {
        $rule = $this->createAPIRule('myapp.com');
        $this->assertEquals(400, $rule['headers']['status-code']);
    }
}
