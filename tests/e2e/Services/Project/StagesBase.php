<?php

namespace Tests\E2E\Services\Project;

use Appwrite\Extend\Exception;
use Appwrite\Tests\Async;
use Tests\E2E\Client;
use Utopia\Config\Config;
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
        $this->assertCount(\count(Config::getParam('onboarding', [])), $response['body']['stages']);

        $ids = array_column($response['body']['stages'], 'id');
        $this->assertContains('tablesDB.create', $ids);
        $this->assertContains('storage.createBucket', $ids);
        $this->assertContains('functions.create', $ids);

        foreach ($response['body']['stages'] as $stage) {
            $this->assertSame($stage['id'], $stage['sdk']);
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
        $method = 'tablesDB.create';

        $response = $this->updateStage($projectId, $method, true);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($method, $response['body']['id']);
        $this->assertSame($method, $response['body']['sdk']);
        $this->assertSame(ONBOARDING_STATUS_SKIPPED, $response['body']['status']);
        $this->assertNotEmpty($response['body']['at']);
        $this->assertSame(ACTOR_TYPE_USER, $response['body']['actorType']);

        $dateValidator = new DatetimeValidator();
        $this->assertSame(true, $dateValidator->isValid($response['body']['at']));

        $list = $this->listStages($projectId);
        $this->assertSame(200, $list['headers']['status-code']);
        $databaseStage = null;
        foreach ($list['body']['stages'] as $stage) {
            if ($stage['id'] === $method) {
                $databaseStage = $stage;
                break;
            }
        }
        $this->assertNotNull($databaseStage);
        $this->assertSame(ONBOARDING_STATUS_SKIPPED, $databaseStage['status']);
        $this->assertSame(ACTOR_TYPE_USER, $databaseStage['actorType']);

        $response = $this->updateStage($projectId, 'unknown.method', true);
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $response['body']['type']);

        $response = $this->updateStage(ID::unique(), 'storage.createBucket', true);
        $this->assertSame(404, $response['headers']['status-code']);
        $this->assertSame(Exception::PROJECT_NOT_FOUND, $response['body']['type']);
    }

    public function testUpdateProjectStageSkipFalseLeavesPending(): void
    {
        $projectId = $this->createTeamAndProject();
        $method = 'storage.createBucket';

        $response = $this->updateStage($projectId, $method, false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($method, $response['body']['id']);
        $this->assertSame('pending', $response['body']['status']);
    }

    public function testUpdateProjectStageWithoutAuthentication(): void
    {
        $projectId = $this->createTeamAndProject();

        $response = $this->updateStage($projectId, 'tablesDB.create', true, false);

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
