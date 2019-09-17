<?php

namespace Tests\E2E;

use Tests\E2E\Client;
use PHPUnit\Framework\TestCase;

class BaseConsole extends TestCase
{
    /**
     * @var Client
     */
    protected $client = null;
    protected $endpoint = 'http://localhost/v1';
    protected $demoEmail = '';
    protected $demoPassword = '';

    public function setUp()
    {
        $this->client = new Client();
    
        $this->client
            ->setEndpoint($this->endpoint)
        ;

        $this->demoEmail = 'user.' . rand(0,1000000) . '@appwrite.io';
        $this->demoPassword = 'password.' . rand(0,1000000);
    }

    public function tearDown()
    {
        $this->client = null;
    }

    public function register() {
        $response = $this->client->call(Client::METHOD_POST, '/auth/register', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], [
            'email' => $this->demoEmail,
            'password' => $this->demoPassword,
            'redirect' => 'http://localhost/confirm',
            'success' => 'http://localhost/success',
            'failure' => 'http://localhost/failure',
            'name' => 'Demo User',
        ]);

        return $response;
    }
}