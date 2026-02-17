<?php

namespace Tests\E2E\Services\Projects\Schedules;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

trait SchedulesBase
{
    public function testCreateProject(): array
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Schedule Test Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Schedule Test Project',
            'teamId' => $team['body']['$id'],
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);

        $projectId = $project['body']['$id'];

        $key = $this->client->call(Client::METHOD_POST, '/projects/'.$projectId.'/keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'keyId' => ID::unique(),
            'name' => 'Schedule Test Key',
            'scopes' => [
                'functions.read',
                'functions.write',
                'execution.read',
                'execution.write',
                'messages.read',
                'messages.write',
            ],
        ]);

        $this->assertEquals(201, $key['headers']['status-code']);

        return [
            'projectId' => $projectId,
            'apiKey' => $key['body']['secret'],
        ];
    }
}
