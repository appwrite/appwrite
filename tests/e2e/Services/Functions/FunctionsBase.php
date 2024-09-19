<?php

namespace Tests\E2E\Services\Functions;

use Appwrite\Tests\Async;
use CURLFile;
use Tests\E2E\Client;
use Utopia\CLI\Console;

trait FunctionsBase
{
    use Async;

    protected string $output = '';

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
            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

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

    protected function createVariable(string $functionId, mixed $params): mixed
    {
        $variable = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

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

    protected function getExecution($functionId, $executionId)
    {
        $execution = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions/' . $executionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $execution;
    }

    protected function listFunctions($params = [])
    {
        $functions = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $functions;
    }

    protected function listDeployments($functionId, $params = [])
    {
        $deployments = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $deployments;
    }

    protected function listExecutions($functionId, $params = [])
    {
        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $executions;
    }

    protected function packageFunction(string $folder = 'php'): CURLFile
    {
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";

        if (!file_exists($code)) {
            Console::execute('cd ' . realpath(__DIR__ . "/../../../resources/functions") . "/$folder  && tar --exclude code.tar.gz -czf code.tar.gz .", '', $this->output);
        }
        if (filesize($code) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        return new CURLFile($code, 'application/x-gzip', \basename($code));
    }

    protected function createDeployment(
        string $functionId,
        mixed $params
    ) {
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $deployment;
    }

    protected function getFunctionUsage(string $functionId, mixed $params): mixed
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
        ], $this->getHeaders()));

        return $template;
    }

    protected function createExecution(string $functionId, mixed $params = [])
    {
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        return $execution;
    }

    protected function deleteFunction($functionId)
    {
        $function = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        return $function;
    }
}
