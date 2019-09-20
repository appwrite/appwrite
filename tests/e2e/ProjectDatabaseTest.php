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
            'projectAPIKeySecret' => $key['body']['secret'],
            'projectSession' => $this->client->parseCookie($user['headers']['set-cookie'])['a-session-' . $project['body']['$uid']],
        ];
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testCollectionCreateSuccess($data) {
        $collection = $this->client->call(Client::METHOD_POST, '/database', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ], [
            'name' => 'Movies',
            'read' => ['*'],
            'write' => ['role:1', 'role:2'],
            'rules' => [
                [
                    'label' => 'Name',
                    'key' => 'name',
                    'type' => 'text',
                    'default' => '',
                    'required' => false,
                    'array' => false
                ],
                [
                    'label' => 'Release Year',
                    'key' => 'releaseYear',
                    'type' => 'numeric',
                    'default' => 0,
                    'required' => false,
                    'array' => false
                ],
            ],
        ]);

        $this->assertEquals($collection['headers']['status-code'], 201);
        $this->assertEquals($collection['body']['$collection'], 0);
        $this->assertEquals($collection['body']['name'], 'Movies');
        $this->assertIsArray($collection['body']['$permissions']);
        $this->assertIsArray($collection['body']['$permissions']['read']);
        $this->assertIsArray($collection['body']['$permissions']['write']);
        $this->assertEquals(count($collection['body']['$permissions']['read']), 1);
        $this->assertEquals(count($collection['body']['$permissions']['write']), 2);

        return array_merge($data, ['collectionId' => $collection['body']['$uid']]);
    }

    /**
     * @depends testCollectionCreateSuccess
     */
    public function testDocumentCreateSuccess($data) {
        $collection = $this->client->call(Client::METHOD_POST, '/database/' . $data['collectionId'] . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-key' => $data['projectAPIKeySecret'],
        ], [
            'data' => [
                'name' => 'Avengers',
                'releaseYear' => 2019,
            ]
        ]);

        $this->assertEquals($collection['headers']['status-code'], 201);
        $this->assertEquals($collection['body']['$collection'], $data['collectionId']);
        $this->assertEquals($collection['body']['name'], 'Avengers');
        $this->assertEquals($collection['body']['releaseYear'], 2019);
        $this->assertIsArray($collection['body']['$permissions']);
        $this->assertIsArray($collection['body']['$permissions']['read']);
        $this->assertIsArray($collection['body']['$permissions']['write']);
        $this->assertEquals(count($collection['body']['$permissions']['read']), 0);
        $this->assertEquals(count($collection['body']['$permissions']['write']), 0);
    }
}