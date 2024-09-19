<?php

namespace Tests\E2E\Services\Functions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;

class FunctionsConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;
    use FunctionsBase;

    public function testCreateFunction(): array
    {
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);
        $functionId = $function['body']['$id'];
        $this->assertEquals(201, $function['headers']['status-code']);

        $function2 = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Failure',
            'execute' => ['some-random-string'],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
        ]);
        $this->assertEquals(400, $function2['headers']['status-code']);

        return [
            'functionId' => $functionId,
        ];
    }

    /**
     * @depends testCreateFunction
     */
    public function testFunctionUsage(array $data)
    {
        /**
         * Test for SUCCESS
         */
        $usage = $this->getFunctionUsage($data['functionId'], [
            'range' => '24h'
        ]);
        $this->assertEquals(200, $usage['headers']['status-code']);
        $this->assertEquals(19, count($usage['body']));
        $this->assertEquals('24h', $usage['body']['range']);
        $this->assertIsNumeric($usage['body']['deploymentsTotal']);
        $this->assertIsNumeric($usage['body']['deploymentsStorageTotal']);
        $this->assertIsNumeric($usage['body']['buildsTotal']);
        $this->assertIsNumeric($usage['body']['buildsStorageTotal']);
        $this->assertIsNumeric($usage['body']['buildsTimeTotal']);
        $this->assertIsNumeric($usage['body']['buildsMbSecondsTotal']);
        $this->assertIsNumeric($usage['body']['executionsTotal']);
        $this->assertIsNumeric($usage['body']['executionsTimeTotal']);
        $this->assertIsNumeric($usage['body']['executionsMbSecondsTotal']);
        $this->assertIsArray($usage['body']['deployments']);
        $this->assertIsArray($usage['body']['deploymentsStorage']);
        $this->assertIsArray($usage['body']['builds']);
        $this->assertIsArray($usage['body']['buildsTime']);
        $this->assertIsArray($usage['body']['buildsStorage']);
        $this->assertIsArray($usage['body']['buildsTime']);
        $this->assertIsArray($usage['body']['buildsMbSeconds']);
        $this->assertIsArray($usage['body']['executions']);
        $this->assertIsArray($usage['body']['executionsTime']);
        $this->assertIsArray($usage['body']['executionsMbSeconds']);

        /**
         * Test for FAILURE
         */
        $usage = $this->getFunctionUsage($data['functionId'], [
            'range' => '232h'
        ]);
        $this->assertEquals(400, $usage['headers']['status-code']);

        $usage = $this->getFunctionUsage('randomFunctionId', [
            'range' => '24h'
        ]);
        $this->assertEquals(404, $usage['headers']['status-code']);
    }

    /**
     * @depends testCreateFunction
     */
    public function testCreateFunctionVariable(array $data)
    {
        /**
         * Test for SUCCESS
         */
        $variable = $this->createVariable(
            $data['functionId'],
            [
                'key' => 'APP_TEST',
                'value' => 'TESTINGVALUE'
            ]
        );
        $variableId = $variable['body']['$id'];
        $this->assertEquals(201, $variable['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        // Test for duplicate key
        $variable = $this->createVariable(
            $data['functionId'],
            [
                'key' => 'APP_TEST',
                'value' => 'ANOTHERTESTINGVALUE'
            ]
        );
        $this->assertEquals(409, $variable['headers']['status-code']);

        // Test for invalid key
        $variable = $this->createVariable(
            $data['functionId'],
            [
                'key' => str_repeat("A", 256),
                'value' => 'TESTINGVALUE'
            ]
        );
        $this->assertEquals(400, $variable['headers']['status-code']);

        // Test for invalid value
        $variable = $this->createVariable(
            $data['functionId'],
            [
                'key' => 'LONGKEY',
                'value' => str_repeat("#", 8193),
            ]
        );
        $this->assertEquals(400, $variable['headers']['status-code']);

        return array_merge(
            $data,
            [
                'variableId' => $variableId
            ]
        );
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
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals("APP_TEST", $response['body']['variables'][0]['key']);
        $this->assertEquals("TESTINGVALUE", $response['body']['variables'][0]['value']);

        /**
         * Test for FAILURE
         */

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
        $this->assertEquals(0, $response['body']['total']);

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
