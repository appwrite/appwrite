<?php

namespace Tests\E2E\Services\Users;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

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
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'range' => '32h',
            'provider' => 'email',
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'range' => '24h',
            'provider' => 'some-random-provider',
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'range' => '24h',
            'provider' => 'email',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(count($response['body']), 9);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['usersCount']);
        $this->assertIsArray($response['body']['usersCreate']);
        $this->assertIsArray($response['body']['usersRead']);
        $this->assertIsArray($response['body']['usersUpdate']);
        $this->assertIsArray($response['body']['usersDelete']);
        $this->assertIsArray($response['body']['sessionsCreate']);
        $this->assertIsArray($response['body']['sessionsProviderCreate']);
        $this->assertIsArray($response['body']['sessionsDelete']);

        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'range' => '24h',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(count($response['body']), 9);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['usersCount']);
        $this->assertIsArray($response['body']['usersCreate']);
        $this->assertIsArray($response['body']['usersRead']);
        $this->assertIsArray($response['body']['usersUpdate']);
        $this->assertIsArray($response['body']['usersDelete']);
        $this->assertIsArray($response['body']['sessionsCreate']);
        $this->assertIsArray($response['body']['sessionsProviderCreate']);
        $this->assertIsArray($response['body']['sessionsDelete']);
    }
}
