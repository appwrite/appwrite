<?php

namespace Tests\E2E\Services\Users;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;

class UsersConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testGetUsersUsage()
    {
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h',
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertIsNumeric($response['body']['usersTotal']);
        $this->assertIsNumeric($response['body']['sessionsTotal']);
        $this->assertIsArray($response['body']['users']);
        $this->assertIsArray($response['body']['sessions']);
    }

    public function testCreateUserWithoutPasswordThenSetPassword()
    {
        // Create a user with email but without password
        $userId = ID::unique();
        $email = $userId . '@example.com';

        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'userId' => $userId,
            'email' => $email,
            // no password provided
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($userId, $response['body']['$id']);
        $this->assertEquals($email, $response['body']['email']);
        $this->assertEmpty($response['body']['password']);

        // Now set the password for that user (console-side)
        $newPassword = 'NewPass123!';

        $set = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/password', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'password' => $newPassword,
        ]);

        $this->assertEquals(200, $set['headers']['status-code']);
        $this->assertEquals($userId, $set['body']['$id']);
        $this->assertNotEmpty($set['body']['password']);
    }
}
