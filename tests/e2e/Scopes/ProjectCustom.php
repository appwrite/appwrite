<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;

trait ProjectCustom
{
    /**
     * @var array
     */
    protected static $project = [];

    /**
     * @return array
     */
    public function getProject(): array
    {
        if (!empty(self::$project)) {
            return self::$project;
        }

        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
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
                'collections.read',
                'collections.write',
                'documents.read',
                'documents.write',
                'files.read',
                'files.write',
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

        $webhook = $this->client->call(Client::METHOD_POST, '/projects/'.$project['body']['$id'].'/webhooks', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Webhook Test',
            'events' => [
                'account.create',
                'account.update.email',
                'account.update.name',
                'account.update.password',
                'account.update.prefs',
                'account.recovery.create',
                'account.recovery.update',
                'account.verification.create',
                'account.verification.update',
                'account.delete',
                'account.sessions.create',
                'account.sessions.delete',
                'database.collections.create',
                'database.collections.update',
                'database.collections.delete',
                'database.documents.create',
                'database.documents.update',
                'database.documents.delete',
                'functions.create',
                'functions.update',
                'functions.delete',
                'functions.tags.create',
                'functions.tags.update',
                'functions.tags.delete',
                'functions.executions.create',
                'functions.executions.update',
                'storage.files.create',
                'storage.files.update',
                'storage.files.delete',
                'users.create',
                'users.update.prefs',
                'users.update.active',
                'users.delete',
                'users.sessions.delete',
                'teams.create',
                'teams.update',
                'teams.delete',
                'teams.memberships.create',
                'teams.memberships.update.status',
                'teams.memberships.delete',
            ],
            'url' => 'http://request-catcher:5000/webhook',
            'security' => false,
            'httpUser' => '',
            'httpPass' => '',
        ]);

        $this->assertEquals(201, $webhook['headers']['status-code']);
        $this->assertNotEmpty($webhook['body']);

        self::$project = [
            '$id' => $project['body']['$id'],
            'name' => $project['body']['name'],
            'apiKey' => $key['body']['secret'],
            'webhookId' => $webhook['body']['$id'],
        ];

        return self::$project;
    }
}
