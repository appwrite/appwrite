<?php

namespace Tests\E2E\Services\GraphQL;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\ID;
use Utopia\Database\Role;

class GraphQLFunctionsServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use GraphQLBase;

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
                'execute' => [Role::any()->toString()],
            ]
        ];

        $function = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

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
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/php/code.tar.gz";
        $gqlPayload = [
            'operations' => \json_encode([
                'query' => $query,
                'variables' => [
                    'functionId' => $function['_id'],
                    'entrypoint' => 'index.php',
                    'activate' => true,
                    'code' => null,
                ]
            ]),
            'map' => \json_encode([
                'code' => ["variables.code"]
            ]),
            'code' => new CURLFile($code, 'application/gzip', 'code.tar.gz'),
        ];

        $deployment = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($deployment['body']['data']);
        $this->assertArrayNotHasKey('errors', $deployment['body']);

        sleep(15);

        return $deployment['body']['data']['functionsCreateDeployment'];
    }

    /**
     * * @depends testCreateFunction
     * @depends testCreateDeployment
     * @param $function
     * @return array
     * @throws \Exception
     */
    public function testCreateExecution($function): array
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
     * @depends testGetDeployment
     * @param $function
     * @param $deployment
     * @return array
     * @throws \Exception
     */
    public function testCreateRetryBuild($function, $deployment): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$RETRY_BUILD);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
                'deploymentId' => $deployment['_id'],
                'buildId' => $deployment['buildId'],
            ]
        ];

        $retryBuild = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($retryBuild['body']['errors']);
        $this->assertEquals("Build not failed", $retryBuild['body']['errors'][0]['message']);
    }

    public function testGetFunctions(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_FUNCTIONS);
        $gqlPayload = [
            'query' => $query,
        ];

        $functions = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($functions['body']['data']);
        $this->assertArrayNotHasKey('errors', $functions['body']);
        $functions = $functions['body']['data']['functionsList'];
        $this->assertIsArray($functions);

        return $functions;
    }

    /**
     * @depends testCreateFunction
     * @param $function
     * @return array
     * @throws \Exception
     */
    public function testGetFunction($function): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_FUNCTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
            ]
        ];

        $function = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($function['body']['data']);
        $this->assertArrayNotHasKey('errors', $function['body']);
        $function = $function['body']['data']['functionsGet'];
        $this->assertIsArray($function);

        return $function;
    }

    public function testGetRuntimes(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_RUNTIMES);
        $gqlPayload = [
            'query' => $query,
        ];

        $runtimes = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($runtimes['body']['data']);
        $this->assertArrayNotHasKey('errors', $runtimes['body']);
        $runtimes = $runtimes['body']['data']['functionsListRuntimes'];
        $this->assertIsArray($runtimes);

        return $runtimes;
    }

    /**
     * @depends testCreateFunction
     * @param $function
     * @return array
     * @throws \Exception
     */
    public function testGetDeployments($function)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_DEPLOYMENTS);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
            ]
        ];

        $deployments = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($deployments['body']['data']);
        $this->assertArrayNotHasKey('errors', $deployments['body']);
        $deployments = $deployments['body']['data']['functionsListDeployments'];
        $this->assertIsArray($deployments);

        return $deployments;
    }

    /**
     * @depends testCreateFunction
     * @depends testCreateDeployment
     * @param $function
     * @return array
     * @throws \Exception
     */
    public function testGetDeployment($function, $deployment)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_DEPLOYMENT);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
                'deploymentId' => $deployment['_id'],
            ]
        ];

        $deployment = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($deployment['body']['data']);
        $this->assertArrayNotHasKey('errors', $deployment['body']);
        $deployment = $deployment['body']['data']['functionsGetDeployment'];
        $this->assertIsArray($deployment);

        return $deployment;
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

    /**
     * @depends testCreateFunction
     * @param $function
     * @return array
     * @throws \Exception
     */
    public function testUpdateFunction($function): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_FUNCTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
                'name' => 'Test Function Updated',
                'execute' => [Role::any()->toString()],
                'vars' => [
                    'name' => 'John Doe',
                    'age' => 42,
                ],
            ]
        ];

        $function = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($function['body']['data']);
        $this->assertArrayNotHasKey('errors', $function['body']);
        $function = $function['body']['data']['functionsUpdate'];
        $this->assertIsArray($function);

        return $function;
    }

    /**
     * @depends testCreateFunction
     * @depends testCreateDeployment
     * @param $function
     * @param $deployment
     * @throws \Exception
     */
    public function testDeleteDeployment($function, $deployment): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_DEPLOYMENT);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
                'deploymentId' => $deployment['_id'],
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($response['body']);
        $this->assertEquals(204, $response['headers']['status-code']);
    }

    /**
     * @depends testCreateFunction
     * @depends testDeleteDeployment
     * @param $function
     * @throws \Exception
     */
    public function testDeleteFunction($function): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_FUNCTION);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'functionId' => $function['_id'],
            ]
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($response['body']);
        $this->assertEquals(204, $response['headers']['status-code']);
    }
}
