<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;

trait TeamsBaseServer
{
    /**
     * @depends testCreateTeam
     */
    public function testGetTeamMemberships($data):array
    {
        $id = $data['teamUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/'.$id.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['sum']);

        /**
         * Test for FAILURE
         */

        return [];
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
        $this->assertGreaterThan(0, $response['body']['joined']);
        $this->assertEquals(true, $response['body']['confirm']);

        $userUid = $response['body']['userId'];

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
            'userUid' => $userUid,
        ];
    }
}