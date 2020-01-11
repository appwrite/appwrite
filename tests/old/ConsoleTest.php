<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ConsoleTest extends BaseConsole
{
    public function testRegisterSuccess(): array
    {
        $response = $this->register();

        $this->assertEquals('http://localhost/success', $response['headers']['location']);
        $this->assertEquals("", $response['body']);

        return [
            'email' => $this->demoEmail,
            'password' => $this->demoPassword,
        ];
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testLoginSuccess(array $data): array
    {
        $response = $this->client->call(Client::METHOD_POST, '/auth/login', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $data['email'],
            'password' => $data['password'],
            'success' => 'http://localhost/success',
            'failure' => 'http://localhost/failure',
        ]);

        $session = $this->client->parseCookie($response['headers']['set-cookie'])['a_session_console'];

        $this->assertEquals('http://localhost/success', $response['headers']['location']);
        $this->assertEquals("", $response['body']);

        return [
            'email' => $data['email'],
            'password' => $data['password'],
            'session' => $session
        ];
    }

    /**
     * @depends testLoginSuccess
     */
    public function testAccountSuccess(array $data): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $data['session'],
            'x-appwrite-project' => 'console',
        ], []);

        $this->assertEquals('Demo User', $response['body']['name']);
        $this->assertEquals($data['email'], $response['body']['email']);
        $this->assertEquals(false, $response['body']['confirm']);
        $this->assertIsArray($response['body']['roles']);
        $this->assertIsInt($response['body']['registration']);
        $this->assertEquals('*', $response['body']['roles'][0]);
        $this->assertEquals('user:' . $response['body']['$uid'], $response['body']['roles'][1]);
        $this->assertEquals('role:1', $response['body']['roles'][2]);

        return $data;
    }

    /**
     * @depends testAccountSuccess
     */
    public function testLogoutSuccess(array $data): void
    {
        $response = $this->client->call(Client::METHOD_DELETE, '/auth/logout', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $data['session'],
            'x-appwrite-project' => 'console',
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('success', $response['body']['result']);
    }

    public function testLogoutFailure(): void
    {
        $response = $this->client->call(Client::METHOD_DELETE, '/auth/logout', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], []);

        $this->assertEquals('401', $response['body']['code']);
    }
}
