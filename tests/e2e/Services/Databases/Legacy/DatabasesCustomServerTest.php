<?php

namespace Tests\E2E\Services\Databases\Legacy;

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

        /**
         * Test for SUCCESS with total=false
         */
        $databasesWithIncludeTotalFalse = $this->client->call(Client::METHOD_GET, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'total' => false
        ]);

        $this->assertEquals(200, $databasesWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($databasesWithIncludeTotalFalse['body']);
        $this->assertIsArray($databasesWithIncludeTotalFalse['body']['databases']);
        $this->assertIsInt($databasesWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $databasesWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($databasesWithIncludeTotalFalse['body']['databases']));

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

        // This collection already exists
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

        // Try to get the collection and check if it has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testListCollections(): array
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
        $this->assertTrue($database['body']['enabled']);

        $databaseId = $database['body']['$id'];
        /**
         * Test for SUCCESS
         */
        $test1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 1',
            'collectionId' => ID::custom('first'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $test2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 2',
            'collectionId' => ID::custom('second'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(2, $collections['body']['total']);
        $this->assertEquals($test1['body']['$id'], $collections['body']['collections'][0]['$id']);
        $this->assertEquals($test1['body']['enabled'], $collections['body']['collections'][0]['enabled']);
        $this->assertEquals($test2['body']['$id'], $collections['body']['collections'][1]['$id']);
        $this->assertEquals($test1['body']['enabled'], $collections['body']['collections'][0]['enabled']);

        /**
         * Test for SUCCESS with total=false
         */
        $collectionsWithIncludeTotalFalse = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'total' => false
        ]);

        $this->assertEquals(200, $collectionsWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($collectionsWithIncludeTotalFalse['body']);
        $this->assertIsArray($collectionsWithIncludeTotalFalse['body']['collections']);
        $this->assertIsInt($collectionsWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $collectionsWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($collectionsWithIncludeTotalFalse['body']['collections']));

        $base = array_reverse($collections['body']['collections']);

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $collections['headers']['status-code']);
        $this->assertCount(1, $collections['body']['collections']);

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $collections['headers']['status-code']);
        $this->assertCount(1, $collections['body']['collections']);

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('enabled', [true])->toString(),
            ],
        ]);

        $this->assertEquals(200, $collections['headers']['status-code']);
        $this->assertCount(2, $collections['body']['collections']);

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('enabled', [false])->toString(),
            ],
        ]);

        $this->assertEquals(200, $collections['headers']['status-code']);
        $this->assertCount(0, $collections['body']['collections']);

        /**
         * Test for Order
         */
        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::orderDesc()->toString(),
            ],
        ]);

        $this->assertEquals(2, $collections['body']['total']);
        $this->assertEquals($base[0]['$id'], $collections['body']['collections'][0]['$id']);
        $this->assertEquals($base[1]['$id'], $collections['body']['collections'][1]['$id']);

        /**
         * Test for After
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['collections'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(1, $collections['body']['collections']);
        $this->assertEquals($base['body']['collections'][1]['$id'], $collections['body']['collections'][0]['$id']);

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['collections'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(0, $collections['body']['collections']);
        $this->assertEmpty($collections['body']['collections']);

        /**
         * Test for Before
         */
        $base = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['collections'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(1, $collections['body']['collections']);
        $this->assertEquals($base['body']['collections'][0]['$id'], $collections['body']['collections'][0]['$id']);

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['collections'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(0, $collections['body']['collections']);
        $this->assertEmpty($collections['body']['collections']);

        /**
         * Test for Search
         */
        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'first'
        ]);

        $this->assertEquals(1, $collections['body']['total']);
        $this->assertEquals('first', $collections['body']['collections'][0]['$id']);

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Test'
        ]);

        $this->assertEquals(2, $collections['body']['total']);
        $this->assertEquals('Test 1', $collections['body']['collections'][0]['name']);
        $this->assertEquals('Test 2', $collections['body']['collections'][1]['name']);

        $collections = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Nonexistent'
        ]);

        $this->assertEquals(0, $collections['body']['total']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // This collection already exists
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 1',
            'collectionId' => ID::custom('first'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(409, $response['headers']['status-code']);
        return [
            'databaseId' => $databaseId,
            'collectionId' => $test1['body']['$id'],
        ];
    }

    /**
     * @depends testListCollections
     */
    public function testGetCollection(array $data): void
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertEquals('Test 1', $collection['body']['name']);
        $this->assertEquals('first', $collection['body']['$id']);
        $this->assertTrue($collection['body']['enabled']);
    }

    /**
     * @depends testListCollections
     */
    public function testUpdateCollection(array $data)
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $collection = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 1 Updated',
            'enabled' => false
        ]);

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertEquals('Test 1 Updated', $collection['body']['name']);
        $this->assertEquals('first', $collection['body']['$id']);
        $this->assertFalse($collection['body']['enabled']);
    }

    /**
     * @depends testListCollections
     */
    public function testCreateEncryptedAttribute(array $data): void
    {
        $databaseId = $data['databaseId'];

        /**
         * Test for SUCCESS
         */

        // Create collection
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Encrypted Actors Data',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $actors['headers']['status-code']);
        $this->assertEquals($actors['body']['name'], 'Encrypted Actors Data');

        /**
         * Test for creating encrypted attributes
         */
        $attributesPath = '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'] . '/attributes';

        $firstName = $this->client->call(Client::METHOD_POST, $attributesPath . '/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'firstName',
            'size' => 256,
            'required' => true,
        ]);

        // checking size test
        $lastName = $this->client->call(Client::METHOD_POST, $attributesPath . '/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lastName',
            'size' => 149,
            'required' => true,
            'encrypt' => true
        ]);
        $this->assertEquals("Size too small. Encrypted strings require a minimum size of " . APP_DATABASE_ENCRYPT_SIZE_MIN . " characters.", $lastName['body']['message']);

        $lastName = $this->client->call(Client::METHOD_POST, $attributesPath . '/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lastName',
            'size' => 256,
            'required' => true,
            'encrypt' => true
        ]);
        $this->assertTrue($lastName['body']['encrypt']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_GET, $attributesPath . '/lastName', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]));

        $this->assertTrue($response['body']['encrypt']);

        /**
         * Check status of every attribute
         */
        $this->assertEquals(202, $firstName['headers']['status-code']);
        $this->assertEquals('firstName', $firstName['body']['key']);
        $this->assertEquals('string', $firstName['body']['type']);

        $this->assertEquals(202, $lastName['headers']['status-code']);
        $this->assertEquals('lastName', $lastName['body']['key']);
        $this->assertEquals('string', $lastName['body']['type']);

        // Wait for database worker to finish creating attributes
        sleep(2);

        // Creating document to ensure cache is purged on schema change
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::unique(),
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

        // Check document to ensure cache is purged on schema change
        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'] . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals('Jonah', $document['body']['firstName']);
        $this->assertEquals('Jameson', $document['body']['lastName']);


        $actors = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);
        $attributes = $actors['body']['attributes'];
        foreach ($attributes as $attribute) {
            $this->assertArrayHasKey('encrypt', $attribute);
            if ($attribute['key'] === 'firstName') {
                $this->assertFalse($attribute['encrypt']);
            }
            if ($attribute['key'] === 'lastName') {
                $this->assertTrue($attribute['encrypt']);
            }
        }

    }

    public function testDeleteAttribute(): array
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

        // Create collection
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $actors['headers']['status-code']);
        $this->assertEquals($actors['body']['name'], 'Actors');

        $firstName = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'firstName',
            'size' => 256,
            'required' => true,
        ]);

        $lastName = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lastName',
            'size' => 256,
            'required' => true,
        ]);

        $unneeded = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'unneeded',
            'size' => 256,
            'required' => true,
        ]);

        // Wait for database worker to finish creating attributes
        sleep(2);

        // Creating document to ensure cache is purged on schema change
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::unique(),
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

        $index = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'key_lastName',
            'type' => 'key',
            'attributes' => [
                'lastName',
            ],
        ]);

        // Wait for database worker to finish creating index
        sleep(2);

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $unneededId = $unneeded['body']['key'];

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertCount(3, $collection['body']['attributes']);
        $this->assertEquals($collection['body']['attributes'][0]['key'], $firstName['body']['key']);
        $this->assertEquals($collection['body']['attributes'][1]['key'], $lastName['body']['key']);
        $this->assertEquals($collection['body']['attributes'][2]['key'], $unneeded['body']['key']);
        $this->assertCount(1, $collection['body']['indexes']);
        $this->assertEquals($collection['body']['indexes'][0]['key'], $index['body']['key']);

        // Delete attribute
        $attribute = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'] . '/attributes/' . $unneededId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $attribute['headers']['status-code']);

        sleep(2);

        // Check document to ensure cache is purged on schema change
        $document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'] . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertNotContains($unneededId, $document['body']);

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertCount(2, $collection['body']['attributes']);
        $this->assertEquals($collection['body']['attributes'][0]['key'], $firstName['body']['key']);
        $this->assertEquals($collection['body']['attributes'][1]['key'], $lastName['body']['key']);

        return [
            'collectionId' => $actors['body']['$id'],
            'key' => $index['body']['key'],
            'databaseId' => $databaseId
        ];
    }

    /**
     * @depends testDeleteAttribute
     */
    public function testDeleteIndex($data): array
    {
        $databaseId = $data['databaseId'];
        $index = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['collectionId'] . '/indexes/' . $data['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $index['headers']['status-code']);

        // Wait for database worker to finish deleting index
        sleep(2);

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['collectionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertCount(0, $collection['body']['indexes']);

        return $data;
    }

    /**
     * @depends testDeleteIndex
     */
    public function testDeleteIndexOnDeleteAttribute($data)
    {
        $databaseId = $data['databaseId'];
        $attribute1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['collectionId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'attribute1',
            'size' => 16,
            'required' => true,
        ]);

        $attribute2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['collectionId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'attribute2',
            'size' => 16,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute1['headers']['status-code']);
        $this->assertEquals(202, $attribute2['headers']['status-code']);
        $this->assertEquals('attribute1', $attribute1['body']['key']);
        $this->assertEquals('attribute2', $attribute2['body']['key']);

        sleep(2);

        $index1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['collectionId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'index1',
            'type' => 'key',
            'attributes' => ['attribute1', 'attribute2'],
            'orders' => ['ASC', 'ASC'],
        ]);

        $index2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['collectionId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'index2',
            'type' => 'key',
            'attributes' => ['attribute2'],
        ]);

        $this->assertEquals(202, $index1['headers']['status-code']);
        $this->assertEquals(202, $index2['headers']['status-code']);
        $this->assertEquals('index1', $index1['body']['key']);
        $this->assertEquals('index2', $index2['body']['key']);

        sleep(2);

        // Expected behavior: deleting attribute2 will cause index2 to be dropped, and index1 rebuilt with a single key
        $deleted = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['collectionId'] . '/attributes/' . $attribute2['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleted['headers']['status-code']);

        // wait for database worker to complete
        sleep(2);

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['collectionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertCount(1, $collection['body']['indexes']);
        $this->assertEquals($index1['body']['key'], $collection['body']['indexes'][0]['key']);
        $this->assertIsArray($collection['body']['indexes'][0]['attributes']);
        $this->assertCount(1, $collection['body']['indexes'][0]['attributes']);
        $this->assertEquals($attribute1['body']['key'], $collection['body']['indexes'][0]['attributes'][0]);

        // Delete attribute
        $deleted = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['collectionId'] . '/attributes/' . $attribute1['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleted['headers']['status-code']);

        return $data;
    }

    public function testCleanupDuplicateIndexOnDeleteAttribute()
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
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'TestCleanupDuplicateIndexOnDeleteAttribute',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertNotEmpty($collection['body']['$id']);

        $collectionId = $collection['body']['$id'];

        $attribute1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'attribute1',
            'size' => 16,
            'required' => true,
        ]);

        $attribute2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'attribute2',
            'size' => 16,
            'required' => true,
        ]);

        $this->assertEquals(202, $attribute1['headers']['status-code']);
        $this->assertEquals(202, $attribute2['headers']['status-code']);
        $this->assertEquals('attribute1', $attribute1['body']['key']);
        $this->assertEquals('attribute2', $attribute2['body']['key']);

        sleep(2);

        $index1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'index1',
            'type' => 'key',
            'attributes' => ['attribute1', 'attribute2'],
            'orders' => ['ASC', 'ASC'],
        ]);

        $index2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'index2',
            'type' => 'key',
            'attributes' => ['attribute2'],
        ]);

        $this->assertEquals(202, $index1['headers']['status-code']);
        $this->assertEquals(202, $index2['headers']['status-code']);
        $this->assertEquals('index1', $index1['body']['key']);
        $this->assertEquals('index2', $index2['body']['key']);

        sleep(2);

        // Expected behavior: deleting attribute1 would cause index1 to be a duplicate of index2 and automatically removed
        $deleted = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $attribute1['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleted['headers']['status-code']);

        // wait for database worker to complete
        sleep(2);

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertCount(1, $collection['body']['indexes']);
        $this->assertEquals($index2['body']['key'], $collection['body']['indexes'][0]['key']);
        $this->assertIsArray($collection['body']['indexes'][0]['attributes']);
        $this->assertCount(1, $collection['body']['indexes'][0]['attributes']);
        $this->assertEquals($attribute2['body']['key'], $collection['body']['indexes'][0]['attributes'][0]);

        // Delete attribute
        $deleted = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $attribute2['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleted['headers']['status-code']);
    }

    /**
     * @depends testDeleteIndexOnDeleteAttribute
     */
    public function testDeleteCollection($data)
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Add Documents to the collection
        $document1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
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

        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
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

        $this->assertEquals(201, $document1['headers']['status-code']);
        $this->assertIsArray($document1['body']['$permissions']);
        $this->assertCount(3, $document1['body']['$permissions']);
        $this->assertEquals($document1['body']['firstName'], 'Tom');
        $this->assertEquals($document1['body']['lastName'], 'Holland');

        $this->assertEquals(201, $document2['headers']['status-code']);
        $this->assertIsArray($document2['body']['$permissions']);
        $this->assertCount(3, $document2['body']['$permissions']);
        $this->assertEquals($document2['body']['firstName'], 'Samuel');
        $this->assertEquals($document2['body']['lastName'], 'Jackson');

        // Delete the actors collection
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals($response['body'], "");

        // Try to get the collection and check if it has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testDeleteCollectionDeletesRelatedAttributes(): void
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'TestDeleteCollectionDeletesRelatedAttributes',
        ]);

        $databaseId = $database['body']['$id'];

        $collection1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Collection1',
            'documentSecurity' => false,
            'permissions' => [],
        ]);

        $collection2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Collection2',
            'documentSecurity' => false,
            'permissions' => [],
        ]);

        $collection1 = $collection1['body']['$id'];
        $collection2 = $collection2['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1 . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), [
            'relatedCollectionId' => $collection2,
            'type' => Database::RELATION_MANY_TO_ONE,
            'twoWay' => false,
            'key' => 'collection2'
        ]);

        sleep(2);

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collection2, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()));

        sleep(2);

        $attributes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1 . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $this->getHeaders()));

        $this->assertEquals(0, $attributes['body']['total']);
    }

    public function testAttributeRowWidthLimit()
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
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('attributeRowWidthLimit'),
            'name' => 'attributeRowWidthLimit',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals($collection['body']['name'], 'attributeRowWidthLimit');

        $collectionId = $collection['body']['$id'];

        // Add wide string attributes to approach row width limit
        for ($i = 0; $i < 15; $i++) {
            $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => "attribute{$i}",
                'size' => 1024,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
        }

        sleep(5);

        $tooWide = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'tooWide',
            'size' => 1024,
            'required' => true,
        ]);

        $this->assertEquals(400, $tooWide['headers']['status-code']);
        $this->assertEquals('attribute_limit_exceeded', $tooWide['body']['type']);
    }

    public function testIndexLimitException()
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
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('testLimitException'),
            'name' => 'testLimitException',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals($collection['body']['name'], 'testLimitException');

        $collectionId = $collection['body']['$id'];

        // add unique attributes for indexing
        for ($i = 0; $i < 64; $i++) {
            // $this->assertEquals(true, static::getDatabase()->createAttribute('indexLimit', "test{$i}", Database::VAR_STRING, 16, true));
            $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => "attribute{$i}",
                'size' => 64,
                'required' => true,
            ]);

            $this->assertEquals(202, $attribute['headers']['status-code']);
        }

        sleep(10);

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertEquals($collection['body']['name'], 'testLimitException');
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertCount(64, $collection['body']['attributes']);
        $this->assertCount(0, $collection['body']['indexes']);

        $this->assertEventually(function () use ($databaseId, $collectionId) {
            $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]));

            foreach ($collection['body']['attributes'] ?? [] as $attribute) {
                $this->assertEquals(
                    'available',
                    $attribute['status'],
                    'attribute: ' . $attribute['key']
                );
            }

            return true;
        }, 60000, 500);


        // Test indexLimit = 64
        // MariaDB, MySQL, and MongoDB create 6 indexes per new collection
        // Add up to the limit, then check if the next index throws IndexLimitException
        for ($i = 0; $i < 58; $i++) {
            $index = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'key' => "key_attribute{$i}",
                'type' => 'key',
                'attributes' => ["attribute{$i}"],
            ]);

            $this->assertEquals(202, $index['headers']['status-code']);
            $this->assertEquals("key_attribute{$i}", $index['body']['key']);
        }

        sleep(5);

        $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertEquals($collection['body']['name'], 'testLimitException');
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertCount(64, $collection['body']['attributes']);
        $this->assertCount(58, $collection['body']['indexes']);

        $tooMany = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'tooMany',
            'type' => 'key',
            'attributes' => ['attribute61'],
        ]);

        $this->assertEquals(400, $tooMany['headers']['status-code']);
        $this->assertEquals("The maximum number of indexes for collection '$collectionId' has been reached.", $tooMany['body']['message']);

        $collection = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $collection['headers']['status-code']);
    }

    public function testAttributeUpdate(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'updateAttributes',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);

        $databaseId = $database['body']['$id'];
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('updateAttributes'),
            'name' => 'updateAttributes'
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $collectionId = $collection['body']['$id'];

        /**
         * Create String Attribute
         */
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'string',
            'size' => 1024,
            'required' => false
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);

        /**
         * Create Email Attribute
         */
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'required' => false
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);

        /**
         * Create IP Attribute
         */
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/ip', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'ip',
            'required' => false
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);

        /**
         * Create URL Attribute
         */
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/url', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'url',
            'required' => false
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);

        /**
         * Create Integer Attribute
         */
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'integer',
            'required' => false
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);

        /**
         * Create Float Attribute
         */
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'float',
            'required' => false
        ]);

        /**
         * Create Boolean Attribute
         */
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'boolean',
            'required' => false
        ]);

        /**
         * Create Datetime Attribute
         */
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'datetime',
            'required' => false
        ]);

        /**
         * Create Enum Attribute
         */
        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'enum',
            'required' => false,
            'elements' => ['lorem', 'ipsum']
        ]);

        $this->assertEquals(202, $attribute['headers']['status-code']);

        sleep(5);

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId
        ];
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateString(array $data)
    {
        $key = 'string';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'lorem'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('lorem', $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $attribute = array_values(array_filter($new['body']['attributes'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertFalse($attribute['required']);
        $this->assertEquals('lorem', $attribute['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'ipsum'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('ipsum', $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'i am no boolean',
            'default' => 'dolor'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => 'ipsum'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => 'ipsum'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::ATTRIBUTE_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateEmail(array $data)
    {
        $key = 'email';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'torsten@appwrite.io'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('torsten@appwrite.io', $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $attribute = array_values(array_filter($new['body']['attributes'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertFalse($attribute['required']);
        $this->assertEquals('torsten@appwrite.io', $attribute['default']);


        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'eldad@appwrite.io'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('eldad@appwrite.io', $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => 'torsten@appwrite.io'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no email'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => 'ipsum'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/email/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => 'torsten@appwrite.io'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::ATTRIBUTE_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateIp(array $data)
    {
        $key = 'ip';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => '127.0.0.1'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('127.0.0.1', $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $attribute = array_values(array_filter($new['body']['attributes'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertFalse($attribute['required']);
        $this->assertEquals('127.0.0.1', $attribute['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => '192.168.0.1'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('192.168.0.1', $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => '127.0.0.1'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no ip'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => '127.0.0.1'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/ip/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => '127.0.0.1'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::ATTRIBUTE_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateUrl(array $data)
    {
        $key = 'url';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'http://appwrite.io'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('http://appwrite.io', $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $attribute = array_values(array_filter($new['body']['attributes'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertFalse($attribute['required']);
        $this->assertEquals('http://appwrite.io', $attribute['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'https://appwrite.io'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('https://appwrite.io', $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => 'https://appwrite.io'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no url'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => 'https://appwrite.io'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/url/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => 'https://appwrite.io'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::ATTRIBUTE_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateInteger(array $data)
    {
        $key = 'integer';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(123, $new['body']['default']);
        $this->assertEquals(0, $new['body']['min']);
        $this->assertEquals(1000, $new['body']['max']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $attribute = array_values(array_filter($new['body']['attributes'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertFalse($attribute['required']);
        $this->assertEquals(123, $attribute['default']);
        $this->assertEquals(0, $attribute['min']);
        $this->assertEquals(1000, $attribute['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);
        $this->assertEquals(0, $new['body']['min']);
        $this->assertEquals(1000, $new['body']['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(456, $new['body']['default']);
        $this->assertEquals(100, $new['body']['min']);
        $this->assertEquals(2000, $new['body']['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 100,
            'min' => 0,
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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
        $this->assertEquals(AppwriteException::ATTRIBUTE_DEFAULT_UNSUPPORTED, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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
        $this->assertEquals(AppwriteException::ATTRIBUTE_VALUE_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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
        $this->assertEquals(AppwriteException::ATTRIBUTE_VALUE_INVALID, $update['body']['type']);


        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer/' . $key, array_merge([
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
        $this->assertEquals(AppwriteException::ATTRIBUTE_VALUE_INVALID, $update['body']['type']);
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateFloat(array $data)
    {
        $key = 'float';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(123.456, $new['body']['default']);
        $this->assertEquals(0, $new['body']['min']);
        $this->assertEquals(1000, $new['body']['max']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $attribute = array_values(array_filter($new['body']['attributes'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertFalse($attribute['required']);
        $this->assertEquals(123.456, $attribute['default']);
        $this->assertEquals(0, $attribute['min']);
        $this->assertEquals(1000, $attribute['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);
        $this->assertEquals(0, $new['body']['min']);
        $this->assertEquals(1000, $new['body']['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(456.789, $new['body']['default']);
        $this->assertEquals(123.456, $new['body']['min']);
        $this->assertEquals(2000, $new['body']['max']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 123.456,
            'min' => 0.0,
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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
        $this->assertEquals(AppwriteException::ATTRIBUTE_DEFAULT_UNSUPPORTED, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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
        $this->assertEquals(AppwriteException::ATTRIBUTE_VALUE_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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
        $this->assertEquals(AppwriteException::ATTRIBUTE_VALUE_INVALID, $update['body']['type']);


        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/float/' . $key, array_merge([
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
        $this->assertEquals(AppwriteException::ATTRIBUTE_VALUE_INVALID, $update['body']['type']);
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateBoolean(array $data)
    {
        $key = 'boolean';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => true
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(true, $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $attribute = array_values(array_filter($new['body']['attributes'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertFalse($attribute['required']);
        $this->assertEquals(true, $attribute['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => false
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals(false, $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => true
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no boolean'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => false
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/boolean/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => true
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::ATTRIBUTE_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateDatetime(array $data)
    {
        $key = 'datetime';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => '1975-06-12 14:12:55+02:00'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('1975-06-12 14:12:55+02:00', $new['body']['default']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $attribute = array_values(array_filter($new['body']['attributes'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertFalse($attribute['required']);
        $this->assertEquals('1975-06-12 14:12:55+02:00', $attribute['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertNull($new['body']['default']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => '1965-06-12 14:12:55+02:00'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertFalse($new['body']['required']);
        $this->assertEquals('1965-06-12 14:12:55+02:00', $new['body']['default']);

        /**
         * Test against failure
         */
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => 'no boolean',
            'default' => '1975-06-12 14:12:55+02:00'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'i am no datetime'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => '1975-06-12 14:12:55+02:00'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => '1975-06-12 14:12:55+02:00'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::ATTRIBUTE_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateEnum(array $data)
    {
        $key = 'enum';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'elements' => ['lorem', 'ipsum', 'dolor'],
            'required' => false,
            'default' => 'lorem'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
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

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $attribute = array_values(array_filter($new['body']['attributes'], fn (array $a) => $a['key'] === $key))[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertFalse($attribute['required']);
        $this->assertEquals('lorem', $attribute['default']);
        $this->assertCount(3, $attribute['elements']);
        $this->assertContains('lorem', $attribute['elements']);
        $this->assertContains('ipsum', $attribute['elements']);
        $this->assertContains('dolor', $attribute['elements']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'elements' => ['lorem', 'ipsum', 'dolor'],
            'required' => false,
            'default' => null
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'elements' => ['ipsum', 'dolor'],
            'required' => false,
            'default' => 'dolor'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
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
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'elements' => [],
            'required' => false,
            'default' => 'lorem'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::ATTRIBUTE_VALUE_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'elements' => ['ipsum', 'dolor'],
            'required' => false,
            'default' => 'lorem'
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::ATTRIBUTE_VALUE_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
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

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => false,
            'default' => 'lorem',
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'elements' => ['lorem', 'ipsum', 'dolor'],
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'default' => 'lorem',
            'elements' => ['lorem', 'ipsum', 'dolor'],
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::GENERAL_ARGUMENT_INVALID, $update['body']['type']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/enum/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'required' => true,
            'default' => 'lorem',
            'elements' => ['lorem', 'ipsum', 'dolor'],
        ]);

        $this->assertEquals(400, $update['headers']['status-code']);
        $this->assertEquals(AppwriteException::ATTRIBUTE_DEFAULT_UNSUPPORTED, $update['body']['type']);
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateStringResize(array $data)
    {
        $key = 'string';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $document = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'documentId' => 'unique()',
                'data' => [
                    'string' => 'string'
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        // Test Resize Up
        $attribute = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'size' => 2048,
            'default' => '',
            'required' => false
        ]);

        $this->assertEquals(200, $attribute['headers']['status-code']);
        $this->assertEquals(2048, $attribute['body']['size']);

        // Test create new document with new size
        $newDoc = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'documentId' => 'unique()',
                'data' => [
                    'string' => str_repeat('a', 2048)
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(201, $newDoc['headers']['status-code']);
        $this->assertEquals(2048, strlen($newDoc['body']['string']));

        // Test update document with new size
        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => str_repeat('a', 2048)
            ]
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals(2048, strlen($document['body']['string']));

        // Test Exception on resize down with data that is too large
        $attribute = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'size' => 10,
            'default' => '',
            'required' => false
        ]);

        $this->assertEquals(400, $attribute['headers']['status-code']);
        $this->assertEquals(AppwriteException::ATTRIBUTE_INVALID_RESIZE, $attribute['body']['type']);

        // original documents to original size, remove new document
        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => 'string'
            ]
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals('string', $document['body']['string']);

        $deleteDoc = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $newDoc['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $deleteDoc['headers']['status-code']);


        // Test Resize Down
        $attribute = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'size' => 10,
            'default' => '',
            'required' => false
        ]);

        $this->assertEquals(200, $attribute['headers']['status-code']);
        $this->assertEquals(10, $attribute['body']['size']);

        // Test create new document with new size
        $newDoc = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'documentId' => 'unique()',
                'data' => [
                    'string' => str_repeat('a', 10)
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(201, $newDoc['headers']['status-code']);
        $this->assertEquals(10, strlen($newDoc['body']['string']));

        // Test update document with new size
        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => str_repeat('a', 10)
            ]
        ]);

        $this->assertEquals(200, $document['headers']['status-code']);
        $this->assertEquals(10, strlen($document['body']['string']));

        // Try create document with string that is too large
        $newDoc = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'documentId' => 'unique()',
                'data' => [
                    'string' => str_repeat('a', 11)
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(400, $newDoc['headers']['status-code']);
        $this->assertEquals(AppwriteException::DOCUMENT_INVALID_STRUCTURE, $newDoc['body']['type']);
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeUpdateNotFound(array $data)
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        $attributes = [
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

        foreach ($attributes as $key => $payload) {
            /**
             * Check if Database exists
             */
            $update = $this->client->call(Client::METHOD_PATCH, '/databases/i_dont_exist/collections/' . $collectionId . '/attributes/' . $key . '/unknown_' . $key, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), $payload);

            $this->assertEquals(404, $update['headers']['status-code']);
            $this->assertEquals(AppwriteException::DATABASE_NOT_FOUND, $update['body']['type']);

            /**
             * Check if Collection exists
             */
            $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/i_dont_exist/attributes/' . $key . '/unknown_' . $key, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), $payload);

            $this->assertEquals(404, $update['headers']['status-code']);
            $this->assertEquals(AppwriteException::COLLECTION_NOT_FOUND, $update['body']['type']);

            /**
             * Check if Attribute exists
             */
            $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key . '/unknown_' . $key, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), $payload);

            $this->assertEquals(404, $update['headers']['status-code']);
            $this->assertEquals(AppwriteException::ATTRIBUTE_NOT_FOUND, $update['body']['type']);
        }
    }

    /**
     * @depends testAttributeUpdate
     */
    public function testAttributeRename(array $data)
    {
        $key = 'string';
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Create document to test against
        $document = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'documentId' => 'unique()',
                'data' => [
                    'string' => 'string'
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(201, $document['headers']['status-code']);

        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string/' . $key, array_merge([
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

        $new = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/' . $key, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals('new_string', $new['body']['key']);

        $doc1 = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertArrayHasKey('new_string', $doc1['body']);
        $this->assertEquals('string', $doc1['body']['new_string']);
        $this->assertArrayNotHasKey('string', $doc1['body']);

        // Try and create a new document with the new attribute
        $doc2 = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'documentId' => 'unique()',
                'data' => [
                    'new_string' => 'string'
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(201, $doc2['headers']['status-code']);
        $this->assertArrayHasKey('new_string', $doc2['body']);
        $this->assertEquals('string', $doc2['body']['new_string']);

        // Expect fail, try and create a new document with the old attribute
        $doc3 = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]),
            [
                'documentId' => 'unique()',
                'data' => [
                    'string' => 'string'
                ],
                "permissions" => ["read(\"any\")"]
            ]
        );

        $this->assertEquals(400, $doc3['headers']['status-code']);
    }

    public function createRelationshipCollections(): void
    {
        // Prepare the database with collections and relationships
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => 'database1',
            'name' => 'Test Database'
        ]);

        $databaseId = $database['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'collection1',
            'name' => 'level1',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'collection2',
            'name' => 'level2',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::user($this->getUser()['$id'])),
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ]
        ]);

        \sleep(2);
    }

    public function cleanupRelationshipCollection(): void
    {
        $this->client->call(Client::METHOD_DELETE, '/databases/database1', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        \sleep(2);
    }

    public function testAttributeRenameRelationshipOneToMany()
    {
        $databaseId = 'database1';
        $collection1Id = 'collection1';
        $collection2Id = 'collection2';

        $this->createRelationshipCollections();

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2Id,
            'type' => 'oneToMany',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        \sleep(3);

        $collection1Attributes =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $collection1RelationAttribute = $collection1Attributes['body']['attributes'][0];

        $this->assertEquals($relation['body']['side'], $collection1RelationAttribute['side']);
        $this->assertEquals($relation['body']['twoWayKey'], $collection1RelationAttribute['twoWayKey']);
        $this->assertEquals($relation['body']['relatedCollection'], $collection1RelationAttribute['relatedCollection']);

        // Create a document for checking later
        $originalDocument = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'unique()',
            'data' => [
                'level2' => [[
                    '$id' => 'unique()',
                    '$permissions' => ["read(\"any\")"]
                ]],
            ],
            "permissions" => ["read(\"any\")"]
        ]);

        $this->assertEquals(201, $originalDocument['headers']['status-code']);

        // Rename the attribute
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/attributes/level2' . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'newKey' => 'new_level_2'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        // Check the document's key has been renamed
        $newDocument = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/documents/' . $originalDocument['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['new_level_2.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('new_level_2', $newDocument['body']);
        $this->assertEquals(1, count($newDocument['body']['new_level_2']));
        $this->assertArrayNotHasKey('level2', $newDocument['body']);

        // Check level2 document has been renamed
        $level2Document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection2Id . '/documents/' . $newDocument['body']['new_level_2'][0]['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertArrayHasKey('level1', $level2Document['body']);
        $this->assertNotEmpty($level2Document['body']['level1']);

        // Check if attribute was renamed on the parent's side
        $collection1Attributes =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $collection1Attributes['headers']['status-code']);
        $this->assertEquals(1, count($collection1Attributes['body']['attributes']));
        $this->assertEquals('new_level_2', $collection1Attributes['body']['attributes'][0]['key']);

        // Check if attribute was renamed on the child's side
        $collection2Attributes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection2Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $collection2Attributes['headers']['status-code']);
        $this->assertEquals(1, count($collection2Attributes['body']['attributes']));
        $this->assertEquals('new_level_2', $collection2Attributes['body']['attributes'][0]['twoWayKey']);

        $this->cleanupRelationshipCollection();
    }

    public function testAttributeRenameRelationshipOneToOne()
    {
        $databaseId = 'database1';
        $collection1Id = 'collection1';
        $collection2Id = 'collection2';

        $this->createRelationshipCollections();

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2Id,
            'type' => 'oneToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        \sleep(3);

        $collection1Attributes =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $collection1RelationAttribute = $collection1Attributes['body']['attributes'][0];

        $this->assertEquals($relation['body']['side'], $collection1RelationAttribute['side']);
        $this->assertEquals($relation['body']['twoWayKey'], $collection1RelationAttribute['twoWayKey']);
        $this->assertEquals($relation['body']['relatedCollection'], $collection1RelationAttribute['relatedCollection']);

        // Create a document for checking later
        $originalDocument = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'unique()',
            'data' => [
                'level2' => [
                    '$id' => 'unique()',
                    '$permissions' => ["read(\"any\")"]
                ],
            ],
            "permissions" => ["read(\"any\")"]
        ]);

        $this->assertEquals(201, $originalDocument['headers']['status-code']);

        // Rename the attribute
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/attributes/level2' . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'newKey' => 'new_level_2'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        // Check the document's key has been renamed
        $newDocument = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/documents/' . $originalDocument['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['new_level_2.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('new_level_2', $newDocument['body']);
        $this->assertNotEmpty($newDocument['body']['new_level_2']);
        $this->assertArrayNotHasKey('level2', $newDocument['body']);

        // Check level2 document has been renamed
        $level2Document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection2Id . '/documents/' . $newDocument['body']['new_level_2']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertArrayHasKey('level1', $level2Document['body']);
        $this->assertNotEmpty($level2Document['body']['level1']);

        // Check if attribute was renamed on the parent's side
        $collection1Attributes =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $collection1Attributes['headers']['status-code']);
        $this->assertEquals(1, count($collection1Attributes['body']['attributes']));
        $this->assertEquals('new_level_2', $collection1Attributes['body']['attributes'][0]['key']);

        // Check if attribute was renamed on the child's side
        $collection2Attributes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection2Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $collection2Attributes['headers']['status-code']);
        $this->assertEquals(1, count($collection2Attributes['body']['attributes']));
        $this->assertEquals('new_level_2', $collection2Attributes['body']['attributes'][0]['twoWayKey']);

        $this->cleanupRelationshipCollection();
    }

    public function testAttributeRenameRelationshipManyToOne()
    {
        $databaseId = 'database1';
        $collection1Id = 'collection1';
        $collection2Id = 'collection2';

        $this->createRelationshipCollections();

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2Id,
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        \sleep(3);

        $collection1Attributes =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $collection1RelationAttribute = $collection1Attributes['body']['attributes'][0];

        $this->assertEquals($relation['body']['side'], $collection1RelationAttribute['side']);
        $this->assertEquals($relation['body']['twoWayKey'], $collection1RelationAttribute['twoWayKey']);
        $this->assertEquals($relation['body']['relatedCollection'], $collection1RelationAttribute['relatedCollection']);

        // Create a document for checking later
        $originalDocument = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'unique()',
            'data' => [
                'level2' => [
                    '$id' => 'unique()',
                    '$permissions' => ["read(\"any\")"]
                ],
            ],
            "permissions" => ["read(\"any\")"]
        ]);

        $this->assertEquals(201, $originalDocument['headers']['status-code']);

        // Rename the attribute
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/attributes/level2' . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'newKey' => 'new_level_2'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        // Check the document's key has been renamed
        $newDocument = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/documents/' . $originalDocument['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['new_level_2.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('new_level_2', $newDocument['body']);
        $this->assertNotEmpty($newDocument['body']['new_level_2']);
        $this->assertArrayNotHasKey('level2', $newDocument['body']);

        // Check level2 document has been renamed
        $level2Document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection2Id . '/documents/' . $newDocument['body']['new_level_2']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['*', 'level1.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('level1', $level2Document['body']);
        $this->assertNotEmpty($level2Document['body']['level1']);

        // Check if attribute was renamed on the parent's side
        $collection1Attributes =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $collection1Attributes['headers']['status-code']);
        $this->assertEquals(1, count($collection1Attributes['body']['attributes']));
        $this->assertEquals('new_level_2', $collection1Attributes['body']['attributes'][0]['key']);

        // Check if attribute was renamed on the child's side
        $collection2Attributes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection2Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $collection2Attributes['headers']['status-code']);
        $this->assertEquals(1, count($collection2Attributes['body']['attributes']));
        $this->assertEquals('new_level_2', $collection2Attributes['body']['attributes'][0]['twoWayKey']);

        $this->cleanupRelationshipCollection();
    }

    public function testAttributeRenameRelationshipManyToMany()
    {
        $databaseId = 'database1';
        $collection1Id = 'collection1';
        $collection2Id = 'collection2';

        $this->createRelationshipCollections();

        $relation = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'relatedCollectionId' => $collection2Id,
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        \sleep(3);

        $collection1Attributes =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $collection1RelationAttribute = $collection1Attributes['body']['attributes'][0];

        $this->assertEquals($relation['body']['side'], $collection1RelationAttribute['side']);
        $this->assertEquals($relation['body']['twoWayKey'], $collection1RelationAttribute['twoWayKey']);
        $this->assertEquals($relation['body']['relatedCollection'], $collection1RelationAttribute['relatedCollection']);

        // Create a document for checking later
        $originalDocument = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'unique()',
            'data' => [
                'level2' => [
                    '$id' => 'unique()',
                    '$permissions' => ["read(\"any\")"]
                ],
            ],
            "permissions" => ["read(\"any\")"]
        ]);

        $this->assertEquals(201, $originalDocument['headers']['status-code']);

        // Rename the attribute
        $update = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/attributes/level2' . '/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'newKey' => 'new_level_2'
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        // Check the document's key has been renamed
        $newDocument = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id . '/documents/' . $originalDocument['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['new_level_2.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('new_level_2', $newDocument['body']);
        $this->assertNotEmpty($newDocument['body']['new_level_2']);
        $this->assertArrayNotHasKey('level2', $newDocument['body']);

        // Check level2 document has been renamed
        $level2Document = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection2Id . '/documents/' . $newDocument['body']['new_level_2']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'queries' => [
                Query::select(['*', 'level1.*'])->toString()
            ]
        ]);

        $this->assertArrayHasKey('level1', $level2Document['body']);
        $this->assertNotEmpty($level2Document['body']['level1']);

        // Check if attribute was renamed on the parent's side
        $collection1Attributes =  $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection1Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $collection1Attributes['headers']['status-code']);
        $this->assertEquals(1, count($collection1Attributes['body']['attributes']));
        $this->assertEquals('new_level_2', $collection1Attributes['body']['attributes'][0]['key']);

        // Check if attribute was renamed on the child's side
        $collection2Attributes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collection2Id, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]);

        $this->assertEquals(200, $collection2Attributes['headers']['status-code']);
        $this->assertEquals(1, count($collection2Attributes['body']['attributes']));
        $this->assertEquals('new_level_2', $collection2Attributes['body']['attributes'][0]['twoWayKey']);

        $this->cleanupRelationshipCollection();
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

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Bulk Create Perms',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $data = [
            '$id' => $collection['body']['$id'],
            'databaseId' => $collection['body']['databaseId']
        ];

        // Await attribute
        $numberAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['$id'] . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'number',
            'required' => true,
        ]);

        $this->assertEquals(202, $numberAttribute['headers']['status-code']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
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
        $this->assertCount(3, $response['body']['documents']);

        $response = $this->client->call(Client::METHOD_GET, "/databases/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(1, $response['body']['documents'][0]['number']);
        $this->assertEquals(2, $response['body']['documents'][1]['number']);
        $this->assertEquals(3, $response['body']['documents'][2]['number']);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['documents']);

        // TEST SUCCESS - $id is auto-assigned if not included in bulk documents
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
                [
                    'number' => 1,
                ]
            ],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // TEST FAIL - Can't use data and document together
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'number' => 5
            ],
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'number' => 1,
                ]
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // TEST FAIL - Can't use $documentId and create bulk documents
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'number' => 1,
                ]
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // TEST FAIL - Can't include invalid ID in bulk documents
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
                [
                    '$id' => '$invalid',
                    'number' => 1,
                ]
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // TEST FAIL - Can't miss number in bulk documents
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
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

        // TEST FAIL - Can't push more than APP_LIMIT_DATABASE_BATCH documents
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => array_fill(0, APP_LIMIT_DATABASE_BATCH + 1, [
                '$id' => ID::unique(),
                'number' => 1,
            ]),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // TEST FAIL - Can't include invalid permissions in nested documents
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    '$permissions' => ['invalid'],
                    'number' => 1,
                ],
            ],
        ]);

        // TEST FAIL - Can't bulk create in a collection with relationships
        $collection2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Bulk Related',
            'documentSecurity' => true,
            'permissions' => [],
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
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

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Bulk Updates',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $data = [
            '$id' => $collection['body']['$id'],
            'databaseId' => $collection['body']['databaseId']
        ];

        // Await attribute
        $numberAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['$id'] . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'number',
            'required' => true,
        ]);

        $this->assertEquals(202, $numberAttribute['headers']['status-code']);

        // Wait for database worker to create attributes
        sleep(2);

        // Create documents
        $createBulkDocuments = function ($amount = 10) use ($data) {
            $documents = [];

            for ($x = 1; $x <= $amount; $x++) {
                $documents[] = [
                    '$id' => ID::unique(),
                    'number' => $x,
                ];
            }

            $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'documents' => $documents,
            ]);

            $this->assertEquals(201, $doc['headers']['status-code']);
        };

        $createBulkDocuments();

        /**
         * Wait for database to purge cache...
         *
         * This test specifically failed on 1.6.x response format,
         * could be due to the slow or overworked machine, but being safe here!
         */
        sleep(5);

        // TEST: Update all documents
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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
        $this->assertCount(10, $response['body']['documents']);

        /**
         * Wait for database to purge cache...
         *
         * This test specifically failed on 1.6.x response format,
         * could be due to the slow or overworked machine, but being safe here!
         */
        sleep(5);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            Query::equal('number', [100])->toString(),
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(10, $documents['body']['total']);

        /**
         * Test for SUCCESS with total=false
         */
        $documentsWithIncludeTotalFalse = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'total' => false
        ]);

        $this->assertEquals(200, $documentsWithIncludeTotalFalse['headers']['status-code']);
        $this->assertIsArray($documentsWithIncludeTotalFalse['body']);
        $this->assertIsArray($documentsWithIncludeTotalFalse['body']['documents']);
        $this->assertIsInt($documentsWithIncludeTotalFalse['body']['total']);
        $this->assertEquals(0, $documentsWithIncludeTotalFalse['body']['total']);
        $this->assertGreaterThan(0, count($documentsWithIncludeTotalFalse['body']['documents']));

        $returnedDocuments = $response['body']['documents'];
        $refetchedDocuments = $documents['body']['documents'];

        $this->assertEquals($returnedDocuments, $refetchedDocuments);

        foreach ($documents['body']['documents'] as $document) {
            $this->assertEquals([
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ], $document['$permissions']);
            $this->assertEquals($collection['body']['$id'], $document['$collectionId']);
            $this->assertEquals($data['databaseId'], $document['$databaseId']);
            $this->assertEquals($document['number'], 100);
        }

        // TEST: Check permissions persist
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'number' => 200
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(10, $response['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            Query::equal('number', [200])->toString(),
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(10, $documents['body']['total']);

        foreach ($documents['body']['documents'] as $document) {
            $this->assertEquals([
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::update(Role::user($this->getUser()['$id'])),
                Permission::delete(Role::user($this->getUser()['$id'])),
            ], $document['$permissions']);
        }

        // TEST: Update documents with limit
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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
        $this->assertCount(5, $response['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('number', [200])->toString()]
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(5, $documents['body']['total']);

        // TEST: Update documents with offset
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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
        $this->assertCount(5, $response['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('number', [300])->toString()]
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(10, $documents['body']['total']);

        // TEST: Update documents with equals filter
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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
        $this->assertCount(10, $response['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('number', [400])->toString()]
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(10, $documents['body']['total']);

        // TEST: Fail - Can't bulk update in a collection with relationships
        $collection2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Bulk Related',
            'documentSecurity' => true,
            'permissions' => [],
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Bulk Upserts',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $data = [
            '$id' => $collection['body']['$id'],
            'databaseId' => $collection['body']['databaseId']
        ];

        // Await attribute
        $numberAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['$id'] . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'number',
            'required' => true,
        ]);

        $this->assertEquals(202, $numberAttribute['headers']['status-code']);

        // Wait for database worker to create attributes
        sleep(2);

        // Create documents
        $createBulkDocuments = function ($amount = 10) use ($data) {
            $documents = [];

            for ($x = 1; $x <= $amount; $x++) {
                $documents[] = [
                    '$id' => "$x",
                    'number' => $x,
                ];
            }

            $response = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'documents' => $documents,
            ]);

            $this->assertEquals(201, $response['headers']['status-code']);

            return $documents;
        };

        $documents = $createBulkDocuments();

        // Update 1 document
        $documents[\array_key_last($documents)]['number'] = 1000;

        // Add 1 document
        $documents[] = ['number' => 11];

        // TEST: Upsert all documents
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => $documents,
        ]);

        // Unchanged docs are skipped. 2 documents should be returned, 1 updated and 1 inserted.
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['documents']);
        $this->assertEquals(1000, $response['body']['documents'][0]['number']);
        $this->assertEquals(11, $response['body']['documents'][1]['number']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        foreach ($documents['body']['documents'] as $index => $document) {
            $this->assertEquals($collection['body']['$id'], $document['$collectionId']);
            $this->assertEquals($data['databaseId'], $document['$databaseId']);
            switch ($index) {
                case 9:
                    $this->assertEquals(1000, $document['number']);
                    break;
                default:
                    $this->assertEquals($index + 1, $document['number']);
            }
        }

        // TEST: Upsert permissions
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
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

        $this->assertEquals(1000, $response['body']['documents'][0]['number']);
        $this->assertEquals([], $response['body']['documents'][0]['$permissions']);
        $this->assertEquals([
            Permission::read(Role::user($this->getUser()['$id'])),
            Permission::update(Role::user($this->getUser()['$id'])),
            Permission::delete(Role::user($this->getUser()['$id'])),
        ], $response['body']['documents'][1]['$permissions']);

        // TEST: Fail - Can't bulk upsert in a collection with relationships
        $collection2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Bulk Related',
            'documentSecurity' => true,
            'permissions' => [],
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
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

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Bulk Deletes',
            'documentSecurity' => false,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        $data = [
            '$id' => $collection['body']['$id'],
            'databaseId' => $collection['body']['databaseId']
        ];

        // Await attribute
        $numberAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['$id'] . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'number',
            'required' => true,
        ]);

        $this->assertEquals(202, $numberAttribute['headers']['status-code']);

        // wait for database worker to create attributes
        sleep(2);

        // Create documents
        $createBulkDocuments = function ($amount = 11) use ($data) {
            $documents = [];

            for ($x = 0; $x < $amount; $x++) {
                $documents[] = [
                    '$id' => ID::unique(),
                    'number' => $x,
                ];
            }

            $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'documents' => $documents,
            ]);

            $this->assertEquals(201, $doc['headers']['status-code']);
        };

        $createBulkDocuments();

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        // TEST: Delete all documents
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(11, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(0, $documents['body']['total']);

        // TEST: Delete documents with query
        $createBulkDocuments();

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::lessThan('number', 5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(6, $documents['body']['total']);

        foreach ($documents['body']['documents'] as $document) {
            $this->assertGreaterThanOrEqual(5, $document['number']);
        }

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, $response['body']['total']);

        // SUCCESS: Delete documents with query
        $createBulkDocuments();

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::lessThan('number', 5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(6, $documents['body']['total']);

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, $response['body']['total']);

        // SUCCESS: Delete Documents with limit query
        $createBulkDocuments();

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(2)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(9, $documents['body']['total']);

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(9, $response['body']['total']);

        // SUCCESS: Delete Documents with offset query
        $createBulkDocuments();

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(5, $documents['body']['total']);

        $lastDoc = end($documents['body']['documents']);

        $this->assertNotEmpty($lastDoc);
        $this->assertEquals(4, $lastDoc['number']);

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, $response['body']['total']);

        // SUCCESS: Delete 100 documents
        $createBulkDocuments(100);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(100, $documents['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(100, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(0, $documents['body']['total']);

        // TEST: Fail - Can't bulk delete in a collection with relationships
        $collection2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Bulk Related',
            'documentSecurity' => true,
            'permissions' => [],
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $data['$id'] . '/attributes/relationship', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()), [
            'relatedCollectionId' => $collection2['body']['$id'],
            'type' => 'manyToOne',
            'twoWay' => true,
            'onDelete' => 'cascade',
            'key' => 'level2',
            'twoWayKey' => 'level1'
        ]);

        $this->assertEquals(202, $response['headers']['status-code']);

        sleep(1);

        $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testDateTimeDocument(): void
    {
        $databaseId = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'DateTime Test Database',
        ]);

        $this->assertEquals(201, $databaseId['headers']['status-code']);
        $databaseId = $databaseId['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'create_modify_dates',
            'documentSecurity' => true,
            'permissions' => [],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create string attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'string',
            'size' => 128,
            'required' => false,
        ]);

        // Create datetime attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/datetime', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'datetime',
            'required' => false,
            'format' => 'datetime',
        ]);

        sleep(1);

        $date = '2000-01-01T10:00:00.000+00:00';

        // Test - default behaviour of external datetime attribute not changed
        $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc1',
            'data' => [
                'datetime' => ''
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::write(Role::any()),
                Permission::update(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);
        $this->assertNotEmpty($doc['body']['datetime']);
        $this->assertNotEmpty($doc['body']['$createdAt']);
        $this->assertNotEmpty($doc['body']['$updatedAt']);

        $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/doc1', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $doc['headers']['status-code']);
        $this->assertNotEmpty($doc['body']['datetime']);
        $this->assertNotEmpty($doc['body']['$createdAt']);
        $this->assertNotEmpty($doc['body']['$updatedAt']);

        // Test - modifying $createdAt and $updatedAt
        $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc2',
            'data' => [
                '$createdAt' => $date
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::write(Role::any()),
                Permission::update(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);
        $this->assertEquals($doc['body']['$createdAt'], $date);
        $this->assertNotEmpty($doc['body']['$updatedAt']);
        $this->assertNotEquals($doc['body']['$updatedAt'], $date);

        $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/doc2', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $doc['headers']['status-code']);
        $this->assertEquals($doc['body']['$createdAt'], $date);
        $this->assertNotEmpty($doc['body']['$updatedAt']);
        $this->assertNotEquals($doc['body']['$updatedAt'], $date);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSingleDocumentDateOperations(): void
    {
        $databaseId = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Single Date Operations Database',
        ]);

        $this->assertEquals(201, $databaseId['headers']['status-code']);
        $databaseId = $databaseId['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'normal_date_operations',
            'documentSecurity' => true,
            'permissions' => [],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create string attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'string',
            'size' => 128,
            'required' => false,
        ]);

        sleep(1);

        $createDate = '2000-01-01T10:00:00.000+00:00';
        $updateDate = '2000-02-01T15:30:00.000+00:00';
        $date1 = '2000-01-01T10:00:00.000+00:00';
        $date2 = '2000-02-01T15:30:00.000+00:00';
        $date3 = '2000-03-01T20:45:00.000+00:00';

        // Test 1: Create with custom createdAt, then update with custom updatedAt
        $doc = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc1',
            'data' => [
                'string' => 'initial',
                '$createdAt' => $createDate
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::write(Role::any()),
                Permission::update(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $doc['headers']['status-code']);
        $this->assertEquals($createDate, $doc['body']['$createdAt']);
        $this->assertNotEquals($createDate, $doc['body']['$updatedAt']);

        // Update with custom updatedAt
        $updatedDoc = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/doc1', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => 'updated',
                '$updatedAt' => $updateDate
            ]
        ]);

        $this->assertEquals(200, $updatedDoc['headers']['status-code']);
        $this->assertEquals($createDate, $updatedDoc['body']['$createdAt']);
        $this->assertEquals($updateDate, $updatedDoc['body']['$updatedAt']);

        // Test 2: Create with both custom dates
        $doc2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc2',
            'data' => [
                'string' => 'both_dates',
                '$createdAt' => $createDate,
                '$updatedAt' => $updateDate
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::write(Role::any()),
                Permission::update(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $doc2['headers']['status-code']);
        $this->assertEquals($createDate, $doc2['body']['$createdAt']);
        $this->assertEquals($updateDate, $doc2['body']['$updatedAt']);

        // Test 3: Create without dates, then update with custom dates
        $doc3 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc3',
            'data' => [
                'string' => 'no_dates'
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::write(Role::any()),
                Permission::update(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $doc3['headers']['status-code']);

        $updatedDoc3 = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/doc3', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => 'updated_no_dates',
                '$createdAt' => $createDate,
                '$updatedAt' => $updateDate
            ]
        ]);

        $this->assertEquals(200, $updatedDoc3['headers']['status-code']);
        $this->assertEquals($createDate, $updatedDoc3['body']['$createdAt']);
        $this->assertEquals($updateDate, $updatedDoc3['body']['$updatedAt']);

        // Test 4: Update only createdAt
        $doc4 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc4',
            'data' => [
                'string' => 'initial'
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::write(Role::any()),
                Permission::update(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $doc4['headers']['status-code']);
        $originalCreatedAt4 = $doc4['body']['$createdAt'];
        $originalUpdatedAt4 = $doc4['body']['$updatedAt'];

        $updatedDoc4 = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/doc4', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => 'updated',
                '$updatedAt' => null,
                '$createdAt' => null
            ],
        ]);

        $this->assertEquals(200, $updatedDoc4['headers']['status-code']);
        $this->assertEquals($originalCreatedAt4, $updatedDoc4['body']['$createdAt']);
        $this->assertNotEquals($originalUpdatedAt4, $updatedDoc4['body']['$updatedAt']);

        // Test 5: Update only updatedAt
        $finalDoc4 = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/doc4', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => 'final',
                '$updatedAt' => $updateDate,
                '$createdAt' => $createDate
            ]
        ]);

        $this->assertEquals(200, $finalDoc4['headers']['status-code']);
        $this->assertEquals($createDate, $finalDoc4['body']['$createdAt']);
        $this->assertEquals($updateDate, $finalDoc4['body']['$updatedAt']);

        // Test 6: Create with updatedAt, update with createdAt
        $doc5 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc5',
            'data' => [
                'string' => 'doc5',
                '$updatedAt' => $date2
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::write(Role::any()),
                Permission::update(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $doc5['headers']['status-code']);
        $this->assertNotEquals($date2, $doc5['body']['$createdAt']);
        $this->assertEquals($date2, $doc5['body']['$updatedAt']);

        $updatedDoc5 = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/doc5', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => 'doc5_updated',
                '$createdAt' => $date1
            ]
        ]);

        $this->assertEquals(200, $updatedDoc5['headers']['status-code']);
        $this->assertEquals($date1, $updatedDoc5['body']['$createdAt']);
        $this->assertNotEquals($date2, $updatedDoc5['body']['$updatedAt']);

        // Test 7: Create with both dates, update with different dates
        $doc6 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'doc6',
            'data' => [
                'string' => 'doc6',
                '$createdAt' => $date1,
                '$updatedAt' => $date2
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::write(Role::any()),
                Permission::update(Role::any()),
            ]
        ]);

        $this->assertEquals(201, $doc6['headers']['status-code']);
        $this->assertEquals($date1, $doc6['body']['$createdAt']);
        $this->assertEquals($date2, $doc6['body']['$updatedAt']);

        $updatedDoc6 = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/doc6', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => 'doc6_updated',
                '$createdAt' => $date3,
                '$updatedAt' => $date3
            ]
        ]);

        $this->assertEquals(200, $updatedDoc6['headers']['status-code']);
        $this->assertEquals($date3, $updatedDoc6['body']['$createdAt']);
        $this->assertEquals($date3, $updatedDoc6['body']['$updatedAt']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testBulkDocumentDateOperations(): void
    {
        $databaseId = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Date Operations Database',
        ]);

        $this->assertEquals(201, $databaseId['headers']['status-code']);
        $databaseId = $databaseId['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'bulk_date_operations',
            'documentSecurity' => true,
            'permissions' => [],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create string attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'string',
            'size' => 128,
            'required' => false,
        ]);

        sleep(1);

        $createDate = '2000-01-01T10:00:00.000+00:00';
        $updateDate = '2000-02-01T15:30:00.000+00:00';

        // Test 1: Bulk create with different date configurations
        $documents = [
            [
                '$id' => 'doc1',
                'string' => 'doc1',
                '$createdAt' => $createDate,
                '$permissions' => [                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),]
            ],
            [
                '$id' => 'doc2',
                'string' => 'doc2',
                '$updatedAt' => $updateDate,
                '$permissions' => [                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),]
            ],
            [
                '$id' => 'doc3',
                'string' => 'doc3',
                '$createdAt' => $createDate,
                '$updatedAt' => $updateDate,
                '$permissions' => [                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),]
            ],
            [
                '$id' => 'doc4',
                'string' => 'doc4',
                '$permissions' => [                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),]
            ],
            [
                '$id' => 'doc5',
                'string' => 'doc5',
                '$createdAt' => null,
                '$permissions' => [                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),]
            ],
            [
                '$id' => 'doc6',
                'string' => 'doc6',
                '$updatedAt' => null,
                '$permissions' => [                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),]
            ]
        ];

        // Create all documents in one bulk operation
        $bulkCreateResponse = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documents' => $documents
        ]);

        $this->assertEquals(201, $bulkCreateResponse['headers']['status-code']);
        $this->assertCount(count($documents), $bulkCreateResponse['body']['documents']);

        // Verify initial state
        foreach (['doc1', 'doc3'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($createDate, $doc['body']['$createdAt'], "createdAt mismatch for $id");
        }

        foreach (['doc2', 'doc3'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($updateDate, $doc['body']['$updatedAt'], "updatedAt mismatch for $id");
        }

        foreach (['doc4', 'doc5', 'doc6'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertNotEmpty($doc['body']['$createdAt'], "createdAt missing for $id");
            $this->assertNotEmpty($doc['body']['$updatedAt'], "updatedAt missing for $id");
        }

        // Test 2: Bulk update with custom dates
        $updateData = [
            'data' => [
                'string' => 'updated',
                '$createdAt' => $createDate,
                '$updatedAt' => $updateDate,
                '$permissions' => [                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::update(Role::user($this->getUser()['$id'])),
                    Permission::delete(Role::user($this->getUser()['$id'])),]
            ],
        ];

        // Use bulk update instead of individual updates
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), $updateData);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(6, $response['body']['documents']);

        // Verify updated state
        foreach (['doc1', 'doc3'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($createDate, $doc['body']['$createdAt'], "createdAt mismatch for $id");
            $this->assertEquals($updateDate, $doc['body']['$updatedAt'], "updatedAt mismatch for $id");
            $this->assertEquals('updated', $doc['body']['string'], "string mismatch for $id");
        }

        foreach (['doc2', 'doc4', 'doc5', 'doc6'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($updateDate, $doc['body']['$updatedAt'], "updatedAt mismatch for $id");
            $this->assertEquals('updated', $doc['body']['string'], "string mismatch for $id");
        }

        $newDate = '2000-03-01T20:45:00.000+00:00';
        $updateDataEnabled = [
            'data' => [
                'string' => 'enabled_update',
                '$createdAt' => $newDate,
                '$updatedAt' => $newDate
            ],
        ];

        // Use bulk update instead of individual updates
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), $updateDataEnabled);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(6, $response['body']['documents']);

        // Verify final state
        foreach (['doc1', 'doc2', 'doc3', 'doc4', 'doc5', 'doc6'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($newDate, $doc['body']['$createdAt'], "createdAt mismatch for $id");
            $this->assertEquals($newDate, $doc['body']['$updatedAt'], "updatedAt mismatch for $id");
            $this->assertEquals('enabled_update', $doc['body']['string'], "string mismatch for $id");
        }

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testUpsertDateOperations(): void
    {
        $databaseId = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Upsert Date Operations Database',
        ]);

        $this->assertEquals(201, $databaseId['headers']['status-code']);
        $databaseId = $databaseId['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'upsert_date_operations',
            'documentSecurity' => true,
            'permissions' => [],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create string attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'string',
            'size' => 128,
            'required' => false,
        ]);

        sleep(1);

        $createDate = '2000-01-01T10:00:00.000+00:00';
        $updateDate = '2000-02-01T15:30:00.000+00:00';
        $date1 = '2000-01-01T10:00:00.000+00:00';
        $date2 = '2000-02-01T15:30:00.000+00:00';
        $date3 = '2000-03-01T20:45:00.000+00:00';

        // Test 1: Upsert new document with custom createdAt
        $upsertDoc1 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'upsert1',
            'data' => [
                'string' => 'upsert1_initial',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::write(Role::any()),
                    Permission::update(Role::any()),
                ],
                '$createdAt' => $createDate
            ],
        ]);

        $this->assertEquals(201, $upsertDoc1['headers']['status-code']);
        $this->assertEquals($createDate, $upsertDoc1['body']['$createdAt']);
        $this->assertNotEquals($createDate, $upsertDoc1['body']['$updatedAt']);

        // Test 2: Upsert existing document with custom updatedAt
        $updatedUpsertDoc1 = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/upsert1', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => 'upsert1_updated',
                '$updatedAt' => $updateDate
            ],
        ]);

        $this->assertEquals(200, $updatedUpsertDoc1['headers']['status-code']);
        $this->assertEquals($createDate, $updatedUpsertDoc1['body']['$createdAt']);
        $this->assertEquals($updateDate, $updatedUpsertDoc1['body']['$updatedAt']);

        // Test 3: Upsert new document with both custom dates
        $upsertDoc2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => 'upsert2',
            'data' => [
                'string' => 'upsert2_both_dates',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::write(Role::any()),
                    Permission::update(Role::any()),
                ],
                '$createdAt' => $createDate,
                '$updatedAt' => $updateDate
            ],
        ]);

        $this->assertEquals(201, $upsertDoc2['headers']['status-code']);
        $this->assertEquals($createDate, $upsertDoc2['body']['$createdAt']);
        $this->assertEquals($updateDate, $upsertDoc2['body']['$updatedAt']);

        // Test 4: Upsert existing document with different dates
        $updatedUpsertDoc2 = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/upsert2', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'data' => [
                'string' => 'upsert2_updated',
                '$createdAt' => $date3,
                '$updatedAt' => $date3,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::write(Role::any()),
                    Permission::update(Role::any()),
                ],
            ]
        ]);

        $this->assertEquals(200, $updatedUpsertDoc2['headers']['status-code']);
        $this->assertEquals($date3, $updatedUpsertDoc2['body']['$createdAt']);
        $this->assertEquals($date3, $updatedUpsertDoc2['body']['$updatedAt']);

        // Test 5: Bulk upsert operations with custom dates
        $upsertDocuments = [
            [
                '$id' => 'bulk_upsert1',
                'string' => 'bulk_upsert1_initial',
                '$createdAt' => $createDate
            ],
            [
                '$id' => 'bulk_upsert2',
                'string' => 'bulk_upsert2_initial',
                '$updatedAt' => $updateDate
            ],
            [
                '$id' => 'bulk_upsert3',
                'string' => 'bulk_upsert3_initial',
                '$createdAt' => $createDate,
                '$updatedAt' => $updateDate
            ],
            [
                '$id' => 'bulk_upsert4',
                'string' => 'bulk_upsert4_initial'
            ]
        ];

        // Create documents using bulk upsert
        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documents' => $upsertDocuments
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(4, $response['body']['documents']);

        // Test 7: Verify initial bulk upsert state
        foreach (['bulk_upsert1', 'bulk_upsert3'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($createDate, $doc['body']['$createdAt'], "createdAt mismatch for $id");
        }

        foreach (['bulk_upsert2', 'bulk_upsert3'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($updateDate, $doc['body']['$updatedAt'], "updatedAt mismatch for $id");
        }

        foreach (['bulk_upsert4'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertNotEmpty($doc['body']['$createdAt'], "createdAt missing for $id");
            $this->assertNotEmpty($doc['body']['$updatedAt'], "updatedAt missing for $id");
        }

        // Test 8: Bulk upsert update with custom dates
        $newDate = '2000-04-01T12:00:00.000+00:00';
        $updateUpsertData = [
            'data' => [
                'string' => 'bulk_upsert_updated',
                '$createdAt' => $newDate,
                '$updatedAt' => $newDate
            ],
            'queries' => [Query::equal('$id', ['bulk_upsert1','bulk_upsert2','bulk_upsert3','bulk_upsert4'])->toString()]
        ];

        $upsertIds = ['bulk_upsert1', 'bulk_upsert2', 'bulk_upsert3', 'bulk_upsert4'];

        // Use bulk update instead of individual updates
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), $updateUpsertData);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(4, $response['body']['documents']);

        // Verify updated state
        foreach ($upsertIds as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($newDate, $doc['body']['$createdAt'], "createdAt mismatch for $id");
            $this->assertEquals($newDate, $doc['body']['$updatedAt'], "updatedAt mismatch for $id");
            $this->assertEquals('bulk_upsert_updated', $doc['body']['string'], "string mismatch for $id");
        }

        // Test 9: checking by passing null to each
        $updateUpsertDataNull = [
            'data' => [
                'string' => 'bulk_upsert_null_test',
                '$createdAt' => null,
                '$updatedAt' => null
            ],
            'queries' => [Query::equal('$id', ['bulk_upsert1','bulk_upsert2','bulk_upsert3','bulk_upsert4'])->toString()]
        ];

        // Use bulk update instead of individual updates
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), $updateUpsertDataNull);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(4, $response['body']['documents']);

        // Verify null handling
        foreach ($upsertIds as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertNotEmpty($doc['body']['$createdAt'], "createdAt missing for $id");
            $this->assertNotEmpty($doc['body']['$updatedAt'], "updatedAt missing for $id");
        }

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialBulkOperations(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial Bulk Operations Test Database'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $databaseId = $database['body']['$id'];

        // Create collection with spatial attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Spatial Bulk Operations Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create string attribute
        $nameAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $nameAttribute['headers']['status-code']);

        // Create point attribute
        $pointAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/point', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'location',
            'required' => true,
        ]);

        $this->assertEquals(202, $pointAttribute['headers']['status-code']);

        // Create polygon attribute
        $polygonAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/polygon', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'area',
            'required' => false,
        ]);

        $this->assertEquals(202, $polygonAttribute['headers']['status-code']);

        // Wait for attributes to be created
        sleep(2);

        // Test 1: Bulk create with spatial data
        $spatialDocuments = [];
        for ($i = 0; $i < 5; $i++) {
            $spatialDocuments[] = [
                '$id' => ID::unique(),
                'name' => 'Location ' . $i,
                'location' => [10.0 + $i, 20.0 + $i], // POINT
                'area' => [
                    [10.0 + $i, 20.0 + $i],
                    [11.0 + $i, 20.0 + $i],
                    [11.0 + $i, 21.0 + $i],
                    [10.0 + $i, 21.0 + $i],
                    [10.0 + $i, 20.0 + $i]
                ] // POLYGON
            ];
        }

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => $spatialDocuments,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertCount(5, $response['body']['documents']);

        // Verify created documents have proper spatial data
        foreach ($response['body']['documents'] as $index => $document) {
            $this->assertNotEmpty($document['$id']);
            $this->assertNotEmpty($document['name']);
            $this->assertIsArray($document['location']);
            $this->assertIsArray($document['area']);
            $this->assertCount(2, $document['location']); // POINT has 2 coordinates

            // Check polygon structure - it might be stored as an array of arrays
            if (is_array($document['area'][0])) {
                $this->assertGreaterThan(1, count($document['area'][0])); // POLYGON has multiple points
            } else {
                $this->assertGreaterThan(1, count($document['area'])); // POLYGON has multiple points
            }

            $this->assertEquals('Location ' . $index, $document['name']);
            $this->assertEquals([10.0 + $index, 20.0 + $index], $document['location']);
        }

        // Test 2: Bulk update with spatial data
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'Updated Location',
                'location' => [15.0, 25.0], // New POINT
                'area' => [
                    [15.0, 25.0],
                    [16.0, 25.0],
                    [16.0, 26.0],
                    [15.0, 26.0],
                    [15.0, 25.0]
                ] // New POLYGON
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(5, $response['body']['documents']);

        // Verify updated documents
        foreach ($response['body']['documents'] as $document) {
            $this->assertEquals('Updated Location', $document['name']);
            $this->assertEquals([15.0, 25.0], $document['location']);
            // The area might be stored as an array of arrays, so check the first element
            $this->assertIsArray($document['area']);
            if (is_array($document['area'][0])) {
                // If it's an array of arrays, check the first polygon
                $this->assertEquals([
                    [15.0, 25.0],
                    [16.0, 25.0],
                    [16.0, 26.0],
                    [15.0, 26.0],
                    [15.0, 25.0]
                ], $document['area'][0]);
            } else {
                // If it's a direct array, check the whole thing
                $this->assertEquals([
                    [15.0, 25.0],
                    [16.0, 25.0],
                    [16.0, 26.0],
                    [15.0, 26.0],
                    [15.0, 25.0]
                ], $document['area']);
            }
        }

        // Test 3: Bulk upsert with spatial data
        $upsertDocuments = [
            [
                '$id' => 'upsert1',
                'name' => 'Upsert Location 1',
                'location' => [30.0, 40.0],
                'area' => [
                    [30.0, 40.0],
                    [31.0, 40.0],
                    [31.0, 41.0],
                    [30.0, 41.0],
                    [30.0, 40.0]
                ]
            ],
            [
                '$id' => 'upsert2',
                'name' => 'Upsert Location 2',
                'location' => [35.0, 45.0],
                'area' => [
                    [35.0, 45.0],
                    [36.0, 45.0],
                    [36.0, 46.0],
                    [35.0, 46.0],
                    [35.0, 45.0]
                ]
            ]
        ];

        $response = $this->client->call(Client::METHOD_PUT, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => $upsertDocuments,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['documents']);

        // Verify upserted documents
        foreach ($response['body']['documents'] as $document) {
            $this->assertNotEmpty($document['$id']);
            $this->assertIsArray($document['location']);
            $this->assertIsArray($document['area']);

            // Verify the spatial data structure
            $this->assertCount(2, $document['location']); // POINT has 2 coordinates
            if (is_array($document['area'][0])) {
                $this->assertGreaterThan(1, count($document['area'][0])); // POLYGON has multiple points
            } else {
                $this->assertGreaterThan(1, count($document['area'])); // POLYGON has multiple points
            }
        }

        // Test 4: Edge cases for spatial bulk operations

        // Test 4a: Invalid point coordinates (should fail)
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'name' => 'Invalid Point',
                    'location' => [1000.0, 2000.0],
                    'area' => [10.0 , 10.0]
                ]
            ],
        ]);

        // invalid polygon
        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'name' => 'Invalid Polygon',
                    'location' => [10.0, 20.0],
                    'area' => [
                        [10.0, 20.0],
                        [11.0, 20.0]
                    ]
                ]
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'name' => 'Missing Location',
                    // Missing required 'location' attribute
                    'area' => [
                        [10.0, 20.0],
                        [11.0, 20.0],
                        [11.0, 21.0],
                        [10.0, 21.0],
                        [10.0, 20.0]
                    ]
                ]
            ],
        ]);

        // This should fail due to missing required attribute
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 4e: Mixed valid and invalid documents in bulk (should fail)
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'name' => 'Valid Document',
                    'location' => [10.0, 20.0],
                    'area' => [
                        [10.0, 20.0],
                        [11.0, 20.0],
                        [11.0, 21.0],
                        [10.0, 21.0],
                        [10.0, 20.0]
                    ]
                ],
                [
                    '$id' => ID::unique(),
                    'name' => 'Invalid Document',
                    // Missing required 'location' attribute
                    'area' => [
                        [10.0, 20.0],
                        [11.0, 20.0],
                        [11.0, 21.0],
                        [10.0, 21.0],
                        [10.0, 20.0]
                    ]
                ]
            ],
        ]);

        // This should fail due to mixed valid/invalid documents
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 4f: Very large spatial data (stress test)
        $largePolygon = [];
        for ($i = 0; $i < 1000; $i++) {
            $largePolygon[] = [$i * 0.001, $i * 0.001];
        }
        $largePolygon[] = [0, 0]; // Close the polygon

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'name' => 'Large Polygon Test',
                    'location' => [0.0, 0.0],
                    'area' => $largePolygon
                ]
            ],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Test 4g: Null values in spatial attributes (should fail)
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'name' => 'Null Values Test',
                    'location' => null, // Null point
                    'area' => null // Null polygon
                ]
            ],
        ]);

        // This should fail due to null values
        $this->assertEquals(400, $response['headers']['status-code']);

        // Test 4h: Bulk operations with spatial data exceeding limits
        $largeBulkDocuments = [];
        for ($i = 0; $i < 100; $i++) {
            $largeBulkDocuments[] = [
                '$id' => ID::unique(),
                'name' => 'Bulk Test ' . $i,
                'location' => [$i * 0.1, $i * 0.1],
                'area' => [
                    [$i * 0.1, $i * 0.1],
                    [($i + 1) * 0.1, $i * 0.1],
                    [($i + 1) * 0.1, ($i + 1) * 0.1],
                    [$i * 0.1, ($i + 1) * 0.1],
                    [$i * 0.1, $i * 0.1]
                ]
            ];
        }

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => $largeBulkDocuments,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSpatialBulkOperationsWithLineStrings(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Spatial LineString Bulk Operations Test Database'
        ]);

        $this->assertNotEmpty($database['body']['$id']);
        $databaseId = $database['body']['$id'];

        // Create collection with line string attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Spatial LineString Bulk Operations Collection',
            'documentSecurity' => true,
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        // Create string attribute
        $nameAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals(202, $nameAttribute['headers']['status-code']);

        // Create line string attribute
        $lineAttribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/line', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'path',
            'required' => true,
        ]);

        // Handle both 201 (created) and 202 (accepted) status codes
        $this->assertEquals(202, $lineAttribute['headers']['status-code']);

        // Wait for attributes to be created
        sleep(2);

        // Test bulk create with line string data
        $lineStringDocuments = [];
        for ($i = 0; $i < 3; $i++) {
            $lineStringDocuments[] = [
                '$id' => ID::unique(),
                'name' => 'Path ' . $i,
                'path' => [
                    [$i * 10, $i * 10],
                    [($i + 1) * 10, ($i + 1) * 10],
                    [($i + 2) * 10, ($i + 2) * 10]
                ] // LINE STRING with 3 points
            ];
        }

        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => $lineStringDocuments,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['documents']);

        // Verify created documents have proper line string data
        foreach ($response['body']['documents'] as $index => $document) {
            $this->assertNotEmpty($document['$id']);
            $this->assertNotEmpty($document['name']);
            $this->assertIsArray($document['path']);
            $this->assertGreaterThan(1, count($document['path'])); // LINE STRING has multiple points
            $this->assertEquals('Path ' . $index, $document['name']);
            $this->assertCount(3, $document['path']); // Each line string has 3 points
        }

        // Test bulk update with line string data
        $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'name' => 'Updated Path',
                'path' => [
                    [0, 0],
                    [50, 50],
                    [80, 80]
                ] // New LINE STRING
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['documents']);

        // Verify updated documents
        foreach ($response['body']['documents'] as $document) {
            $this->assertEquals('Updated Path', $document['name']);
            $this->assertEquals([
                [0, 0],
                [50, 50],
                [80, 80]
            ], $document['path']);
        }

        // Test: Invalid line string (single point - should fail)
        $response = $this->client->call(Client::METHOD_POST, "/databases/{$databaseId}/collections/{$collectionId}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documents' => [
                [
                    '$id' => ID::unique(),
                    'name' => 'Invalid Line String',
                    'path' => [[10, 20]] // Single point - invalid line string
                ]
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testCreateCollectionWithAttributesAndIndexes(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Test Multi Create',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Test: Create collection with attributes and indexes in one call
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('movies'),
            'name' => 'Movies',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                    'required' => true,
                ],
                [
                    'key' => 'year',
                    'type' => Database::VAR_INTEGER,
                    'required' => false,
                    'default' => 2024,
                ],
                [
                    'key' => 'rating',
                    'type' => Database::VAR_FLOAT,
                    'required' => false,
                ],
                [
                    'key' => 'active',
                    'type' => Database::VAR_BOOLEAN,
                    'required' => false,
                    'default' => true,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_title',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['title'],
                ],
                [
                    'key' => 'idx_year',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['year'],
                    'orders' => ['DESC'],
                ],
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals('Movies', $collection['body']['name']);
        $this->assertEquals('movies', $collection['body']['$id']);

        // Verify attributes were created and are available
        $attributes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/movies/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $attributes['headers']['status-code']);
        $this->assertEquals(4, $attributes['body']['total']);

        $attrByKey = [];
        foreach ($attributes['body']['attributes'] as $attr) {
            $attrByKey[$attr['key']] = $attr;
        }

        $this->assertEquals('available', $attrByKey['title']['status']);
        $this->assertEquals(Database::VAR_STRING, $attrByKey['title']['type']);
        $this->assertEquals(256, $attrByKey['title']['size']);
        $this->assertTrue($attrByKey['title']['required']);

        $this->assertEquals('available', $attrByKey['year']['status']);
        $this->assertEquals(Database::VAR_INTEGER, $attrByKey['year']['type']);
        $this->assertFalse($attrByKey['year']['required']);
        $this->assertEquals(2024, $attrByKey['year']['default']);

        $this->assertEquals('available', $attrByKey['rating']['status']);
        $this->assertEquals(Database::VAR_FLOAT, $attrByKey['rating']['type']);

        $this->assertEquals('available', $attrByKey['active']['status']);
        $this->assertEquals(Database::VAR_BOOLEAN, $attrByKey['active']['type']);
        $this->assertTrue($attrByKey['active']['default']);

        // Verify indexes were created and are available
        $indexes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/movies/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $indexes['headers']['status-code']);
        $this->assertEquals(2, $indexes['body']['total']);

        $idxByKey = [];
        foreach ($indexes['body']['indexes'] as $idx) {
            $idxByKey[$idx['key']] = $idx;
        }

        $this->assertEquals('available', $idxByKey['idx_title']['status']);
        $this->assertEquals(Database::INDEX_KEY, $idxByKey['idx_title']['type']);
        $this->assertEquals(['title'], $idxByKey['idx_title']['attributes']);

        $this->assertEquals('available', $idxByKey['idx_year']['status']);
        $this->assertEquals(Database::INDEX_KEY, $idxByKey['idx_year']['type']);
        $this->assertEquals(['year'], $idxByKey['idx_year']['attributes']);
        $this->assertEquals(['DESC'], $idxByKey['idx_year']['orders']);

        // Verify we can create documents using the attributes
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/movies/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'The Matrix',
                'year' => 1999,
                'rating' => 8.7,
                'active' => true,
            ],
        ]);

        $this->assertEquals(201, $document['headers']['status-code']);
        $this->assertEquals('The Matrix', $document['body']['title']);
        $this->assertEquals(1999, $document['body']['year']);
        $this->assertEquals(8.7, $document['body']['rating']);
        $this->assertTrue($document['body']['active']);

        // Test: Create document with default values
        $document2 = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/movies/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'New Movie',
            ],
        ]);

        $this->assertEquals(201, $document2['headers']['status-code']);
        $this->assertEquals('New Movie', $document2['body']['title']);
        $this->assertEquals(2024, $document2['body']['year']); // default value
        $this->assertTrue($document2['body']['active']); // default value

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testCreateCollectionWithAttributesAndIndexesErrors(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Test Multi Create Errors',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Test: Invalid attribute type
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Invalid Type',
            'attributes' => [
                [
                    'key' => 'test',
                    'type' => 'invalid_type',
                    'size' => 256,
                ],
            ],
        ]);

        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Index referencing non-existent attribute
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Invalid Index',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_invalid',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['nonexistent'],
                ],
            ],
        ]);

        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: String attribute without size (should fail - size is required for strings)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'No Size',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                ],
            ],
        ]);

        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Required attribute with default value (should fail)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Required With Default',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                    'required' => true,
                    'default' => 'test',
                ],
            ],
        ]);

        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Duplicate attribute keys
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Duplicate Keys',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 128,
                ],
            ],
        ]);

        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Index on system attribute ($id)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'System Attr Index',
            'attributes' => [],
            'indexes' => [
                [
                    'key' => 'idx_id',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['$id'],
                ],
            ],
        ]);

        // Should succeed - system attributes can be indexed
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Test: Relationship attributes not supported inline (rejected as invalid type)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Relationship Test',
            'attributes' => [
                [
                    'key' => 'related',
                    'type' => 'relationship',
                    'relatedCollection' => 'some_collection',
                    'relationType' => 'oneToOne',
                ],
            ],
        ]);

        $this->assertEquals(400, $collection['headers']['status-code']);
        $this->assertStringContainsString('Invalid type', $collection['body']['message']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testCreateCollectionCleanupOnFailure(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Test Cleanup',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collectionId = ID::unique();

        // Test: Create collection with invalid index referencing non-existent attribute (should fail)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => $collectionId,
            'name' => 'Should Fail',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_invalid',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['nonexistent'],
                ],
            ],
        ]);

        $this->assertEquals(400, $collection['headers']['status-code']);

        // Verify collection was cleaned up - creating with same ID should succeed
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => $collectionId,
            'name' => 'Should Succeed',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testCreateCollectionWithEnumAttribute(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Test Enum',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Test: Create collection with enum attribute
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::custom('status_collection'),
            'name' => 'Status Collection',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
            'attributes' => [
                [
                    'key' => 'status',
                    'type' => Database::VAR_STRING,
                    'size' => 32,
                    'required' => true,
                    'format' => 'enum',
                    'elements' => ['pending', 'active', 'completed', 'cancelled'],
                ],
            ],
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);

        // Verify attribute
        $attributes = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/status_collection/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $attributes['headers']['status-code']);
        $this->assertEquals(1, $attributes['body']['total']);
        $this->assertEquals('available', $attributes['body']['attributes'][0]['status']);
        $this->assertEquals('enum', $attributes['body']['attributes'][0]['format']);
        $this->assertEquals(['pending', 'active', 'completed', 'cancelled'], $attributes['body']['attributes'][0]['elements']);

        // Test creating document with valid enum value
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/status_collection/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::unique(),
            'data' => [
                'status' => 'active',
            ],
        ]);

        $this->assertEquals(201, $document['headers']['status-code']);
        $this->assertEquals('active', $document['body']['status']);

        // Test creating document with invalid enum value
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/status_collection/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'documentId' => ID::unique(),
            'data' => [
                'status' => 'invalid_status',
            ],
        ]);

        $this->assertEquals(400, $document['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testCreateCollectionAttributeValidationEdgeCases(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Test Attribute Edge Cases',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Test: Reserved attribute key ($id)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Reserved Key Test',
            'attributes' => [
                [
                    'key' => '$id',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Reserved attribute key ($createdAt)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Reserved Key Test 2',
            'attributes' => [
                [
                    'key' => '$createdAt',
                    'type' => Database::VAR_DATETIME,
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Integer default value with wrong type (string instead of int)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Wrong Default Type',
            'attributes' => [
                [
                    'key' => 'count',
                    'type' => Database::VAR_INTEGER,
                    'default' => 'not_an_integer',
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Boolean default value with wrong type
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Wrong Boolean Default',
            'attributes' => [
                [
                    'key' => 'active',
                    'type' => Database::VAR_BOOLEAN,
                    'default' => 'yes',
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: min > max for integer
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Min Greater Than Max',
            'attributes' => [
                [
                    'key' => 'score',
                    'type' => Database::VAR_INTEGER,
                    'min' => 100,
                    'max' => 10,
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Default value outside min/max range
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Default Out of Range',
            'attributes' => [
                [
                    'key' => 'score',
                    'type' => Database::VAR_INTEGER,
                    'min' => 0,
                    'max' => 100,
                    'default' => 150,
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: String default exceeds size
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Default Exceeds Size',
            'attributes' => [
                [
                    'key' => 'name',
                    'type' => Database::VAR_STRING,
                    'size' => 5,
                    'default' => 'This is way too long for size 5',
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: 'signed' on non-numeric type
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Signed On String',
            'attributes' => [
                [
                    'key' => 'name',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                    'signed' => true,
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Array attribute with default value
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Array With Default',
            'attributes' => [
                [
                    'key' => 'tags',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                    'array' => true,
                    'default' => ['tag1', 'tag2'],
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Format on non-string type (format is only allowed for strings)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Format On Integer',
            'attributes' => [
                [
                    'key' => 'count',
                    'type' => Database::VAR_INTEGER,
                    'format' => 'enum',
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Valid integer with min/max range and default within range (should succeed)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Valid Range',
            'attributes' => [
                [
                    'key' => 'score',
                    'type' => Database::VAR_INTEGER,
                    'min' => 0,
                    'max' => 100,
                    'default' => 50,
                ],
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testCreateCollectionEnumValidationEdgeCases(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Test Enum Edge Cases',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Test: Enum with empty elements array
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Empty Enum Elements',
            'attributes' => [
                [
                    'key' => 'status',
                    'type' => Database::VAR_STRING,
                    'size' => 32,
                    'format' => 'enum',
                    'elements' => [],
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Enum with empty string element
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Empty String Element',
            'attributes' => [
                [
                    'key' => 'status',
                    'type' => Database::VAR_STRING,
                    'size' => 32,
                    'format' => 'enum',
                    'elements' => ['active', '', 'inactive'],
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Enum default not in elements
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Default Not In Elements',
            'attributes' => [
                [
                    'key' => 'status',
                    'type' => Database::VAR_STRING,
                    'size' => 32,
                    'format' => 'enum',
                    'elements' => ['active', 'inactive'],
                    'default' => 'pending',
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Enum with valid default in elements (should succeed)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Valid Enum Default',
            'attributes' => [
                [
                    'key' => 'status',
                    'type' => Database::VAR_STRING,
                    'size' => 32,
                    'format' => 'enum',
                    'elements' => ['active', 'inactive', 'pending'],
                    'default' => 'pending',
                ],
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testCreateCollectionIndexValidationEdgeCases(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Test Index Edge Cases',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Test: Duplicate index keys
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Duplicate Index Keys',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_title',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['title'],
                ],
                [
                    'key' => 'idx_title',
                    'type' => Database::INDEX_UNIQUE,
                    'attributes' => ['title'],
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Invalid index type
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Invalid Index Type',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_title',
                    'type' => 'invalid_type',
                    'attributes' => ['title'],
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Empty attributes array in index
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Empty Index Attributes',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_empty',
                    'type' => Database::INDEX_KEY,
                    'attributes' => [],
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Orders array length mismatch
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Orders Length Mismatch',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
                [
                    'key' => 'year',
                    'type' => Database::VAR_INTEGER,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_compound',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['title', 'year'],
                    'orders' => ['ASC'], // Only one order for two attributes
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Lengths array length mismatch
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Lengths Mismatch',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
                [
                    'key' => 'description',
                    'type' => Database::VAR_STRING,
                    'size' => 1024,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_compound',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['title', 'description'],
                    'lengths' => [100], // Only one length for two attributes
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Invalid order value
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Invalid Order',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_title',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['title'],
                    'orders' => ['INVALID'],
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Valid compound index with proper orders/lengths (should succeed)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Valid Compound Index',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
                [
                    'key' => 'year',
                    'type' => Database::VAR_INTEGER,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_compound',
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['title', 'year'],
                    'orders' => ['ASC', 'DESC'],
                ],
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testCreateCollectionDatetimeValidation(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Test Datetime Validation',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Test: Invalid datetime default (not ISO 8601)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Invalid Datetime Default',
            'attributes' => [
                [
                    'key' => 'publishedAt',
                    'type' => Database::VAR_DATETIME,
                    'default' => 'not-a-date',
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Valid datetime with ISO 8601 default (should succeed)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Valid Datetime',
            'attributes' => [
                [
                    'key' => 'publishedAt',
                    'type' => Database::VAR_DATETIME,
                ],
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testCreateCollectionFloatValidation(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Test Float Validation',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Test: Float with min > max
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Float Min Greater Max',
            'attributes' => [
                [
                    'key' => 'price',
                    'type' => Database::VAR_FLOAT,
                    'min' => 100.50,
                    'max' => 10.25,
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Float default outside range
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Float Default Out of Range',
            'attributes' => [
                [
                    'key' => 'price',
                    'type' => Database::VAR_FLOAT,
                    'min' => 0.0,
                    'max' => 100.0,
                    'default' => 150.50,
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Valid float with range (should succeed)
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Valid Float Range',
            'attributes' => [
                [
                    'key' => 'price',
                    'type' => Database::VAR_FLOAT,
                    'min' => 0.0,
                    'max' => 1000.0,
                    'default' => 99.99,
                ],
            ],
        ]);
        $this->assertEquals(201, $collection['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testCreateCollectionMissingRequiredFields(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Test Missing Fields',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        // Test: Attribute without key
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Missing Attribute Key',
            'attributes' => [
                [
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Attribute without type
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Missing Attribute Type',
            'attributes' => [
                [
                    'key' => 'title',
                    'size' => 256,
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Index without key
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Missing Index Key',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
            'indexes' => [
                [
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['title'],
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Index without type
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Missing Index Type',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_title',
                    'attributes' => ['title'],
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Test: Index without attributes
        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Missing Index Attributes',
            'attributes' => [
                [
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                ],
            ],
            'indexes' => [
                [
                    'key' => 'idx_title',
                    'type' => Database::INDEX_KEY,
                ],
            ],
        ]);
        $this->assertEquals(400, $collection['headers']['status-code']);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }
}
