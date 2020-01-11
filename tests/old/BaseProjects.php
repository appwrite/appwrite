<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class BaseProjects extends BaseConsole
{
    /**
     * @var Client
     */
    protected $projectsDemoEmail = '';
    protected $projectsDemoPassword = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectsDemoEmail = 'user.' . rand(0, 1000000) . '@appwrite.io';
        $this->projectsDemoPassword = 'password.' . rand(0, 1000000);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->client = null;
    }

    public function projectRegister($projectId)
    {
        $response = $this->client->call(Client::METHOD_POST, '/auth/register', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $this->projectsDemoEmail,
            'password' => $this->projectsDemoPassword,
            'confirm' => 'http://localhost/confirm',
            'success' => 'http://localhost/success',
            'failure' => 'http://localhost/failure',
            'name' => 'Porject Demo User',
        ]);

        return $response;
    }
}
