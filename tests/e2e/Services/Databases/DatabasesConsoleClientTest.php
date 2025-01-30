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

        $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Database Updated',
            'enabled' => true,
        ]);

        return [
            'moviesId' => $movies['body']['$id'],
            'databaseId' => $databaseId,
            'tvShowsId' => $tvShows['body']['$id']
        ];
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
        $this->assertEquals(11, count($response['body']));
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

    public function testTimeouts(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'slowQueriesDatabase',
        ]);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
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

        $collectionId = $collection['body']['$id'];

        $this->assertEquals(201, $collection['headers']['status-code']);

        $longtext = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
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

        for ($i = 0; $i <= 1; $i++) {
            $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'documentId' => ID::unique(),
                'data' => [
                    'longtext' => file_get_contents(__DIR__ . '/../../../resources/longtext.txt'),
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
            $docs[] = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-timeout' => 1,
            ], $this->getHeaders()), [
                'queries' => [
                    Query::notEqual('longtext', 'appwrite')->toString(),
                ],
            ]);
        }

        $this->assertEquals(408, $docs[0]['headers']['status-code']); // insert
        $this->assertEquals(408, $docs[1]['headers']['status-code']); // update
        $this->assertEquals(408, $docs[2]['headers']['status-code']); // update
        $this->assertEquals(408, $docs[3]['headers']['status-code']); // update
        $this->assertEquals(403, $docs[4]['headers']['status-code']); // update
        $this->assertEquals(403, $docs[5]['headers']['status-code']); // blocked

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId
        ];
    }

    /**
     * @depends testTimeouts
     */
    public function testConsoleTimeouts($data): void
    {
        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/slow-queries', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('collectionId', $data['$id'])->toString(),
                Query::equal('blocked', [true])->toString(),
                Query::orderAsc('$updatedAt')->toString(),
            ]
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(1, $documents['body']['total']);
        $this->assertEquals(true, $documents['body']['documents'][0]['blocked']);
        $this->assertEquals(5, $documents['body']['documents'][0]['count']);

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
