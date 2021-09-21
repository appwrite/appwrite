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

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h',
            'provider' => 'some-random-provider'
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
            'provider' => 'email'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(count($response['body']), 9);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['users.count']);
        $this->assertIsArray($response['body']['users.create']);
        $this->assertIsArray($response['body']['users.read']);
        $this->assertIsArray($response['body']['users.update']);
        $this->assertIsArray($response['body']['users.delete']);
        $this->assertIsArray($response['body']['sessions.create']);
        $this->assertIsArray($response['body']['sessions.provider.create']);
        $this->assertIsArray($response['body']['sessions.delete']);
    
        $response = $this->client->call(Client::METHOD_GET, '/users/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(count($response['body']), 9);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['users.count']);
        $this->assertIsArray($response['body']['users.create']);
        $this->assertIsArray($response['body']['users.read']);
        $this->assertIsArray($response['body']['users.update']);
        $this->assertIsArray($response['body']['users.delete']);
        $this->assertIsArray($response['body']['sessions.create']);
        $this->assertIsArray($response['body']['sessions.provider.create']);
        $this->assertIsArray($response['body']['sessions.delete']);
    }
}