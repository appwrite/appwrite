<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

class ProjectConsoleClientTest extends Scope
{
    use ProjectBase;
    use ProjectCustom;
    use SideConsole;

    public function testDeleteProject(): void
    {
        $team = $this->createTeam('Delete Project Team');
        $project = $this->createProject($team['body']['$id'], 'Delete Project');

        $response = $this->client->call(Client::METHOD_DELETE, '/project', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['body']['$id'],
        ], $this->getHeaders()));

        $this->assertSame(204, $response['headers']['status-code']);

        $getProject = $this->getConsoleProject($project['body']['$id']);

        $this->assertSame(404, $getProject['headers']['status-code']);
    }

    public function testDeleteProjectUsingKey(): void
    {
        $team = $this->createTeam('Delete Project Key Team');
        $project = $this->createProject($team['body']['$id'], 'Delete Project Using Key');
        $apiKey = $this->createProjectKey($project['body']['$id'], ['project.write']);

        $response = $this->client->call(Client::METHOD_DELETE, '/project', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['body']['$id'],
            'x-appwrite-key' => $apiKey,
        ]);

        $this->assertSame(204, $response['headers']['status-code']);

        $getProject = $this->getConsoleProject($project['body']['$id']);

        $this->assertSame(404, $getProject['headers']['status-code']);
    }

    protected function createTeam(string $name): array
    {
        $response = $this->client->call(Client::METHOD_POST, '/teams', $this->getConsoleSessionHeaders(), [
            'teamId' => ID::unique(),
            'name' => $name,
        ]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($name, $response['body']['name']);
        $this->assertNotEmpty($response['body']['$id']);

        return $response;
    }

    protected function createProject(string $teamId, string $name): array
    {
        $response = $this->client->call(Client::METHOD_POST, '/projects', $this->getConsoleSessionHeaders(), [
            'projectId' => ID::unique(),
            'region' => System::getEnv('_APP_REGION', 'default'),
            'name' => $name,
            'teamId' => $teamId,
        ]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertSame($name, $response['body']['name']);
        $this->assertNotEmpty($response['body']['$id']);

        return $response;
    }

    protected function createProjectKey(string $projectId, array $scopes): string
    {
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/keys', $this->getConsoleSessionHeaders(), [
            'keyId' => ID::unique(),
            'name' => 'Delete Project Key',
            'scopes' => $scopes,
        ]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['secret']);

        return $response['body']['secret'];
    }

    protected function getConsoleProject(string $projectId): array
    {
        return $this->client->call(Client::METHOD_GET, '/projects/' . $projectId, $this->getConsoleSessionHeaders());
    }

    protected function getConsoleSessionHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ];
    }
}
