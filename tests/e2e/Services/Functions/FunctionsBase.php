<?php

namespace Tests\E2E\Services\Functions;

use Appwrite\Tests\Async;
use CURLFile;
use Tests\E2E\Client;
use Utopia\CLI\Console;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\System\System;

trait FunctionsBase
{
    use Async;

    protected string $stdout = '';
    protected string $stderr = '';

    protected function setupFunction(mixed $params): string
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $params);

        $this->assertEquals($function['headers']['status-code'], 201, 'Setup function failed with status code: ' . $function['headers']['status-code'] . ' and response: ' . json_encode($function['body'], JSON_PRETTY_PRINT));

        $functionId = $function['body']['$id'];

        return $functionId;
    }

    protected function setupDeployment(string $functionId, mixed $params): string
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $params);
        $this->assertEquals($deployment['headers']['status-code'], 202, 'Setup deployment failed with status code: ' . $deployment['headers']['status-code'] . ' and response: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]));
            $this->assertEquals('ready', $deployment['body']['status'], 'Deployment status is not ready, deployment: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        // Not === so multipart/form-data works fine too
        if (($params['activate'] ?? false) == true) {
            $this->assertEventually(function () use ($functionId, $deploymentId) {
                $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]));
                $this->assertEquals($deploymentId, $function['body']['deploymentId'], 'Deployment is not activated, deployment: ' . json_encode($function['body'], JSON_PRETTY_PRINT));
            }, 100000, 500);
        }

        return $deploymentId;
    }

    protected function cleanupFunction(string $functionId): void
    {
        $function = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertEquals($function['headers']['status-code'], 204);
    }

    protected function createFunction(mixed $params): mixed
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $function;
    }

    protected function updateFunction(string $functionId, mixed $params): mixed
    {
        $function = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $function;
    }

    protected function createVariable(string $functionId, mixed $params): mixed
    {
        $variable = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variable;
    }

    protected function getVariable(string $functionId, string $variableId): mixed
    {
        $variable = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $variable;
    }

    protected function updateVariable(string $functionId, string $variableId, mixed $params): mixed
    {
        $variable = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variable;
    }

    protected function listVariables(string $functionId, mixed $params = []): mixed
    {
        $variables = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $variables;
    }

    protected function deleteVariable(string $functionId, string $variableId): mixed
    {
        $variable = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $variable;
    }

    protected function getFunction(string $functionId): mixed
    {
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $function;
    }

    protected function getDeployment(string $functionId, string $deploymentId): mixed
    {
        $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $deployment;
    }

    protected function getExecution(string $functionId, $executionId): mixed
    {
        $execution = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions/' . $executionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $execution;
    }

    protected function listFunctions(mixed $params = []): mixed
    {
        $functions = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $functions;
    }

    protected function listDeployments(string $functionId, $params = []): mixed
    {
        $deployments = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $deployments;
    }

    protected function listExecutions(string $functionId, mixed $params = []): mixed
    {
        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $executions;
    }

    protected function packageFunction(string $function): CURLFile
    {
        $folderPath = realpath(__DIR__ . '/../../../resources/functions') . "/$function";
        $tarPath = "$folderPath/code.tar.gz";

        Console::execute("cd $folderPath && tar --exclude code.tar.gz -czf code.tar.gz .", '', $this->stdout, $this->stderr);

        if (filesize($tarPath) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        return new CURLFile($tarPath, 'application/x-gzip', \basename($tarPath));
    }

    protected function createDeployment(string $functionId, mixed $params = []): mixed
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $deployment;
    }

    protected function deleteDeployment(string $functionId, string $deploymentId): mixed
    {
        $deployment = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        return $deployment;
    }

    protected function createTemplateDeployment(string $functionId, mixed $params = []): mixed
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments/template', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $deployment;
    }

    protected function getUsage(string $functionId, mixed $params): mixed
    {
        $usage = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $usage;
    }

    protected function getTemplate(string $templateId)
    {
        $template = $this->client->call(Client::METHOD_GET, '/functions/templates/' . $templateId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        return $template;
    }

    protected function helperGetLatestCommit(string $owner, string $repository): ?string
    {
        $ch = curl_init("https://api.github.com/repos/{$owner}/{$repository}/commits/main");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Appwrite',
            'Accept: application/vnd.github.v3+json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $commitData = json_decode($response, true);
            if (isset($commitData['sha'])) {
                return $commitData['sha'];
            }
        }

        return null;
    }

    protected function createExecution(string $functionId, mixed $params = []): mixed
    {
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $execution;
    }

    protected function deleteFunction(string $functionId): mixed
    {
        $function = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $function;
    }

    protected function setupFunctionDomain(string $functionId, string $subdomain = ''): string
    {
        $subdomain = $subdomain ? $subdomain : ID::unique();
        $rule = $this->client->call(Client::METHOD_POST, '/proxy/rules/function', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'domain' => $subdomain . '.' . System::getEnv('_APP_DOMAIN_FUNCTIONS', ''),
            'functionId' => $functionId,
        ]);

        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertNotEmpty($rule['body']['$id']);
        $this->assertNotEmpty($rule['body']['domain']);

        $domain = $rule['body']['domain'];

        return $domain;
    }

    protected function getFunctionDomain(string $functionId): string
    {
        $rules = $this->client->call(Client::METHOD_GET, '/proxy/rules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('deploymentResourceId', [$functionId])->toString(),
                Query::equal('trigger', ['manual'])->toString(),
                Query::equal('type', ['deployment'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $rules['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $rules['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($rules['body']['rules']));
        $this->assertNotEmpty($rules['body']['rules'][0]['domain']);

        $domain = $rules['body']['rules'][0]['domain'];

        return $domain;
    }

    protected function getDeploymentDownload(string $functionId, string $deploymentId, string $type): mixed
    {
        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId . '/download', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'type' => $type
        ]);

        return $response;
    }

    protected function setupDuplicateDeployment(string $functionId, string $deploymentId): string
    {
        $deployment = $this->createDuplicateDeployment($functionId, $deploymentId);
        $this->assertEquals(202, $deployment['headers']['status-code']);

        $deploymentId = $deployment['body']['$id'];
        $this->assertNotEmpty($deploymentId);

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);
            $this->assertEquals('ready', $deployment['body']['status'], 'Deployment status is not ready, deployment: ' . json_encode($deployment['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $function = $this->getFunction($functionId);
            $this->assertEquals($deploymentId, $function['body']['deploymentId'], 'Deployment is not activated, deployment: ' . json_encode($function['body'], JSON_PRETTY_PRINT));
        }, 100000, 500);

        return $deploymentId;
    }

    protected function createDuplicateDeployment(string $functionId, string $deploymentId): mixed
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments/duplicate', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'deploymentId' => $deploymentId,
        ]);

        return $deployment;
    }

    protected function updateFunctionDeployment(string $functionId, string $deploymentId): mixed
    {
        $function = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployment', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'deploymentId' => $deploymentId
        ]);

        return $function;
    }

    protected function cancelDeployment(string $functionId, string $deploymentId): mixed
    {
        $deployment = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId . '/status', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $deployment;
    }

    protected function listSpecifications(): mixed
    {
        $specifications = $this->client->call(Client::METHOD_GET, '/functions/specifications', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $specifications;
    }
}
