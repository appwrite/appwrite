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

    protected static array $testFunctionCache = [];
    protected static array $testVariablesCache = [];

    /**
     * Setup a test function for independent tests (with static caching)
     */
    protected function setupTestFunction(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(static::$testFunctionCache[$cacheKey])) {
            return static::$testFunctionCache[$cacheKey];
        }

        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);

        $functionId = $function['body']['$id'];

        static::$testFunctionCache[$cacheKey] = [
            'functionId' => $functionId,
        ];

        return static::$testFunctionCache[$cacheKey];
    }

    /**
     * Setup test variables for independent tests (with static caching)
     */
    protected function setupTestVariables(): array
    {
        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(static::$testVariablesCache[$cacheKey])) {
            return static::$testVariablesCache[$cacheKey];
        }

        $data = $this->setupTestFunction();
        $functionId = $data['functionId'];

        $variable = $this->createVariable(
            $functionId,
            [
                'key' => 'APP_TEST',
                'value' => 'TESTINGVALUE',
                'secret' => false
            ]
        );

        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        $secretVariable = $this->createVariable(
            $functionId,
            [
                'key' => 'APP_TEST_1',
                'value' => 'TESTINGVALUE_1',
                'secret' => true
            ]
        );

        $this->assertEquals(201, $secretVariable['headers']['status-code']);
        $secretVariableId = $secretVariable['body']['$id'];

        static::$testVariablesCache[$cacheKey] = array_merge($data, [
            'variableId' => $variableId,
            'secretVariableId' => $secretVariableId
        ]);

        return static::$testVariablesCache[$cacheKey];
    }

    public function testCreateFunction(): void
    {
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);

        $function2 = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Failure',
            'execute' => ['some-random-string'],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
        ]);

        $this->assertEquals(400, $function2['headers']['status-code']);
    }

    public function testFunctionUsage(): void
    {
        $data = $this->setupTestFunction();

        /**
         * Test for SUCCESS
         */
        $usage = $this->getUsage($data['functionId'], [
            'range' => '24h'
        ]);
        $this->assertEquals(200, $usage['headers']['status-code']);
        $this->assertEquals(24, count($usage['body']));
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
        $usage = $this->getUsage($data['functionId'], [
            'range' => '232h'
        ]);
        $this->assertEquals(400, $usage['headers']['status-code']);

        $usage = $this->getUsage('randomFunctionId', [
            'range' => '24h'
        ]);
        $this->assertEquals(404, $usage['headers']['status-code']);
    }

    public function testCreateFunctionVariable(): void
    {
        // Create a new function for this test to avoid conflicts with cached data
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Variable Creation',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 10,
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $functionId = $function['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $variable = $this->createVariable(
            $functionId,
            [
                'key' => 'APP_TEST',
                'value' => 'TESTINGVALUE',
                'secret' => false
            ]
        );

        $this->assertEquals(201, $variable['headers']['status-code']);

        // test for secret variable
        $variable = $this->createVariable(
            $functionId,
            [
                'key' => 'APP_TEST_1',
                'value' => 'TESTINGVALUE_1',
                'secret' => true
            ]
        );

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals('APP_TEST_1', $variable['body']['key']);
        $this->assertEmpty($variable['body']['value']);
        $this->assertTrue($variable['body']['secret']);

        /**
         * Test for FAILURE
         */
        // Test for duplicate key
        $variable = $this->createVariable(
            $functionId,
            [
                'key' => 'APP_TEST',
                'value' => 'ANOTHERTESTINGVALUE',
                'secret' => false
            ]
        );

        $this->assertEquals(409, $variable['headers']['status-code']);

        // Test for invalid key
        $variable = $this->createVariable(
            $functionId,
            [
                'key' => str_repeat("A", 256),
                'value' => 'TESTINGVALUE'
            ]
        );

        $this->assertEquals(400, $variable['headers']['status-code']);

        // Test for invalid value
        $variable = $this->createVariable(
            $functionId,
            [
                'key' => 'LONGKEY',
                'value' => str_repeat("#", 8193),
            ]
        );

        $this->assertEquals(400, $variable['headers']['status-code']);
    }

    public function testListVariables(): void
    {
        $data = $this->setupTestVariables();

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, sizeof($response['body']['variables']));
        $this->assertEquals(2, $response['body']['total']);
        $this->assertEquals("APP_TEST", $response['body']['variables'][0]['key']);
        $this->assertEquals("TESTINGVALUE", $response['body']['variables'][0]['value']);
        $this->assertEquals("APP_TEST_1", $response['body']['variables'][1]['key']);
        $this->assertEmpty($response['body']['variables'][1]['value']);

        /**
         * Test for FAILURE
         */
    }

    public function testGetVariable(): void
    {
        $data = $this->setupTestVariables();

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

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables/' . $data['secretVariableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST_1", $response['body']['key']);
        $this->assertEmpty($response['body']['value']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'] . '/variables/NON_EXISTING_VARIABLE', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testUpdateVariable(): void
    {
        // Create a fresh function and variables for this test since it modifies them
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Update Variable',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 10,
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $functionId = $function['body']['$id'];

        $variable = $this->createVariable($functionId, [
            'key' => 'APP_TEST',
            'value' => 'TESTINGVALUE',
            'secret' => false
        ]);
        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        $secretVariable = $this->createVariable($functionId, [
            'key' => 'APP_TEST_1',
            'value' => 'TESTINGVALUE_1',
            'secret' => true
        ]);
        $this->assertEquals(201, $secretVariable['headers']['status-code']);
        $secretVariableId = $secretVariable['body']['$id'];

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE',
            'value' => 'TESTINGVALUEUPDATED'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE", $response['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $response['body']['value']);

        $variable = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE", $variable['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $variable['body']['value']);

        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $secretVariableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE_1',
            'value' => 'TESTINGVALUEUPDATED_1'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE_1", $response['body']['key']);
        $this->assertEmpty($response['body']['value']);

        $variable = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/variables/' . $secretVariableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE_1", $variable['body']['key']);
        $this->assertEmpty($variable['body']['value']);

        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE_2',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE_2", $response['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $response['body']['value']);

        $variable = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE_2", $variable['body']['key']);
        $this->assertEquals("TESTINGVALUEUPDATED", $variable['body']['value']);

        // convert non-secret variable to secret
        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE_2',
            'secret' => true
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE_2", $response['body']['key']);
        $this->assertEmpty($response['body']['value']);
        $this->assertTrue($response['body']['secret']);

        $variable = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $variable['headers']['status-code']);
        $this->assertEquals("APP_TEST_UPDATE_2", $variable['body']['key']);
        $this->assertEmpty($variable['body']['value']);
        $this->assertTrue($variable['body']['secret']);

        // convert secret variable to non-secret
        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE',
            'secret' => false
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'value' => 'TESTINGVALUEUPDATED_2'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $longKey = str_repeat("A", 256);
        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => $longKey,
            'value' => 'TESTINGVALUEUPDATED'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $longValue = str_repeat("#", 8193);
        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'APP_TEST_UPDATE',
            'value' => $longValue
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testDeleteVariable(): void
    {
        // Create a fresh function and variables for this test since it deletes them
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'name' => 'Test Delete Variable',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 10,
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $functionId = $function['body']['$id'];

        $variable = $this->createVariable($functionId, [
            'key' => 'APP_TEST',
            'value' => 'TESTINGVALUE',
            'secret' => false
        ]);
        $this->assertEquals(201, $variable['headers']['status-code']);
        $variableId = $variable['body']['$id'];

        $secretVariable = $this->createVariable($functionId, [
            'key' => 'APP_TEST_1',
            'value' => 'TESTINGVALUE_1',
            'secret' => true
        ]);
        $this->assertEquals(201, $secretVariable['headers']['status-code']);
        $secretVariableId = $secretVariable['body']['$id'];

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId . '/variables/' . $variableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId . '/variables/' . $secretVariableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/variables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, sizeof($response['body']['variables']));
        $this->assertEquals(0, $response['body']['total']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId . '/variables/NON_EXISTING_VARIABLE', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testVariableE2E(): void
    {
        $function = $this->createFunction([
            'functionId' => ID::unique(),
            'runtime' => 'node-22',
            'name' => 'Variable E2E Test',
            'entrypoint' => 'index.js',
            'logging' => false,
            'execute' => ['any']
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertFalse($function['body']['logging']);
        $this->assertNotEmpty($function['body']['$id']);

        $functionId = $function['body']['$id'] ?? '';

        // create variable
        $variable = $this->createVariable($functionId, [
            'key' => 'CUSTOM_VARIABLE',
            'value' => 'a_secret_value',
            'secret' => true,
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertNotEmpty($variable['body']['$id']);
        $this->assertEquals('CUSTOM_VARIABLE', $variable['body']['key']);
        $this->assertEquals('', $variable['body']['value']);
        $this->assertEquals(true, $variable['body']['secret']);

        $deploymentId = $this->setupDeployment($functionId, [
            'entrypoint' => 'index.js',
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        $this->assertNotEmpty($deploymentId);

        $execution = $this->createExecution($functionId);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEmpty($execution['body']['logs']);
        $this->assertEmpty($execution['body']['errors']);
        $body = json_decode($execution['body']['responseBody']);
        $this->assertEquals('a_secret_value', $body->CUSTOM_VARIABLE);

        $this->cleanupFunction($functionId);
    }

    public function testFunctionDownload(): void
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'runtime' => 'node-22',
            'name' => 'Download Test',
            'entrypoint' => 'index.js',
            'logging' => false,
            'execute' => ['any']
        ]);

        $deploymentId = $this->setupDeployment($functionId, [
            'entrypoint' => 'index.js',
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        $this->assertNotEmpty($deploymentId);

        $response = $this->getDeploymentDownload($functionId, $deploymentId, 'source');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('application/gzip', $response['headers']['content-type']);
        $this->assertGreaterThan(0, $response['headers']['content-length']);
        $this->assertGreaterThan(0, \strlen($response['body']));

        $deploymentMd5 = \md5($response['body']);

        $response = $this->getDeploymentDownload($functionId, $deploymentId, 'output');
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('application/gzip', $response['headers']['content-type']);
        $this->assertGreaterThan(0, $response['headers']['content-length']);
        $this->assertGreaterThan(0, \strlen($response['body']));

        $buildMd5 = \md5($response['body']);

        $this->assertNotEquals($deploymentMd5, $buildMd5);

        $this->cleanupFunction($functionId);
    }
}
