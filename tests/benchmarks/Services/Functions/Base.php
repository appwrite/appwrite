<?php

namespace Tests\Benchmarks\Services\Functions;

use CURLFile;
use PhpBench\Attributes\BeforeMethods;
use Tests\Benchmarks\Scopes\Scope;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Utopia\CLI\Console;
use Utopia\Database\ID;
use Utopia\Database\Role;

abstract class Base extends Scope
{
    use ProjectCustom;

    protected static string $functionId;
    protected static string $deploymentId;
    protected static string $executionId;

    #[BeforeMethods(['createFunction', 'prepareDeployment', 'createDeployment', 'patchDeployment'])]
    public function benchExecutionCreate()
    {
        $this->client->call(Client::METHOD_POST, '/functions/' . static::$functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
    }

    public function createFunction()
    {
        $response = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'php-8.0',
            'timeout' => 10,
            'execute' => [Role::users()->toString()]
        ]);
        static::$functionId = $response['body']['$id'];
    }

    public function prepareDeployment()
    {
        $stdout = '';
        $stderr = '';

        Console::execute(
            'cd ' . realpath(__DIR__ . "/../../resources/functions/php") . " && \
            tar --exclude code.tar.gz -czf code.tar.gz .",
            '',
            $stdout,
            $stderr
        );
    }

    public function createDeployment()
    {
        $code = realpath(__DIR__ . '/../../resources/functions/php/code.tar.gz');

        $response = $this->client->call(Client::METHOD_POST, '/functions/' . static::$functionId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'entrypoint' => 'index.php',
            'code' => new CURLFile(
                $code,
                'application/x-gzip',
                \basename($code)
            ),
        ]);

        static::$deploymentId = $response['body']['$id'];

        while (true) {
            $response = $this->client->call(Client::METHOD_GET, '/functions/' . static::$functionId . '/deployments/' . static::$deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]);

            $status = $response['body']['status'] ?? '';

            switch ($status) {
                case '':
                case 'processing':
                case 'building':
                    usleep(200);
                    break;
                case 'ready':
                    break 2;
                case 'failed':
                    throw new \Exception('Failed to build function');
            }
        }

        sleep(1);
    }

    public function patchDeployment()
    {
        $this->client->call(Client::METHOD_PATCH, '/functions/' . static::$functionId . '/deployments/' . static::$deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);
    }
}
