<?php

namespace Tests\E2E\Services\Databases\Tables;

use Appwrite\Extend\Exception as AppwriteException;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

class DatabasesCustomServerTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;

    public function testListDatabases()
    {
        $test1 = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::custom('first'),
            'name' => 'Test 1',
        ]);

        $this->assertEquals(201, $test1['headers']['status-code']);
        $this->assertEquals('Test 1', $test1['body']['name']);

        $test2 = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::custom('second'),
            'name' => 'Test 2',
        ]);
        $this->assertEquals(201, $test2['headers']['status-code']);
        $this->assertEquals('Test 2', $test2['body']['name']);

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(2, $databases['body']['total']);
        $this->assertEquals($test1['body']['$id'], $databases['body']['databases'][0]['$id']);
        $this->assertEquals($test2['body']['$id'], $databases['body']['databases'][1]['$id']);

        $base = array_reverse($databases['body']['databases']);

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);
        $this->assertEquals(200, $databases['headers']['status-code']);
        $this->assertCount(1, $databases['body']['databases']);

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);
        $this->assertEquals(200, $databases['headers']['status-code']);
        $this->assertCount(1, $databases['body']['databases']);

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('name', ['Test 1', 'Test 2'])->toString(),
            ],
        ]);
        $this->assertEquals(200, $databases['headers']['status-code']);
        $this->assertCount(2, $databases['body']['databases']);

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('name', ['Test 2'])->toString(),
            ],
        ]);
        $this->assertEquals(200, $databases['headers']['status-code']);
        $this->assertCount(1, $databases['body']['databases']);

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('$id', ['first'])->toString(),
            ],
        ]);
        $this->assertEquals(200, $databases['headers']['status-code']);
        $this->assertCount(1, $databases['body']['databases']);

        /**
         * Test for Order
         */
        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderDesc()->toString(),
            ],
        ]);

        $this->assertEquals(2, $databases['body']['total']);
        $this->assertEquals($base[0]['$id'], $databases['body']['databases'][0]['$id']);
        $this->assertEquals($base[1]['$id'], $databases['body']['databases'][1]['$id']);

        /**
         * Test for After
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['databases'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(1, $databases['body']['databases']);
        $this->assertEquals($base['body']['databases'][1]['$id'], $databases['body']['databases'][0]['$id']);

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['databases'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(0, $databases['body']['databases']);
        $this->assertEmpty($databases['body']['databases']);

        /**
         * Test for Before
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['databases'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(1, $databases['body']['databases']);
        $this->assertEquals($base['body']['databases'][0]['$id'], $databases['body']['databases'][0]['$id']);

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['databases'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(0, $databases['body']['databases']);
        $this->assertEmpty($databases['body']['databases']);

        /**
         * Test for Search
         */
        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'first'
        ]);

        $this->assertEquals(1, $databases['body']['total']);
        $this->assertEquals('first', $databases['body']['databases'][0]['$id']);

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Test'
        ]);

        $this->assertEquals(2, $databases['body']['total']);
        $this->assertEquals('Test 1', $databases['body']['databases'][0]['name']);
        $this->assertEquals('Test 2', $databases['body']['databases'][1]['name']);

        $databases = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Nonexistent'
        ]);

        $this->assertEquals(0, $databases['body']['total']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // This database already exists
        $response = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 1',
            'databaseId' => ID::custom('first'),
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);
        return ['databaseId' => $test1['body']['$id']];
    }

    /**
     * @depends testListDatabases
     */
    public function testGetDatabase(array $data): array
    {
        $databaseId = $data['databaseId'];
        /**
         * Test for SUCCESS
         */
        $database = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $database['headers']['status-code']);
        $this->assertEquals($databaseId, $database['body']['$id']);
        $this->assertEquals('Test 1', $database['body']['name']);
        $this->assertEquals(true, $database['body']['enabled']);
        return ['databaseId' => $database['body']['$id']];
    }

    /**
     * @depends testListDatabases
     */
    public function testUpdateDatabase(array $data)
    {
        $databaseId = $data['databaseId'];

        $database = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Test 1 Updated',
            'enabled' => false,
        ]);

        $this->assertEquals(200, $database['headers']['status-code']);
        $this->assertEquals('Test 1 Updated', $database['body']['name']);
        $this->assertFalse($database['body']['enabled']);

        // Now update the database without the passing the enabled parameter
        $database = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'name' => 'Test 1'
        ]);

        $this->assertEquals(200, $database['headers']['status-code']);
        $this->assertEquals('Test 1', $database['body']['name']);
        $this->assertTrue($database['body']['enabled']);
    }

    /**
     * @depends testListDatabases
     */
    public function testDeleteDatabase($data)
    {
        $databaseId = $data['databaseId'];

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals("", $response['body']);

        // Try to get the database and check if it has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testListTables(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'invalidRowDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('invalidRowDatabase', $database['body']['name']);
        $this->assertTrue($database['body']['enabled']);

        $databaseId = $database['body']['$id'];
        /**
         * Test for SUCCESS
         */
        $test1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 1',
            'tableId' => ID::custom('first'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $test2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 2',
            'tableId' => ID::custom('second'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(2, $tables['body']['total']);
        $this->assertEquals($test1['body']['$id'], $tables['body']['tables'][0]['$id']);
        $this->assertEquals($test1['body']['enabled'], $tables['body']['tables'][0]['enabled']);
        $this->assertEquals($test2['body']['$id'], $tables['body']['tables'][1]['$id']);
        $this->assertEquals($test1['body']['enabled'], $tables['body']['tables'][0]['enabled']);

        $base = array_reverse($tables['body']['tables']);

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $tables['headers']['status-code']);
        $this->assertCount(1, $tables['body']['tables']);

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $tables['headers']['status-code']);
        $this->assertCount(1, $tables['body']['tables']);

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('enabled', [true])->toString(),
            ],
        ]);

        $this->assertEquals(200, $tables['headers']['status-code']);
        $this->assertCount(2, $tables['body']['tables']);

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('enabled', [false])->toString(),
            ],
        ]);

        $this->assertEquals(200, $tables['headers']['status-code']);
        $this->assertCount(0, $tables['body']['tables']);

        /**
         * Test for Order
         */
        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderDesc()->toString(),
            ],
        ]);

        $this->assertEquals(2, $tables['body']['total']);
        $this->assertEquals($base[0]['$id'], $tables['body']['tables'][0]['$id']);
        $this->assertEquals($base[1]['$id'], $tables['body']['tables'][1]['$id']);

        /**
         * Test for After
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['tables'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(1, $tables['body']['tables']);
        $this->assertEquals($base['body']['tables'][1]['$id'], $tables['body']['tables'][0]['$id']);

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['tables'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(0, $tables['body']['tables']);
        $this->assertEmpty($tables['body']['tables']);

        /**
         * Test for Before
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['tables'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(1, $tables['body']['tables']);
        $this->assertEquals($base['body']['tables'][0]['$id'], $tables['body']['tables'][0]['$id']);

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['tables'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(0, $tables['body']['tables']);
        $this->assertEmpty($tables['body']['tables']);

        /**
         * Test for Search
         */
        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'first'
        ]);

        $this->assertEquals(1, $tables['body']['total']);
        $this->assertEquals('first', $tables['body']['tables'][0]['$id']);

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Test'
        ]);

        $this->assertEquals(2, $tables['body']['total']);
        $this->assertEquals('Test 1', $tables['body']['tables'][0]['name']);
        $this->assertEquals('Test 2', $tables['body']['tables'][1]['name']);

        $tables = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Nonexistent'
        ]);

        $this->assertEquals(0, $tables['body']['total']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // This table already exists
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 1',
            'tableId' => ID::custom('first'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);
        return [
            'databaseId' => $databaseId,
            'tableId' => $test1['body']['$id'],
        ];
    }

    /**
     * @depends testListTables
     */
    public function testGetTable(array $data): void
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $table = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertEquals('Test 1', $table['body']['name']);
        $this->assertEquals('first', $table['body']['$id']);
        $this->assertTrue($table['body']['enabled']);
    }

    /**
     * @depends testListTables
     */
    public function testUpdateTable(array $data)
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $table = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 1 Updated',
            'enabled' => false
        ]);

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertEquals('Test 1 Updated', $table['body']['name']);
        $this->assertEquals('first', $table['body']['$id']);
        $this->assertFalse($table['body']['enabled']);
    }

    /**
     * @depends testListTables
     */
    public function testCreateEncryptedColumn(array $data): void
    {

        $databaseId = $data['databaseId'];

        /**
         * Test for SUCCESS
         */

        // Create table
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Encrypted Actors Data',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $actors['headers']['status-code']);
        $this->assertEquals('Encrypted Actors Data', $actors['body']['name']);

        /**
         * Test for creating encrypted columns
         */

        $columnsPath = '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'] . '/columns';

        $firstName = $this->client->call(Client::METHOD_POST, $columnsPath . '/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'firstName',
            'size' => 256,
            'required' => true,
        ]);

        $lastName = $this->client->call(Client::METHOD_POST, $columnsPath . '/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lastName',
            'size' => 256,
            'required' => true,
            'encrypt' => true,
        ]);


        /**
         * Check status of every column
         */
        $this->assertEquals(202, $firstName['headers']['status-code']);
        $this->assertEquals('firstName', $firstName['body']['key']);
        $this->assertEquals('string', $firstName['body']['type']);

        $this->assertEquals(202, $lastName['headers']['status-code']);
        $this->assertEquals('lastName', $lastName['body']['key']);
        $this->assertEquals('string', $lastName['body']['type']);

        // Wait for database worker to finish creating columns
        sleep(2);

        // Creating row to ensure cache is purged on schema change
        $row = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => ID::unique(),
            'data' => [
                'firstName' => 'Jonah',
                'lastName' => 'Jameson',
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        // Check row to ensure cache is purged on schema change
        $row = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'] . '/rows/' . $row['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals('Jonah', $row['body']['firstName']);
        $this->assertEquals('Jameson', $row['body']['lastName']);
    }

    public function testDeleteColumn(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'invalidRowDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('invalidRowDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        /**
         * Test for SUCCESS
         */

        // Create table
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $actors['headers']['status-code']);
        $this->assertEquals($actors['body']['name'], 'Actors');

        $firstName = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'firstName',
            'size' => 256,
            'required' => true,
        ]);

        $lastName = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lastName',
            'size' => 256,
            'required' => true,
        ]);

        $unneeded = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'unneeded',
            'size' => 256,
            'required' => true,
        ]);

        // Wait for database worker to finish creating columns
        sleep(2);

        // Creating row to ensure cache is purged on schema change
        $row = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => ID::unique(),
            'data' => [
                'firstName' => 'lorem',
                'lastName' => 'ipsum',
                'unneeded' => 'dolor'
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $index = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'key_lastName',
            'type' => 'key',
            'columns' => [
                'lastName',
            ],
        ]);

        // Wait for database worker to finish creating index
        sleep(2);

        $table = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $unneededId = $unneeded['body']['key'];

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertIsArray($table['body']['columns']);
        $this->assertCount(3, $table['body']['columns']);
        $this->assertEquals($table['body']['columns'][0]['key'], $firstName['body']['key']);
        $this->assertEquals($table['body']['columns'][1]['key'], $lastName['body']['key']);
        $this->assertEquals($table['body']['columns'][2]['key'], $unneeded['body']['key']);
        $this->assertCount(1, $table['body']['indexes']);
        $this->assertEquals($table['body']['indexes'][0]['key'], $index['body']['key']);

        // Delete column
        $column = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'] . '/columns/' . $unneededId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $column['headers']['status-code']);

        sleep(2);

        // Check row to ensure cache is purged on schema change
        $row = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'] . '/rows/' . $row['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertNotContains($unneededId, $row['body']);

        $table = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertIsArray($table['body']['columns']);
        $this->assertCount(2, $table['body']['columns']);
        $this->assertEquals($table['body']['columns'][0]['key'], $firstName['body']['key']);
        $this->assertEquals($table['body']['columns'][1]['key'], $lastName['body']['key']);

        return [
            'tableId' => $actors['body']['$id'],
            'key' => $index['body']['key'],
            'databaseId' => $databaseId
        ];
    }

    /**
     * @depends testDeleteColumn
     */
    public function testDeleteIndex($data): array
    {
        $databaseId = $data['databaseId'];
        $index = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/tables/' . $data['tableId'] . '/indexes/' . $data['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $index['headers']['status-code']);

        // Wait for database worker to finish deleting index
        sleep(2);

        $table = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $data['tableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertCount(0, $table['body']['indexes']);

        return $data;
    }

    /**
     * @depends testDeleteIndex
     */
    public function testDeleteIndexOnDeleteColumn($data)
    {
        $databaseId = $data['databaseId'];
        $column1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['tableId'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'column1',
            'size' => 16,
            'required' => true,
        ]);

        $column2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['tableId'] . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'column2',
            'size' => 16,
            'required' => true,
        ]);

        $this->assertEquals(202, $column1['headers']['status-code']);
        $this->assertEquals(202, $column2['headers']['status-code']);
        $this->assertEquals('column1', $column1['body']['key']);
        $this->assertEquals('column2', $column2['body']['key']);

        sleep(2);

        $index1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['tableId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'index1',
            'type' => 'key',
            'columns' => ['column1', 'column2'],
            'orders' => ['ASC', 'ASC'],
        ]);

        $index2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['tableId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'index2',
            'type' => 'key',
            'columns' => ['column2'],
        ]);

        $this->assertEquals(202, $index1['headers']['status-code']);
        $this->assertEquals(202, $index2['headers']['status-code']);
        $this->assertEquals('index1', $index1['body']['key']);
        $this->assertEquals('index2', $index2['body']['key']);

        sleep(2);

        // Expected behavior: deleting column2 will cause index2 to be dropped, and index1 rebuilt with a single key
        $deleted = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/tables/' . $data['tableId'] . '/columns/' . $column2['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleted['headers']['status-code']);

        // wait for database worker to complete
        sleep(2);

        $table = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $data['tableId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertIsArray($table['body']['indexes']);
        $this->assertCount(1, $table['body']['indexes']);
        $this->assertEquals($index1['body']['key'], $table['body']['indexes'][0]['key']);
        $this->assertIsArray($table['body']['indexes'][0]['columns']);
        $this->assertCount(1, $table['body']['indexes'][0]['columns']);
        $this->assertEquals($column1['body']['key'], $table['body']['indexes'][0]['columns'][0]);

        // Delete column
        $deleted = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/tables/' . $data['tableId'] . '/columns/' . $column1['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleted['headers']['status-code']);

        return $data;
    }

    public function testCleanupDuplicateIndexOnDeleteColumn()
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'invalidRowDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('invalidRowDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $table = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'TestCleanupDuplicateIndexOnDeleteColumn',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $this->assertNotEmpty($table['body']['$id']);

        $tableId = $table['body']['$id'];

        $column1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'column1',
            'size' => 16,
            'required' => true,
        ]);

        $column2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'column2',
            'size' => 16,
            'required' => true,
        ]);

        $this->assertEquals(202, $column1['headers']['status-code']);
        $this->assertEquals(202, $column2['headers']['status-code']);
        $this->assertEquals('column1', $column1['body']['key']);
        $this->assertEquals('column2', $column2['body']['key']);

        sleep(2);

        $index1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'index1',
            'type' => 'key',
            'columns' => ['column1', 'column2'],
            'orders' => ['ASC', 'ASC'],
        ]);

        $index2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'index2',
            'type' => 'key',
            'columns' => ['column2'],
        ]);

        $this->assertEquals(202, $index1['headers']['status-code']);
        $this->assertEquals(202, $index2['headers']['status-code']);
        $this->assertEquals('index1', $index1['body']['key']);
        $this->assertEquals('index2', $index2['body']['key']);

        sleep(2);

        // Expected behavior: deleting column1 would cause index1 to be a duplicate of index2 and automatically removed
        $deleted = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $column1['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleted['headers']['status-code']);

        // wait for database worker to complete
        sleep(2);

        $table = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertIsArray($table['body']['indexes']);
        $this->assertCount(1, $table['body']['indexes']);
        $this->assertEquals($index2['body']['key'], $table['body']['indexes'][0]['key']);
        $this->assertIsArray($table['body']['indexes'][0]['columns']);
        $this->assertCount(1, $table['body']['indexes'][0]['columns']);
        $this->assertEquals($column2['body']['key'], $table['body']['indexes'][0]['columns'][0]);

        // Delete column
        $deleted = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $column2['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleted['headers']['status-code']);
    }

    /**
     * @depends testDeleteIndexOnDeleteColumn
     */
    public function testDeleteTable($data)
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Add Rows to the table
        $row1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'firstName' => 'Tom',
                'lastName' => 'Holland',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $row2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'firstName' => 'Samuel',
                'lastName' => 'Jackson',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ],
        ]);

        $this->assertEquals(201, $row1['headers']['status-code']);
        $this->assertIsArray($row1['body']['$permissions']);
        $this->assertCount(3, $row1['body']['$permissions']);
        $this->assertEquals($row1['body']['firstName'], 'Tom');
        $this->assertEquals($row1['body']['lastName'], 'Holland');

        $this->assertEquals(201, $row2['headers']['status-code']);
        $this->assertIsArray($row2['body']['$permissions']);
        $this->assertCount(3, $row2['body']['$permissions']);
        $this->assertEquals('Samuel', $row2['body']['firstName']);
        $this->assertEquals('Jackson', $row2['body']['lastName']);

        // Delete the actors table
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals($response['body'], "");

        // Try to get the table and check if it has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testDeleteTableDeletesRelatedColumns(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'TestDeleteTableDeletesRelatedColumns',
        ]);

        $databaseId = $database['body']['$id'];

        $table1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Table1',
            'rowSecurity' => false,
            'permissions' => [],
        ]);

        $table2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Table2',
            'rowSecurity' => false,
            'permissions' => [],
        ]);

        $table1 = $table1['body']['$id'];
        $table2 = $table2['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $table1 . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'relatedTableId' => $table2,
            'type' => Database::RELATION_MANY_TO_ONE,
            'twoWay' => false,
            'key' => 'table2'
        ]);

        sleep(2);

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/tables/' . $table2, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()));

        sleep(2);

        $columns = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1 . '/columns', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()));

        $this->assertEquals(0, $columns['body']['total']);
    }

    public function testColumnRowWidthLimit()
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'invalidRowDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('invalidRowDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $table = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('columnRowWidthLimit'),
            'name' => 'columnRowWidthLimit',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $this->assertEquals('columnRowWidthLimit', $table['body']['name']);

        $tableId = $table['body']['$id'];

        // Add wide string columns to approach row width limit
        for ($i = 0; $i < 15; $i++) {
            $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => "column{$i}",
                'size' => 1024,
                'required' => true,
            ]);

            $this->assertEquals(202, $column['headers']['status-code']);
        }

        sleep(5);

        $tooWide = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'tooWide',
            'size' => 1024,
            'required' => true,
        ]);

        $this->assertEquals(400, $tooWide['headers']['status-code']);
        $this->assertEquals('column_limit_exceeded', $tooWide['body']['type']);
    }

    public function testIndexLimitException()
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'invalidRowDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('invalidRowDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $table = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('testLimitException'),
            'name' => 'testLimitException',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);
        $this->assertEquals('testLimitException', $table['body']['name']);

        $tableId = $table['body']['$id'];

        // add unique columns for indexing
        for ($i = 0; $i < 64; $i++) {
            // $this->assertEquals(true, static::getDatabase()->createColumn('indexLimit', "test{$i}", Database::VAR_STRING, 16, true));
            $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => "column{$i}",
                'size' => 64,
                'required' => true,
            ]);

            $this->assertEquals(202, $column['headers']['status-code']);
        }

        sleep(10);

        $table = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertEquals('testLimitException', $table['body']['name']);
        $this->assertIsArray($table['body']['columns']);
        $this->assertIsArray($table['body']['indexes']);
        $this->assertCount(64, $table['body']['columns']);
        $this->assertCount(0, $table['body']['indexes']);

        foreach ($table['body']['columns'] as $column) {
            $this->assertEquals('available', $column['status'], 'column: ' . $column['key']);
        }

        // Test indexLimit = 64
        // MariaDB, MySQL, and MongoDB create 6 indexes per new table
        // Add up to the limit, then check if the next index throws IndexLimitException
        for ($i = 0; $i < 58; $i++) {
            $index = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/indexes', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => "key_column{$i}",
                'type' => 'key',
                'columns' => ["column{$i}"],
            ]);

            $this->assertEquals(202, $index['headers']['status-code']);
            $this->assertEquals("key_column{$i}", $index['body']['key']);
        }

        sleep(5);

        $table = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $table['headers']['status-code']);
        $this->assertEquals($table['body']['name'], 'testLimitException');
        $this->assertIsArray($table['body']['columns']);
        $this->assertIsArray($table['body']['indexes']);
        $this->assertCount(64, $table['body']['columns']);
        $this->assertCount(58, $table['body']['indexes']);

        $tooMany = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'tooMany',
            'type' => 'key',
            'columns' => ['column61'],
        ]);

        $this->assertEquals(400, $tooMany['headers']['status-code']);
        $this->assertEquals('Index limit exceeded', $tooMany['body']['message']);

        $table = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $table['headers']['status-code']);
    }

    public function testColumnUpdate(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'updateColumns',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);

        $databaseId = $database['body']['$id'];
        $table = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::custom('updateColumns'),
            'name' => 'updateColumns'
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);

        $tableId = $table['body']['$id'];

        /**
         * Create String Column
         */
        $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'string',
            'size' => 1024,
            'required' => false
        ]);

        $this->assertEquals(202, $column['headers']['status-code']);

        /**
         * Create Email Column
         */
        $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'required' => false
        ]);

        $this->assertEquals(202, $column['headers']['status-code']);

        /**
         * Create IP Column
         */
        $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/ip', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'ip',
            'required' => false
        ]);

        $this->assertEquals(202, $column['headers']['status-code']);

        /**
         * Create URL Column
         */
        $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/url', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'url',
            'required' => false
        ]);

        $this->assertEquals(202, $column['headers']['status-code']);

        /**
         * Create Integer Column
         */
        $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'integer',
            'required' => false
        ]);

        $this->assertEquals(202, $column['headers']['status-code']);

        /**
         * Create Float Column
         */
        $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'float',
            'required' => false
        ]);

        /**
         * Create Boolean Column
         */
        $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'boolean',
            'required' => false
        ]);

        /**
         * Create Datetime Column
         */
        $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/datetime', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'datetime',
            'required' => false
        ]);

        /**
         * Create Enum Column
         */
        $column = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'enum',
            'required' => false,
            'elements' => ['lorem', 'ipsum']
        ]);

        $this->assertEquals(202, $column['headers']['status-code']);

        sleep(5);

        return [
            'databaseId' => $databaseId,
            'tableId' => $tableId
        ];
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateString(array $data)
    {
        $key = 'string';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'lorem'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('lorem', $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $column = array_values(array_filter($new['body']['columns'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($column);
        $this->assertFalse($column['required']);
        $this->assertEquals('lorem', $column['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'ipsum'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('ipsum', $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'i am no boolean',
            'default' => 'dolor'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => 'ipsum'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => 'ipsum'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateEmail(array $data)
    {
        $key = 'email';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'torsten@appwrite.io'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('torsten@appwrite.io', $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $column = array_values(array_filter($new['body']['columns'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($column);
        $this->assertFalse($column['required']);
        $this->assertEquals('torsten@appwrite.io', $column['default']);


        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'eldad@appwrite.io'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('eldad@appwrite.io', $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => 'torsten@appwrite.io'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no email'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => 'ipsum'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => 'torsten@appwrite.io'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateIp(array $data)
    {
        $key = 'ip';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => '127.0.0.1'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('127.0.0.1', $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $column = array_values(array_filter($new['body']['columns'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($column);
        $this->assertFalse($column['required']);
        $this->assertEquals('127.0.0.1', $column['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => '192.168.0.1'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('192.168.0.1', $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => '127.0.0.1'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no ip'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => '127.0.0.1'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => '127.0.0.1'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateUrl(array $data)
    {
        $key = 'url';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'http://appwrite.io'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('http://appwrite.io', $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $column = array_values(array_filter($new['body']['columns'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($column);
        $this->assertFalse($column['required']);
        $this->assertEquals('http://appwrite.io', $column['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'https://appwrite.io'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('https://appwrite.io', $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => 'https://appwrite.io'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no url'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => 'https://appwrite.io'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => 'https://appwrite.io'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateInteger(array $data)
    {
        $key = 'integer';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123,
            'min' => 0,
            'max' => 1000
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(123, $new['body']['default']);
        $this->assertEquals(0, $new['body']['min']);
        $this->assertEquals(1000, $new['body']['max']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $column = array_values(array_filter($new['body']['columns'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($column);
        $this->assertFalse($column['required']);
        $this->assertEquals(123, $column['default']);
        $this->assertEquals(0, $column['min']);
        $this->assertEquals(1000, $column['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null,
            'min' => 0,
            'max' => 1000
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);
        $this->assertEquals(0, $new['body']['min']);
        $this->assertEquals(1000, $new['body']['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 456,
            'min' => 100,
            'max' => 2000
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(456, $new['body']['default']);
        $this->assertEquals(100, $new['body']['min']);
        $this->assertEquals(2000, $new['body']['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 100,
            'min' => 0,
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 10,
            'max' => 100,
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => 123,
            'min' => 0,
            'max' => 500
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no integer',
            'min' => 0,
            'max' => 500
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 100,
            'min' => 'i am no integer',
            'max' => 500
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 100,
            'min' => 0,
            'max' => 'i am no integer'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'min' => 0,
            'max' => 100,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => 50,
            'min' => 0,
            'max' => 100,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => 50,
            'min' => 0,
            'max' => 100
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_DEFAULT_UNSUPPORTED, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 50,
            'min' => 55,
            'max' => 100
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_VALUE_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 105,
            'min' => 50,
            'max' => 100
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_VALUE_INVALID, $update['body']['type']);


        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 50,
            'min' => 200,
            'max' => 100
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_VALUE_INVALID, $update['body']['type']);
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateFloat(array $data)
    {
        $key = 'float';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123.456,
            'min' => 0.0,
            'max' => 1000.0
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(123.456, $new['body']['default']);
        $this->assertEquals(0, $new['body']['min']);
        $this->assertEquals(1000, $new['body']['max']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $column = array_values(array_filter($new['body']['columns'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($column);
        $this->assertFalse($column['required']);
        $this->assertEquals(123.456, $column['default']);
        $this->assertEquals(0, $column['min']);
        $this->assertEquals(1000, $column['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null,
            'min' => 0.0,
            'max' => 1000.0
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);
        $this->assertEquals(0, $new['body']['min']);
        $this->assertEquals(1000, $new['body']['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 456.789,
            'min' => 123.456,
            'max' => 2000.0
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(456.789, $new['body']['default']);
        $this->assertEquals(123.456, $new['body']['min']);
        $this->assertEquals(2000, $new['body']['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123.456,
            'min' => 0.0,
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 23.456,
            'max' => 100.0,
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => 123.456,
            'min' => 0.0,
            'max' => 1000.0
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no integer',
            'min' => 0.0,
            'max' => 500.0
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123.456,
            'min' => 'i am no integer',
            'max' => 500.0
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123.456,
            'min' => 0.0,
            'max' => 'i am no integer'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'min' => 0.0,
            'max' => 100.0,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => 123.456,
            'min' => 0.0,
            'max' => 100.0,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => 123.456,
            'min' => 0.0,
            'max' => 100.0
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_DEFAULT_UNSUPPORTED, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123.456,
            'min' => 200.0,
            'max' => 300.0
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_VALUE_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123.456,
            'min' => 0.0,
            'max' => 100.0
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_VALUE_INVALID, $update['body']['type']);


        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 50.0,
            'min' => 200.0,
            'max' => 100.0
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_VALUE_INVALID, $update['body']['type']);
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateBoolean(array $data)
    {
        $key = 'boolean';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => true
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(true, $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $column = array_values(array_filter($new['body']['columns'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($column);
        $this->assertFalse($column['required']);
        $this->assertEquals(true, $column['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => false
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(false, $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => true
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no boolean'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => false
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => true
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateDatetime(array $data)
    {
        $key = 'datetime';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => '1975-06-12 14:12:55+02:00'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('1975-06-12 14:12:55+02:00', $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $column = array_values(array_filter($new['body']['columns'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($column);
        $this->assertFalse($column['required']);
        $this->assertEquals('1975-06-12 14:12:55+02:00', $column['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => '1965-06-12 14:12:55+02:00'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('1965-06-12 14:12:55+02:00', $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => '1975-06-12 14:12:55+02:00'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no datetime'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => '1975-06-12 14:12:55+02:00'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => '1975-06-12 14:12:55+02:00'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateEnum(array $data)
    {
        $key = 'enum';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'elements' => ['lorem', 'ipsum', 'dolor'],
            'required' => false,
            'default' => 'lorem'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('lorem', $new['body']['default']);
        $this->assertCount(3, $new['body']['elements']);
        $this->assertContains('lorem', $new['body']['elements']);
        $this->assertContains('ipsum', $new['body']['elements']);
        $this->assertContains('dolor', $new['body']['elements']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $column = array_values(array_filter($new['body']['columns'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($column);
        $this->assertFalse($column['required']);
        $this->assertEquals('lorem', $column['default']);
        $this->assertCount(3, $column['elements']);
        $this->assertContains('lorem', $column['elements']);
        $this->assertContains('ipsum', $column['elements']);
        $this->assertContains('dolor', $column['elements']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'elements' => ['lorem', 'ipsum', 'dolor'],
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);
        $this->assertCount(3, $new['body']['elements']);
        $this->assertContains('lorem', $new['body']['elements']);
        $this->assertContains('ipsum', $new['body']['elements']);
        $this->assertContains('dolor', $new['body']['elements']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'elements' => ['ipsum', 'dolor'],
            'required' => false,
            'default' => 'dolor'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('dolor', $new['body']['default']);
        $this->assertCount(2, $new['body']['elements']);
        $this->assertContains('ipsum', $new['body']['elements']);
        $this->assertContains('dolor', $new['body']['elements']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'elements' => [],
            'required' => false,
            'default' => 'lorem'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_VALUE_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'elements' => ['ipsum', 'dolor'],
            'required' => false,
            'default' => 'lorem'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_VALUE_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => 'lorem',
            'elements' => ['lorem', 'ipsum', 'dolor'],
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123,
            'elements' => ['lorem', 'ipsum', 'dolor'],
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'lorem',
            'elements' => 'i am no array',
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'lorem',
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'elements' => ['lorem', 'ipsum', 'dolor'],
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => 'lorem',
            'elements' => ['lorem', 'ipsum', 'dolor'],
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => 'lorem',
            'elements' => ['lorem', 'ipsum', 'dolor'],
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateStringResize(array $data)
    {
        $key = 'string';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $row = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/tables/' . $tableId . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'rowId' => 'unique()',
                'data' => [
                    'string' => 'string'
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        // Test Resize Up
        $column = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'size' => 2048,
            'default' => '',
            'required' => false
        ]);

        $this->assertEquals(200, $column['headers']['status-code']);
        $this->assertEquals(2048, $column['body']['size']);

        // Test create new row with new size
        $newDoc = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/tables/' . $tableId . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'rowId' => 'unique()',
                'data' => [
                    'string' => str_repeat('a', 2048)
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(201, $newDoc['headers']['status-code']);
        $this->assertEquals(2048, strlen($newDoc['body']['string']));

        // Test update row with new size
        $row = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => str_repeat('a', 2048)
            ]
        ]);

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals(2048, strlen($row['body']['string']));

        // Test Exception on resize down with data that is too large
        $column = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'size' => 10,
            'default' => '',
            'required' => false
        ]);

        $this->assertEquals(400, $column['headers']['status-code']);
        $this->assertEquals(AppwriteException::COLUMN_INVALID_RESIZE, $column['body']['type']);

        // original rows to original size, remove new row
        $row = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => 'string'
            ]
        ]);

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals('string', $row['body']['string']);

        $deleteDoc = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/tables/' . $tableId . '/rows/' . $newDoc['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleteDoc['headers']['status-code']);


        // Test Resize Down
        $column = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'size' => 10,
            'default' => '',
            'required' => false
        ]);

        $this->assertEquals(200, $column['headers']['status-code']);
        $this->assertEquals(10, $column['body']['size']);

        // Test create new row with new size
        $newDoc = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/tables/' . $tableId . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'rowId' => 'unique()',
                'data' => [
                    'string' => str_repeat('a', 10)
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(201, $newDoc['headers']['status-code']);
        $this->assertEquals(10, strlen($newDoc['body']['string']));

        // Test update row with new size
        $row = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => str_repeat('a', 10)
            ]
        ]);

        $this->assertEquals(200, $row['headers']['status-code']);
        $this->assertEquals(10, strlen($row['body']['string']));

        // Try create row with string that is too large
        $newDoc = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/tables/' . $tableId . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'rowId' => 'unique()',
                'data' => [
                    'string' => str_repeat('a', 11)
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(400, $newDoc['headers']['status-code']);
        $this->assertEquals(AppwriteException::ROW_INVALID_STRUCTURE, $newDoc['body']['type']);
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnUpdateNotFound(array $data)
    {
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        $columns = [
            'string' => [
                'required' => false,
                'default' => 'ipsum'
            ],
            'email' => [
                'required' => false,
                'default' => 'eldad@appwrite.io'
            ],
            'ip' => [
                'required' => false,
                'default' => '127.0.0.1'
            ],
            'url' => [
                'required' => false,
                'default' => 'https://appwrite.io'
            ],
            'integer' => [
                'required' => false,
                'default' => 5,
                'min' => 0,
                'max' => 10
            ],
            'float' => [
                'required' => false,
                'default' => 5.5,
                'min' => 0.0,
                'max' => 10.0
            ],
            'datetime' => [
                'required' => false,
                'default' => '1975-06-12 14:12:55+02:00'
            ],
            'enum' => [
                'elements' => ['lorem', 'ipsum', 'dolor'],
                'required' => false,
                'default' => 'lorem'
            ]
        ];

        foreach ($columns as $key => $payload) {
            /**
             * Check if Database exists
             */
            $update = $this->client->call(Client::METHOD_PATCH, '/databases/i_dont_exist/tables/' . $tableId . '/columns/' . $key . '/unknown_' . $key, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), $payload);

            $this->assertEquals(404, $update['headers']['status-code']);
            $this->assertEquals(AppwriteException::DATABASE_NOT_FOUND, $update['body']['type']);

            /**
             * Check if Table exists
             */
            $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/i_dont_exist/columns/' . $key . '/unknown_' . $key, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), $payload);

            $this->assertEquals(404, $update['headers']['status-code']);
            $this->assertEquals(AppwriteException::TABLE_NOT_FOUND, $update['body']['type']);

            /**
             * Check if Column exists
             */
            $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key . '/unknown_' . $key, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), $payload);

            $this->assertEquals(404, $update['headers']['status-code']);
            $this->assertEquals(AppwriteException::COLUMN_NOT_FOUND, $update['body']['type']);
        }
    }

    /**
     * @depends testColumnUpdate
     */
    public function testColumnRename(array $data)
    {
        $key = 'string';
        $databaseId = $data['databaseId'];
        $tableId = $data['tableId'];

        // Create row to test against
        $row = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/tables/' . $tableId . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'rowId' => 'unique()',
                'data' => [
                    'string' => 'string'
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(201, $row['headers']['status-code']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'lorum',
            'newKey' => 'new_string',
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $key = 'new_string';

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/columns/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals('new_string', $new['body']['key']);

        $doc1 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $tableId . '/rows/' . $row['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertArrayHasKey('new_string', $doc1['body']);
        $this->assertEquals('string', $doc1['body']['new_string']);
        $this->assertArrayNotHasKey('string', $doc1['body']);

        // Try and create a new row with the new column
        $doc2 = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/tables/' . $tableId . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'rowId' => 'unique()',
                'data' => [
                    'new_string' => 'string'
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(201, $doc2['headers']['status-code']);
        $this->assertArrayHasKey('new_string', $doc2['body']);
        $this->assertEquals('string', $doc2['body']['new_string']);

        // Expect fail, try and create a new row with the old column
        $doc3 = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/tables/' . $tableId . '/rows',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'rowId' => 'unique()',
                'data' => [
                    'string' => 'string'
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(400, $doc3['headers']['status-code']);
    }

    public function createRelationshipTables(): void
    {
        // Prepare the database with tables and relationships
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => 'database1',
            'name' => 'Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'table1',
            'name' => 'level1',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => 'table2',
            'name' => 'level2',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        \sleep(2);
    }

    public function cleanupRelationshipTable(): void
    {
        $this->client->call(Client::METHOD_DELETE, '/databases/database1', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        \sleep(2);
    }

    public function testColumnRenameRelationshipOneToMany()
    {
        $databaseId = 'database1';
        $table1Id = 'table1';
        $table2Id = 'table2';

        $this->createRelationshipTables();

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $table1Id . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2Id,
            'type' => 'oneToMany',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        \sleep(3);

        $table1Columns =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $table1RelationColumn = $table1Columns['body']['columns'][0];

        $this->assertEquals($relation['body']['side'], $table1RelationColumn['side']);
        $this->assertEquals($relation['body']['twoWayKey'], $table1RelationColumn['twoWayKey']);
        $this->assertEquals($relation['body']['relatedTable'], $table1RelationColumn['relatedTable']);

        // Create a row for checking later
        $originalRow = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $table1Id . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => 'unique()',
            'data' => [
                'level2' => [[
                    '$id' => 'unique()',
                    '$permissions' => ["read(\"any\")"]
                ]],
            ],
            "permissions" => ["read(\"any\")"]
        ]);

        $this->assertEquals(201, $originalRow['headers']['status-code']);

        // Rename the column
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $table1Id . '/columns/level2' . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'newKey' => 'new_level_2'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        // Check the row's key has been renamed
        $newRow = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id . '/rows/' . $originalRow['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['new_level_2.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('new_level_2', $newRow['body']);
        $this->assertEquals(1, count($newRow['body']['new_level_2']));
        $this->assertArrayNotHasKey('level2', $newRow['body']);

        // Check level2 row has been renamed
        $level2Row = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table2Id . '/rows/' . $newRow['body']['new_level_2'][0]['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertArrayHasKey('level1', $level2Row['body']);
        $this->assertNotEmpty($level2Row['body']['level1']);

        // Check if column was renamed on the parent's side
        $table1Columns =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $table1Columns['headers']['status-code']);
        $this->assertEquals(1, count($table1Columns['body']['columns']));
        $this->assertEquals('new_level_2', $table1Columns['body']['columns'][0]['key']);

        // Check if column was renamed on the child's side
        $table2Columns = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table2Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $table2Columns['headers']['status-code']);
        $this->assertEquals(1, count($table2Columns['body']['columns']));
        $this->assertEquals('new_level_2', $table2Columns['body']['columns'][0]['twoWayKey']);

        $this->cleanupRelationshipTable();
    }

    public function testColumnRenameRelationshipOneToOne()
    {
        $databaseId = 'database1';
        $table1Id = 'table1';
        $table2Id = 'table2';

        $this->createRelationshipTables();

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $table1Id . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2Id,
            'type' => 'oneToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        \sleep(3);

        $table1Columns =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $table1RelationColumn = $table1Columns['body']['columns'][0];

        $this->assertEquals($relation['body']['side'], $table1RelationColumn['side']);
        $this->assertEquals($relation['body']['twoWayKey'], $table1RelationColumn['twoWayKey']);
        $this->assertEquals($relation['body']['relatedTable'], $table1RelationColumn['relatedTable']);

        // Create a row for checking later
        $originalRow = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $table1Id . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => 'unique()',
            'data' => [
                'level2' => [
                    '$id' => 'unique()',
                    '$permissions' => ["read(\"any\")"]
                ],
            ],
            "permissions" => ["read(\"any\")"]
        ]);

        $this->assertEquals(201, $originalRow['headers']['status-code']);

        // Rename the column
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $table1Id . '/columns/level2' . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'newKey' => 'new_level_2'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        // Check the row's key has been renamed
        $newRow = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id . '/rows/' . $originalRow['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['new_level_2.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('new_level_2', $newRow['body']);
        $this->assertNotEmpty($newRow['body']['new_level_2']);
        $this->assertArrayNotHasKey('level2', $newRow['body']);

        // Check level2 row has been renamed
        $level2Row = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table2Id . '/rows/' . $newRow['body']['new_level_2']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertArrayHasKey('level1', $level2Row['body']);
        $this->assertNotEmpty($level2Row['body']['level1']);

        // Check if column was renamed on the parent's side
        $table1Columns =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $table1Columns['headers']['status-code']);
        $this->assertEquals(1, count($table1Columns['body']['columns']));
        $this->assertEquals('new_level_2', $table1Columns['body']['columns'][0]['key']);

        // Check if column was renamed on the child's side
        $table2Columns = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table2Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $table2Columns['headers']['status-code']);
        $this->assertEquals(1, count($table2Columns['body']['columns']));
        $this->assertEquals('new_level_2', $table2Columns['body']['columns'][0]['twoWayKey']);

        $this->cleanupRelationshipTable();
    }

    public function testColumnRenameRelationshipManyToOne()
    {
        $databaseId = 'database1';
        $table1Id = 'table1';
        $table2Id = 'table2';

        $this->createRelationshipTables();

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $table1Id . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2Id,
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        \sleep(3);

        $table1Columns =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $table1RelationColumn = $table1Columns['body']['columns'][0];

        $this->assertEquals($relation['body']['side'], $table1RelationColumn['side']);
        $this->assertEquals($relation['body']['twoWayKey'], $table1RelationColumn['twoWayKey']);
        $this->assertEquals($relation['body']['relatedTable'], $table1RelationColumn['relatedTable']);

        // Create a row for checking later
        $originalRow = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $table1Id . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => 'unique()',
            'data' => [
                'level2' => [
                    '$id' => 'unique()',
                    '$permissions' => ["read(\"any\")"]
                ],
            ],
            "permissions" => ["read(\"any\")"]
        ]);

        $this->assertEquals(201, $originalRow['headers']['status-code']);

        // Rename the column
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $table1Id . '/columns/level2' . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'newKey' => 'new_level_2'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        // Check the row's key has been renamed
        $newRow = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id . '/rows/' . $originalRow['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['new_level_2.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('new_level_2', $newRow['body']);
        $this->assertNotEmpty($newRow['body']['new_level_2']);
        $this->assertArrayNotHasKey('level2', $newRow['body']);

        // Check level2 row has been renamed
        $level2Row = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table2Id . '/rows/' . $newRow['body']['new_level_2']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['*', 'level1.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('level1', $level2Row['body']);
        $this->assertNotEmpty($level2Row['body']['level1']);

        // Check if column was renamed on the parent's side
        $table1Columns =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $table1Columns['headers']['status-code']);
        $this->assertEquals(1, count($table1Columns['body']['columns']));
        $this->assertEquals('new_level_2', $table1Columns['body']['columns'][0]['key']);

        // Check if column was renamed on the child's side
        $table2Columns = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table2Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $table2Columns['headers']['status-code']);
        $this->assertEquals(1, count($table2Columns['body']['columns']));
        $this->assertEquals('new_level_2', $table2Columns['body']['columns'][0]['twoWayKey']);

        $this->cleanupRelationshipTable();
    }

    public function testColumnRenameRelationshipManyToMany()
    {
        $databaseId = 'database1';
        $table1Id = 'table1';
        $table2Id = 'table2';

        $this->createRelationshipTables();

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $table1Id . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedTableId' => $table2Id,
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        \sleep(3);

        $table1Columns =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $table1RelationColumn = $table1Columns['body']['columns'][0];

        $this->assertEquals($relation['body']['side'], $table1RelationColumn['side']);
        $this->assertEquals($relation['body']['twoWayKey'], $table1RelationColumn['twoWayKey']);
        $this->assertEquals($relation['body']['relatedTable'], $table1RelationColumn['relatedTable']);

        // Create a row for checking later
        $originalRow = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $table1Id . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'rowId' => 'unique()',
            'data' => [
                'level2' => [
                    '$id' => 'unique()',
                    '$permissions' => ["read(\"any\")"]
                ],
            ],
            "permissions" => ["read(\"any\")"]
        ]);

        $this->assertEquals(201, $originalRow['headers']['status-code']);

        // Rename the column
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/tables/' . $table1Id . '/columns/level2' . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'newKey' => 'new_level_2'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        // Check the row's key has been renamed
        $newRow = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id . '/rows/' . $originalRow['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['new_level_2.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('new_level_2', $newRow['body']);
        $this->assertNotEmpty($newRow['body']['new_level_2']);
        $this->assertArrayNotHasKey('level2', $newRow['body']);

        // Check level2 row has been renamed
        $level2Row = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table2Id . '/rows/' . $newRow['body']['new_level_2']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['*', 'level1.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('level1', $level2Row['body']);
        $this->assertNotEmpty($level2Row['body']['level1']);

        // Check if column was renamed on the parent's side
        $table1Columns =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $table1Columns['headers']['status-code']);
        $this->assertEquals(1, count($table1Columns['body']['columns']));
        $this->assertEquals('new_level_2', $table1Columns['body']['columns'][0]['key']);

        // Check if column was renamed on the child's side
        $table2Columns = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/tables/' . $table2Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $table2Columns['headers']['status-code']);
        $this->assertEquals(1, count($table2Columns['body']['columns']));
        $this->assertEquals('new_level_2', $table2Columns['body']['columns'][0]['twoWayKey']);

        $this->cleanupRelationshipTable();
    }

    public function testBulkCreate(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Create Perms',
        ]);

        $this->assertNotEmpty($database['body']['$id']);

        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Bulk Create Perms',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);

        $data = [
            '$id' => $table['body']['$id'],
            'databaseId' => $table['body']['databaseId']
        ];

        // Await column
        $numberColumn = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['$id'] . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'number',
            'required' => true,
        ]);

        $this->assertEquals(202, $numberColumn['headers']['status-code']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/tables/{$data['$id']}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rows' => [
                [
                    '$id' => ID::unique(),
                    'number' => 1,
                ],
                [
                    '$id' => ID::unique(),
                    'number' => 2,
                ],
                [
                    '$id' => ID::unique(),
                    'number' => 3,
                ],
            ],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['rows']);

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/tables/{$data['$id']}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(1, $response['body']['rows'][0]['number']);
        $this->assertEquals(2, $response['body']['rows'][1]['number']);
        $this->assertEquals(3, $response['body']['rows'][2]['number']);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['rows']);

        // TEST SUCCESS - $id is auto-assigned if not included in bulk rows
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/tables/{$data['$id']}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rows' => [
                [
                    'number' => 1,
                ]
            ],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // TEST FAIL - Can't use data and row together
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/tables/{$data['$id']}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'number' => 5
            ],
            'rows' => [
                [
                    '$id' => ID::unique(),
                    'number' => 1,
                ]
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // TEST FAIL - Can't use $rowId and create bulk rows
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/tables/{$data['$id']}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'rows' => [
                [
                    '$id' => ID::unique(),
                    'number' => 1,
                ]
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // TEST FAIL - Can't include invalid ID in bulk rows
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/tables/{$data['$id']}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rows' => [
                [
                    '$id' => '$invalid',
                    'number' => 1,
                ]
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // TEST FAIL - Can't miss number in bulk rows
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/tables/{$data['$id']}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rows' => [
                [
                    '$id' => ID::unique(),
                    'number' => 1,
                ],
                [
                    '$id' => ID::unique(),
                ],
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // TEST FAIL - Can't push more than APP_LIMIT_DATABASE_BATCH rows
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/tables/{$data['$id']}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rows' => array_fill(0, APP_LIMIT_DATABASE_BATCH + 1, [
                '$id' => ID::unique(),
                'number' => 1,
            ]),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // TEST FAIL - Can't include invalid permissions in nested rows
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/tables/{$data['$id']}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rows' => [
                [
                    '$id' => ID::unique(),
                    '$permissions' => ['invalid'],
                    'number' => 1,
                ],
            ],
        ]);

        // TEST FAIL - Can't bulk create in a table with relationships
        $table2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Bulk Related',
            'rowSecurity' => true,
            'permissions' => [],
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/tables/{$data['$id']}/rows", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rows' => [
                ['$id' => ID::unique(), 'number' => 1,],
                ['$id' => ID::unique(), 'number' => 2,],
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testBulkUpdate(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Updates'
        ]);

        $this->assertNotEmpty($database['body']['$id']);

        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Bulk Updates',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);

        $data = [
            '$id' => $table['body']['$id'],
            'databaseId' => $table['body']['databaseId']
        ];

        // Await column
        $numberColumn = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['$id'] . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'number',
            'required' => true,
        ]);

        $this->assertEquals(202, $numberColumn['headers']['status-code']);

        // Wait for database worker to create columns
        sleep(2);

        // Create rows
        $createBulkRows = function ($amount = 10) use ($data) {
            $rows = [];

            for ($x = 1; $x <= $amount; $x++) {
                $rows[] = [
                    '$id' => ID::unique(),
                    'number' => $x,
                ];
            }

            $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'rows' => $rows,
            ]);

            $this->assertEquals(201, $doc['headers']['status-code']);
        };

        $createBulkRows();

        /**
         * Wait for database to purge cache...
         *
         * This test specifically failed on 1.6.x response format,
         * could be due to the slow or overworked machine, but being safe here!
         */
        sleep(5);

        // TEST: Update all rows
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'number' => 100,
                '$permissions' => [
                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),
                ]
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(10, $response['body']['rows']);

        /**
         * Wait for database to purge cache...
         *
         * This test specifically failed on 1.6.x response format,
         * could be due to the slow or overworked machine, but being safe here!
         */
        sleep(5);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            Query::equal('number', [100])->toString(),
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(10, $rows['body']['total']);

        $returnedRows = $response['body']['rows'];
        $refetchedRows = $rows['body']['rows'];

        $this->assertEquals($returnedRows, $refetchedRows);

        foreach ($rows['body']['rows'] as $row) {
            $this->assertEquals([
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ], $row['$permissions']);
            $this->assertEquals($table['body']['$id'], $row['$tableId']);
            $this->assertEquals($data['databaseId'], $row['$databaseId']);
            $this->assertEquals(100, $row['number']);
        }

        // TEST: Check permissions persist
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'number' => 200
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(10, $response['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            Query::equal('number', [200])->toString(),
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(10, $rows['body']['total']);

        foreach ($rows['body']['rows'] as $row) {
            $this->assertEquals([
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ], $row['$permissions']);
        }

        // TEST: Update rows with limit
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'number' => 300
            ],
            'queries' => [
                Query::limit(5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(5, $response['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('number', [200])->toString()]
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(5, $rows['body']['total']);

        // TEST: Update rows with offset
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'number' => 300
            ],
            'queries' => [
                Query::offset(5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(5, $response['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('number', [300])->toString()]
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(10, $rows['body']['total']);

        // TEST: Update rows with equals filter
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'number' => 400
            ],
            'queries' => [
                Query::equal('number', [300])->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(10, $response['body']['rows']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('number', [400])->toString()]
        ]);

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(10, $rows['body']['total']);

        // TEST: Fail - Can't bulk update in a table with relationships
        $table2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Bulk Related',
            'rowSecurity' => true,
            'permissions' => [],
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'number' => 500
            ],
            'queries' => [
                Query::equal('number', [300])->toString(),
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testBulkUpsert(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Upserts'
        ]);

        $this->assertNotEmpty($database['body']['$id']);

        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Bulk Upserts',
            'rowSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);

        $data = [
            '$id' => $table['body']['$id'],
            'databaseId' => $table['body']['databaseId']
        ];

        // Await column
        $numberColumn = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['$id'] . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'number',
            'required' => true,
        ]);

        $this->assertEquals(202, $numberColumn['headers']['status-code']);

        // Wait for database worker to create columns
        sleep(2);

        // Create rows
        $createBulkRows = function ($amount = 10) use ($data) {
            $rows = [];

            for ($x = 1; $x <= $amount; $x++) {
                $rows[] = [
                    '$id' => "$x",
                    'number' => $x,
                ];
            }

            $response = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'rows' => $rows,
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);

            return $rows;
        };

        $rows = $createBulkRows();

        // Update 1 row
        $rows[\array_key_last($rows)]['number'] = 1000;

        // Add 1 row
        $rows[] = ['number' => 11];

        // TEST: Upsert all rows
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rows' => $rows,
        ]);

        // Unchanged docs are skipped. 2 rows should be returned, 1 updated and 1 inserted.
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['rows']);
        $this->assertEquals(1000, $response['body']['rows'][0]['number']);
        $this->assertEquals(11, $response['body']['rows'][1]['number']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(11, $rows['body']['total']);

        foreach ($rows['body']['rows'] as $index => $row) {
            $this->assertEquals($table['body']['$id'], $row['$tableId']);
            $this->assertEquals($data['databaseId'], $row['$databaseId']);
            switch ($index) {
                case 9:
                    $this->assertEquals(1000, $row['number']);
                    break;
                default:
                    $this->assertEquals($index + 1, $row['number']);
            }
        }

        // TEST: Upsert permissions
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rows' => [
                [
                    '$id' => '1',
                    'number' => 1000,
                ],
                [
                    '$id' => '10',
                    '$permissions' => [
                        Permission::read(Role::user($this->getUser()['$id'])),
                        Permission::update(Role::user($this->getUser()['$id'])),
                        Permission::delete(Role::user($this->getUser()['$id'])),
                    ],
                    'number' => 10,
                ],
            ],
        ]);

        $this->assertEquals(1000, $response['body']['rows'][0]['number']);
        $this->assertEquals([], $response['body']['rows'][0]['$permissions']);
        $this->assertEquals([
            Permission::read(Role::user($this->getUser()['$id'])),
            Permission::update(Role::user($this->getUser()['$id'])),
            Permission::delete(Role::user($this->getUser()['$id'])),
        ], $response['body']['rows'][1]['$permissions']);

        // TEST: Fail - Can't bulk upsert in a table with relationships
        $table2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Bulk Related',
            'rowSecurity' => true,
            'permissions' => [],
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rows' => [
                [
                    '$id' => '1',
                    'number' => 1000,
                ],
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testBulkDelete(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Deletes'
        ]);

        $this->assertNotEmpty($database['body']['$id']);

        $databaseId = $database['body']['$id'];

        $table = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Bulk Deletes',
            'rowSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $table['headers']['status-code']);

        $data = [
            '$id' => $table['body']['$id'],
            'databaseId' => $table['body']['databaseId']
        ];

        // Await column
        $numberColumn = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['$id'] . '/columns/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'number',
            'required' => true,
        ]);

        $this->assertEquals(202, $numberColumn['headers']['status-code']);

        // wait for database worker to create columns
        sleep(2);

        // Create rows
        $createBulkRows = function ($amount = 11) use ($data) {
            $rows = [];

            for ($x = 0; $x < $amount; $x++) {
                $rows[] = [
                    '$id' => ID::unique(),
                    'number' => $x,
                ];
            }

            $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'rows' => $rows,
            ]);

            $this->assertEquals(201, $doc['headers']['status-code']);
        };

        $createBulkRows();

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(11, $rows['body']['total']);

        // TEST: Delete all rows
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(11, $response['body']['total']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(0, $rows['body']['total']);

        // TEST: Delete rows with query
        $createBulkRows();

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(11, $rows['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::lessThan('number', 5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, $response['body']['total']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(6, $rows['body']['total']);

        foreach ($rows['body']['rows'] as $row) {
            $this->assertGreaterThanOrEqual(5, $row['number']);
        }

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, $response['body']['total']);

        // SUCCESS: Delete rows with query
        $createBulkRows();

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(11, $rows['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::lessThan('number', 5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, $response['body']['total']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(6, $rows['body']['total']);

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, $response['body']['total']);

        // SUCCESS: Delete Rows with limit query
        $createBulkRows();

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(11, $rows['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(2)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(9, $rows['body']['total']);

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(9, $response['body']['total']);

        // SUCCESS: Delete Rows with offset query
        $createBulkRows();

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(11, $rows['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, $response['body']['total']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(5, $rows['body']['total']);

        $lastDoc = end($rows['body']['rows']);

        $this->assertNotEmpty($lastDoc);
        $this->assertEquals(4, $lastDoc['number']);

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, $response['body']['total']);

        // SUCCESS: Delete 100 rows
        $createBulkRows(100);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(100, $rows['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(100, $response['body']['total']);

        $rows = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $rows['headers']['status-code']);
        $this->assertEquals(0, $rows['body']['total']);

        // TEST: Fail - Can't bulk delete in a table with relationships
        $table2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Bulk Related',
            'rowSecurity' => true,
            'permissions' => [],
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/tables/' . $data['$id'] . '/columns/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()), [
            'relatedTableId' => $table2['body']['$id'],
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/tables/' . $data['$id'] . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(400, $response['headers']['status-code']);
    }
}
