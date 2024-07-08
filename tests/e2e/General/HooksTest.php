<?php

namespace Tests\E2E\General;

use Appwrite\Extend\Exception;
use Appwrite\ID;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class HooksTest extends Scope
{
    use ProjectConsole;
    use SideClient;

    public function setUp(): void
    {
        parent::setUp();
        $this->client->setEndpoint('http://localhost');
    }

    public function testProjectHooks()
    {
        /**
        * Test for api controllers
        */
        $response = $this->client->call(Client::METHOD_GET, '/v1/locale', \array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ]), [
            'project' => 'console'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/v1/locale', \array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ]), [
            'project' => '$this_project_doesnt_exist'
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
        * Test for web controllers
        */
        $response = $this->client->call(Client::METHOD_GET, headers: [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], params: [
            'project' => 'console'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, headers: [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], params: [
            'project' => '$this_project_doesnt_exist'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testUserHooks()
    {
        /**
         * Setup blocked user
         */
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';

        $response = $this->client->call(Client::METHOD_POST, '/v1/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/v1/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']];
        $cookie = 'a_session_' . $this->getProject()['$id'] . '=' . $session;

        $response = $this->client->call(Client::METHOD_GET, '/v1/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => $cookie,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/v1/account/status', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => $cookie,
        ], [
            'status' => false,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/v1/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => $cookie,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        /**
        * Test for api controllers
        */
        $response = $this->client->call(Client::METHOD_GET, '/v1/locale', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => $cookie,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals(Exception::USER_BLOCKED, $response['body']['type']);

        /**
        * Test for web controllers
        */
        $response = $this->client->call(Client::METHOD_GET, headers: [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => $cookie,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
    }
}
