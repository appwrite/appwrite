<?php

namespace Tests\E2E\Services\Functions;

use Appwrite\Tests\Async;
use CURLFile;
use Tests\E2E\Client;
use Utopia\CLI\Console;

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
        $functionId = $function['body']['$id'];

        $this->assertEquals($function['headers']['status-code'], 201);
        return $functionId;
    }

    protected function setupDeployment(string $functionId, mixed $params): string
    {
        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), $params);
        $deploymentId = $deployment['body']['$id'];

        $this->assertEquals($deployment['headers']['status-code'], 202);

        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->getDeployment($functionId, $deploymentId);
            $this->assertEquals('completed', $deployment['body']['status']);
        }, 200000, 500);

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

    protected function createFunction(mixed $params)
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);
        $functionId = $function['body']['$id'];

        if (empty($variables)) {
            return $function;
        }

        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $function;
    }

    protected function createVariable($functionId, $key, $value)
    {
        $variable = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => $key,
            'value' => $value
        ]);

        return $variable;
    }

    protected function getFunction($functionId)
    {
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        return $function;
    }

    protected function getDeployment($functionId, $deploymentId)
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

    protected function listDeployments($functionId, $params = [])
    {
        $deployments = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), $params));

        return $deployments;
    }

    protected function listExecutions($functionId, $params = [])
    {
        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), $params));

        return $executions;
    }

    protected function packageFunction(string $folder = 'php')
    {
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";

        Console::execute('cd ' . realpath(__DIR__ . "/../../../resources/functions") . "/$folder  && tar --exclude code.tar.gz -czf code.tar.gz .", '', $this->stdout, $this->stderr);

        if (!file_exists($code)) {
            throw new \Exception('Failed to create code package. ' . $code . ' does not exist.');
        }
        if (filesize($code) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        return new CURLFile($code, 'application/x-gzip', \basename($code));
    }

    protected function createDeployment(
        string $functionId,
        mixed $params,
        bool $cli = false,
        bool $admin = false
    ) {
        $authHeaders = $this->getHeaders();

        if ($admin) {
            $authHeaders = [
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ];
        }
        if ($cli) {
            $authHeaders[] = 'x-sdk-language: cli';
        }

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],

        ], $params);

        return $deployment;
    }

    protected function getFunctionUsage($functionId, $params)
    {
        $usage = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders(), $params));

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

    protected function createExecution(string $functionId, mixed $params)
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
