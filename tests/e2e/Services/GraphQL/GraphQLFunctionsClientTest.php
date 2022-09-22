<?php

namespace Tests\E2E\Services\GraphQL;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\ID;
use Utopia\Database\Role;

class GraphQLFunctionsClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;
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
                'runtime' => 'ruby-3.0',
                'vars' => [
                    'name' => 'John Doe',
                    'age' => 42,
                ]
                'execute' => [Role::any()],
            ]
        ];

        $function = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertIsArray($function['body']['data']);
        $this->assertArrayNotHasKey('errors', $function['body']);

        return $function['body']['data']['functionsCreate'];
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
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/ruby/code.tar.gz";
        $gqlPayload = [
            'operations' => \json_encode([
                'query' => $query,
                'variables' => [
                    'functionId' => $function['_id'],
                    'entrypoint' => 'main.rb',
                    'activate' => true,
                    'code' => null,
                ]
            ]),
            'map' => \json_encode([
                'code' => ["variables.code"]
            ]),
            'code' => new CURLFile($code, 'application/gzip', 'code.tar.gz'),
        ];

        $deployment = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertIsArray($deployment['body']['data']);
        $this->assertArrayNotHasKey('errors', $deployment['body']);

        sleep(15);

        return $deployment['body']['data']['functionsCreateDeployment'];
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
