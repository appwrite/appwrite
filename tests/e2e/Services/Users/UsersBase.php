<?php

namespace Tests\E2E\Services\Users;

use Tests\E2E\Client;

trait UsersBase
{
    public function testCreateUser():array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => 'users.service@example.com',
            'password' => 'password',
            'name' => 'Project User',
        ]);

        $this->assertEquals($user['headers']['status-code'], 201);
        $this->assertEquals($user['body']['name'], 'Project User');
        $this->assertEquals($user['body']['email'], 'users.service@example.com');
        $this->assertEquals($user['body']['status'], true);
        $this->assertGreaterThan(0, $user['body']['registration']);

        return ['userId' => $user['body']['$id']];
    }

    /**
     * @depends testCreateUser
     */
    public function testGetUser(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['name'], 'Project User');
        $this->assertEquals($user['body']['email'], 'users.service@example.com');
        $this->assertEquals($user['body']['status'], true);
        $this->assertGreaterThan(0, $user['body']['registration']);

        $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($sessions['headers']['status-code'], 200);
        $this->assertIsArray($sessions['body']);

        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']);

        $users = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($users['headers']['status-code'], 200);
        $this->assertIsArray($users['body']);
        $this->assertIsArray($users['body']['users']);
        $this->assertIsInt($users['body']['sum']);
        $this->assertGreaterThan(0, $users['body']['sum']);

        $users = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'example.com'
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
     * @depends testGetUser
     */
    public function testUpdateUserStatus(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/status', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'status' => false,
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['status'], false);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['status'], false);

        return $data;
    }

    /**
     * @depends testGetUser
     */
    public function testUpdateEmailVerification(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/verification', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'emailVerification' => true,
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['emailVerification'], true);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['emailVerification'], true);

        return $data;
    }

    /**
     * @depends testGetUser
     */
    public function testUpdateAndGetUserPrefs(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/'.$data['userId'].'/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'prefs' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
            ],
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['funcKey1'], 'funcValue1');
        $this->assertEquals($user['body']['funcKey2'], 'funcValue2');

        $user = $this->client->call(Client::METHOD_GET, '/users/'.$data['userId'].'/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body'], [
            'funcKey1' => 'funcValue1',
            'funcKey2' => 'funcValue2',
        ]);

        /**
         * Test for FAILURE
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'prefs' => 'bad-string',
        ]);

        $this->assertEquals($user['headers']['status-code'], 400);

        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 400);

        return $data;
    }

    /**
     * @depends testGetUser
     */
    public function testDeleteUser(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_DELETE, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 204);

        /**
         * Test for FAILURE
         */
        $user = $this->client->call(Client::METHOD_DELETE, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 404);

        return $data;
    }

    // TODO add test for session delete
    // TODO add test for all sessions delete
}