<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;

trait TeamsBaseClient
{
    /**
     * @depends testCreateTeam
     */
    public function testGetTeamMemberships($data):array
    {
        $teamUid = $data['teamUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertNotEmpty($response['body']['memberships'][0]['$id']);
        $this->assertEquals($this->getUser()['name'], $response['body']['memberships'][0]['name']);
        $this->assertEquals($this->getUser()['email'], $response['body']['memberships'][0]['email']);
        $this->assertEquals('owner', $response['body']['memberships'][0]['roles'][0]);

        /**
         * Test for FAILURE
         */

        return $data;
    }

    /**
     * @depends testCreateTeam
     */
    public function testCreateTeamMembership($data):array
    {
        $teamUid = $data['teamUid'] ?? '';
        $teamName = $data['teamName'] ?? '';
        $email = uniqid().'friend@localhost.test';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
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
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(2, $response['body']['roles']);
        $this->assertIsInt($response['body']['joined']);
        $this->assertEquals(false, $response['body']['confirm']);

        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals('Friend User', $lastEmail['to'][0]['name']);
        $this->assertEquals('Invitation to '.$teamName.' Team at '.$this->getProject()['name'], $lastEmail['subject']);

        $secret = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);
        $membershipUid = substr($lastEmail['text'], strpos($lastEmail['text'], '?membershipId=', 0) + 14, 13);
        $userUid = substr($lastEmail['text'], strpos($lastEmail['text'], '&userId=', 0) + 8, 13);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => 'dasdkaskdjaskdjasjkd',
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => 'bad string',
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
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
            'secret' => $secret,
            'membershipUid' => $membershipUid,
            'userUid' => $userUid,
        ];
    }

    /**
     * @depends testCreateTeamMembership
     */
    public function testUpdateTeamMembership($data):array
    {
        $teamUid = $data['teamUid'] ?? '';
        $secret = $data['secret'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';
        $userUid = $data['userUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => $secret,
            'userId' => $userUid,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(2, $response['body']['roles']);
        $this->assertIsInt($response['body']['joined']);
        $this->assertEquals(true, $response['body']['confirm']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => 'sdasdasd',
            'userId' => $userUid,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => '',
            'userId' => $userUid,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => $secret,
            'userId' => 'sdasd',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/teams/'.$teamUid.'/memberships/'.$membershipUid.'/status', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'secret' => $secret,
            'userId' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testUpdateTeamMembership
     */
    public function testDeleteTeamMembership($data):array
    {
        $teamUid = $data['teamUid'] ?? '';
        $membershipUid = $data['membershipUid'] ?? '';
        
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/teams/'.$teamUid.'/memberships/'.$membershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/'.$teamUid.'/memberships/'.$membershipUid, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['memberships']);

        return [];
    }


    // /**
    //  * @depends testCreateTeam
    //  */
    // public function testDeleteTeamMembershipsWorker($data):array
    // {
    //     $teamUid = $data['teamUid'] ?? '';
    //     $count = 100;

    //     /* 
    //     * Create $count Team Memberships 
    //     */
    //     for ($i = 0; $i < $count; ++$i) {
    //         $email = uniqid().'friend@localhost.test';
    //         /**
    //          * Test for SUCCESS
    //          */
    //         $response = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
    //             'content-type' => 'application/json',
    //             'x-appwrite-project' => $this->getProject()['$id'],
    //         ], $this->getHeaders()), [
    //             'email' => $email,
    //             'name' => 'Friend User ' . $i,
    //             'roles' => ['admin', 'editor'],
    //             'url' => 'http://localhost:5000/join-us#title'
    //         ]);

    //         $this->assertEquals(201, $response['headers']['status-code']);
    //         $this->assertNotEmpty($response['body']['$id']);
    //         $this->assertNotEmpty($response['body']['userId']);
    //         $this->assertNotEmpty($response['body']['teamId']);
    //         $this->assertCount(2, $response['body']['roles']);
    //         $this->assertIsInt($response['body']['joined']);
    //         $this->assertEquals(false, $response['body']['confirm']);
    //     }

    //     /**
    //      * Get team memberships 
    //      */
    //     $response = $this->client->call(Client::METHOD_GET, '/teams/'.$teamUid.'/memberships', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));

    //     $this->assertEquals(200, $response['headers']['status-code']);
    //     $this->assertEquals($count + 1 /* +1 for Team owner */, $response['body']['sum']);

    //     /**
    //      * Delete the team
    //      */
    //     $response = $this->client->call(Client::METHOD_DELETE, '/teams/'.$teamUid, array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));

    //     $this->assertEquals(204, $response['headers']['status-code']);
    //     $this->assertEmpty($response['body']);


    //     return [];
    // }

}