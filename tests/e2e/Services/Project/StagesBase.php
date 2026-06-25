<?php

namespace Tests\E2E\Services\Project;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Utopia\Config\Config;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait StagesBase
{
    // =========================================================================
    // List stages tests
    // =========================================================================

    public function testListProjectStages(): void
    {
        $projectId = $this->getProject()['$id'];

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
        }
    }

    public function testListProjectStagesWithoutAuthentication(): void
    {
        $response = $this->listStages($this->getProject()['$id'], false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Update stage (skip) tests
    // =========================================================================

    public function testUpdateProjectStageSkip(): void
    {
        $projectId = $this->getProject()['$id'];
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
    }

    public function testUpdateProjectStageSkipFalseLeavesPending(): void
    {
        $projectId = $this->getProject()['$id'];
        $method = 'storage.createBucket';

        $response = $this->updateStage($projectId, $method, false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($method, $response['body']['id']);
        $this->assertSame('pending', $response['body']['status']);
    }

    public function testUpdateProjectStageWithoutAuthentication(): void
    {
        $response = $this->updateStage($this->getProject()['$id'], 'tablesDB.create', true, false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function listStages(string $projectId, bool $authenticated = true): mixed
    {
        // Stages live under /projects (console scope); use the root console session.
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ];

        if ($authenticated) {
            $headers['origin'] = 'http://localhost';
            $headers['cookie'] = 'a_session_console=' . $this->getRoot()['session'];
        }

        return $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/stages', $headers, []);
    }

    protected function updateStage(string $projectId, string $stageId, bool $skip, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ];

        if ($authenticated) {
            $headers['origin'] = 'http://localhost';
            $headers['cookie'] = 'a_session_console=' . $this->getRoot()['session'];
        }

        return $this->client->call(Client::METHOD_PATCH, '/projects/' . $projectId . '/stages/' . rawurlencode($stageId), $headers, [
            'skip' => $skip,
        ]);
    }
}
