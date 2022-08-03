<?php

namespace Tests\E2E\Services\Functions;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Client;
use Tests\E2E\Scopes\SideConsole;

class FunctionsConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testCreateFunction(): array
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => 'unique()',
            'name' => 'Test',
            'execute' => ['user:' . $this->getUser()['$id']],
            'runtime' => 'php-8.0',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => 'unique()',
            'name' => 'Test Failure',
            'execute' => ['some-random-string'],
            'runtime' => 'php-8.0'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [
            'functionId' => $function['body']['$id']
        ];
    }

    /**
     * @depends testCreateFunction
     */
    public function testGetCollectionUsage(array $data)
    {
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '232h'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/randomFunctionId/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(count($response['body']), 4);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['functionsExecutions']);
        $this->assertIsArray($response['body']['functionsFailures']);
        $this->assertIsArray($response['body']['functionsCompute']);
    }

    /**
     * @depends testCreateFunction
     */
    public function testCreateFunctionVariable(array $data)
    {
        $response = $this->client->call(Client::METHOD_POST, '/functions/variables/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST',
            'value' => 'TESTINGVALUE'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        return array_merge(
            $data,
            [
                'variableId' => $response['body']['$id']
            ]
        );
    }

    /**
     * Test for FAILURE
     * @depends testCreateFunctionVariable
     */
    public function testCreateDuplicateVariable(array $data)
    {
        $response = $this->client->call(Client::METHOD_POST, '/functions/variables/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST',
            'value' => 'TESTINGVALUE'
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateDuplicateVariable
     */
    public function testListVariables(array $data)
    {
        $response = $this->client->call(Client::METHOD_GET, '/functions/variables/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, sizeof($response['body']['variables']));
        $this->assertEquals("APP_TEST", $response['body']['variables'][0]['key']);
        $this->assertEquals("TESTINGVALUE", $response['body']['variables'][0]['value']);

        return $data;
    }

    /**
     * @depends testListVariables
     */
    public function testGetVariable(array $data)
    {
        $response = $this->client->call(Client::METHOD_GET, '/functions/variables/' . $data['functionId'] . '/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST", $response['body']['key']);
        $this->assertEquals("TESTINGVALUE", $response['body']['value']);

        return $data;
    }

    /**
     * @depends testGetVariable
     */
    public function testUpdateVariable(array $data)
    {
        $response = $this->client->call(Client::METHOD_PUT, '/functions/variables/' . $data['functionId'] . '/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE',
            'value' => 'TESTINGVALUEUPDATED'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $variable = $this->client->call(Client::METHOD_GET, '/functions/variables/' . $data['functionId'] . '/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE", $variable['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $variable['body']['value']);

        return $data;
    }

    /**
     * @depends testUpdateVariable
     */
    public function testDeleteVariable(array $data)
    {
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/variables/' . $data['functionId'] . '/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/variables/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, sizeof($response['body']['variables']));

        return $data;
    }
}
