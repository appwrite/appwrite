<?php

namespace Tests\E2E\Services\Functions;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;

class FunctionsCustomClientTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideClient;

    public function testCreate(): array
    {
        /**
         * Test for SUCCESS
         */
        $response1 = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);

        $this->assertEquals(401, $response1['headers']['status-code']);

        return [];
    }

    public function testCreateExecution(): array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
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

        $this->assertEquals(201, $function['headers']['status-code']);

        /** Create Variables */
        $variable = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'funcKey1',
            'value' => 'funcValue1',
        ]);

        $variable2 = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'funcKey2',
            'value' => 'funcValue2',
        ]);

        $variable3 = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'key' => 'funcKey3',
            'value' => 'funcValue3',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals(201, $variable2['headers']['status-code']);
        $this->assertEquals(201, $variable3['headers']['status-code']);

        $folder = 'php';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);

        // Poll until deployment is built
        while (true) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $deployment['headers']['status-code'] >= 400
                || \in_array($deployment['body']['status'], ['ready', 'failed'])
            ) {
                break;
            }

            \sleep(1);
        }

        $this->assertEquals('ready', $deployment['body']['status']);

        $function = $this->client->call(Client::METHOD_PATCH, '/functions/' . $function['body']['$id'] . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(200, $function['headers']['status-code']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'async' => true,
        ]);

        $this->assertEquals(401, $execution['headers']['status-code']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => true,
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $function['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);

        return [];
    }

    public function testCreateCustomExecution(): array
    {
        /**
         * Test for SUCCESS
         */
        $projectId = $this->getProject()['$id'];
        $apikey = $this->getProject()['apiKey'];

        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::any()->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 10,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        /** Create Variables */
        $variable = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'key' => 'funcKey1',
            'value' => 'funcValue1',
        ]);

        $variable2 = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'key' => 'funcKey2',
            'value' => 'funcValue2',
        ]);

        $variable3 = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'key' => 'funcKey3',
            'value' => 'funcValue3',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals(201, $variable2['headers']['status-code']);
        $this->assertEquals(201, $variable3['headers']['status-code']);

        $folder = 'php-fn';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)), //different tarball names intentional
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        // Poll until deployment is built
        while (true) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $deployment['headers']['status-code'] >= 400
                || \in_array($deployment['body']['status'], ['ready', 'failed'])
            ) {
                break;
            }

            \sleep(1);
        }

        $this->assertEquals('ready', $deployment['body']['status']);

        $function = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], []);

        $this->assertEquals(200, $function['headers']['status-code']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'body' => 'foobar',
            'async' => false
        ]);

        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals($functionId, $output['APPWRITE_FUNCTION_ID']);
        $this->assertEquals('Test', $output['APPWRITE_FUNCTION_NAME']);
        $this->assertEquals($deploymentId, $output['APPWRITE_FUNCTION_DEPLOYMENT']);
        $this->assertEquals('http', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertEquals('PHP', $output['APPWRITE_FUNCTION_RUNTIME_NAME']);
        $this->assertEquals('8.0', $output['APPWRITE_FUNCTION_RUNTIME_VERSION']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT']);
        $this->assertEquals('foobar', $output['APPWRITE_FUNCTION_DATA']);
        $this->assertEquals($this->getUser()['$id'], $output['APPWRITE_FUNCTION_USER_ID']);
        $this->assertNotEmpty($output['APPWRITE_FUNCTION_JWT']);
        $this->assertEquals($projectId, $output['APPWRITE_FUNCTION_PROJECT_ID']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'body' => 'foobar',
            'async' => true
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);

        $executionId = $execution['body']['$id'] ?? '';

        sleep(5);

        $execution = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions/' . $executionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ]);

        $this->assertEmpty($execution['body']['responseBody']);
        $this->assertEquals(200, $execution['headers']['status-code']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);

        return [
            'functionId' => $functionId
        ];
    }

    public function testCreateCustomExecutionGuest()
    {
        /**
         * Test for SUCCESS
         */
        $projectId = $this->getProject()['$id'];
        $apikey = $this->getProject()['apiKey'];

        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::any()->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'vars' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
                'funcKey3' => 'funcValue3',
            ],
            'timeout' => 10,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $folder = 'php-fn';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)), //different tarball names intentional
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        // Poll until deployment is built
        while (true) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $deployment['headers']['status-code'] >= 400
                || \in_array($deployment['body']['status'], ['ready', 'failed'])
            ) {
                break;
            }

            \sleep(1);
        }

        $this->assertEquals('ready', $deployment['body']['status']);

        // Why do we have to do this?
        $function = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], []);

        $this->assertEquals(200, $function['headers']['status-code']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'data' => 'foobar',
            'async' => true,
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);
    }

    public function testCreateExecutionNoDeployment(): array
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 10,
        ]);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'async' => true,
        ]);

        $this->assertEquals(404, $execution['headers']['status-code']);

        return [];
    }

    /**
     * @depends testCreateCustomExecution
     */
    public function testListExecutions(array $data)
    {
        $functionId = $data['functionId'];
        $projectId = $this->getProject()['$id'];
        $apikey = $this->getProject()['apiKey'];

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'data' => 'foobar'
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);

        $base = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ]);

        $this->assertEquals(200, $base['headers']['status-code']);
        $this->assertCount(3, $base['body']['executions']);
        $this->assertEquals('completed', $base['body']['executions'][0]['status']);
        $this->assertEquals('completed', $base['body']['executions'][1]['status']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [ 'limit(1)' ]
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(1, $executions['body']['executions']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [ 'offset(1)' ]
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(2, $executions['body']['executions']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [ 'equal("status", ["completed"])' ]
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(3, $executions['body']['executions']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [ 'equal("status", ["failed"])' ]
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(0, $executions['body']['executions']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [ 'cursorAfter("' . $base['body']['executions'][0]['$id'] . '")' ],
        ]);

        $this->assertCount(2, $executions['body']['executions']);
        $this->assertEquals($base['body']['executions'][1]['$id'], $executions['body']['executions'][0]['$id']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [ 'cursorBefore("' . $base['body']['executions'][1]['$id'] . '")' ],
        ]);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    public function testSynchronousExecution(): array
    {
        /**
         * Test for SUCCESS
         */

        $projectId = $this->getProject()['$id'];
        $apikey = $this->getProject()['apiKey'];

        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::any()->toString()],
            'runtime' => 'php-8.0',
            'entrypoint' => 'index.php',
            'timeout' => 10,
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        /** Create Variables */
        $variable = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'key' => 'funcKey1',
            'value' => 'funcValue1',
        ]);

        $variable2 = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'key' => 'funcKey2',
            'value' => 'funcValue2',
        ]);

        $variable3 = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/variables', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'key' => 'funcKey3',
            'value' => 'funcValue3',
        ]);

        $this->assertEquals(201, $variable['headers']['status-code']);
        $this->assertEquals(201, $variable2['headers']['status-code']);
        $this->assertEquals(201, $variable3['headers']['status-code']);

        $folder = 'php-fn';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)), //different tarball names intentional
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);

        // Poll until deployment is built
        while (true) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $deployment['headers']['status-code'] >= 400
                || \in_array($deployment['body']['status'], ['ready', 'failed'])
            ) {
                break;
            }

            \sleep(1);
        }

        $this->assertEquals('ready', $deployment['body']['status']);

        $function = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ]);

        $this->assertEquals(200, $function['headers']['status-code']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'body' => 'foobar',
            // Testing default value, should be 'async' => false
        ]);

        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals($functionId, $output['APPWRITE_FUNCTION_ID']);
        $this->assertEquals('Test', $output['APPWRITE_FUNCTION_NAME']);
        $this->assertEquals($deploymentId, $output['APPWRITE_FUNCTION_DEPLOYMENT']);
        $this->assertEquals('http', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertEquals('PHP', $output['APPWRITE_FUNCTION_RUNTIME_NAME']);
        $this->assertEquals('8.0', $output['APPWRITE_FUNCTION_RUNTIME_VERSION']);
        $this->assertEquals('', $output['APPWRITE_FUNCTION_EVENT']);
        $this->assertEquals('foobar', $output['APPWRITE_FUNCTION_DATA']);
        $this->assertEquals($this->getUser()['$id'], $output['APPWRITE_FUNCTION_USER_ID']);
        $this->assertNotEmpty($output['APPWRITE_FUNCTION_JWT']);
        $this->assertEquals($projectId, $output['APPWRITE_FUNCTION_PROJECT_ID']);
        // Client should never see logs and errors
        $this->assertEmpty($execution['body']['logs']);
        $this->assertEmpty($execution['body']['errors']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);

        return [];
    }
}
