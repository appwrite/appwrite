<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\DatetimeValidator;

trait TeamsBase
{
    public function testCreateTeam(): array
    {
        /**
         * Test for SUCCESS
         */
        $response1 = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Arsenal',
            'roles' => ['player'],
        ]);

        $this->assertEquals(201, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);
        $this->assertEquals('Arsenal', $response1['body']['name']);
        $this->assertGreaterThan(-1, $response1['body']['total']);
        $this->assertIsInt($response1['body']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response1['body']['$createdAt']));

        $teamUid = $response1['body']['$id'];
        $teamName = $response1['body']['name'];

        $teamId = ID::unique();
        $response2 = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => $teamId,
            'name' => 'Manchester United'
        ]);

        $this->assertEquals(201, $response2['headers']['status-code']);
        $this->assertNotEmpty($response2['body']['$id']);
        $this->assertEquals($teamId, $response2['body']['$id']);
        $this->assertEquals('Manchester United', $response2['body']['name']);
        $this->assertGreaterThan(-1, $response2['body']['total']);
        $this->assertIsInt($response2['body']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response2['body']['$createdAt']));

        $response3 = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Newcastle'
        ]);

        $this->assertEquals(201, $response3['headers']['status-code']);
        $this->assertNotEmpty($response3['body']['$id']);
        $this->assertEquals('Newcastle', $response3['body']['name']);
        $this->assertGreaterThan(-1, $response3['body']['total']);
        $this->assertIsInt($response3['body']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response3['body']['$createdAt']));
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return ['teamUid' => $teamUid, 'teamName' => $teamName];
    }

    /**
     * @depends testCreateTeam
     */
    public function testGetTeam($data): array
    {
        $id = $data['teamUid'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Arsenal', $response['body']['name']);
        $this->assertGreaterThan(-1, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$createdAt']));

        /**
         * Test for FAILURE
         */

        return [];
    }

    /**
     * @depends testCreateTeam
     */
    public function testListTeams($data): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertGreaterThan(2, count($response['body']['teams']));

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'limit(2)' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, count($response['body']['teams']));

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'offset(1)' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(1, count($response['body']['teams']));

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'greaterThanEqual("total", 0)' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(2, count($response['body']['teams']));

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("name", ["Arsenal", "Newcastle"])' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, count($response['body']['teams']));

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Manchester',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertCount(1, $response['body']['teams']);
        $this->assertEquals('Manchester United', $response['body']['teams'][0]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'United',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertCount(1, $response['body']['teams']);
        $this->assertEquals('Manchester United', $response['body']['teams'][0]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => $data['teamUid'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertCount(1, $response['body']['teams']);
        $this->assertEquals('Arsenal', $response['body']['teams'][0]['name']);

        $teams = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'limit(2)' ],
        ]);

        $this->assertEquals(200, $teams['headers']['status-code']);
        $this->assertGreaterThan(0, $teams['body']['total']);
        $this->assertIsInt($teams['body']['total']);
        $this->assertCount(2, $teams['body']['teams']);

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'limit(1)', 'cursorAfter("' . $teams['body']['teams'][0]['$id'] . '")' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertCount(1, $response['body']['teams']);
        $this->assertEquals($teams['body']['teams'][1]['$id'], $response['body']['teams'][0]['$id']);

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'limit(1)', 'cursorBefore("' . $teams['body']['teams'][1]['$id'] . '")' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertCount(1, $response['body']['teams']);
        $this->assertEquals($teams['body']['teams'][0]['$id'], $response['body']['teams'][0]['$id']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'cursorAfter("unknown")' ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [];
    }

    public function testUpdateTeam(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Demo'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Demo', $response['body']['name']);
        $this->assertGreaterThan(-1, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$createdAt']));

        $response = $this->client->call(Client::METHOD_PUT, '/teams/' . $response['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Demo New'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Demo New', $response['body']['name']);
        $this->assertGreaterThan(-1, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$createdAt']));

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/teams/' . $response['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [];
    }

    public function testDeleteTeam(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Demo'
        ]);

        $teamUid = $response['body']['$id'];

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Demo', $response['body']['name']);
        $this->assertGreaterThan(-1, $response['body']['total']);
        $this->assertIsInt($response['body']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$createdAt']));

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/' . $teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        return [];
    }
}
