<?php

namespace Tests\E2E\Services\GraphQL;

use Appwrite\Tests\Async;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;

class FunctionsClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use Base;
    use Async;

    private static array $cachedFunction = [];
    private static array $cachedDeployment = [];
    private static array $cachedExecution = [];

    protected function setupFunction(): array
    {
        $key = $this->getProject()['$id'];
        if (!empty(static::$cachedFunction[$key])) {
            return static::$cachedFunction[$key];
        }

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_FUNCTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => ID::unique(),
                'name' => 'Test Function',
                'runtime' => 'node-22',
                'entrypoint' => 'index.js',
                'execute' => [Role::any()->toString()],
            ]
        ];

        $function = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertIsArray($function['body']['data']);
        $this->assertArrayNotHasKey('errors', $function['body']);

        $function = $function['body']['data']['functionsCreate'];
        $functionId = $function['_id'];

        $query = '
            mutation createVariables($functionId: String!) {
                var1: functionsCreateVariable(functionId: $functionId, key: "name", value: "John Doe") {
                    _id
                }
                var2: functionsCreateVariable(functionId: $functionId, key: "age", value: "42") {
                    _id
                }
            }
        ';
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $functionId,
            ]
        ];

        $variables = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertIsArray($variables['body']['data']);
        $this->assertArrayNotHasKey('errors', $variables['body']);

        static::$cachedFunction[$key] = $function;
        return $function;
    }

    protected function setupDeployment(): array
    {
        $key = $this->getProject()['$id'];
        if (!empty(static::$cachedDeployment[$key])) {
            return static::$cachedDeployment[$key];
        }

        $function = $this->setupFunction();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_DEPLOYMENT);

        $gqlPayload = [
            'operations' => \json_encode([
                'query' => $query,
                'variables' => [
                    'functionId' => $function['_id'],
                    'activate' => true,
                    'code' => null,
                ]
            ]),
            'map' => \json_encode([
                'code' => ["variables.code"]
            ]),
            'code' => $this->packageFunction('basic')
        ];

        $deployment = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertIsArray($deployment['body']['data']);
        $this->assertArrayNotHasKey('errors', $deployment['body']);

        // Poll get deployment until an error, or status is either 'ready' or 'failed'
        $deployment = $deployment['body']['data']['functionsCreateDeployment'];
        $deploymentId = $deployment['_id'];

        $query = $this->getQuery(self::GET_DEPLOYMENT);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
                'deploymentId' => $deploymentId,
            ]
        ];

        $this->assertEventually(function () use ($projectId, $gqlPayload, &$deployment) {
            $deployment = $this->client->call(Client::METHOD_POST, '/graphql', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], $gqlPayload);

            $this->assertIsArray($deployment['body']['data']);
            $this->assertArrayNotHasKey('errors', $deployment['body']);

            $deployment = $deployment['body']['data']['functionsGetDeployment'];
            $this->assertEquals('ready', $deployment['status']);
        }, 60000);

        static::$cachedDeployment[$key] = $deployment;
        return $deployment;
    }

    protected function setupExecution(): array
    {
        $key = $this->getProject()['$id'];
        if (!empty(static::$cachedExecution[$key])) {
            return static::$cachedExecution[$key];
        }

        $function = $this->setupFunction();
        $this->setupDeployment(); // Ensure deployment exists

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_EXECUTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
            ]
        ];

        $execution = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($execution['body']['data']);
        $this->assertArrayNotHasKey('errors', $execution['body']);

        static::$cachedExecution[$key] = $execution['body']['data']['functionsCreateExecution'];
        return static::$cachedExecution[$key];
    }

    public function testCreateFunction(): void
    {
        $function = $this->setupFunction();
        $this->assertIsArray($function);
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function testCreateDeployment(): void
    {
        $deployment = $this->setupDeployment();
        $this->assertIsArray($deployment);
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function testCreateExecution(): void
    {
        $execution = $this->setupExecution();
        $this->assertIsArray($execution);
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function testGetExecutions(): array
    {
        $function = $this->setupFunction();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_EXECUTIONS);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
            ]
        ];

        $executions = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($executions['body']['data']);
        $this->assertArrayNotHasKey('errors', $executions['body']);
        $executions = $executions['body']['data']['functionsListExecutions'];
        $this->assertIsArray($executions);

        return $executions;
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function testGetExecution(): array
    {
        $function = $this->setupFunction();
        $execution = $this->setupExecution();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_EXECUTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
                'executionId' => $execution['_id'],
            ]
        ];

        $execution = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($execution['body']['data']);
        $this->assertArrayNotHasKey('errors', $execution['body']);
        $execution = $execution['body']['data']['functionsGetExecution'];
        $this->assertIsArray($execution);

        return $execution;
    }
}
