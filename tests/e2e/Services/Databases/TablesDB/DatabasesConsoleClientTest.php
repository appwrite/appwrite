<?php

namespace Tests\E2E\Services\Databases\TablesDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

class DatabasesConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testCreateTable(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'invalidDocumentDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('invalidDocumentDatabase', $database['body']['name']);
        $this->assertTrue($database['body']['enabled']);

        $databaseId = $database['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $movies = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'tableId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $movies['headers']['status-code']);
        $this->assertEquals('Movies', $movies['body']['name']);

        /**
         * Test when database is disabled but can still create tables
         */
        $database = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'invalidDocumentDatabase Updated',
            'enabled' => false,
        ]);

        $this->assertFalse($database['body']['enabled']);

        $tvShows = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'tableId' => ID::unique(),
            'name' => 'TvShows',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        /**
         * Test when table is disabled but can still modify tables
         */
        $database = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $movies['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Movies',
            'enabled' => false,
        ]);

        $this->assertEquals(201, $tvShows['headers']['status-code']);
        $this->assertEquals('TvShows', $tvShows['body']['name']);

        return ['moviesId' => $movies['body']['$id'], 'databaseId' => $databaseId, 'tvShowsId' => $tvShows['body']['$id']];
    }

    /**
     * @depends testCreateTable
     * @param array $data
     * @throws \Exception
     */
    public function testListTable(array $data)
    {
        /**
         * Test when database is disabled but can still call list tables
         */
        $databaseId = $data['databaseId'];

        $tables = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(200, $tables['headers']['status-code']);
        $this->assertEquals(2, $tables['body']['total']);
    }

    /**
     * @depends testCreateTable
     * @param array $data
     * @throws \Exception
     */
    public function testGetTable(array $data)
    {
        $databaseId = $data['databaseId'];
        $moviesCollectionId = $data['moviesId'];

        /**
         * Test when database and table are disabled but can still call get table
         */
        $table = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $moviesCollectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertEquals('Movies', $table['body']['name']);
        $this->assertEquals($moviesCollectionId, $table['body']['$id']);
        $this->assertFalse($table['body']['enabled']);
    }

    /**
     * @depends testCreateTable
     * @param array $data
     * @throws \Exception
     * @throws \Exception
     */
    public function testUpdateTable(array $data)
    {
        $databaseId = $data['databaseId'];
        $moviesCollectionId = $data['moviesId'];

        /**
         * Test When database and table are disabled but can still call update table
         */
        $table = $this->client->call(Client::METHOD_PUT, '/tablesdb/' . $databaseId . '/tables/' . $moviesCollectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Movies Updated',
            'enabled' => false
        ]);

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertEquals('Movies Updated', $table['body']['name']);
        $this->assertEquals($moviesCollectionId, $table['body']['$id']);
        $this->assertFalse($table['body']['enabled']);
    }

    /**
     * @depends testCreateTable
     * @param array $data
     * @throws \Exception
     * @throws \Exception
     */
    public function testDeleteTable(array $data)
    {
        $databaseId = $data['databaseId'];
        $tvShowsId = $data['tvShowsId'];

        /**
         * Test when database and table are disabled but can still call delete table
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $tvShowsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals("", $response['body']);
    }

    /**
     * @depends testCreateTable
     */
    public function testGetDatabaseUsage(array $data)
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);



        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(15, $response['body']);
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertIsNumeric($response['body']['rowsTotal']);
        $this->assertIsNumeric($response['body']['tablesTotal']);
        $this->assertIsArray($response['body']['tables']);
        $this->assertIsArray($response['body']['rows']);
    }


    /**
     * @depends testCreateTable
     */
    public function testGetTableUsage(array $data)
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h'
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/randomCollectionId/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(3, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertIsNumeric($response['body']['rowsTotal']);
        $this->assertIsArray($response['body']['rows']);
    }

    /**
     * @depends testCreateTable
     * @throws \Utopia\Database\Exception\Query
     */
    public function testGetTableLogs(array $data)
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for SUCCESS
         */
        $logs = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::limit(1)->toString()]
        ]);

        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::offset(1)->toString()]
        ]);

        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $data['moviesId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::offset(1)->toString(), Query::limit(1)->toString()]
        ]);

        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);
    }
}
