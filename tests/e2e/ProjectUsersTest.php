<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ProjectUsersTest extends BaseProjects
{
    public function testRegisterSuccess(): array
    {
        return $this->initProject(['users.read', 'users.write']);
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testUserCreateSuccess(array $data): array
    {
        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ], [
            'email' => 'users.service@example.com',
            'password' => 'password',
            'name' => 'Project User',
        ]);

        $this->assertEquals($user['headers']['status-code'], 201);
        $this->assertEquals($user['body']['name'], 'Project User');
        $this->assertEquals($user['body']['email'], 'users.service@example.com');
        $this->assertEquals($user['body']['status'], 0);
        $this->assertGreaterThan(0, $user['body']['registration']);
        $this->assertIsArray($user['body']['roles']);

        return array_merge($data, ['userId' => $user['body']['$uid']]);
    }

    /**
     * @depends testUserCreateSuccess
     */
    public function testUserReadSuccess(array $data): array
    {
        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['name'], 'Project User');
        $this->assertEquals($user['body']['email'], 'users.service@example.com');
        $this->assertEquals($user['body']['status'], 0);
        $this->assertGreaterThan(0, $user['body']['registration']);
        $this->assertIsArray($user['body']['roles']);

        $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/sessions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals($sessions['headers']['status-code'], 200);
        $this->assertIsArray($sessions['body']);

        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']);

        $users = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals($users['headers']['status-code'], 200);
        $this->assertIsArray($users['body']);
        $this->assertIsArray($users['body']['users']);
        $this->assertIsInt($users['body']['sum']);
        $this->assertGreaterThan(0, $users['body']['sum']);

        $users = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ], [
            'search' => 'demo'
        ]);

        $this->assertEquals($users['headers']['status-code'], 200);
        $this->assertIsArray($users['body']);
        $this->assertIsArray($users['body']['users']);
        $this->assertIsInt($users['body']['sum']);
        $this->assertEquals(1, $users['body']['sum']);
        $this->assertGreaterThan(0, $users['body']['sum']);
        $this->assertCount(1, $users['body']['users']);

        return $data;
    }

    /**
     * @depends testUserReadSuccess
     */
    public function testUserUpdateStatusSuccess(array $data): array
    {
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/status', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ], [
            'status' => 2,
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['status'], 2);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['status'], 2);

        return $data;
    }

    /**
     * @depends testUserReadSuccess
     */
    public function testUserUpdatePrefsSuccess(array $data): array
    {
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/prefs', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ], [
            'prefs' => [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['key1'], 'value1');
        $this->assertEquals($user['body']['key2'], 'value2');

        return $data;
    }

    // TODO add test for session delete
    // TODO add test for all sessions delete
}
