<?php

namespace Tests\E2E\Services\Project;

use Appwrite\Extend\Exception;
use Appwrite\Tests\Async;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\System\System;

trait StagesBase
{
    use Async;

    // =========================================================================
    // List stages tests
    // =========================================================================

    public function testListProjectStages(): void
    {
        $projectId = $this->createTeamAndProject();

        $response = $this->listStages($projectId);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['stages']);
        $this->assertCount(3, $response['body']['stages']);

        $ids = array_column($response['body']['stages'], 'id');
        $this->assertSame(['create_database', 'create_bucket', 'create_function'], $ids);

        foreach ($response['body']['stages'] as $stage) {
            $this->assertArrayHasKey('sdk', $stage);
            $this->assertArrayHasKey('status', $stage);
            $this->assertArrayHasKey('at', $stage);
            $this->assertArrayHasKey('actorType', $stage);
            $this->assertSame('pending', $stage['status']);
        }

        // Verify via GET for unknown project
        $response = $this->listStages(ID::unique());
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::PROJECT_NOT_FOUND, $response['body']['type']);
    }

    public function testListProjectStagesWithoutAuthentication(): void
    {
        $projectId = $this->createTeamAndProject();

        $response = $this->listStages($projectId, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Update stage (skip) tests
    // =========================================================================

    public function testUpdateProjectStageSkip(): void
    {
        $projectId = $this->createTeamAndProject();

        $response = $this->updateStage($projectId, 'create_database', true);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('create_database', $response['body']['id']);
        $this->assertSame('databases.create', $response['body']['sdk']);
        $this->assertSame(ONBOARDING_STATUS_SKIPPED, $response['body']['status']);
        $this->assertNotEmpty($response['body']['at']);
        $this->assertSame(ACTIVITY_TYPE_USER, $response['body']['actorType']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($response['body']['at']));

        $list = $this->listStages($projectId);
        $this->assertSame(200, $list['headers']['status-code']);
        $first = $list['body']['stages'][0];
        $this->assertSame('create_database', $first['id']);
        $this->assertSame(ONBOARDING_STATUS_SKIPPED, $first['status']);
        $this->assertSame(ACTIVITY_TYPE_USER, $first['actorType']);

        $response = $this->updateStage($projectId, 'unknown_stage_xyz', true);
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $response['body']['type']);

        $response = $this->updateStage(ID::unique(), 'create_bucket', true);
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::PROJECT_NOT_FOUND, $response['body']['type']);
    }

    public function testUpdateProjectStageSkipFalseLeavesPending(): void
    {
        $projectId = $this->createTeamAndProject();

        $response = $this->updateStage($projectId, 'create_bucket', false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('create_bucket', $response['body']['id']);
        $this->assertSame('pending', $response['body']['status']);
    }

    public function testUpdateProjectStageWithoutAuthentication(): void
    {
        $projectId = $this->createTeamAndProject();

        $response = $this->updateStage($projectId, 'create_database', true, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function listStages(string $projectId, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/stages', $headers, []);
    }

    protected function updateStage(string $projectId, string $stageId, bool $skip, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId . '/stages/' . $stageId, $headers, [
            'skip' => $skip,
        ]);
    }

    /**
     * Creates a new team and child project under the console session (isolated from cached getProject()).
     */
    protected function createTeamAndProject(): string
    {
        $teamId = ID::unique();
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()), [
            'teamId' => $teamId,
            'name' => 'Stages E2E Team',
        ]);

        $this->assertContains($team['headers']['status-code'], [201, 409]);
        if ($team['headers']['status-code'] === 201) {
            $teamId = $team['body']['$id'];
        }

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Stages E2E Project',
            'teamId' => $teamId,
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertSame(201, $project['headers']['status-code']);

        return $project['body']['$id'];
    }
}
