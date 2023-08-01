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

    public function testCreateDatabase(): array
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
         * Test When database is disabled but can still create collections
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

        $this->assertEquals(201, $tvShows['headers']['status-code']);
        $this->assertEquals($tvShows['body']['name'], 'TvShows');

        return ['moviesId' => $movies['body']['$id'], 'databaseId' => $databaseId, 'tvShowsId' => $tvShows['body']['$id']];
    }

    /**
     * @depends testCreateCollection
     * @param array $data
     */
    public function testListCollection(array $data)
    {
        /**
         * Test When database is disabled but can still call list collections
         */
        $databaseId = $data['databaseId'];

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals(200, $collections['headers']['status-code']);
        $this->assertEquals(2, $collections['body']['total']);
    }

    /**
     * @depends testCreateCollection
     * @param array $data
     */
    public function testGetCollection(array $data)
    {
        $databaseId = $data['databaseId'];
        $moviesCollectionId = $data['moviesId'];

        /**
         * Test When database is disabled but can still call get collection
         */
        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $moviesCollectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertEquals('Movies', $collection['body']['name']);
        $this->assertEquals($moviesCollectionId, $collection['body']['$id']);
        $this->assertTrue($collection['body']['enabled']);
    }

    /**
     * @depends testCreateCollection
     * @param array $data
     */
    public function testUpdateCollection(array $data)
    {
        $databaseId = $data['databaseId'];
        $moviesCollectionId = $data['moviesId'];

        /**
         * Test When database is disabled but can still call update collection
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
     */
    public function testDeleteCollection(array $data)
    {
        $databaseId = $data['databaseId'];
        $tvShowsId = $data['tvShowsId'];

        /**
         * Test When database is disabled but can still call Delete collection
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $tvShowsId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals($response['body'], "");
    }

    /**
     * @depends testCreateDatabase
     */
    // public function testGetDatabaseUsage(array $data)
    // {
    //     $databaseId = $data['databaseId'];
    //     /**
    //      * Test for FAILURE
    //      */

    //     $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/usage', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id']
    //     ], $this->getHeaders()), [
    //         'range' => '32h'
    //     ]);

    //     $this->assertEquals(400, $response['headers']['status-code']);

    //     /**
    //      * Test for SUCCESS
    //      */

    //     $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/usage', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id']
    //     ], $this->getHeaders()), [
    //         'range' => '24h'
    //     ]);

    //     $this->assertEquals(200, $response['headers']['status-code']);
    //     $this->assertEquals(count($response['body']), 11);
    //     $this->assertEquals($response['body']['range'], '24h');
    //     $this->assertIsArray($response['body']['documentsCount']);
    //     $this->assertIsArray($response['body']['collectionsCount']);
    //     $this->assertIsArray($response['body']['documentsCreate']);
    //     $this->assertIsArray($response['body']['documentsRead']);
    //     $this->assertIsArray($response['body']['documentsUpdate']);
    //     $this->assertIsArray($response['body']['documentsDelete']);
    //     $this->assertIsArray($response['body']['collectionsCreate']);
    //     $this->assertIsArray($response['body']['collectionsRead']);
    //     $this->assertIsArray($response['body']['collectionsUpdate']);
    //     $this->assertIsArray($response['body']['collectionsDelete']);
    // }


    /**
     * @depends testCreateDatabase
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
        $this->assertEquals(count($response['body']), 6);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['documentsCount']);
        $this->assertIsArray($response['body']['documentsCreate']);
        $this->assertIsArray($response['body']['documentsRead']);
        $this->assertIsArray($response['body']['documentsUpdate']);
        $this->assertIsArray($response['body']['documentsDelete']);
    }

    /**
     * @depends testCreateDatabase
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

    /**
     * @depends testCreateDatabase
     */
    public function testTimeoutCollection(array $data): array
    {
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Slow Queries',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $data = [
            '$id' => $collection['body']['$id'],
            'databaseId' => $collection['body']['databaseId']
        ];

        $longtext = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'longtext',
            'size' => 100000000,
            'required' => false,
            'default' => null,
        ]);

        $this->assertEquals($longtext['headers']['status-code'], 202);

        return $data;
    }

    /**
     * @depends testTimeoutCollection
     */
    public function testTimeouts(array $data): void
    {
        for ($i = 0; $i <= 1; $i++) {
            $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'documentId' => ID::unique(),
                'data' => [
                    'longtext' => file_get_contents(__DIR__ . '/longtext.txt'),
                ],
                'permissions' => [
                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),
                ]
            ]);
        }

        $docs = [];
        for ($i = 0; $i <= 5; $i++) { // _APP_SLOW_QUERIES_MAX_HITS = 5
            $docs[] = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-timeout' => 1,
            ], $this->getHeaders()), [
                'queries' => ['notEqual("longtext", "appwrite")'],
            ]);
        }

        $this->assertEquals(408, $docs[0]['headers']['status-code']); // insert
        $this->assertEquals(408, $docs[1]['headers']['status-code']); // update
        $this->assertEquals(408, $docs[2]['headers']['status-code']); // update
        $this->assertEquals(408, $docs[3]['headers']['status-code']); // update
        $this->assertEquals(403, $docs[4]['headers']['status-code']); // update
        $this->assertEquals(403, $docs[5]['headers']['status-code']); // blocked
    }

    /**
     * @depends testTimeoutCollection
     */
    public function testConsoleTimeouts($data): void
    {
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/slow-queries', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                'equal("collectionId", "' . $data['$id'] . '")',
                'equal("blocked", true)',
                'orderAsc("$updatedAt")'
            ]
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(1, $documents['body']['total']);
        $this->assertEquals(true, $documents['body']['documents'][0]['blocked']);
        $this->assertEquals(2, $documents['body']['documents'][0]['count']);

        $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/slow-queries/' . $documents['body']['documents'][0]['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);
        $this->assertEquals(200, $doc['headers']['status-code']);
        $this->assertEquals($doc['body']['queries'][0], 'notEqual("longtext", "appwrite")');

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/slow-queries/' . $documents['body']['documents'][0]['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);
        $this->assertEquals(204, $response['headers']['status-code']);

        $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/slow-queries/' . $documents['body']['documents'][0]['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);
        $this->assertEquals(404, $doc['headers']['status-code']);
    }
}
