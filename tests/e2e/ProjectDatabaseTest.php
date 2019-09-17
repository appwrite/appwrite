<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ProjectDatabaseTest extends BaseProjects
{
    public function testRegisterSuccess()
    {
        $response = $this->register();

        $this->assertEquals('http://localhost/success', $response['headers']['location']);
        $this->assertEquals("\n", $response['body']);
        
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
            'scopes' => ['collections.read', 'collections.write', 'documents.read', 'documents.write',],
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertNotEmpty($key['body']);
        $this->assertNotEmpty($key['body']['secret']);

        $user = $this->projectRegister($project['body']['$uid']);
        
        $this->assertEquals('http://localhost/success', $user['headers']['location']);
        $this->assertEquals("\n", $user['body']);
        
        return [
            'email' => $this->demoEmail,
            'password' => $this->demoPassword,
            'session' => $session,
            'projectUid' => $project['body']['$uid'],
            'projectAPIKeyUid' => $key['body']['$uid'],
            'projectAPIKeySecret' => $key['body']['secret'],
            'projectSession' => $this->projectClient->parseCookie($user['headers']['set-cookie'])['a-session-console'],
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

}