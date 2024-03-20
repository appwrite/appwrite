<?php

namespace Tests\E2E\Services\Databases;

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

    public function testCreateCollection(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
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

        /**
         * Test when database is disabled but can still create collections
         */
        $database = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'invalidDocumentDatabase Updated',
            'enabled' => false,
        ]);

        $this->assertFalse($database['body']['enabled']);

        $tvShows = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'collectionId' => ID::unique(),
            'name' => 'TvShows',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        /**
         * Test when collection is disabled but can still modify collections
         */
        $database = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $movies['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Movies',
            'enabled' => false,
        ]);

        $this->assertEquals(201, $tvShows['headers']['status-code']);
        $this->assertEquals($tvShows['body']['name'], 'TvShows');

        return ['moviesId' => $movies['body']['$id'], 'databaseId' => $databaseId, 'tvShowsId' => $tvShows['body']['$id']];
    }

    /**
     * @depends testCreateCollection
     * @param array $data
     * @throws \Exception
     */
    public function testListCollection(array $data)
    {
        /**
         * Test when database is disabled but can still call list collections
         */
        $databaseId = $data['databaseId'];

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(200, $collections['headers']['status-code']);
        $this->assertEquals(2, $collections['body']['total']);
    }

    /**
     * @depends testCreateCollection
     * @param array $data
     * @throws \Exception
     */
    public function testGetCollection(array $data)
    {
        $databaseId = $data['databaseId'];
        $moviesCollectionId = $data['moviesId'];

        /**
         * Test when database and collection are disabled but can still call get collection
         */
        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $moviesCollectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertEquals('Movies', $collection['body']['name']);
        $this->assertEquals($moviesCollectionId, $collection['body']['$id']);
        $this->assertFalse($collection['body']['enabled']);
    }

    /**
     * @depends testCreateCollection
     * @param array $data
     * @throws \Exception
     * @throws \Exception
     */
    public function testUpdateCollection(array $data)
    {
        $databaseId = $data['databaseId'];
        $moviesCollectionId = $data['moviesId'];

        /**
         * Test When database and collection are disabled but can still call update collection
         */
        $collection = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $moviesCollectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Movies Updated',
            'enabled' => false
        ]);

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertEquals('Movies Updated', $collection['body']['name']);
        $this->assertEquals($moviesCollectionId, $collection['body']['$id']);
        $this->assertFalse($collection['body']['enabled']);
    }

    /**
     * @depends testCreateCollection
     * @param array $data
     * @throws \Exception
     * @throws \Exception
     */
    public function testDeleteCollection(array $data)
    {
        $databaseId = $data['databaseId'];
        $tvShowsId = $data['tvShowsId'];

        /**
         * Test when database and collection are disabled but can still call delete collection
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $tvShowsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals($response['body'], "");
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
        $this->assertEquals(5, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertIsNumeric($response['body']['documentsTotal']);
        $this->assertIsNumeric($response['body']['collectionsTotal']);
        $this->assertIsArray($response['body']['collections']);
        $this->assertIsArray($response['body']['documents']);
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
        $this->assertEquals(3, count($response['body']));
        $this->assertEquals('24h', $response['body']['range']);
        $this->assertIsNumeric($response['body']['documentsTotal']);
        $this->assertIsArray($response['body']['documents']);
    }

    /**
     * @depends testCreateCollection
     * @throws \Utopia\Database\Exception\Query
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
            'queries' => [Query::limit(1)->toString()]
        ]);

        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertLessThanOrEqual(1, count($logs['body']['logs']));
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::offset(1)->toString()]
        ]);

        $this->assertEquals(200, $logs['headers']['status-code']);
        $this->assertIsArray($logs['body']['logs']);
        $this->assertIsNumeric($logs['body']['total']);

        $logs = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['moviesId'] . '/logs', array_merge([
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
