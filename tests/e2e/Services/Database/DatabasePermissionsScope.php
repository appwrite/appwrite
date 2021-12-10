<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Client;

trait DatabasePermissionsScope
{
    public array $users = [];
    public array $teams = [];

    public function createUser(string $id, string $email, string $password = 'test123!'): array
    {
        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => $id,
            'email' => $email,
            'password' => $password
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $session = $this->client->parseCookie((string)$session['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

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

    public function getServerHeader(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ];
    }
}
