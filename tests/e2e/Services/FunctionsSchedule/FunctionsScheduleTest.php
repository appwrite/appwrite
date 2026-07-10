<?php

declare(strict_types=1);

namespace Tests\E2E\Services\FunctionsSchedule;

use Appwrite\ID;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\Role;

final class FunctionsScheduleTest extends Scope
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
        $futureTime = (new \DateTime())->add(new \DateInterval('PT2M')); // 2 minutes in the future
        $futureTime->setTime((int) $futureTime->format('H'), (int) $futureTime->format('i'), 0, 0);


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

        $this->cleanupFunction($functionId);
    }

}
