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

    public function testCreateFunction(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_FUNCTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => ID::unique(),
                'name' => 'Test Function',
                'runtime' => 'php-8.0',
                'entrypoint' => 'index.php',
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

        return $function;
    }

    /**
     * @depends testCreateFunction
     * @param $function
     * @return array
     * @throws \Exception
     */
    public function testCreateDeployment($function): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_DEPLOYMENT);

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
            'code' => $this->packageFunction('php')
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

        $query = $this->getQuery(self::$GET_DEPLOYMENT);
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

        return $deployment;
    }

    /**
     * @depends testCreateFunction
     * @depends testCreateDeployment
     * @param $function
     * @param $deployment
     * @return array
     * @throws \Exception
     */
    public function testCreateExecution($function, $deployment): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_EXECUTION);
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
        return $execution['body']['data']['functionsCreateExecution'];
    }

    /**
     * @depends testCreateFunction
     * @param $function
     * @return array
     * @throws \Exception
     */
    public function testGetExecutions($function): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_EXECUTIONS);
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
     * @depends testCreateFunction
     * @depends testCreateExecution
     * @param $function
     * @param $execution
     * @return array
     * @throws \Exception
     */
    public function testGetExecution($function, $execution): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_EXECUTION);
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
