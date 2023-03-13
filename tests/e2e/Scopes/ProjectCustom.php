<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

trait ProjectCustom
{
    /**
     * @var array
     */
    protected static $project = [];

    /**
     * @param bool $fresh
     * @return array
     */
    public function getProject(bool $fresh = false): array
    {
        if (!empty(self::$project) && !$fresh) {
            return self::$project;
        }

        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'teamId' => ID::unique(),
            'name' => 'Demo Project Team',
        ]);
        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Demo Project Team', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);

        $project = $this->client->call(Client::METHOD_POST, '/project', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'projectId' => ID::unique(),
            'region' => 'default',
            'name' => 'Demo Project',
            'teamId' => $team['body']['$id'],
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

        $key = $this->client->call(Client::METHOD_POST, '/project/' . $project['body']['$id'] . '/keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Demo Project Key',
            'scopes' => [
                'users.read',
                'users.write',
                'teams.read',
                'teams.write',
                'databases.read',
                'databases.write',
                'collections.read',
                'collections.write',
                'documents.read',
                'documents.write',
                'files.read',
                'files.write',
                'buckets.read',
                'buckets.write',
                'functions.read',
                'functions.write',
                'execution.read',
                'execution.write',
                'locale.read',
                'avatars.read',
                'health.read',
            ],
        ]);

        $this->assertEquals(201, $key['headers']['status-code']);
        $this->assertNotEmpty($key['body']);
        $this->assertNotEmpty($key['body']['secret']);

        $webhook = $this->client->call(Client::METHOD_POST, '/project/' . $project['body']['$id'] . '/webhooks', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Webhook Test',
            'events' => [
                'databases.*',
                'functions.*',
                'buckets.*',
                'teams.*',
                'users.*'
            ],
            'url' => 'http://request-catcher:5000/webhook',
            'security' => false,
            'httpUser' => '',
            'httpPass' => '',
        ]);

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertNotEmpty($webhook['body']);

        $project = [
            '$id' => $project['body']['$id'],
            'name' => $project['body']['name'],
            'apiKey' => $key['body']['secret'],
            'webhookId' => $webhook['body']['$id'],
            'signatureKey' => $webhook['body']['signatureKey'],
        ];
        if ($fresh) {
            return $project;
        }
        self::$project = $project;

        return self::$project;
    }

    public function getNewKey(array $scopes)
    {

        $projectId = self::$project['$id'];

        $key = $this->client->call(Client::METHOD_POST, '/project/' . $projectId . '/keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Demo Project Key',
            'scopes' => $scopes,
        ]);

        $this->assertEquals(201, $key['headers']['status-code']);
        $this->assertNotEmpty($key['body']);
        $this->assertNotEmpty($key['body']['secret']);

        return $key['body']['secret'];
    }
}
