<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ProjectUsersTest extends BaseProjects
{
    public function testRegisterSuccess()
    {
        return $this->initProject(['users.read', 'users.write']);
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testUserCreateSuccess($data)
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
    public function testUserReadSuccess($data)
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

        return $data;
    }

    /**
     * @depends testUserReadSuccess
     */
    public function testUserUpdateStatusSuccess($data)
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

        var_dump($user);
        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['status'], 2);

        return $data;
    }
}
