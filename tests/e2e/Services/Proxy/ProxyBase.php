<?php

namespace Tests\E2E\Services\Proxy;

use Appwrite\ID;
use Appwrite\Tests\Async;
use CURLFile;
use Tests\E2E\Client;
use Utopia\CLI\Console;

trait ProxyBase
{
    use Async;

    protected function listRules(array $params = []): mixed
    {
        $rule = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $rule;
    }

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

    protected function updateRuleVerification(string $ruleId): mixed
    {
        $rule = $this->client->call(Client::METHOD_PATCH, '/proxy/rules/' . $ruleId . '/verification', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        return $rule;
    }

    protected function createSiteRule(string $domain, string $siteId, string $branch = ''): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/site', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
            'siteId' => $siteId,
            'branch' => $branch,
        ]);

        return $rule;
    }

    protected function getRule(string $ruleId): mixed
    {
        $rule = $this->client->call(Client::METHOD_GET, '/proxy/rules/' . $ruleId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        return $rule;
    }

    protected function createRedirectRule(string $domain, string $url, int $statusCode, string $resourceType, string $resourceId): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/redirect', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
            'url' => $url,
            'statusCode' => $statusCode,
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
        ]);

        return $rule;
    }

    protected function createFunctionRule(string $domain, string $functionId, string $branch = ''): mixed
    {
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/function', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $domain,
            'functionId' => $functionId,
            'branch' => $branch,
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

    protected function setupRedirectRule(string $domain, string $url, int $statusCode, string $resourceType, string $resourceId): string
    {
        $rule = $this->createRedirectRule($domain, $url, $statusCode, $resourceType, $resourceId);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function setupFunctionRule(string $domain, string $functionId, string $branch = ''): string
    {
        $rule = $this->createFunctionRule($domain, $functionId, $branch);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function setupSiteRule(string $domain, string $siteId, string $branch = ''): string
    {
        $rule = $this->createSiteRule($domain, $siteId, $branch);

        $this->assertEquals(201, $rule['headers']['status-code'], 'Failed to setup rule: ' . \json_encode($rule));

        return $rule['body']['$id'];
    }

    protected function cleanupRule(string $ruleId): void
    {
        $rule = $this->deleteRule($ruleId);
        $this->assertEquals(204, $rule['headers']['status-code'], 'Failed to cleanup rule: ' . \json_encode($rule));
    }

    protected function cleanupSite(string $siteId): void
    {
        $site = $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $site['headers']['status-code'], 'Failed to cleanup site: ' . \json_encode($site));
    }

    protected function cleanupFunction(string $functionId): void
    {
        $function = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $function['headers']['status-code'], 'Failed to cleanup function: ' . \json_encode($function));
    }

    protected function setupSite(): mixed
    {
        // Site
        $site = $this->client->call(Client::METHOD_POST, '/sites', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'siteId' => ID::unique(),
            'name' => 'Proxy site',
            'framework' => 'other',
            'adapter' => 'static',
            'buildRuntime' => 'static-1',
            'outputDirectory' => './',
            'buildCommand' => '',
            'installCommand' => '',
            'fallbackFile' => '',
        ]);

        $this->assertEquals($site['headers']['status-code'], 201, 'Setup site failed with status code: ' . $site['headers']['status-code'] . ' and response: ' . json_encode($site['body'], JSON_PRETTY_PRINT));

        $siteId = $site['body']['$id'];

        // Deployment
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'code' => $this->packageSite('static'),
            'activate' => 'true'
        ]);

        $this->assertEquals($deployment['headers']['status-code'], 202, 'Setup deployment failed with status code: ' . $deployment['headers']['status-code'] . ' and response: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentId) {
            $site = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals($deploymentId, $site['body']['deploymentId'], 'Deployment is not activated, deployment: ' . json_encode($site['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        return ['siteId' => $siteId, 'deploymentId' => $deploymentId];
    }

    protected function setupFunction(): mixed
    {
        // Function
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'functionId' => ID::unique(),
            'runtime' => 'node-22',
            'name' => 'Proxy Function',
            'entrypoint' => 'index.js',
            'commands' => '',
            'execute' => ['any']
        ]);

        $this->assertEquals($function['headers']['status-code'], 201, 'Setup function failed with status code: ' . $function['headers']['status-code'] . ' and response: ' . json_encode($function['body'], JSON_PRETTY_PRINT));

        $functionId = $function['body']['$id'];

        // Deployment
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'code' => $this->packageFunction('basic'),
            'activate' => 'true'
        ]);

        $this->assertEquals($deployment['headers']['status-code'], 202, 'Setup deployment failed with status code: ' . $deployment['headers']['status-code'] . ' and response: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals($deploymentId, $function['body']['deploymentId'], 'Deployment is not activated, deployment: ' . json_encode($function['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        return ['functionId' => $functionId, 'deploymentId' => $deploymentId];
    }

    private function packageSite(string $site): CURLFile
    {
        $stdout = '';
        $stderr = '';

        $folderPath = realpath(__DIR__ . '/../../../resources/sites') . "/$site";
        $tarPath = "$folderPath/code.tar.gz";

        Console::execute("cd $folderPath && tar --exclude code.tar.gz -czf code.tar.gz .", '', $stdout, $stderr);

        if (filesize($tarPath) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        return new CURLFile($tarPath, 'application/x-gzip', \basename($tarPath));
    }

    private function packageFunction(string $function): CURLFile
    {
        $stdout = '';
        $stderr = '';

        $folderPath = realpath(__DIR__ . '/../../../resources/functions') . "/$function";
        $tarPath = "$folderPath/code.tar.gz";

        Console::execute("cd $folderPath && tar --exclude code.tar.gz -czf code.tar.gz .", '', $stdout, $stderr);

        if (filesize($tarPath) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        return new CURLFile($tarPath, 'application/x-gzip', \basename($tarPath));
    }
}
