<?php

namespace Tests\E2E\Services\Users;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Client;
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
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h',
            'provider' => 'email'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h',
            'provider' => 'some-random-provider'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h',
            'provider' => 'email'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(9, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
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
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(9, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
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
