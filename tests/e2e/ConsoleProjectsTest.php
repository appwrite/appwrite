<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ConsoleProjectsTest extends BaseConsole
{
    public function testRegisterSuccess()
    {
        $response = $this->register();

        $this->assertEquals('http://localhost/success', $response['headers']['location']);
        $this->assertEquals("", $response['body']);
        
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
    public function testProjectsList($data)
    {
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
    public function testProjectsCreateSuccess($data)
    {
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

        $data['project'] = $response['body'];

        return $data;
    }

    /**
     * @depends testProjectsCreateSuccess
     */
    public function testProjectsUpdateSuccess($data)
    {
        $response = $this->client->call(Client::METHOD_POST, '/projects', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a-session-console=' . $data['session'],
        ], array_merge($data['project'], [
            'name' => 'New Project Name',
            'description' => 'New Demo Project Description',
            'logo' => '',
            'url' => 'https://appwrite.io/new',
        ]));

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('New Project Name', $response['body']['name']);
        $this->assertEquals('New Demo Project Description', $response['body']['description']);
        $this->assertEquals('https://appwrite.io/new', $response['body']['url']);
    }
}
