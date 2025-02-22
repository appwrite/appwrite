<?php

namespace Tests\E2E\Services\Proxy;

use Appwrite\Tests\Async;
use Tests\E2E\Client;

trait ProxyBase
{
    use Async;

    protected function createRule(mixed $params): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

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

    protected function setupRule(mixed $params): string
    {
        $rule = $this->createRule($params);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function cleanupRule(string $ruleId): void
    {
        $rule = $this->deleteRule($ruleId);
        $this->assertEquals(204, $rule['headers']['status-code'], 'Failed to cleanup rule: ' . \json_encode($rule));
    }
}
