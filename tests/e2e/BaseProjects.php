<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class BaseProjects extends BaseConsole
{
    /**
     * @var Client
     */
    protected $projectClient = null;
    protected $projectsDemoEmail = '';
    protected $projectsDemoPassword = '';

    public function setUp()
    {
        parent::setUp();

        $this->projectClient = new Client();
    
        $this->projectClient
            ->setEndpoint($this->endpoint)
        ;

        $this->projectsDemoEmail = 'user.' . rand(0,1000000) . '@appwrite.io';
        $this->projectsDemoPassword = 'password.' . rand(0,1000000);
    }

    public function tearDown()
    {
        parent::tearDown();

        $this->client = null;
    }

    public function projectRegister($projectId)
    {
        $response = $this->projectClient->call(Client::METHOD_POST, '/auth/register', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $this->projectsDemoEmail,
            'password' => $this->projectsDemoPassword,
            'redirect' => 'http://localhost/confirm',
            'success' => 'http://localhost/success',
            'failure' => 'http://localhost/failure',
            'name' => 'Porject Demo User',
        ]);

        return $response;
    }
}