<?php

namespace Tests\E2E\Services\Projects;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

trait ProjectsBase
{
    protected function setupProject(mixed $params): string
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Project Test',
        ]);

        $this->assertEquals(201, $team['headers']['status-code'], 'Setup team failed with status code: ' . $team['headers']['status-code'] . ' and response: ' . json_encode($team['body'], JSON_PRETTY_PRINT));

        $project = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            ...$params,
            'teamId' => $team['body']['$id'],
        ]);

        $this->assertEquals(201, $project['headers']['status-code'], 'Setup project failed with status code: ' . $project['headers']['status-code'] . ' and response: ' . json_encode($project['body'], JSON_PRETTY_PRINT));

        return $project['body']['$id'];
    }

    protected function setupDevKey(mixed $params): array
    {
        $devKey = $this->client->call(Client::METHOD_POST, '/projects/' . $params['projectId'] . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), $params);

        $this->assertEquals(201, $devKey['headers']['status-code'], 'Setup devKey failed with status code: ' . $devKey['headers']['status-code'] . ' and response: ' . json_encode($devKey['body'], JSON_PRETTY_PRINT));

        return [
            '$id' => $devKey['body']['$id'],
            'secret' => $devKey['body']['secret'],
        ];
    }
}
