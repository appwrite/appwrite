<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Helpers\ID;

trait TeamsBaseServer
{
    /**
     * @depends testCreateTeam
     */
    public function testGetTeamMemberships($data): array
    {
        $id = $data['teamUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $id . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['total']);

        /**
         * Test for FAILURE
         */

        return [];
    }

    /**
     * @depends testCreateTeamMembership
     */
    public function testGetTeamMembership($data): void
    {
        $teamUid = $data['teamUid'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships/' . $membershipUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['userName']);
        $this->assertNotEmpty($response['body']['userEmail']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertNotEmpty($response['body']['teamName']);
        $this->assertCount(2, $response['body']['roles']);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($response['body']['joined'])); // is null in DB
        $this->assertEquals(true, $response['body']['confirm']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships/' . $membershipUid . 'dasdasd', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid . '/memberships/' . $membershipUid, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    /**
     * @depends testCreateTeam
     */
    public function testCreateTeamMembership($data): array
    {
        $teamUid = $data['teamUid'] ?? '';
        $teamName = $data['teamName'] ?? '';
        $email = uniqid() . 'friend@localhost.test';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertEquals('Friend User', $response['body']['userName']);
        $this->assertEquals($email, $response['body']['userEmail']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(2, $response['body']['roles']);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($response['body']['joined']));
        $this->assertEquals(true, $response['body']['confirm']);

        $userUid = $response['body']['userId'];
        $membershipUid = $response['body']['$id'];

        // $response = $this->client->call(Client::METHOD_GET, '/users/'.$userUid, array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$id'],
        // ], $this->getHeaders()), []);

        // $this->assertEquals($userUid, $response['body']['$id']);
        // $this->assertContains('team:'.$teamUid, $response['body']['roles']);
        // $this->assertContains('team:'.$teamUid.'/admin', $response['body']['roles']);
        // $this->assertContains('team:'.$teamUid.'/editor', $response['body']['roles']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => 'dasdkaskdjaskdjasjkd',
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => 'bad string',
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/' . $teamUid . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://example.com/join-us#title' // bad url
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [
            'teamUid' => $teamUid,
            'userUid' => $userUid,
            'membershipUid' => $membershipUid
        ];
    }

    /**
     * @depends testCreateTeamMembership
     */
    public function testUpdateMembershipRoles($data)
    {
        $teamUid = $data['teamUid'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $roles = ['admin', 'editor', 'uncle'];
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamUid . '/memberships/' . $membershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'roles' => $roles
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(count($roles), $response['body']['roles']);
        $this->assertEquals($roles[0], $response['body']['roles'][0]);
        $this->assertEquals($roles[1], $response['body']['roles'][1]);
        $this->assertEquals($roles[2], $response['body']['roles'][2]);


        /**
         * Test for FAILURE
         */
        $apiKey = $this->getNewKey(['teams.read']);
        $roles = ['admin', 'editor', 'uncle'];
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamUid . '/memberships/' . $membershipUid, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $apiKey
        ], [
            'roles' => $roles
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateMembershipRoles
     */
    public function testDeleteUserUpdatesTeamMembershipCount($data)
    {
        $teamUid = $data['teamUid'] ?? '';
        $userUid = $data['userUid'] ?? '';

        /** Get Team Count */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));


        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Arsenal', $response['body']['name']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($response['body']['$createdAt']));

        /** Delete User */
        $user = $this->client->call(Client::METHOD_DELETE, '/users/' . $userUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($user['headers']['status-code'], 204);

        /** Wait for deletes worker to delete membership and update team membership count */
        sleep(5);

        /** Get Team Count */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));


        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Arsenal', $response['body']['name']);
        $this->assertEquals(0, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertEquals(true, $dateValidator->isValid($response['body']['$createdAt']));
    }

    public function testTeamDeleteUpdatesUserMembership()
    {
        // Array to store the IDs of newly created users
        $new_users = [];

        /**
         * Create a new team
         */
        $new_team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'New Team Test',
            'roles' => ['admin', 'editor'],
        ]);

        $this->assertEquals(201, $new_team['headers']['status-code']);
        $this->assertNotEmpty($new_team['body']['$id']);
        $this->assertEquals('New Team Test', $new_team['body']['name']);
        $this->assertGreaterThan(-1, $new_team['body']['total']);
        $this->assertIsInt($new_team['body']['total']);
        $this->assertArrayHasKey('prefs', $new_team['body']);

        /**
         * Use the Create Team Membership endpoint
         * to create 5 new users and add them to the team immediately
         */
        for ($i = 0; $i < 5; $i++) {
            $new_membership = $this->client->call(Client::METHOD_POST, '/teams/' . $new_team['body']['$id'] . '/memberships', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'email' => 'newuser' . $i . '@localhost.test',
                'name' => 'New User ' . $i,
                'roles' => ['admin', 'editor'],
                'url' => 'http://localhost:5000/join-us#title'
            ]);

            $this->assertEquals(201, $new_membership['headers']['status-code']);
            $this->assertNotEmpty($new_membership['body']['$id']);
            $this->assertNotEmpty($new_membership['body']['userId']);
            $this->assertEquals('New User ' . $i, $new_membership['body']['userName']);
            $this->assertEquals('newuser' . $i . '@localhost.test', $new_membership['body']['userEmail']);
            $this->assertNotEmpty($new_membership['body']['teamId']);
            $this->assertCount(2, $new_membership['body']['roles']);
            $dateValidator = new DatetimeValidator();
            $this->assertEquals(true, $dateValidator->isValid($new_membership['body']['joined']));
            $this->assertEquals(true, $new_membership['body']['confirm']);

            $new_users[] = $new_membership['body']['userId'];
        }

        /**
         * Delete the team
         */
        $team_del_response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $new_team['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $team_del_response['headers']['status-code']);

        /**
         * Check that the team memberships for each of the new users has been deleted
         */
        for ($i = 0; $i < 5; $i++) {
            $membership = $this->client->call(Client::METHOD_GET, '/users/' . $new_users[$i] . '/memberships', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $membership['headers']['status-code']);
            $this->assertEquals(0, $membership['body']['total']);
        }
    }
}
