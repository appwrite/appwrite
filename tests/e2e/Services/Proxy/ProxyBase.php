<?php

namespace Tests\E2E\Services\Proxy;

use Appwrite\Tests\Async;
use Tests\E2E\Client;

trait ProxyBase
{
    use Async;

    // TODO: @Meldiron different kinds of rules, creation failure, list, get, update status

    protected function createAPIRule(string $domain): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
        ]);

        return $rule;
    }

    protected function createSiteRule(string $domain, string $siteId): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/site', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
            'siteId' => $siteId,
        ]);

        return $rule;
    }

    protected function createFunctionRule(string $domain, string $functionId): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/function', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
            'functionId' => $functionId,
        ]);

        return $rule;
    }

    protected function deleteRule(string $ruleId): mixed
    {
        $rule = $this->client->call(Client::METHOD_DELETE, '/proxy/rules/' . $ruleId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        return $rule;
    }

    protected function setupAPIRule(string $domain): string
    {
        $rule = $this->createAPIRule($domain);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function setupFunctionRule(string $domain, string $functionId): string
    {
        $rule = $this->createFunctionRule($domain, $functionId);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function setupSiteRule(string $domain, string $siteId): string
    {
        $rule = $this->createSiteRule($domain, $siteId);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function cleanupRule(string $ruleId): void
    {
        $rule = $this->deleteRule($ruleId);
        $this->assertEquals(204, $rule['headers']['status-code'], 'Failed to cleanup rule: ' . \json_encode($rule));
    }
}
