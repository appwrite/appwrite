<?php

namespace Tests\E2E\Services\Databases\Permissions;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

trait DatabasesPermissionsBase
{
    public array $users = [];
    public array $teams = [];

    // URL Helper Methods - uses methods from ApiLegacy/ApiTablesDB traits
    protected function getDatabaseUrl(string $databaseId = ''): string
    {
        $base = $this->getApiBasePath();
        return $databaseId ? "{$base}/{$databaseId}" : $base;
    }

    protected function getContainerUrl(string $databaseId, string $containerId = ''): string
    {
        $resource = $this->getContainerResource();
        $base = "{$this->getApiBasePath()}/{$databaseId}/{$resource}";
        return $containerId ? "{$base}/{$containerId}" : $base;
    }

    protected function getSchemaUrl(string $databaseId, string $containerId, string $type = '', string $key = ''): string
    {
        $resource = $this->getSchemaResource();
        $base = "{$this->getContainerUrl($databaseId, $containerId)}/{$resource}";
        if ($type) {
            $base .= "/{$type}";
        }
        if ($key) {
            $base .= "/{$key}";
        }
        return $base;
    }

    protected function getRecordUrl(string $databaseId, string $containerId, string $recordId = ''): string
    {
        $resource = $this->getRecordResource();
        $base = "{$this->getContainerUrl($databaseId, $containerId)}/{$resource}";
        return $recordId ? "{$base}/{$recordId}" : $base;
    }

    // User Management Methods
    public function createUser(string $id, string $email, string $password = 'test123!'): array
    {
        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-dev-key' => $this->getProject()['devKey'] ?? '',
        ], [
            'userId' => $id,
            'email' => $email,
            'password' => $password
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $session = $session['cookies']['a_session_' . $this->getProject()['$id']];

        $user = [
            '$id' => $user['body']['$id'],
            'email' => $user['body']['email'],
            'session' => $session,
        ];
        $this->users[$id] = $user;

        return $user;
    }

    public function getCreatedUser(string $id): array
    {
        return $this->users[$id] ?? [];
    }

    // Team Management Methods
    public function createTeam(string $id, string $name): array
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', $this->getServerHeader(), [
            'teamId' => $id,
            'name' => $name
        ]);
        $this->teams[$id] = $team['body'];

        return $team['body'];
    }

    public function addToTeam(string $user, string $team, array $roles = []): array
    {
        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $team . '/memberships', $this->getServerHeader(), [
            'teamId' => $team,
            'email' => $this->getCreatedUser($user)['email'],
            'roles' => $roles,
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        return [
            'user' => $membership['body']['userId'],
            'membership' => $membership['body']['$id']
        ];
    }

    // Helper Methods
    public function getServerHeader(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];
    }
}
