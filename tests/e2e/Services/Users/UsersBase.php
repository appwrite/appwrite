<?php

namespace Tests\E2E\Services\Users;

use Tests\E2E\Client;
use Utopia\Database\Database;

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
            'userId' => 'unique()',
            'email' => 'cristiano.ronaldo@manchester-united.co.uk',
            'password' => 'password',
            'name' => 'Cristiano Ronaldo',
        ]);

        $this->assertEquals($user['headers']['status-code'], 201);
        $this->assertEquals($user['body']['name'], 'Cristiano Ronaldo');
        $this->assertEquals($user['body']['email'], 'cristiano.ronaldo@manchester-united.co.uk');
        $this->assertEquals($user['body']['status'], true);
        $this->assertGreaterThan(0, $user['body']['registration']);

        /**
         * Test Create with Custom ID for SUCCESS
         */
        $res = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'user1',
            'email' => 'lionel.messi@psg.fr',
            'password' => 'password',
            'name' => 'Lionel Messi',
        ]);

        $this->assertEquals($res['headers']['status-code'], 201);
        $this->assertEquals($res['body']['$id'], 'user1');
        $this->assertEquals($res['body']['name'], 'Lionel Messi');
        $this->assertEquals($res['body']['email'], 'lionel.messi@psg.fr');
        $this->assertEquals(true, $res['body']['status']);
        $this->assertGreaterThan(0, $res['body']['registration']);

        return ['userId' => $user['body']['$id']];
    }

    /**
     * @depends testCreateUser
     */
    public function testListUsers(array $data): void
    {
        /**
         * Test for SUCCESS listUsers
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(2, $response['body']['users']);

        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);
        $this->assertEquals($response['body']['users'][1]['$id'], 'user1');

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'cursor' => $response['body']['users'][0]['$id']
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], 'user1');

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'cursor' => 'user1',
            'cursorDirection' => Database::CURSOR_BEFORE
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);

        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        /**
         * Test for SUCCESS searchUsers
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Ronaldo'
        ]);
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'cristiano.ronaldo'
        ]);
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'manchester'
        ]);
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'manchester-united.co.uk'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertIsArray($response['body']['users']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertEquals(1, $response['body']['sum']);
        $this->assertCount(1, $response['body']['users']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $data['userId']
        ]);
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['$id'], $data['userId']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'cursor' => 'unknown'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
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
        $this->assertEquals($user['body']['name'], 'Cristiano Ronaldo');
        $this->assertEquals($user['body']['email'], 'cristiano.ronaldo@manchester-united.co.uk');
        $this->assertEquals($user['body']['status'], true);
        $this->assertGreaterThan(0, $user['body']['registration']);

        $sessions = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/sessions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($sessions['headers']['status-code'], 200);
        $this->assertIsArray($sessions['body']);

        $users = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($users['headers']['status-code'], 200);
        $this->assertIsArray($users['body']);
        $this->assertIsArray($users['body']['users']);
        $this->assertIsInt($users['body']['sum']);
        $this->assertGreaterThan(0, $users['body']['sum']);

        return $data;
    }

    /**
     * @depends testGetUser
     */
    public function testUpdateUserName(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/name', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Updated name',
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['name'], 'Updated name');

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['name'], 'Updated name');

        return $data;
    }

    /**
     * @depends testGetUser
     */
    public function testUpdateUserEmail(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => 'users.service@updated.com',
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['email'], 'users.service@updated.com');

        $user = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertEquals($user['body']['email'], 'users.service@updated.com');

        return $data;
    }

    /**
     * @depends testUpdateUserEmail
     */
    public function testUpdateUserPassword(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $user = $this->client->call(Client::METHOD_PATCH, '/users/' . $data['userId'] . '/password', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'password' => 'password2',
        ]);

        $this->assertEquals($user['headers']['status-code'], 200);
        $this->assertNotEmpty($user['body']['$id']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => 'users.service@updated.com',
            'password' => 'password2'
        ]);

        $this->assertEquals($session['headers']['status-code'], 201);

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
    public function testGetLogs(array $data): void
    {
        /**
         * Test for SUCCESS
         */
        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['sum']);

        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 1
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['sum']);

        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'offset' => 1
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['sum']);

        $logs = $this->client->call(Client::METHOD_GET, '/users/' . $data['userId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'offset' => 1,
            'limit' => 1
        ]);

        $this->assertEquals($logs['headers']['status-code'], 200);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['sum']);
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