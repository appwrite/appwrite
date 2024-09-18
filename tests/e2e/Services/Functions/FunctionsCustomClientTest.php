<?php

namespace Tests\E2E\Services\Functions;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

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
            'schedule' => '* * * * *', // execute every minute
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

        $this->assertDeployment($function['body']['$id'], $deploymentId);

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

        $this->assertEventually(function () use ($function) {
            $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/executions', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $executions['headers']['status-code']);
            $this->assertCount(2, $executions['body']['executions']);

            // Check if the scheduled execution has completed
            $scheduledExecution = $executions['body']['executions'][1];
            $this->assertEquals('schedule', $scheduledExecution['trigger']);
            $this->assertEquals('completed', $scheduledExecution['status']);
            $this->assertEquals(200, $scheduledExecution['responseStatusCode']);
            $this->assertEquals('', $scheduledExecution['responseBody']);
            $this->assertNotEmpty($scheduledExecution['logs']);
            $this->assertNotEmpty($scheduledExecution['errors']);
            $this->assertGreaterThan(0, $scheduledExecution['duration']);
        }, 120000, 2000);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $function['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);

        return [];
    }

    public function testCreateScheduledExecution(): void
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
            'timeout' => 10,
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);

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

        $this->assertDeployment($function['body']['$id'], $deploymentId, true);

        // Schedule execution for the future
        \date_default_timezone_set('UTC');
        $futureTime = (new \DateTime())->add(new \DateInterval('PT2M'));
        $futureTime->setTime($futureTime->format('H'), $futureTime->format('i'), 0, 0);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => true,
            'scheduledAt' =>  $futureTime->format(\DateTime::ATOM),
            'path' => '/custom-path',
            'method' => 'PATCH',
            'body' => 'custom-body',
            'headers' => [
                'x-custom-header' => 'custom-value'
            ]
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);
        $this->assertEquals('scheduled', $execution['body']['status']);
        $this->assertEquals('PATCH', $execution['body']['requestMethod']);
        $this->assertEquals('/custom-path', $execution['body']['requestPath']);
        $this->assertCount(0, $execution['body']['requestHeaders']);

        $executionId = $execution['body']['$id'];

        $this->assertEventually(function () use ($function, $executionId) {
            $execution = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/executions/' . $executionId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals('completed', $execution['body']['status']);
        }, 150000, 2000); // Timeout after 150 seconds, wait 2 seconds between retries

        // After ensuring the execution is completed, fetch it again for assertions
        $execution = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/executions/' . $executionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $execution['headers']['status-code']);
        $this->assertEquals(200, $execution['body']['responseStatusCode']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals('/custom-path', $execution['body']['requestPath']);
        $this->assertEquals('PATCH', $execution['body']['requestMethod']);
        $this->assertStringContainsString('body-is-custom-body', $execution['body']['logs']);
        $this->assertStringContainsString('custom-header-is-custom-value', $execution['body']['logs']);
        $this->assertStringContainsString('method-is-patch', $execution['body']['logs']);
        $this->assertStringContainsString('path-is-/custom-path', $execution['body']['logs']);
        $this->assertStringContainsString('user-is-' . $this->getUser()['$id'], $execution['body']['logs']);
        $this->assertStringContainsString('jwt-is-valid', $execution['body']['logs']);
        $this->assertGreaterThan(0, $execution['body']['duration']);

        /* Test for FAILURE */

        // Schedule synchronous execution
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => false,
            'scheduledAt' => $futureTime->format(\DateTime::ATOM),
        ]);

        $this->assertEquals(400, $execution['headers']['status-code']);

        // Execution with seconds precision
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => true,
            'scheduledAt' => (new \DateTime("2100-12-08 16:12:02"))->format(\DateTime::ATOM)
        ]);

        $this->assertEquals(400, $execution['headers']['status-code']);

        // Execution with milliseconds precision
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => true,
            'scheduledAt' => (new \DateTime("2100-12-08 16:12:02.255"))->format(\DateTime::ATOM)
        ]);

        $this->assertEquals(400, $execution['headers']['status-code']);

        // Execution too soon
        $futureTime = (new \DateTime())->add(new \DateInterval('PT1M'));
        $futureTime->setTime($futureTime->format('H'), $futureTime->format('i'), 0, 0);
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $function['body']['$id'] . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => true,
            'scheduledAt' => $futureTime->format(\DateTime::ATOM),
        ]);

        $this->assertEquals(400, $execution['headers']['status-code']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $function['body']['$id'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);
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

        $this->assertDeployment($function['body']['$id'], $deploymentId);

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
        $this->assertGreaterThan(0, $execution['body']['duration']);
        $this->assertEquals('completed', $execution['body']['status']);
        $this->assertEquals($functionId, $output['APPWRITE_FUNCTION_ID']);
        $this->assertEquals('Test', $output['APPWRITE_FUNCTION_NAME']);
        $this->assertEquals($deploymentId, $output['APPWRITE_FUNCTION_DEPLOYMENT']);
        $this->assertEquals('http', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertEquals('PHP', $output['APPWRITE_FUNCTION_RUNTIME_NAME']);
        $this->assertEquals('8.0', $output['APPWRITE_FUNCTION_RUNTIME_VERSION']);
        $this->assertEquals(APP_VERSION_STABLE, $output['APPWRITE_VERSION']);
        $this->assertEquals('default', $output['APPWRITE_REGION']);
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

        $this->assertEventually(function () use ($functionId, $executionId, $projectId, $apikey) {
            $execution = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions/' . $executionId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $apikey,
            ]);

            $this->assertEquals('completed', $execution['body']['status']);
            $this->assertEquals(200, $execution['body']['responseStatusCode']);
        }, 30000, 1000);

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

        $this->assertDeployment($function['body']['$id'], $deploymentId);

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
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(1, $executions['body']['executions']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(2, $executions['body']['executions']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [
                Query::equal('status', ['completed'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(3, $executions['body']['executions']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [
                Query::equal('status', ['failed'])->toString(),
            ],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(0, $executions['body']['executions']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['executions'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(2, $executions['body']['executions']);
        $this->assertEquals($base['body']['executions'][1]['$id'], $executions['body']['executions'][0]['$id']);

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['executions'][1]['$id']]))->toString(),
            ],
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

        $this->assertDeployment($function['body']['$id'], $deploymentId);

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
        $this->assertEquals(APP_VERSION_STABLE, $output['APPWRITE_VERSION']);
        $this->assertEquals('default', $output['APPWRITE_REGION']);
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

    public function testNonOverrideOfHeaders(): array
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
            'runtime' => 'node-18.0',
            'entrypoint' => 'index.js'
        ]);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(201, $function['headers']['status-code']);

        $folder = 'node';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $apikey,
        ], [
            'entrypoint' => 'index.js',
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)), //different tarball names intentional
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);

        $this->assertDeployment($function['body']['$id'], $deploymentId);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'x-appwrite-event' => "OVERRIDDEN",
            'x-appwrite-trigger' => "OVERRIDDEN",
            'x-appwrite-user-id' => "OVERRIDDEN",
            'x-appwrite-user-jwt' => "OVERRIDDEN",
        ]);

        $output = json_decode($execution['body']['responseBody'], true);
        $this->assertNotEquals('OVERRIDDEN', $output['APPWRITE_FUNCTION_JWT']);
        $this->assertNotEquals('OVERRIDDEN', $output['APPWRITE_FUNCTION_EVENT']);
        $this->assertNotEquals('OVERRIDDEN', $output['APPWRITE_FUNCTION_TRIGGER']);
        $this->assertNotEquals('OVERRIDDEN', $output['APPWRITE_FUNCTION_USER_ID']);

        // Cleanup : Delete function
        $response = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $this->assertEquals(204, $response['headers']['status-code']);

        return [];
    }

    public function testListTemplates()
    {
        /**
         * Test for SUCCESS
         */
        $expectedTemplates = array_slice(Config::getParam('function-templates', []), 0, 25);
        $templates = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()));

        $this->assertEquals(200, $templates['headers']['status-code']);
        $this->assertGreaterThan(0, $templates['body']['total']);
        $this->assertIsArray($templates['body']['templates']);

        $this->assertArrayHasKey('runtimes', $templates['body']['templates'][0]);
        $this->assertArrayHasKey('useCases', $templates['body']['templates'][0]);
        for ($i = 0; $i < 25; $i++) {
            $this->assertEquals($expectedTemplates[$i]['name'], $templates['body']['templates'][$i]['name']);
            $this->assertEquals($expectedTemplates[$i]['id'], $templates['body']['templates'][$i]['id']);
            $this->assertEquals($expectedTemplates[$i]['icon'], $templates['body']['templates'][$i]['icon']);
            $this->assertEquals($expectedTemplates[$i]['tagline'], $templates['body']['templates'][$i]['tagline']);
            $this->assertEquals($expectedTemplates[$i]['useCases'], $templates['body']['templates'][$i]['useCases']);
            $this->assertEquals($expectedTemplates[$i]['vcsProvider'], $templates['body']['templates'][$i]['vcsProvider']);
            $this->assertEquals($expectedTemplates[$i]['runtimes'], $templates['body']['templates'][$i]['runtimes']);
            $this->assertEquals($expectedTemplates[$i]['variables'], $templates['body']['templates'][$i]['variables']);
            if (array_key_exists('scopes', $expectedTemplates[$i])) {
                $this->assertEquals($expectedTemplates[$i]['scopes'], $templates['body']['templates'][$i]['scopes']);
            }
        }

        $templates_offset = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'limit' => 1,
            'offset' => 2
        ]);

        $this->assertEquals(200, $templates_offset['headers']['status-code']);
        $this->assertEquals(1, $templates_offset['body']['total']);
        // assert that offset works as expected
        $this->assertEquals($templates['body']['templates'][2]['id'], $templates_offset['body']['templates'][0]['id']);

        $templates = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'useCases' => ['starter', 'ai'],
            'runtimes' => ['bun-1.0', 'dart-2.16']
        ]);

        $this->assertEquals(200, $templates['headers']['status-code']);
        $this->assertGreaterThanOrEqual(3, $templates['body']['total']);
        $this->assertIsArray($templates['body']['templates']);
        foreach ($templates['body']['templates'] as $template) {
            $this->assertContains($template['useCases'][0], ['starter', 'ai']);
        }
        $this->assertArrayHasKey('runtimes', $templates['body']['templates'][0]);
        $this->assertContains('bun-1.0', array_column($templates['body']['templates'][0]['runtimes'], 'name'));

        $templates = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 5,
            'offset' => 2,
            'useCases' => ['databases'],
            'runtimes' => ['node-16.0']
        ]);

        $this->assertEquals(200, $templates['headers']['status-code']);
        $this->assertEquals(5, $templates['body']['total']);
        $this->assertIsArray($templates['body']['templates']);
        $this->assertArrayHasKey('runtimes', $templates['body']['templates'][0]);
        foreach ($templates['body']['templates'] as $template) {
            $this->assertContains($template['useCases'][0], ['databases']);
        }
        $this->assertContains('node-16.0', array_column($templates['body']['templates'][0]['runtimes'], 'name'));

        /**
         * Test for FAILURE
         */
        $templates = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'limit' => 5001,
            'offset' => 10,
        ]);

        $this->assertEquals(400, $templates['headers']['status-code']);
        $this->assertEquals('Invalid `limit` param: Value must be a valid range between 1 and 5,000', $templates['body']['message']);

        $templates = $this->client->call(Client::METHOD_GET, '/functions/templates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 5,
            'offset' => 5001,
        ]);

        $this->assertEquals(400, $templates['headers']['status-code']);
        $this->assertEquals('Invalid `offset` param: Value must be a valid range between 0 and 5,000', $templates['body']['message']);
    }

    public function testGetTemplate()
    {
        /**
         * Test for SUCCESS
         */
        $template = $this->client->call(Client::METHOD_GET, '/functions/templates/query-neo4j-auradb', array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $template['headers']['status-code']);
        $this->assertIsArray($template['body']);
        $this->assertEquals('query-neo4j-auradb', $template['body']['id']);
        $this->assertEquals('Query Neo4j AuraDB', $template['body']['name']);
        $this->assertEquals('icon-neo4j', $template['body']['icon']);
        $this->assertEquals('Graph database with focus on relations between data.', $template['body']['tagline']);
        $this->assertEquals(['databases'], $template['body']['useCases']);
        $this->assertEquals('github', $template['body']['vcsProvider']);

        /**
         * Test for FAILURE
         */
        $template = $this->client->call(Client::METHOD_GET, '/functions/templates/invalid-template-id', array_merge([
            'content-type' => 'application/json',
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $template['headers']['status-code']);
        $this->assertEquals('Function Template with the requested ID could not be found.', $template['body']['message']);
    }
}
