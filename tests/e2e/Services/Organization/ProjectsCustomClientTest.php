<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Organization;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Id;

final class ProjectsCustomClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    public function testOrganizationRejectsProjectSession(): void
    {
        $projectId = $this->getProject()['$id'];

        $project = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ]);
        $this->assertSame(200, $project['headers']['status-code']);
        $organizationId = $project['body']['teamId'];

        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // A project team sharing the organization's ID must not count as organization membership
        $team = $this->client->call(Client::METHOD_POST, '/teams', $headers, [
            'teamId' => $organizationId,
            'name' => 'Project Team',
        ]);
        $this->assertSame(201, $team['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/organization/projects', $headers);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_unauthorized', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_GET, '/organization/projects/' . $projectId, $headers);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_unauthorized', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_GET, '/organization/projects/' . $projectId . '/keys', $headers);
        $this->assertSame(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/organization/projects/' . $projectId . '/keys', $headers, [
            'keyId' => Id::unique(),
            'name' => 'Key',
            'scopes' => ['users.read'],
        ]);
        $this->assertSame(401, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/organization/projects/' . $projectId . '/keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
            'x-appwrite-project' => $projectId,
        ]);
        $this->assertSame(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/organization/projects/' . $projectId . '/keys', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
    }
}
