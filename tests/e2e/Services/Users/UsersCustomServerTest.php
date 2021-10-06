<?php

namespace Tests\E2E\Services\Users;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class UsersCustomServerTest extends Scope
{
    use UsersBase;
    use ProjectCustom;
    use SideServer;

    public function testDeprecatedUsers():array
    {
        /**
         * Test for FAILURE (don't allow recreating account with same custom ID)
         */

        // Create user with custom ID 'meldiron'
        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'meldiron',
            'email' => 'matej@appwrite.io',
            'password' => 'my-superstr0ng-password',
            'name' => 'Matej Bačo'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Delete user with custom ID 'meldiron'
        $response = $this->client->call(Client::METHOD_DELETE, '/users/meldiron', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        // Try to create user with custom ID 'meldiron' again, but now it should fail
        $response1 = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'meldiron',
            'email' => 'matej2@appwrite.io',
            'password' => 'someones-superstr0ng-password',
            'name' => 'Matej Bačo Second'
        ]);

        $this->assertEquals(409, $response1['headers']['status-code']);
        $this->assertEquals('Account already exists', $response1['body']['message']);

        return [];
    }

}