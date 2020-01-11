<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;

trait ProjectCustom
{
    /**
     * @var string
     */
    protected $rootEmail = '';
    
    /**
     * @var string
     */
    protected $rootPassword = '';

    /**
     * @var array
     */
    protected $project = [];

    /**
     * @return array
     */
    public function getProject(): array
    {
        if(!empty($this->project)) {
            return $this->project;
        }

        $root = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $this->rootEmail,
            'password' => $this->rootEmail,
            'name' => 'Demo User',
        ]);

        $this->assertEquals(201, $root['headers']['status-code']);

        $session = $this->client->parseCookie($root['headers']['set-cookie'])['a_session_console'];

        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Demo Project Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Demo Project Team', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$uid']);

        $project = $this->client->call(Client::METHOD_POST, '/projects', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
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
            'cookie' => 'a_session_console=' . $session,
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Demo Project Key',
            'scopes' => [
                'account',
                '',
                '',
            ],
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertNotEmpty($key['body']);
        $this->assertNotEmpty($key['body']['secret']);

        // return [
        //     'email' => $this->demoEmail,
        //     'password' => $this->demoPassword,
        //     'session' => $session,
        //     'projectUid' => $project['body']['$uid'],
        //     'projectAPIKeySecret' => $key['body']['secret'],
        //     'projectSession' => $this->client->parseCookie($user['headers']['set-cookie'])['a_session_' . $project['body']['$uid']],
        // ];

        $this->project = [
            '$uid' => $project['body']['$uid'],
            'name' => $project['body']['name'],
            'apiKey' => $key['body']['secret'],
        ];

        var_dump('init project');

        return $this->project;
    }
}
