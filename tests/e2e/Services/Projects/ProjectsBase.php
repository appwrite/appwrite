<?php

namespace Tests\E2E\Services\Projects;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;

trait ProjectsBase
{
    protected function setupProject(mixed $params, string $teamId = null, bool $newTeam = true): string
    {
        if ($newTeam) {
            $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'teamId' => $teamId ?? ID::unique(),
                'name' => 'Project Test',
            ]);

            $this->assertEquals(201, $team['headers']['status-code'], 'Setup team failed with status code: ' . $team['headers']['status-code'] . ' and response: ' . json_encode($team['body'], JSON_PRETTY_PRINT));

            $teamId = $team['body']['$id'];
        }

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            ...$params,
            'teamId' => $teamId,
        ]);

        $this->assertEquals(201, $project['headers']['status-code'], 'Setup project failed with status code: ' . $project['headers']['status-code'] . ' and response: ' . json_encode($project['body'], JSON_PRETTY_PRINT));

        return $project['body']['$id'];
    }

    protected function setupDevKey(mixed $params): array
    {
        $devKey = $this->client->call(Client::METHOD_POST, '/projects/' . $params['projectId'] . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        $this->assertEquals(201, $devKey['headers']['status-code'], 'Setup devKey failed with status code: ' . $devKey['headers']['status-code'] . ' and response: ' . json_encode($devKey['body'], JSON_PRETTY_PRINT));

        return [
            '$id' => $devKey['body']['$id'],
            'secret' => $devKey['body']['secret'],
        ];
    }

    protected function setupUserMembership(mixed $params): array
    {
        // Create membership
        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $params['teamId'] . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $params['email'],
            'name' => $params['name'],
            'roles' => $params['roles'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertEquals($params['name'], $response['body']['userName']);
        $this->assertEquals($params['email'], $response['body']['userEmail']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(count($params['roles']), $response['body']['roles']);
        $this->assertEquals(false, $response['body']['confirm']);

        $userId = $response['body']['userId'];
        $membershipId = $response['body']['$id'];


        $lastEmail = $this->getLastEmail();
        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);
        $userId = $tokens['userId'];
        $secret = $tokens['secret'];
        // Confirm membership
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . $params['teamId'] . '/memberships/' . $membershipId . '/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $userId,
            'secret' => $secret,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(count($params['roles']), $response['body']['roles']);
        $this->assertEquals(true, $response['body']['confirm']);

        // Simulate password recovery flow to reset password for the created user (useful when creating session for this user)
        $response = $this->client->call(Client::METHOD_POST, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $params['email'],
            'url' => 'http://localhost/recovery',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);

        $lastEmail = $this->getLastEmail();
        $this->assertEquals($params['email'], $lastEmail['to'][0]['address']);
        $this->assertEquals($params['name'], $lastEmail['to'][0]['name']);
        $this->assertEquals('Password Reset for ' . $this->getProject()['name'], $lastEmail['subject']);
        $this->assertStringContainsStringIgnoringCase('Reset your ' . $this->getProject()['name'] . ' password using the link.', $lastEmail['text']);

        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html']);
        $secret = $tokens['secret'];

        $response = $this->client->call(Client::METHOD_PUT, '/account/recovery', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $userId,
            'secret' => $secret,
            'password' => 'password',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        return [
            'userId' => $userId,
            'membershipId' => $membershipId,
        ];
    }

    protected function updateMembershipRole(string $teamId, string $membershipId, array $roles): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamId . '/memberships/' . $membershipId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'roles' => $roles,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
    }

    protected function setupFunction(string $projectId, string $functionId, string $token): void
    {
        $function = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $token,
        ]), [
            'functionId' => $functionId,
            'name' => 'Test function',
            'execute' => [Role::any()->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);
        $this->assertEquals(201, $function['headers']['status-code']);
        $this->assertNotEmpty($function['body']['$id']);
    }
}
