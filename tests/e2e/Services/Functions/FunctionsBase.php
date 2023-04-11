<?php

namespace Tests\E2E\Services\Functions;

use Tests\E2E\Client;
use Utopia\CLI\Console;

trait FunctionsBase
{
    protected string $stdout = '';

    protected string $stderr = '';

    protected function packageCode($folder)
    {
        Console::execute('cd '.realpath(__DIR__.'/../../../resources/functions')."/$folder  && tar --exclude code.tar.gz -czf code.tar.gz .", '', $this->stdout, $this->stderr);
    }

    // /**
    //  * @depends testCreateTeam
    //  */
    // public function testGetTeam($data):array
    // {
    //     $id = $data['teamUid'] ?? '';

    //     /**
    //      * Test for SUCCESS
    //      */
    //     $response = $this->client->call(Client::METHOD_GET, '/teams/'.$id, array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));

    //     $this->assertEquals(200, $response['headers']['status-code']);
    //     $this->assertNotEmpty($response['body']['$id']);
    //     $this->assertEquals('Arsenal', $response['body']['name']);
    //     $this->assertGreaterThan(-1, $response['body']['total']);
    //     $this->assertIsInt($response['body']['total']);
    //     $this->assertIsInt($response['body']['dateCreated']);

    //     /**
    //      * Test for FAILURE
    //      */

    //      return [];
    // }

    // /**
    //  * @depends testCreateTeam
    //  */
    // public function testListTeams($data):array
    // {
    //     /**
    //      * Test for SUCCESS
    //      */
    //     $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));

    //     $this->assertEquals(200, $response['headers']['status-code']);
    //     $this->assertGreaterThan(0, $response['body']['total']);
    //     $this->assertIsInt($response['body']['total']);
    //     $this->assertCount(3, $response['body']['teams']);

    //     $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'limit' => 2,
    //     ]);

    //     $this->assertEquals(200, $response['headers']['status-code']);
    //     $this->assertGreaterThan(0, $response['body']['total']);
    //     $this->assertIsInt($response['body']['total']);
    //     $this->assertCount(2, $response['body']['teams']);

    //     $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'offset' => 1,
    //     ]);

    //     $this->assertEquals(200, $response['headers']['status-code']);
    //     $this->assertGreaterThan(0, $response['body']['total']);
    //     $this->assertIsInt($response['body']['total']);
    //     $this->assertCount(2, $response['body']['teams']);

    //     $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'search' => 'Manchester',
    //     ]);

    //     $this->assertEquals(200, $response['headers']['status-code']);
    //     $this->assertGreaterThan(0, $response['body']['total']);
    //     $this->assertIsInt($response['body']['total']);
    //     $this->assertCount(1, $response['body']['teams']);
    //     $this->assertEquals('Manchester United', $response['body']['teams'][0]['name']);

    //     $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'search' => 'United',
    //     ]);

    //     $this->assertEquals(200, $response['headers']['status-code']);
    //     $this->assertGreaterThan(0, $response['body']['total']);
    //     $this->assertIsInt($response['body']['total']);
    //     $this->assertCount(1, $response['body']['teams']);
    //     $this->assertEquals('Manchester United', $response['body']['teams'][0]['name']);

    //     /**
    //      * Test for FAILURE
    //      */

    //      return [];
    // }

    // public function testUpdateTeam():array
    // {
    //     /**
    //      * Test for SUCCESS
    //      */
    //     $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'name' => 'Demo'
    //     ]);

    //     $this->assertEquals(201, $response['headers']['status-code']);
    //     $this->assertNotEmpty($response['body']['$id']);
    //     $this->assertEquals('Demo', $response['body']['name']);
    //     $this->assertGreaterThan(-1, $response['body']['total']);
    //     $this->assertIsInt($response['body']['total']);
    //     $this->assertIsInt($response['body']['dateCreated']);

    //     $response = $this->client->call(Client::METHOD_PUT, '/teams/'.$response['body']['$id'], array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'name' => 'Demo New'
    //     ]);

    //     $this->assertEquals(200, $response['headers']['status-code']);
    //     $this->assertNotEmpty($response['body']['$id']);
    //     $this->assertEquals('Demo New', $response['body']['name']);
    //     $this->assertGreaterThan(-1, $response['body']['total']);
    //     $this->assertIsInt($response['body']['total']);
    //     $this->assertIsInt($response['body']['dateCreated']);

    //     /**
    //      * Test for FAILURE
    //      */
    //     $response = $this->client->call(Client::METHOD_PUT, '/teams/'.$response['body']['$id'], array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //     ]);

    //     $this->assertEquals(400, $response['headers']['status-code']);

    //     return [];
    // }

    // public function testDeleteTeam():array
    // {
    //     /**
    //      * Test for SUCCESS
    //      */
    //     $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()), [
    //         'name' => 'Demo'
    //     ]);

    //     $teamUid = $response['body']['$id'];

    //     $this->assertEquals(201, $response['headers']['status-code']);
    //     $this->assertNotEmpty($response['body']['$id']);
    //     $this->assertEquals('Demo', $response['body']['name']);
    //     $this->assertGreaterThan(-1, $response['body']['total']);
    //     $this->assertIsInt($response['body']['total']);
    //     $this->assertIsInt($response['body']['dateCreated']);

    //     $response = $this->client->call(Client::METHOD_DELETE, '/teams/'.$teamUid, array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));

    //     $this->assertEquals(204, $response['headers']['status-code']);
    //     $this->assertEmpty($response['body']);

    //     /**
    //      * Test for FAILURE
    //      */
    //     $response = $this->client->call(Client::METHOD_GET, '/teams/'.$teamUid, array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //     ], $this->getHeaders()));

    //     $this->assertEquals(404, $response['headers']['status-code']);

    //     return [];
    // }
}
