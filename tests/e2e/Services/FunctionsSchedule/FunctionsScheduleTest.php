<?php

namespace Tests\E2E\Services\FunctionsSchedule;

use Appwrite\ID;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\Role;

class FunctionsScheduleTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideServer;

    public function testCreateScheduledExecution()
    {
        /**
         * Test for SUCCESS
         */
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '* * * * *', // Execute every 60 seconds
            'timeout' => 10,
        ]);

        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        // Wait for scheduled execution
        \sleep(60);

        $this->assertEventually(function () use ($functionId) {
            $executions = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $executions['headers']['status-code']);
            $this->assertCount(1, $executions['body']['executions']);

            $asyncExecution = $executions['body']['executions'][0];

            $this->assertEquals('schedule', $asyncExecution['trigger']);
            $this->assertEquals('completed', $asyncExecution['status']);
            $this->assertEquals(200, $asyncExecution['responseStatusCode']);
            $this->assertEquals('', $asyncExecution['responseBody']);
            $this->assertNotEmpty($asyncExecution['logs']);
            $this->assertNotEmpty($asyncExecution['errors']);
            $this->assertGreaterThan(0, $asyncExecution['duration']);
            $this->assertNotEmpty($asyncExecution['$id']);
            $headers = array_column($asyncExecution['requestHeaders'] ?? [], 'value', 'name');
            $this->assertEmpty($headers['x-appwrite-client-ip'] ?? '');
        }, 60000, 500);

        $this->cleanupFunction($functionId);
    }

    public function testCreateScheduledAtExecution(): void
    {
        /**
         * Test for SUCCESS
         */
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 10,
            'logging' => true,
        ]);
        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        // Schedule execution for the future
        \date_default_timezone_set('UTC');
        $futureTime = (new \DateTime())->add(new \DateInterval('PT2M')); // 2 minute in the future
        $futureTime->setTime($futureTime->format('H'), $futureTime->format('i'), 0, 0);


        $execution = $this->client->call(
            Client::METHOD_POST,
            '/functions/' . $functionId . '/executions',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'origin' => 'http://localhost',
                'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $this->getUser()['session'],
            ],
            [
                'async' => true,
                'scheduledAt' => $futureTime->format(\DateTime::ATOM),
                'path' => '/custom-path',
                'method' => 'PATCH',
                'body' => 'custom-body',
                'headers' => [
                    'x-custom-header' => 'custom-value'
                ]
            ]
        );
        $executionId = $execution['body']['$id'];

        $this->assertEquals(202, $execution['headers']['status-code']);
        $this->assertEquals('scheduled', $execution['body']['status']);
        $this->assertEquals('PATCH', $execution['body']['requestMethod']);
        $this->assertEquals('/custom-path', $execution['body']['requestPath']);
        $this->assertCount(1, $execution['body']['requestHeaders']);
        $this->assertEquals('x-appwrite-client-ip', $execution['body']['requestHeaders'][0]['name']);
        $this->assertNotEmpty($execution['body']['requestHeaders'][0]['value']);

        \sleep(120);

        $this->assertEventually(function () use ($functionId, $executionId) {
            $execution = $this->getExecution($functionId, $executionId);

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
        }, 10000, 500);

        /* Test for FAILURE */
        // Schedule synchronous execution
        $execution = $this->createExecution($functionId, [
            'async' => 'false',
            'scheduledAt' =>  $futureTime->format(\DateTime::ATOM),
        ]);
        $this->assertEquals(400, $execution['headers']['status-code']);

        // Execution with seconds precision
        $execution = $this->createExecution($functionId, [
            'async' => true,
            'scheduledAt' => (new \DateTime("2100-12-08 16:12:02"))->format(\DateTime::ATOM)
        ]);
        $this->assertEquals(400, $execution['headers']['status-code']);

        // Execution with milliseconds precision
        $execution = $this->createExecution($functionId, [
            'async' => true,
            'scheduledAt' => (new \DateTime("2100-12-08 16:12:02.255"))->format(\DateTime::ATOM)
        ]);
        $this->assertEquals(400, $execution['headers']['status-code']);

        // Execution too soon
        $execution = $this->createExecution($functionId, [
            'async' => true,
            'scheduledAt' => (new \DateTime())->add(new \DateInterval('PT1S'))->format(\DateTime::ATOM)
        ]);
        $this->assertEquals(400, $execution['headers']['status-code']);

        $this->cleanupFunction($functionId, $executionId);
    }

    public function testDeleteScheduledExecution()
    {
        $functionId = $this->setupFunction([
            'functionId' => ID::unique(),
            'name' => 'Test',
            'execute' => [Role::user($this->getUser()['$id'])->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 10,
            'logging' => true,
        ]);

        $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true
        ]);

        $futureTime = (new \DateTime())->add(new \DateInterval('PT10H'));
        $futureTime->setTime($futureTime->format('H'), $futureTime->format('i'), 0, 0);

        $execution = $this->createExecution($functionId, [
            'async' => true,
            'scheduledAt' => $futureTime->format('Y-m-d H:i:s'),
        ]);

        $this->assertEquals(202, $execution['headers']['status-code']);

        $executionId = $execution['body']['$id'] ?? '';

        $execution = $this->client->call(Client::METHOD_DELETE, '/functions/' . $functionId . '/executions/' . $executionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $execution['headers']['status-code']);

        $this->cleanupFunction($functionId);
    }
}
