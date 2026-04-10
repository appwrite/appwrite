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

    public function testCreateUserWithNullName()
    {
        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        $endpoints = [
            '/users' => [
                'password' => 'password123',
            ],
            '/users/bcrypt' => [
                'password' => '$2y$10$wQvP5E4J3lhL7zR7V2eTGuI8T8H3Y5mJ4v6S0m7nVh2Y9zT4cJx7K',
            ],
            '/users/md5' => [
                'password' => '5f4dcc3b5aa765d61d8327deb882cf99',
            ],
            '/users/argon2' => [
                'password' => '$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI',
            ],
            '/users/sha' => [
                'password' => '4243da0a694e8a2f727c8060fe0507c8fa01ca68146c76d2c190805b638c20c6bf6ba04e21f11ae138785d0bff63c416e6f87badbffad37f6dee50094cc38c70',
                'passwordVersion' => 'sha512',
            ],
            '/users/phpass' => [
                'password' => '$P$Br387rwferoKN7uwHZqNMu98q3U8RO.',
            ],
            '/users/scrypt' => [
                'password' => '3fdef49701bc4cfaacd551fe017283513284b4731e6945c263246ef948d3cf63b5d269c31fd697246085111a428245e24a4ddc6b64c687bc60a8910dbafc1d5b',
                'passwordSalt' => 'appwrite',
                'passwordCpu' => 16384,
                'passwordMemory' => 13,
                'passwordParallel' => 2,
                'passwordLength' => 64,
            ],
            '/users/scrypt-modified' => [
                'password' => 'UlM7JiXRcQhzAGlaonpSqNSLIz475WMddOgLjej5De9vxTy48K6WtqlEzrRFeK4t0COfMhWCb8wuMHgxOFCHFQ==',
                'passwordSalt' => 'UxLMreBr6tYyjQ==',
                'passwordSaltSeparator' => 'Bw==',
                'passwordSignerKey' => 'XyEKE9RcTDeLEsL/RjwPDBv/RqDl8fb3gpYEOQaPihbxf1ZAtSOHCjuAAa7Q3oHpCYhXSN9tizHgVOwn6krflQ==',
            ],
        ];

        foreach ($endpoints as $endpoint => $payload) {
            $userId = ID::unique();
            $email = $userId . '@example.com';

            $response = $this->client->call(Client::METHOD_POST, $endpoint, $headers, array_merge([
                'userId' => $userId,
                'email' => $email,
                'name' => null,
            ], $payload));

            $this->assertEquals(201, $response['headers']['status-code'], $endpoint);
            $this->assertEquals($userId, $response['body']['$id']);
            $this->assertEquals($email, $response['body']['email']);
            $this->assertSame('', $response['body']['name']);
        }
    }
}
