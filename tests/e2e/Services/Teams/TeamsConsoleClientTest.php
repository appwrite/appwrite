<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\ID;

class TeamsConsoleClientTest extends Scope
{
    use TeamsBase;
    use TeamsBaseClient;
    use ProjectConsole;
    use SideClient;

    public function testRequestHeader()
    {
        /**
         * Test without header
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console'
        ], $this->getHeaders()), [
            'name' => 'Latest version Team',
            'teamId' => ID::unique()
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $team1Id = $response['body']['$id'];

        /**
         * Test with header
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'x-appwrite-response-format' => '0.11.0'
        ], $this->getHeaders()), [
            'name' => 'Latest version Team'
            // Notice "teamId' is not defined
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $team2Id = $response['body']['$id'];

        /**
         * Cleanup, so I don't invalidate some listTeams requests by mistake
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $team1Id, \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $team2Id, \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
    }
}
