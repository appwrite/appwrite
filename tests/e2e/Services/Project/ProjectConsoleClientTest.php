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

    public function testGetProject(): void
    {
        $team = $this->createTeam('Get Project Team');
        $project = $this->createProject($team['body']['$id'], 'Get Project');

        $response = $this->client->call(Client::METHOD_GET, '/project', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['body']['$id'],
        ], $this->getHeaders()));

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame($project['body']['$id'], $response['body']['$id']);
        $this->assertNotEmpty($response['body']['$createdAt']);
        $this->assertNotEmpty($response['body']['$updatedAt']);
        $this->assertSame('Get Project', $response['body']['name']);
        $this->assertSame($team['body']['$id'], $response['body']['teamId']);
        $this->assertSame('active', $response['body']['status']);

        // Auth methods
        $this->assertIsArray($response['body']['authMethods']);
        $this->assertNotEmpty($response['body']['authMethods']);
        foreach ($response['body']['authMethods'] as $authMethod) {
            $this->assertArrayHasKey('$id', $authMethod);
            $this->assertArrayHasKey('enabled', $authMethod);
            $this->assertIsBool($authMethod['enabled']);
        }

        // Services
        $this->assertIsArray($response['body']['services']);
        $this->assertNotEmpty($response['body']['services']);
        foreach ($response['body']['services'] as $service) {
            $this->assertArrayHasKey('$id', $service);
            $this->assertArrayHasKey('enabled', $service);
            $this->assertIsBool($service['enabled']);
        }

        // Protocols
        $this->assertIsArray($response['body']['protocols']);
        $this->assertNotEmpty($response['body']['protocols']);
        foreach ($response['body']['protocols'] as $protocol) {
            $this->assertArrayHasKey('$id', $protocol);
            $this->assertArrayHasKey('enabled', $protocol);
            $this->assertIsBool($protocol['enabled']);
        }

        // SMTP defaults
        $this->assertFalse($response['body']['smtpEnabled']);
        $this->assertSame('', $response['body']['smtpSenderEmail']);
        $this->assertSame('', $response['body']['smtpSenderName']);
        $this->assertSame('', $response['body']['smtpReplyToEmail']);
        $this->assertSame('', $response['body']['smtpReplyToName']);
        $this->assertSame('', $response['body']['smtpHost']);
        $this->assertSame('', $response['body']['smtpPort']);
        $this->assertSame('', $response['body']['smtpUsername']);
        $this->assertSame('', $response['body']['smtpPassword']);
        $this->assertSame('', $response['body']['smtpSecure']);

        // Other fields
        $this->assertIsArray($response['body']['labels']);
        $this->assertIsArray($response['body']['devKeys']);
        $this->assertSame(0, $response['body']['pingCount']);
        $this->assertSame('', $response['body']['pingedAt']);

        // Ensure old flattened fields are not present
        $this->assertArrayNotHasKey('description', $response['body']);
        $this->assertArrayNotHasKey('logo', $response['body']);
        $this->assertArrayNotHasKey('url', $response['body']);
        $this->assertArrayNotHasKey('authDuration', $response['body']);
        $this->assertArrayNotHasKey('authLimit', $response['body']);
        $this->assertArrayNotHasKey('authSessionsLimit', $response['body']);
        $this->assertArrayNotHasKey('authPasswordHistory', $response['body']);
        $this->assertArrayNotHasKey('authPasswordDictionary', $response['body']);
        $this->assertArrayNotHasKey('authPersonalDataCheck', $response['body']);
        $this->assertArrayNotHasKey('authDisposableEmails', $response['body']);
        $this->assertArrayNotHasKey('authCanonicalEmails', $response['body']);
        $this->assertArrayNotHasKey('authFreeEmails', $response['body']);
        $this->assertArrayNotHasKey('oAuthProviders', $response['body']);
        $this->assertArrayNotHasKey('platforms', $response['body']);
        $this->assertArrayNotHasKey('webhooks', $response['body']);
        $this->assertArrayNotHasKey('keys', $response['body']);
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
