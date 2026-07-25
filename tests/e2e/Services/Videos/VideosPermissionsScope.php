<?php

namespace Tests\E2E\Services\Videos;

use Tests\E2E\Client;

/**
 * Helpers for building extra users and teams inside the test project, used by
 * the Videos access-control suite.
 */
trait VideosPermissionsScope
{
    /** @var array<string, array> */
    public array $users = [];

    /** @var array<string, array> */
    public array $teams = [];

    public function createUser(string $id, string $email, string $password = 'password123!'): array
    {
        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => $id,
            'email' => $email,
            'password' => $password,
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

        $this->assertEquals(201, $session['headers']['status-code']);

        // Client::call() parses Set-Cookie for us; this trait used to call a
        // Client::parseCookie() helper that no longer exists.
        $created = [
            '$id' => $user['body']['$id'],
            'email' => $user['body']['email'],
            'session' => $session['cookies']['a_session_' . $this->getProject()['$id']] ?? '',
        ];

        $this->users[$id] = $created;

        return $created;
    }

    public function getCreatedUser(string $id): array
    {
        return $this->users[$id] ?? [];
    }

    /**
     * Headers authenticating as a user created by createUser().
     */
    public function getUserHeaders(string $id): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . ($this->getCreatedUser($id)['session'] ?? ''),
        ];
    }

    public function createTeam(string $id, string $name): array
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', $this->getServerHeader(), [
            'teamId' => $id,
            'name' => $name,
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
            'url' => 'http://localhost:5000/join-us#title',
        ]);

        return [
            'user' => $membership['body']['userId'],
            'membership' => $membership['body']['$id'],
        ];
    }

    public function getServerHeader(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }
}
