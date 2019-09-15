<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ConsoleProjectsTest extends Base
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
    
    public function testRegisterSuccess()
    {
        $response = $this->register();

        $this->assertEquals('http://localhost/success', $response['headers']['location']);
        $this->assertEquals("\n", $response['body']);
        
        $session = $this->client->parseCookie($response['headers']['set-cookie'])['a-session-console'];

        return [
            'email' => $this->demoEmail,
            'password' => $this->demoPassword,
            'session' => $session
        ];
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testProjectsList($data) {
        $response = $this->client->call(Client::METHOD_GET, '/projects', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a-session-console=' . $data['session'],
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testProjectsCreateSuccess($data) {
        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a-session-console=' . $data['session'],
        ], [
            'name' => 'Demo Project Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Demo Project Team', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$uid']);

        $response = $this->client->call(Client::METHOD_POST, '/projects', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a-session-console=' . $data['session'],
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

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
    }
}