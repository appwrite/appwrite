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
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
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

        return ['userId' => $user['body']['$uid']];
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
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['name'], 'Project User');
        $this->assertEquals($user['body']['email'], 'users.service@example.com');
        $this->assertEquals($user['body']['status'], 0);
        $this->assertGreaterThan(0, $user['body']['registration']);
        $this->assertIsArray($user['body']['roles']);

        $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals($sessions['headers']['status-code'], 200);
        $this->assertIsArray($sessions['body']);

        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']);

        $users = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals($users['headers']['status-code'], 200);
        $this->assertIsArray($users['body']);
        $this->assertIsArray($users['body']['users']);
        $this->assertIsInt($users['body']['sum']);
        $this->assertGreaterThan(0, $users['body']['sum']);

        $users = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
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
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'status' => 2,
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['status'], 2);

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['status'], 2);

        return $data;
    }

    /**
     * @depends testGetUser
     */
    public function testUpdateUserPrefs(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
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