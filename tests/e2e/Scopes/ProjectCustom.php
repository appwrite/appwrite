<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

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

        $project = $this->client->call(Client::METHOD_POST, '/projects', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'projectId' => ID::unique(),
            'region' => System::getEnv('_APP_REGION', 'default'),
            'name' => 'Demo Project',
            'teamId' => $team['body']['$id'],
            'description' => 'Demo Project Description',
            'url' => 'https://appwrite.io',
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertNotEmpty($project['body']);

        $key = $this->client->call(Client::METHOD_POST, '/projects/' . $project['body']['$id'] . '/keys', [
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
                'tables.read',
                'tables.write',
                'documents.read',
                'documents.write',
                'rows.read',
                'rows.write',
                'files.read',
                'files.write',
                'buckets.read',
                'buckets.write',
                'sites.read',
                'sites.write',
                'functions.read',
                'functions.write',
                'sites.read',
                'sites.write',
                'execution.read',
                'execution.write',
                'log.read',
                'log.write',
                'locale.read',
                'avatars.read',
                'health.read',
                'rules.read',
                'rules.write',
                'sessions.write',
                'targets.read',
                'targets.write',
                'providers.read',
                'providers.write',
                'messages.read',
                'messages.write',
                'topics.write',
                'topics.read',
                'subscribers.write',
                'subscribers.read',
                'migrations.write',
                'migrations.read',
                'tokens.read',
                'tokens.write',
            ],
        ]);

        $this->assertEquals(201, $key['headers']['status-code']);
        $this->assertNotEmpty($key['body']);
        $this->assertNotEmpty($key['body']['secret']);

        $devKey = $this->client->call(Client::METHOD_POST, '/projects/' . $project['body']['$id'] . '/dev-keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);
        $this->assertEquals(201, $devKey['headers']['status-code']);
        $this->assertNotEmpty($devKey['body']);
        $this->assertNotEmpty($devKey['body']['secret']);

        $webhook = $this->client->call(Client::METHOD_POST, '/projects/' . $project['body']['$id'] . '/webhooks', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Webhook Test',
            'events' => [
                'databases.*',
                // 'functions.*', TODO @christyjacob4 : enable test once we allow functions.* events
                'buckets.*',
                'teams.*',
                'users.*'
            ],
            'url' => 'http://request-catcher-webhook:5000/',
            'security' => false,
        ]);

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertNotEmpty($webhook['body']);

        $this->client->call(Client::METHOD_PATCH, '/projects/' . $project['body']['$id'] . '/smtp', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'enabled' => true,
            'senderEmail' => 'mailer@appwrite.io',
            'senderName' => 'Mailer',
            'host' => 'maildev',
            'port' => 1025,
            'username' => '',
            'password' => '',
        ]);

        $project = [
            '$id' => $project['body']['$id'],
            'name' => $project['body']['name'],
            'apiKey' => $key['body']['secret'],
            'devKey' => $devKey['body']['secret'],
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

        $key = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/keys', [
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
    public function updateProjectinvalidateSessionsProperty(bool $value)
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . self::$project['$id'] . '/auth/session-invalidation', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ]), [
            'enabled' => $value,
        ]);

        return $response['headers']['status-code'];
    }
}
