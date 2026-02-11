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

    public function testJoins(): void
    {
        // todo: Move to Base class

        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => 'joins',
            'name' => 'Joins'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('Joins', $database['body']['name']);
        $this->assertEquals('tablesdb', $database['body']['type']);

        $databaseId = $database['body']['$id'];

        $users = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'users',
            'name' => 'users',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $users['headers']['status-code']);
        $this->assertEquals($users['body']['name'], 'users');

        $sessions = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'sessions',
            'name' => 'sessions',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $sessions['headers']['status-code']);
        $this->assertEquals($sessions['body']['name'], 'sessions');

        /**
         * Create columns users
         */
        $username = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $users['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'username',
            'size' => 256,
            'required' => false,
        ]);
        $this->assertEquals('username', $username['body']['key']);

        $age = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $users['body']['$id'] . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'age',
            'required' => false,
            'min' => 0,
            'max' => 100,
        ]);
        $this->assertEquals('age', $age['body']['key']);

        /**
         * Create columns sessions
         */
        $userId = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $sessions['body']['$id'] . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'userId',
            'required' => false,
        ]);
        $this->assertEquals('userId', $userId['body']['key']);

        sleep(2);

        $abraham = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $users['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'username' => 'Abraham',
                'age' => 50,
            ],
            'permissions' => [
                //Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $abraham['headers']['status-code']);
        $this->assertEquals('Abraham', $abraham['body']['username']);
        $this->assertEquals(50, $abraham['body']['age']);
        $this->assertEquals(1, $abraham['body']['$sequence']);

        $bill = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $users['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'username' => 'Bill',
                'age' => 40,
            ],
            'permissions' => [
                //Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $bill['headers']['status-code']);
        $this->assertEquals('Bill', $bill['body']['username']);
        $this->assertEquals(40, $bill['body']['age']);
        $this->assertEquals(2, $bill['body']['$sequence']);

        $session1 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $sessions['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'userId' => $abraham['body']['$sequence'],
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals($abraham['body']['$sequence'], $session1['body']['userId']);
        $this->assertEquals(201, $session1['headers']['status-code']);

        $session2 = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $sessions['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'userId' => $bill['body']['$sequence'],
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
            ]
        ]);
        $this->assertEquals(201, $session2['headers']['status-code']);
        $this->assertEquals($bill['body']['$sequence'], $session2['body']['userId']);

        /**
         * Simple join query
         */
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $users['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($sessions['body']['$id'], 'B', [Query::relationEqual('', '$sequence','B', 'userId')])->toString(),
                Query::orderDesc('username', '')->toString(),
            ],
        ]);

        $this->assertEquals(2, $rows['body']['total']);
        $this->assertEquals('Bill', $rows['body']['rows'][0]['username']);
        $this->assertEquals(40, $rows['body']['rows'][0]['age']);
        $this->assertEquals('Abraham', $rows['body']['rows'][1]['username']);
        $this->assertArrayHasKey('$id', $rows['body']['rows'][0]);
        $this->assertArrayHasKey('$sequence', $rows['body']['rows'][0]);
        $this->assertArrayHasKey('$createdAt', $rows['body']['rows'][0]);
        $this->assertArrayHasKey('$updatedAt', $rows['body']['rows'][0]);
        $this->assertArrayHasKey('$permissions', $rows['body']['rows'][0]);
        $this->assertArrayNotHasKey('userId', $rows['body']['rows'][0]);

        /**
         * Simple join query with select queries
         */
        $rows = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $users['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::select('username', '')->toString(),
                Query::select('userId', 'B')->toString(),
                Query::join($sessions['body']['$id'], 'B', [Query::relationEqual('', '$sequence','B', 'userId')])->toString(),
                Query::orderDesc('username', '')->toString(),
            ],
        ]);

        var_dump($rows);
        $this->assertEquals(2, $rows['body']['total']);
        $this->assertEquals('Bill', $rows['body']['rows'][0]['username']);
        $this->assertEquals('Abraham', $rows['body']['rows'][1]['username']);
        $this->assertEquals($bill['body']['$sequence'], $rows['body']['rows'][0]['userId']);
        $this->assertEquals($abraham['body']['$sequence'], $rows['body']['rows'][1]['userId']);
        $this->assertArrayNotHasKey('age', $rows['body']['rows'][0]);
        $this->assertArrayNotHasKey('$id', $rows['body']['rows'][0]);
        $this->assertArrayNotHasKey('$sequence', $rows['body']['rows'][0]);
        $this->assertArrayNotHasKey('$createdAt', $rows['body']['rows'][0]);
        $this->assertArrayNotHasKey('$updatedAt', $rows['body']['rows'][0]);
        $this->assertArrayNotHasKey('$permissions', $rows['body']['rows'][0]);


        $this->assertEquals('shmuel', 'fogel');
    }


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
