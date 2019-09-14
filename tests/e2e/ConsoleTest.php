<?php

namespace Tests\E2E;

use Tests\E2E\Client;
use PHPUnit\Framework\TestCase;

class ConsoleTest extends TestCase
{
    /**
     * @var Client
     */
    protected $client = null;
    protected $endpoint = 'http://localhost/v1';

    public function setUp()
    {
        $this->client = new Client(null);

        $this->client
            ->setEndpoint($this->endpoint)
        ;
    }

    public function tearDown()
    {
        $this->client = null;
    }

    public function testRegisterSuccess()
    {
        $response = $this->client->call(Client::METHOD_POST, '/auth/register', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], [
            'email' => 'username1@appwrite.io',
            'password' => 'password',
            'redirect' => 'http://localhost/confirm',
            'success' => 'http://localhost/success',
            'failure' => 'http://localhost/failure',
            'name' => 'User 1',
        ]);

        $this->assertEquals('http://localhost/failure', $response['headers']['location']);
        $this->assertEquals("\n", $response['body']);
    }

    public function testLoginFailure()
    {
        $response = $this->client->call(Client::METHOD_POST, '/auth/login', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], [
            'email' => 'username@appwrite.io',
            'password' => 'password1',
            'success' => 'http://localhost/success',
            'failure' => 'http://localhost/failure',
        ]);

        $this->assertEquals('http://localhost/failure', $response['headers']['location']);
        $this->assertEquals("\n", $response['body']);
    }

    public function testLoginSuccess()
    {
        $response = $this->client->call(Client::METHOD_POST, '/auth/login', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], [
            'email' => 'username@appwrite.io',
            'password' => 'password',
            'success' => 'http://localhost/success',
            'failure' => 'http://localhost/failure',
        ]);

        $this->assertEquals('http://localhost/success', $response['headers']['location']);
        $this->assertEquals("\n", $response['body']);
    }
}