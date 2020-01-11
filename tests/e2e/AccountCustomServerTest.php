<?php

namespace Tests\E2E;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class AccountCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testCreateAccount():array
    {
        $email = uniqid().'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
            'X-Appwrite-Key' => $this->getProject()['apiKey'],
        ], [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        
        return [];
    }
}