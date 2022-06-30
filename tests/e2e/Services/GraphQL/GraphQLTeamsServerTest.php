<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\SideServer;

class GraphQLTeamsServerTest extends GraphQLTeamsBase
{
    use SideServer;

    /**
     * @depends testCreateTeam
     */
    public function testUpdateTeam($team)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_TEAM);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
                'name' => 'New Name',
            ],
        ];

        $team = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($team['body']['data']);
        $this->assertArrayNotHasKey('errors', $team['body']);
        $team = $team['body']['data']['teamsUpdate'];
        $this->assertEquals('New Name', $team['name']);
    }

    public function testDeleteTeam()
    {
        $team = $this->testCreateTeam();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_TEAM);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'teamId' => $team['_id'],
            ],
        ];

        $team = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertEquals(200, $team['headers']['status-code']);
    }
}