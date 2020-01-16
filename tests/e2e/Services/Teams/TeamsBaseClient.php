<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;

trait TeamsBaseClient
{
    /**
     * @depends testCreateTeam
     */
    public function testGetTeamMembers($data):array
    {
        $uid = (isset($data['teamUid'])) ? $data['teamUid'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/'.$uid.'/members', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body'][0]['$uid']);
        $this->assertEquals($this->getUser()['$uid'], $response['body'][0]['$uid']);
        $this->assertEquals($this->getUser()['name'], $response['body'][0]['name']);
        $this->assertEquals($this->getUser()['email'], $response['body'][0]['email']);
        $this->assertEquals('owner', $response['body'][0]['roles'][0]);

        /**
         * Test for FAILURE
         */

         return [];
    }
}