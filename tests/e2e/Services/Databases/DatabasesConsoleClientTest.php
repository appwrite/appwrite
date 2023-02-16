<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Client;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class DatabasesConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testCreateCollection(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'invalidDocumentDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('invalidDocumentDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        /**
         * Test for SUCCESS
         */
        $movies = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $movies['headers']['status-code']);
        $this->assertEquals($movies['body']['name'], 'Movies');

        return ['moviesId' => $movies['body']['$id'], 'databaseId' => $databaseId];
    }

    /**
     * @depends testCreateCollection
     */
    public function testGetDatabaseUsage(array $data)
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(count($response['body']), 3);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['documentsTotal']);
        $this->assertIsArray($response['body']['collectionsTotal']);
    }


    /**
     * @depends testCreateCollection
     */
    public function testGetCollectionUsage(array $data)
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/randomCollectionId/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(count($response['body']), 2);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['documentsTotal']);
    }

    /**
     * @depends testCreateCollection
     */
    public function testGetCollectionLogs(array $data)
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for SUCCESS
         */
        $logs = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 1
        ]);

        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'offset' => 1
        ]);

        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'offset' => 1,
            'limit' => 1
        ]);

        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);
    }
}
