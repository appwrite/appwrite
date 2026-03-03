<?php

namespace Tests\E2E\Services\Projects\Schedules;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\System\System;

class SchedulesConsoleClientTest extends Scope
{
    use ProjectConsole;
    use SchedulesBase;
    use SideClient;

    protected static array $cachedScheduleData = [];

    protected function setupScheduleData(): array
    {
        if (!empty(self::$cachedScheduleData)) {
            return self::$cachedScheduleData;
        }

        $data = $this->setupScheduleProjectData();
        $id = $data['projectId'];
        $apiKey = $data['apiKey'];

        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $apiKey,
        ], [
            'functionId' => ID::unique(),
            'name' => 'Test Schedule Function',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'execute' => ['any'],
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $functionId = $function['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'resourceType' => 'function',
            'resourceId' => $functionId,
            'schedule' => '0 0 * * *',
            'active' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        self::$cachedScheduleData = array_merge($data, [
            'scheduleId' => $response['body']['$id'],
            'functionId' => $functionId,
        ]);

        return self::$cachedScheduleData;
    }

    public function testCreateSchedule(): void
    {
        $data = $this->setupScheduleProjectData();
        $id = $data['projectId'];
        $apiKey = $data['apiKey'];

        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_POST, '/functions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-key' => $apiKey,
        ], [
            'functionId' => ID::unique(),
            'name' => 'Test Schedule Function',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'execute' => ['any'],
        ]);

        $this->assertEquals(201, $function['headers']['status-code']);
        $functionId = $function['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'resourceType' => 'function',
            'resourceId' => $functionId,
            'schedule' => '0 0 * * *',
            'active' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['$createdAt']);
        $this->assertNotEmpty($response['body']['$updatedAt']);
        $this->assertEquals('function', $response['body']['resourceType']);
        $this->assertEquals($functionId, $response['body']['resourceId']);
        $this->assertNotEmpty($response['body']['resourceUpdatedAt']);
        $this->assertNotEmpty($response['body']['projectId']);
        $this->assertEquals('0 0 * * *', $response['body']['schedule']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertTrue($response['body']['active']);
        $this->assertNotEmpty($response['body']['region']);

        // Create with data
        $scheduleData = ['key' => 'value'];
        $responseWithData = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'resourceType' => 'function',
            'resourceId' => $functionId,
            'schedule' => '0 12 * * *',
            'active' => true,
            'data' => json_encode($scheduleData),
        ]);

        $this->assertEquals(201, $responseWithData['headers']['status-code']);
        $this->assertEquals($scheduleData, $responseWithData['body']['data']);

        /**
         * Test for FAILURE
         */

        // Resource not found
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'resourceType' => 'function',
            'resourceId' => ID::unique(),
            'schedule' => '0 0 * * *',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        // Invalid resource type
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'resourceType' => 'invalid',
            'resourceId' => ID::unique(),
            'schedule' => '0 0 * * *',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Invalid cron
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'resourceType' => 'function',
            'resourceId' => ID::unique(),
            'schedule' => 'not-a-cron',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Missing resourceType
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'resourceId' => ID::unique(),
            'schedule' => '0 0 * * *',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Missing resourceId
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'resourceType' => 'function',
            'schedule' => '0 0 * * *',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Missing schedule
        $response = $this->client->call(Client::METHOD_POST, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'resourceType' => 'function',
            'resourceId' => ID::unique(),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testGetSchedule(): void
    {
        $data = $this->setupScheduleData();
        $id = $data['projectId'];
        $scheduleId = $data['scheduleId'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/schedules/'.$scheduleId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($scheduleId, $response['body']['$id']);
        $this->assertEquals('function', $response['body']['resourceType']);
        $this->assertEquals('0 0 * * *', $response['body']['schedule']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertTrue($response['body']['active']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/schedules/'.ID::unique(), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testListSchedules(): void
    {
        $data = $this->setupScheduleData();
        $id = $data['projectId'];

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['schedules']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);
        $this->assertGreaterThanOrEqual(1, \count($response['body']['schedules']));

        // Verify schedule structure
        $schedule = $response['body']['schedules'][0];
        $this->assertArrayHasKey('$id', $schedule);
        $this->assertArrayHasKey('$createdAt', $schedule);
        $this->assertArrayHasKey('$updatedAt', $schedule);
        $this->assertArrayHasKey('resourceType', $schedule);
        $this->assertArrayHasKey('resourceId', $schedule);
        $this->assertArrayHasKey('projectId', $schedule);
        $this->assertArrayHasKey('schedule', $schedule);
        $this->assertArrayHasKey('data', $schedule);
        $this->assertArrayHasKey('active', $schedule);
        $this->assertArrayHasKey('region', $schedule);

        /** Filter by resourceType */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('resourceType', ['function'])->toString()],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);

        foreach ($response['body']['schedules'] as $schedule) {
            $this->assertEquals('function', $schedule['resourceType']);
        }

        /** Filter by active status */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('active', [true])->toString()],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        foreach ($response['body']['schedules'] as $schedule) {
            $this->assertTrue($schedule['active']);
        }

        /** List with total disabled */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'total' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['total']);
        $this->assertIsArray($response['body']['schedules']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$id.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('nonexistent', ['value'])->toString()],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testScheduleProjectIsolation(): void
    {
        $data = $this->setupScheduleData();
        $scheduleId = $data['scheduleId'];

        // Create a second project
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Isolation Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);

        $otherProject = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Isolation Test Project',
            'teamId' => $team['body']['$id'],
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(201, $otherProject['headers']['status-code']);
        $otherProjectId = $otherProject['body']['$id'];

        // Try to get the schedule from the other project
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$otherProjectId.'/schedules/'.$scheduleId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        // List should not include schedules from other projects
        $response = $this->client->call(Client::METHOD_GET, '/projects/'.$otherProjectId.'/schedules', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);

        $scheduleIds = array_column($response['body']['schedules'], '$id');
        $this->assertNotContains($scheduleId, $scheduleIds);
    }
}
