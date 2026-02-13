<?php

namespace Tests\E2E\Services\Schedules;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

class SchedulesCustomServerTest extends Scope
{
    use SchedulesBase;
    use ProjectCustom;
    use SideServer;

    public function testCreateSchedule(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->createSchedule([
            'resourceType' => 'function',
            'resourceId' => ID::unique(),
            'schedule' => '0 0 * * *',
            'active' => true,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['$createdAt']);
        $this->assertNotEmpty($response['body']['$updatedAt']);
        $this->assertEquals('function', $response['body']['resourceType']);
        $this->assertNotEmpty($response['body']['resourceId']);
        $this->assertNotEmpty($response['body']['resourceUpdatedAt']);
        $this->assertNotEmpty($response['body']['projectId']);
        $this->assertEquals('0 0 * * *', $response['body']['schedule']);
        $this->assertTrue($response['body']['active']);
        $this->assertNotEmpty($response['body']['region']);

        return ['scheduleId' => $response['body']['$id']];
    }

    public function testCreateScheduleExecutionType(): void
    {
        $response = $this->createSchedule([
            'resourceType' => 'execution',
            'resourceId' => ID::unique(),
            'schedule' => '*/10 * * * *',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('execution', $response['body']['resourceType']);
        $this->assertFalse($response['body']['active']);
    }

    public function testCreateScheduleMessageType(): void
    {
        $response = $this->createSchedule([
            'resourceType' => 'message',
            'resourceId' => ID::unique(),
            'schedule' => '0 9 * * 1',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('message', $response['body']['resourceType']);
    }

    public function testCreateScheduleInvalidResourceType(): void
    {
        $response = $this->createSchedule([
            'resourceType' => 'invalid',
            'resourceId' => ID::unique(),
            'schedule' => '0 0 * * *',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateScheduleInvalidCron(): void
    {
        $response = $this->createSchedule([
            'resourceType' => 'function',
            'resourceId' => ID::unique(),
            'schedule' => 'not-a-cron',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateScheduleMissingRequired(): void
    {
        // Missing resourceType
        $response = $this->createSchedule([
            'resourceId' => ID::unique(),
            'schedule' => '0 0 * * *',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Missing resourceId
        $response = $this->createSchedule([
            'resourceType' => 'function',
            'schedule' => '0 0 * * *',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Missing schedule
        $response = $this->createSchedule([
            'resourceType' => 'function',
            'resourceId' => ID::unique(),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    /**
     * @depends testCreateSchedule
     */
    public function testGetSchedule(array $data): void
    {
        $scheduleId = $data['scheduleId'];

        /**
         * Test for SUCCESS
         */
        $response = $this->getSchedule($scheduleId);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($scheduleId, $response['body']['$id']);
        $this->assertEquals('function', $response['body']['resourceType']);
        $this->assertEquals('0 0 * * *', $response['body']['schedule']);
        $this->assertTrue($response['body']['active']);
    }

    public function testGetScheduleNotFound(): void
    {
        $response = $this->getSchedule('nonexistent');

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    /**
     * @depends testCreateSchedule
     */
    public function testListSchedules(array $data): void
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->listSchedules();

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
        $this->assertArrayHasKey('active', $schedule);
        $this->assertArrayHasKey('region', $schedule);
    }

    /**
     * @depends testCreateSchedule
     */
    public function testListSchedulesWithQuery(array $data): void
    {
        // Filter by resourceType
        $response = $this->listSchedules([
            'queries' => ['equal("resourceType", "function")'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $response['body']['total']);

        foreach ($response['body']['schedules'] as $schedule) {
            $this->assertEquals('function', $schedule['resourceType']);
        }

        // Filter by active status
        $response = $this->listSchedules([
            'queries' => ['equal("active", true)'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        foreach ($response['body']['schedules'] as $schedule) {
            $this->assertTrue($schedule['active']);
        }
    }

    public function testListSchedulesWithTotalDisabled(): void
    {
        $response = $this->listSchedules([
            'total' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['total']);
        $this->assertIsArray($response['body']['schedules']);
    }

    public function testListSchedulesInvalidQuery(): void
    {
        $response = $this->listSchedules([
            'queries' => ['equal("nonexistent", "value")'],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testScheduleProjectIsolation(): void
    {
        // Create a schedule in the current project
        $response = $this->createSchedule([
            'resourceType' => 'function',
            'resourceId' => ID::unique(),
            'schedule' => '0 12 * * *',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $scheduleId = $response['body']['$id'];

        // Create a fresh project with its own key
        $freshProject = $this->getProject(true);

        // Try to get the schedule from the other project
        $response = $this->client->call(\Tests\E2E\Client::METHOD_GET, '/schedules/' . $scheduleId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $freshProject['$id'],
            'x-appwrite-key' => $freshProject['apiKey'],
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        // List should not include schedules from other projects
        $response = $this->client->call(\Tests\E2E\Client::METHOD_GET, '/schedules', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $freshProject['$id'],
            'x-appwrite-key' => $freshProject['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $scheduleIds = array_column($response['body']['schedules'], '$id');
        $this->assertNotContains($scheduleId, $scheduleIds);
    }
}
