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

        $this->demoEmail = 'user.' . rand(0, 1000000) . '@appwrite.io';
        $this->demoPassword = 'password.' . rand(0, 1000000);
    }

    public function tearDown()
    {
        $this->client = null;
    }

    public function register()
    {
        $response = $this->client->call(Client::METHOD_POST, '/auth/register', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
        ], [
            'email' => $this->demoEmail,
            'password' => $this->demoPassword,
            'confirm' => 'http://localhost/confirm',
            'success' => 'http://localhost/success',
            'failure' => 'http://localhost/failure',
            'name' => 'Demo User',
        ]);

        return $response;
    }

    public function initProject(array $scopes) {
        $response = $this->register();

        $this->assertEquals('http://localhost/success', $response['headers']['location']);
        $this->assertEquals("", $response['body']);
        
        $session = $this->client->parseCookie($response['headers']['set-cookie'])['a-session-console'];

        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a-session-console=' . $session,
        ], [
            'name' => 'Demo Project Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Demo Project Team', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$uid']);

        $project = $this->client->call(Client::METHOD_POST, '/projects', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a-session-console=' . $session,
        ], [
            'name' => 'Demo Project',
            'teamId' => $team['body']['$uid'],
            'description' => 'Demo Project Description',
            'logo' => '',
            'url' => 'https://appwrite.io',
            'legalName' => '',
            'legalCountry' => '',
            'legalState' => '',
            'legalCity' => '',
            'legalAddress' => '',
            'legalTaxId' => '',
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertNotEmpty($project['body']);

        $key = $this->client->call(Client::METHOD_POST, '/projects/' . $project['body']['$uid'] . '/keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a-session-console=' . $session,
        ], [
            'name' => 'Demo Project Key',
            'scopes' => $scopes,
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertNotEmpty($key['body']);
        $this->assertNotEmpty($key['body']['secret']);

        $user = $this->projectRegister($project['body']['$uid']);
        
        $this->assertEquals('http://localhost/success', $user['headers']['location']);
        $this->assertEquals("", $user['body']);
        
        return [
            'email' => $this->demoEmail,
            'password' => $this->demoPassword,
            'session' => $session,
            'projectUid' => $project['body']['$uid'],
            'projectAPIKeySecret' => $key['body']['secret'],
            'projectSession' => $this->client->parseCookie($user['headers']['set-cookie'])['a-session-' . $project['body']['$uid']],
        ];
    }
}
