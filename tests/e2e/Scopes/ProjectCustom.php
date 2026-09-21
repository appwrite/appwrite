<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
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

        if ($fresh) {
            return $this->createNewProject();
        }

        self::$project = $this->createNewProject();

        return self::$project;
    }

    /**
     * Create a new project with team, API key, webhook, and SMTP config.
     */
    protected function createNewProject(): array
    {
        $maxRetries = 5;
        $team = $this->createTeamFixture([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'teamId' => ID::unique(),
            'name' => 'Demo Project Team',
        ]);

        $this->assertEquals(200, $team['headers']['status-code']);
        $this->assertEquals('Demo Project Team', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);
        $teamId = $team['body']['$id'];

        $project = null;
        for ($i = 0; $i < $maxRetries; $i++) {
            $project = $this->client->call(Client::METHOD_POST, '/projects', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
                'x-appwrite-project' => 'console',
            ], [
                'projectId' => ID::unique(),
                'region' => System::getEnv('_APP_REGION', 'default'),
                'name' => 'Demo Project',
                'teamId' => $teamId,
                'description' => 'Demo Project Description',
                'url' => 'https://appwrite.io',
            ]);

            if ($project['headers']['status-code'] === 201) {
                break;
            }

            if ($project['headers']['status-code'] === 401 && $i < $maxRetries - 1) {
                \usleep(500000); // 500ms delay before retry
                continue;
            }
        }

        $this->assertEquals(201, $project['headers']['status-code'], 'Project creation failed with status: ' . $project['headers']['status-code']);
        $this->assertNotEmpty($project['body']);

        $key = null;
        for ($i = 0; $i < $maxRetries; $i++) {
            $key = $this->client->call(Client::METHOD_POST, '/projects/' . $project['body']['$id'] . '/keys', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
                'x-appwrite-project' => 'console',
            ], [
                'keyId' => ID::unique(),
                'name' => 'Demo Project Key ' . $project['body']['$id'],
                'scopes' => [
                    'users.read',
                    'users.write',
                    'teams.read',
                    'teams.write',
                    'databases.read',
                    'databases.write',
                    'collections.read',
                    'collections.write',
                    'documentsdb.read',
                    'documentsdb.write',
                    'documentsdb.collections.read',
                    'documentsdb.collections.write',
                    'documentsdb.documents.read',
                    'documentsdb.documents.write',
                    'documentsdb.indexes.read',
                    'documentsdb.indexes.write',
                    'vectorsdb.read',
                    'vectorsdb.write',
                    'vectorsdb.collections.read',
                    'vectorsdb.collections.write',
                    'vectorsdb.documents.read',
                    'vectorsdb.documents.write',
                    'vectorsdb.indexes.read',
                    'vectorsdb.indexes.write',
                    'tables.read',
                    'tables.write',
                    'documents.read',
                    'documents.write',
                    'rows.read',
                    'rows.write',
                    'embeddings.write',
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
                    'executions.read',
                    'executions.write',
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
                    'webhooks.read',
                    'webhooks.write',
                    'project.read',
                    'project.write',
                    'keys.read',
                    'keys.write',
                    'platforms.read',
                    'platforms.write',
                    'mocks.read',
                    'mocks.write',
                    'project.policies.read',
                    'project.policies.write',
                    'project.oauth2.read',
                    'project.oauth2.write',
                    'templates.read',
                    'templates.write',
                    'insights.read',
                    'insights.write',
                    'reports.read',
                    'reports.write',
                ],
            ]);

            if ($key['headers']['status-code'] === 201) {
                break;
            }

            if ($key['headers']['status-code'] === 401 && $i < $maxRetries - 1) {
                \usleep(500000);
                continue;
            }
        }

        $this->assertEquals(201, $key['headers']['status-code'], 'Key creation failed with status: ' . $key['headers']['status-code']);
        $this->assertNotEmpty($key['body']);
        $this->assertNotEmpty($key['body']['secret']);

        $webhook = $this->client->call(Client::METHOD_POST, '/webhooks', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $project['body']['$id'],
            'x-appwrite-mode' => 'admin'
        ], [
            'webhookId' => 'unique()',
            'name' => 'Webhook Test',
            'events' => [
                'databases.*',
                'documentsdb.*',
                'vectorsdb.*',
                'functions.*',
                'buckets.*',
                'teams.*',
                'users.*'
            ],
            'url' => 'http://request-catcher-webhook:5000/',
            'tls' => false,
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
            'port' => intval(System::getEnv('_APP_SMTP_PORT', "1025")),
            'username' => System::getEnv('_APP_SMTP_USERNAME', 'user'),
            'password' => System::getEnv('_APP_SMTP_PASSWORD', 'password'),
        ]);

        return [
            '$id' => $project['body']['$id'],
            'name' => $project['body']['name'],
            'region' => $project['body']['region'],
            'apiKey' => $key['body']['secret'],
            'webhookId' => $webhook['body']['$id'],
            'signatureKey' => $webhook['body']['secret'],
        ];
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
            'keyId' => ID::unique(),
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
