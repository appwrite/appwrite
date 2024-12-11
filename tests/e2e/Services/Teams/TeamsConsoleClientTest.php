<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class TeamsConsoleClientTest extends Scope
{
    use TeamsBase;
    use TeamsBaseClient;
    use ProjectConsole;
    use SideClient;

    /**
     * @depends testCreateTeam
     */
    public function testTeamMembershipPerms($data): array
    {
        $teamUid = $data['teamUid'] ?? '';
        $teamName = $data['teamName'] ?? '';
        $email = uniqid() . 'friend@localhost.test';
        $name = 'Friend User';
        $password = 'password';

        // Create a user account before we create a invite so we can check if the user has permissions when it shouldn't
        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console'], [
                'userId' => 'unique()',
                'email' => $email,
                'password' => $password,
                'name' => $name,
            ], false);

        $this->assertEquals(201, $user['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => $name,
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/users', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);

        $ownerMembershipUid = $response['body']['memberships'][1]['$id'];

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid . '/memberships/' . $ownerMembershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        $this->assertEquals(204, $response['headers']['status-code']);

        return $data;
    }
}
