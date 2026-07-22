<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Appwrite\Tests\Async\Exceptions\Critical;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\System\System;

final class VariablesCustomServerTest extends Scope
{
    use VariablesBase;
    use ProjectCustom;
    use SideServer;

    /**
     * Test that project variables are available in function build and runtime.
     */
    public function testProjectVariableInFunction(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        // 1. Create a project variable
        $variable = $this->createVariable(
            ID::unique(),
            'GLOBAL_VARIABLE',
            'Project Variable Value',
            false
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // 2. Create a function with build commands that echo the variable
        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'functionId' => ID::unique(),
            'name' => 'Project Variable Test',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'execute' => ['any'],
            'timeout' => 15,
            'commands' => 'echo $GLOBAL_VARIABLE',
        ]);

        $this->assertSame(201, $function['headers']['status-code']);
        $functionId = $function['body']['$id'];

        // 3. Deploy the function (basic function reads GLOBAL_VARIABLE from env)
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'code' => $this->packageCode('functions', 'basic'),
            'activate' => true,
        ]);

        $this->assertSame(202, $deployment['headers']['status-code']);
        $deploymentId = $deployment['body']['$id'] ?? '';

        // 4. Wait for deployment to be ready and activated
        $this->assertEventually(function () use ($projectId, $apiKey, $functionId, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);

            $status = $deployment['body']['status'] ?? '';
            if ($status === 'failed') {
                throw new Critical('Deployment build failed: ' . ($deployment['body']['buildLogs'] ?? 'no logs'));
            }

            $this->assertSame('ready', $status, 'Deployment status is not ready');
        }, 120000, 500);

        $this->assertEventually(function () use ($projectId, $apiKey, $functionId, $deploymentId) {
            $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);
            $this->assertSame($deploymentId, $function['body']['deploymentId'] ?? '');
        }, 120000, 500);

        // 5. Verify the project variable was available during build. Log
        // callbacks stream asynchronously, so the echoed line can land in
        // buildLogs shortly after the deployment turns ready.
        $this->assertEventually(function () use ($projectId, $apiKey, $functionId, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);
            $this->assertSame(200, $deployment['headers']['status-code']);
            $this->assertStringContainsString('Project Variable Value', (string) $deployment['body']['buildLogs']);
        }, 30000, 500);

        // 6. Execute the function and verify the project variable is in runtime output
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'async' => false,
        ]);

        $this->assertSame(201, $execution['headers']['status-code']);
        $this->assertSame('completed', $execution['body']['status']);
        $this->assertSame(200, $execution['body']['responseStatusCode']);
        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertSame('Project Variable Value', $output['GLOBAL_VARIABLE']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ]);
        $this->deleteVariable($variableId);
    }

    /**
     * Test that project variables are available in site build and SSR runtime.
     */
    public function testProjectVariableInSite(): void
    {
        $projectId = $this->getProject()['$id'];
        $apiKey = $this->getProject()['apiKey'];

        // 1. Create a project variable
        $variable = $this->createVariable(
            ID::unique(),
            'name',
            'ProjectVarTest',
        );

        $this->assertSame(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        // 2. Create a site
        $site = $this->client->call(Client::METHOD_POST, '/sites', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'siteId' => ID::unique(),
            'name' => 'Project Variable Astro Site',
            'framework' => 'astro',
            'adapter' => 'ssr',
            'buildRuntime' => 'node-22',
            'outputDirectory' => './dist',
            'buildCommand' => 'echo $name && npm run build',
            'installCommand' => 'npm ci',
            'fallbackFile' => '',
        ]);

        $this->assertSame(201, $site['headers']['status-code']);
        $siteId = $site['body']['$id'];

        // 3. Setup domain for proxy access
        $sitesDomain = \explode(',', System::getEnv('_APP_DOMAIN_SITES', ''))[0];
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/site', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'domain' => ID::unique() . '.' . $sitesDomain,
            'siteId' => $siteId,
        ]);

        $this->assertSame(201, $rule['headers']['status-code']);

        // 4. Deploy the site (astro site reads import.meta.env.name)
        $deployment = $this->client->call(Client::METHOD_POST, '/sites/' . $siteId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ], [
            'code' => $this->packageCode('sites', 'astro'),
            'activate' => 'true',
        ]);

        $this->assertSame(202, $deployment['headers']['status-code']);
        $deploymentId = $deployment['body']['$id'] ?? '';

        // 5. Wait for deployment to be ready and activated
        $this->assertEventually(function () use ($projectId, $apiKey, $siteId, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);

            $status = $deployment['body']['status'] ?? '';
            if ($status === 'failed') {
                throw new Critical('Site deployment failed: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
            }

            $this->assertSame('ready', $status, 'Deployment status is not ready');
        }, 120000, 500);

        $this->assertEventually(function () use ($projectId, $apiKey, $siteId, $deploymentId) {
            $site = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apiKey,
            ]);
            $this->assertSame($deploymentId, $site['body']['deploymentId'] ?? '');
        }, 120000, 500);

        // 6. Verify the project variable was available during build
        $deployment = $this->client->call(Client::METHOD_GET, '/sites/' . $siteId . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ]);
        $this->assertSame(200, $deployment['headers']['status-code']);
        $this->assertStringContainsString('ProjectVarTest', (string) $deployment['body']['buildLogs']);

        // 7. Get the domain and access the site
        $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('deploymentResourceId', [$siteId])->toString(),
                Query::equal('trigger', ['manual'])->toString(),
                Query::equal('type', ['deployment'])->toString(),
            ],
        ]);

        $this->assertSame(200, $rules['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, \count($rules['body']['rules']));
        $domain = $rules['body']['rules'][0]['domain'];

        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertStringContainsString('Env variable is ProjectVarTest', (string) $response['body']);
        $this->assertStringNotContainsString('Variable not found', (string) $response['body']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/sites/' . $siteId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apiKey,
        ]);
        $this->deleteVariable($variableId);
    }
}
