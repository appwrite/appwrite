<?php

namespace Tests\E2E\Services\Functions;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Client;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\ID;
use Utopia\Database\Role;

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
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
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
            'functionId' => ID::unique(),
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
        $this->assertEquals(count($response['body']), 9);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['executionsTotal']);
        $this->assertIsArray($response['body']['executionsFailure']);
        $this->assertIsArray($response['body']['executionsSuccess']);
        $this->assertIsArray($response['body']['executionsTime']);
        $this->assertIsArray($response['body']['buildsTotal']);
        $this->assertIsArray($response['body']['buildsFailure']);
        $this->assertIsArray($response['body']['buildsSuccess']);
        $this->assertIsArray($response['body']['buildsTime']);
    }

    /**
     * @depends testCreateFunction
     */
    public function testCreateFunctionVariable(array $data)
    {
        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST',
            'value' => 'TESTINGVALUE'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $variableId = $response['body']['$id'];

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST',
            'value' => 'ANOTHER_TESTINGVALUE'
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);

        return array_merge(
            $data,
            [
                'variableId' => $variableId
            ]
        );

        $longKey = str_repeat("A", 256);
        $response = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => $longKey,
            'value' => 'TESTINGVALUE'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $longValue = str_repeat("#", 8193);
        $response = $this->client->call(Client::METHOD_POST, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'LONGKEY',
            'value' => $longValue
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * @depends testCreateFunctionVariable
     */
    public function testListVariables(array $data)
    {
        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, sizeof($response['body']['variables']));
        $this->assertEquals("APP_TEST", $response['body']['variables'][0]['key']);
        $this->assertEquals("TESTINGVALUE", $response['body']['variables'][0]['value']);

        $variableId = $response['body']['variables'][0]['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'limit(0)' ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(0, $response['body']['variables']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'offset(1)' ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(0, $response['body']['variables']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("key", "APP_TEST")' ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['variables']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $variableId
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['variables']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("key", "NON_EXISTING_VARIABLE")' ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(0, $response['body']['variables']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("value", "MY_SECRET")' ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testListVariables
     */
    public function testGetVariable(array $data)
    {
        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST", $response['body']['key']);
        $this->assertEquals("TESTINGVALUE", $response['body']['value']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables/NON_EXISTING_VARIABLE', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testGetVariable
     */
    public function testUpdateVariable(array $data)
    {
        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'] . '/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE',
            'value' => 'TESTINGVALUEUPDATED'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE", $response['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $response['body']['value']);

        $variable = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE", $variable['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $variable['body']['value']);

        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'] . '/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE_2',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE_2", $response['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $response['body']['value']);

        $variable = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE_2", $variable['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $variable['body']['value']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'] . '/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'] . '/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'value' => 'TESTINGVALUEUPDATED_2'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $longKey = str_repeat("A", 256);
        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'] . '/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => $longKey,
            'value' => 'TESTINGVALUEUPDATED'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $longValue = str_repeat("#", 8193);
        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $data['functionId'] . '/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE',
            'value' => $longValue
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateVariable
     */
    public function testDeleteVariable(array $data)
    {
        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/variables/' . $data['variableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, sizeof($response['body']['variables']));

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $data['functionId'] . '/variables/NON_EXISTING_VARIABLE', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }
}
