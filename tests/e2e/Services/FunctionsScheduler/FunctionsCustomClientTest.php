<?php

namespace Tests\E2E\Services\FunctionsScheduler;

use Appwrite\Tests\Retry;
use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;

class FunctionsCustomClientTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideClient;

    #[Retry(count: 2)]
    public function testCreateScheduledCronExecution(): array
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

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId);

        $function = $this->client->call(Client::METHOD_PATCH, '/functions/' . $function['body']['$id'] . '/deployments/' . $deploymentId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], []);

        $functionId = $function['body']['$id'] ?? '';

        $this->assertEquals(200, $function['headers']['status-code']);

        // Wait for the first scheduled execution to be created
        sleep(70);

        $startTime = time();
        $maxWaitTime = 30;
        while (true) {
            $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if (
                $executions['headers']['status-code'] >= 400
                || (
                    isset($executions['body']['executions'][0])
                    && \in_array($executions['body']['executions'][0]['status'], ['completed', 'failed'])
                )
            ) {
                break;
            }

            if (time() - $startTime > $maxWaitTime) {
                break;
            }

            \sleep(1);
        }

        $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/executions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $executions['headers']['status-code']);
        $this->assertCount(1, $executions['body']['executions']);
        $this->assertIsArray($executions['body']['executions']);
        $this->assertEquals($executions['body']['executions'][0]['trigger'], 'schedule');
        $this->assertEquals($executions['body']['executions'][0]['status'], 'completed');
        $this->assertEquals($executions['body']['executions'][0]['responseStatusCode'], 200);
        $this->assertEquals($executions['body']['executions'][0]['responseBody'], '');
        $this->assertNotEmpty($executions['body']['executions'][0]['logs'], '');
        $this->assertNotEmpty($executions['body']['executions'][0]['errors'], '');
        $this->assertGreaterThan(0, $executions['body']['executions'][0]['duration']);

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

        $this->awaitDeploymentIsBuilt($function['body']['$id'], $deploymentId, true);

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

        $start = \microtime(true);
        while (true) {
            $execution = $this->client->call(Client::METHOD_GET, '/functions/' . $function['body']['$id'] . '/executions/' . $executionId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            if ($execution['body']['status'] === 'completed') {
                break;
            }

            $timeout = 60 + 60 + 15; // up to 1 minute round up, 1 minute schedule postpone, 15s cold start safety
            if (\microtime(true) - $start > $timeout) {
                $this->fail('Scheduled execution did not complete with status ' . $execution['body']['status'] . ': ' . \json_encode($execution));
            }

            usleep(500000); // 0.5 seconds
        }

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
}
