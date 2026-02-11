<?php

namespace Tests\E2E\Services\Logs;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class LogsConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testListLogsByProject(): void
    {
        $projectId = $this->getProject()['$id'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'resource' => 'project',
            'resourceId' => $projectId,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['httpLogs']);
        $this->assertIsInt($response['body']['total']);

        /**
         * Test for FAILURE - wrong project ID
         */
        $response = $this->client->call(Client::METHOD_GET, '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'resource' => 'project',
            'resourceId' => 'invalid-project-id',
        ]);

        $this->assertEquals(403, $response['headers']['status-code']);

        /**
         * Test for FAILURE - missing resource
         */
        $response = $this->client->call(Client::METHOD_GET, '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'resourceId' => $projectId,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for FAILURE - missing resourceId
         */
        $response = $this->client->call(Client::METHOD_GET, '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'resource' => 'project',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for FAILURE - invalid resource type
         */
        $response = $this->client->call(Client::METHOD_GET, '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'resource' => 'invalid',
            'resourceId' => $projectId,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testListLogsByDeployment(): void
    {
        $projectId = $this->getProject()['$id'];

        /**
         * Test for FAILURE - deployment not found
         */
        $response = $this->client->call(Client::METHOD_GET, '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'resource' => 'deployment',
            'resourceId' => 'nonexistent-deployment',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testListLogsPagination(): void
    {
        $projectId = $this->getProject()['$id'];

        /**
         * Test for SUCCESS - custom limit and offset
         */
        $response = $this->client->call(Client::METHOD_GET, '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'resource' => 'project',
            'resourceId' => $projectId,
            'limit' => 10,
            'offset' => 0,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['httpLogs']);
        $this->assertLessThanOrEqual(10, count($response['body']['httpLogs']));
    }

    public function testGetLog(): void
    {
        $projectId = $this->getProject()['$id'];

        /**
         * Test for FAILURE - log not found
         */
        $response = $this->client->call(Client::METHOD_GET, '/logs/nonexistent-log-id', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testDeleteLog(): void
    {
        $projectId = $this->getProject()['$id'];

        /**
         * Test for FAILURE - log not found
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/logs/nonexistent-log-id', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }
}
