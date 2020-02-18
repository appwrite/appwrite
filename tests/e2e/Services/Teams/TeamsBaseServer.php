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
        $id = (isset($data['teamUid'])) ? $data['teamUid'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/'.$id.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
        
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(0, $response['body']);

        /**
         * Test for FAILURE
         */

         return [];
    }
}