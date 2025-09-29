<?php

namespace Tests\E2E\Services\Databases\DocumentsDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
        $test1 = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::custom('first'),
            'name' => 'Test 1',
        ]);

        $this->assertEquals(201, $test1['headers']['status-code']);
        $this->assertEquals('Test 1', $test1['body']['name']);

        $test2 = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::custom('second'),
            'name' => 'Test 2',
        ]);
        $this->assertEquals(201, $test2['headers']['status-code']);
        $this->assertEquals('Test 2', $test2['body']['name']);

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(2, $databases['body']['total']);
        $this->assertEquals($test1['body']['$id'], $databases['body']['databases'][0]['$id']);
        $this->assertEquals($test2['body']['$id'], $databases['body']['databases'][1]['$id']);

        $base = array_reverse($databases['body']['databases']);

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);
        $this->assertEquals(200, $databases['headers']['status-code']);
        $this->assertCount(1, $databases['body']['databases']);

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);
        $this->assertEquals(200, $databases['headers']['status-code']);
        $this->assertCount(1, $databases['body']['databases']);

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('name', ['Test 1', 'Test 2'])->toString(),
            ],
        ]);
        $this->assertEquals(200, $databases['headers']['status-code']);
        $this->assertCount(2, $databases['body']['databases']);

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('name', ['Test 2'])->toString(),
            ],
        ]);
        $this->assertEquals(200, $databases['headers']['status-code']);
        $this->assertCount(1, $databases['body']['databases']);

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
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
        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
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
        $base = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['databases'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(1, $databases['body']['databases']);
        $this->assertEquals($base['body']['databases'][1]['$id'], $databases['body']['databases'][0]['$id']);

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
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
        $base = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['databases'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(1, $databases['body']['databases']);
        $this->assertEquals($base['body']['databases'][0]['$id'], $databases['body']['databases'][0]['$id']);

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
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
        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'first'
        ]);

        $this->assertEquals(1, $databases['body']['total']);
        $this->assertEquals('first', $databases['body']['databases'][0]['$id']);

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Test'
        ]);

        $this->assertEquals(2, $databases['body']['total']);
        $this->assertEquals('Test 1', $databases['body']['databases'][0]['name']);
        $this->assertEquals('Test 2', $databases['body']['databases'][1]['name']);

        $databases = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Nonexistent'
        ]);

        $this->assertEquals(0, $databases['body']['total']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // This collection already exists
        $response = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
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
        $database = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId, [
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

        $database = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId, [
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
        $database = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId, [
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

        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals("", $response['body']);

        // Try to get the collection and check if it has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testListCollections(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
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
        $test1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $test2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(2, $collections['body']['total']);
        $this->assertEquals($test1['body']['$id'], $collections['body']['collections'][0]['$id']);
        $this->assertEquals($test1['body']['enabled'], $collections['body']['collections'][0]['enabled']);
        $this->assertEquals($test2['body']['$id'], $collections['body']['collections'][1]['$id']);
        $this->assertEquals($test1['body']['enabled'], $collections['body']['collections'][0]['enabled']);

        $base = array_reverse($collections['body']['collections']);

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $collections['headers']['status-code']);
        $this->assertCount(1, $collections['body']['collections']);

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(1)->toString(),
            ],
        ]);

        $this->assertEquals(200, $collections['headers']['status-code']);
        $this->assertCount(1, $collections['body']['collections']);

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::equal('enabled', [true])->toString(),
            ],
        ]);

        $this->assertEquals(200, $collections['headers']['status-code']);
        $this->assertCount(2, $collections['body']['collections']);

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
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
        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
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
        $base = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => $base['body']['collections'][0]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(1, $collections['body']['collections']);
        $this->assertEquals($base['body']['collections'][1]['$id'], $collections['body']['collections'][0]['$id']);

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
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
        $base = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorBefore(new Document(['$id' => $base['body']['collections'][1]['$id']]))->toString(),
            ],
        ]);

        $this->assertCount(1, $collections['body']['collections']);
        $this->assertEquals($base['body']['collections'][0]['$id'], $collections['body']['collections'][0]['$id']);

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
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
        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'first'
        ]);

        $this->assertEquals(1, $collections['body']['total']);
        $this->assertEquals('first', $collections['body']['collections'][0]['$id']);

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Test'
        ]);

        $this->assertEquals(2, $collections['body']['total']);
        $this->assertEquals('Test 1', $collections['body']['collections'][0]['name']);
        $this->assertEquals('Test 2', $collections['body']['collections'][1]['name']);

        $collections = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Nonexistent'
        ]);

        $this->assertEquals(0, $collections['body']['total']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::cursorAfter(new Document(['$id' => 'unknown']))->toString(),
            ],
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // This collection already exists
        $response = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $collection = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
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

        $collection = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
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

    public function testCleanupDuplicateIndexOnDeleteAttribute()
    {
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
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
        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $index1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'index1',
            'type' => 'key',
            'attributes' => ['attribute1', 'attribute2'],
            'orders' => ['ASC', 'ASC'],
        ]);

        $index2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
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

        $collection = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertCount(2, $collection['body']['indexes']);
        $this->assertEquals($index1['body']['key'], $collection['body']['indexes'][0]['key']);
        $this->assertEquals($index2['body']['key'], $collection['body']['indexes'][1]['key']);
        $this->assertIsArray($collection['body']['indexes'][0]['attributes']);
        $this->assertCount(2, $collection['body']['indexes'][0]['attributes']);
        $this->assertEquals('attribute1', $collection['body']['indexes'][0]['attributes'][0]);
        $this->assertEquals('attribute2', $collection['body']['indexes'][0]['attributes'][1]);
    }

    /**
     * @depends testDeleteIndexOnDeleteAttribute
     */
    public function testDeleteCollection($data)
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];

        // Add Documents to the collection
        $document1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $document2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals($response['body'], "");

        // Try to get the collection and check if it has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);
    }

    public function testIndexLimitException()
    {
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
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
        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $collection = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertEquals($collection['body']['name'], 'testLimitException');
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertCount(0, $collection['body']['indexes']);

        // no limit in the documentsdb as we dont store attribute
        for ($i = 0; $i < 58; $i++) {
            $index = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/indexes', array_merge([
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

        $collection = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $collection['headers']['status-code']);
        $this->assertEquals($collection['body']['name'], 'testLimitException');
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertCount(58, $collection['body']['indexes']);

        $collection = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $collection['headers']['status-code']);
    }

    public function testBulkCreate(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Create Perms',
        ]);

        $this->assertNotEmpty($database['body']['$id']);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $response = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
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

        $response = $this->client->call(Client::METHOD_GET, "/documentsdb/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(1, $response['body']['documents'][0]['number']);
        $this->assertEquals(2, $response['body']['documents'][1]['number']);
        $this->assertEquals(3, $response['body']['documents'][2]['number']);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['documents']);

        // TEST SUCCESS - $id is auto-assigned if not included in bulk documents
        $response = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
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
        $response = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
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
        $response = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
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
        $response = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
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

        // missing number attribute should pass
        $response = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
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

        $this->assertEquals(201, $response['headers']['status-code']);

        // TEST FAIL - Can't push more than APP_LIMIT_DATABASE_BATCH documents
        $response = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
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
        $response = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$data['$id']}/documents", array_merge([
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
    }

    public function testBulkUpdate(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Updates'
        ]);

        $this->assertNotEmpty($database['body']['$id']);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        // Create documents
        $createBulkDocuments = function ($amount = 10) use ($data) {
            $documents = [];

            for ($x = 1; $x <= $amount; $x++) {
                $documents[] = [
                    '$id' => ID::unique(),
                    'number' => $x,
                ];
            }

            $doc = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            Query::equal('number', [100])->toString(),
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(10, $documents['body']['total']);

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
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'number' => 200
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(10, $response['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('number', [200])->toString()]
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(5, $documents['body']['total']);

        // TEST: Update documents with offset
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('number', [300])->toString()]
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(10, $documents['body']['total']);

        // TEST: Update documents with equals filter
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::equal('number', [400])->toString()]
        ]);

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(10, $documents['body']['total']);
    }

    public function testBulkUpsert(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Upserts'
        ]);

        $this->assertNotEmpty($database['body']['$id']);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        // Create documents
        $createBulkDocuments = function ($amount = 10) use ($data) {
            $documents = [];

            for ($x = 1; $x <= $amount; $x++) {
                $documents[] = [
                    '$id' => "$x",
                    'number' => $x,
                ];
            }

            $response = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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
        $response = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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
        $response = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
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
    }

    public function testBulkDelete(): void
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Deletes'
        ]);

        $this->assertNotEmpty($database['body']['$id']);

        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        // Create documents
        $createBulkDocuments = function ($amount = 11) use ($data) {
            $documents = [];

            for ($x = 0; $x < $amount; $x++) {
                $documents[] = [
                    '$id' => ID::unique(),
                    'number' => $x,
                ];
            }

            $doc = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'documents' => $documents,
            ]);

            $this->assertEquals(201, $doc['headers']['status-code']);
        };

        $createBulkDocuments();

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        // TEST: Delete all documents
        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(11, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(0, $documents['body']['total']);

        // TEST: Delete documents with query
        $createBulkDocuments();

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::lessThan('number', 5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(6, $documents['body']['total']);

        foreach ($documents['body']['documents'] as $document) {
            $this->assertGreaterThanOrEqual(5, $document['number']);
        }

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, $response['body']['total']);

        // SUCCESS: Delete documents with query
        $createBulkDocuments();

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::lessThan('number', 5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(6, $documents['body']['total']);

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, $response['body']['total']);

        // SUCCESS: Delete Documents with limit query
        $createBulkDocuments();

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(2)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(9, $documents['body']['total']);

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(9, $response['body']['total']);

        // SUCCESS: Delete Documents with offset query
        $createBulkDocuments();

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(11, $documents['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::offset(5)->toString(),
            ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(5, $documents['body']['total']);

        $lastDoc = end($documents['body']['documents']);

        $this->assertNotEmpty($lastDoc);
        $this->assertEquals(4, $lastDoc['number']);

        // Cleanup
        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(5, $response['body']['total']);

        // SUCCESS: Delete 100 documents
        $createBulkDocuments(100);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(100, $documents['body']['total']);

        $response = $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(100, $response['body']['total']);

        $documents = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $data['databaseId'] . '/collections/' . $data['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $documents['headers']['status-code']);
        $this->assertEquals(0, $documents['body']['total']);
    }

    public function testDateTimeDocument(): void
    {
        $databaseId = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'DateTime Test Database',
        ]);

        $this->assertEquals(201, $databaseId['headers']['status-code']);
        $databaseId = $databaseId['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $date = '2000-01-01T10:00:00.000+00:00';

        // Test - default behaviour of external datetime attribute not changed
        $doc = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
        $this->assertEmpty($doc['body']['datetime']);
        $this->assertNotEmpty($doc['body']['$createdAt']);
        $this->assertNotEmpty($doc['body']['$updatedAt']);

        $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/doc1', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $doc['headers']['status-code']);
        $this->assertEmpty($doc['body']['datetime']);
        $this->assertNotEmpty($doc['body']['$createdAt']);
        $this->assertNotEmpty($doc['body']['$updatedAt']);

        // Test - modifying $createdAt and $updatedAt
        $doc = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/doc2', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $doc['headers']['status-code']);
        $this->assertEquals($doc['body']['$createdAt'], $date);
        $this->assertNotEmpty($doc['body']['$updatedAt']);
        $this->assertNotEquals($doc['body']['$updatedAt'], $date);

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testSingleDocumentDateOperations(): void
    {
        $databaseId = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Single Date Operations Database',
        ]);

        $this->assertEquals(201, $databaseId['headers']['status-code']);
        $databaseId = $databaseId['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $createDate = '2000-01-01T10:00:00.000+00:00';
        $updateDate = '2000-02-01T15:30:00.000+00:00';
        $date1 = '2000-01-01T10:00:00.000+00:00';
        $date2 = '2000-02-01T15:30:00.000+00:00';
        $date3 = '2000-03-01T20:45:00.000+00:00';

        // Test 1: Create with custom createdAt, then update with custom updatedAt
        $doc = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
        $updatedDoc = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/doc1', array_merge([
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
        $doc2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
        $doc3 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $updatedDoc3 = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/doc3', array_merge([
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
        $doc4 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $updatedDoc4 = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/doc4', array_merge([
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
        $finalDoc4 = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/doc4', array_merge([
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
        $doc5 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $updatedDoc5 = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/doc5', array_merge([
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
        $doc6 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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

        $updatedDoc6 = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/doc6', array_merge([
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
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testBulkDocumentDateOperations(): void
    {
        $databaseId = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Bulk Date Operations Database',
        ]);

        $this->assertEquals(201, $databaseId['headers']['status-code']);
        $databaseId = $databaseId['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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
        $bulkCreateResponse = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($createDate, $doc['body']['$createdAt'], "createdAt mismatch for $id");
        }

        foreach (['doc2', 'doc3'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($updateDate, $doc['body']['$updatedAt'], "updatedAt mismatch for $id");
        }

        foreach (['doc4', 'doc5', 'doc6'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
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
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), $updateData);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(6, $response['body']['documents']);

        // Verify updated state
        foreach (['doc1', 'doc3'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($createDate, $doc['body']['$createdAt'], "createdAt mismatch for $id");
            $this->assertEquals($updateDate, $doc['body']['$updatedAt'], "updatedAt mismatch for $id");
            $this->assertEquals('updated', $doc['body']['string'], "string mismatch for $id");
        }

        foreach (['doc2', 'doc4', 'doc5', 'doc6'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
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
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), $updateDataEnabled);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(6, $response['body']['documents']);

        // Verify final state
        foreach (['doc1', 'doc2', 'doc3', 'doc4', 'doc5', 'doc6'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($newDate, $doc['body']['$createdAt'], "createdAt mismatch for $id");
            $this->assertEquals($newDate, $doc['body']['$updatedAt'], "updatedAt mismatch for $id");
            $this->assertEquals('enabled_update', $doc['body']['string'], "string mismatch for $id");
        }

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

    public function testUpsertDateOperations(): void
    {
        $databaseId = $this->client->call(Client::METHOD_POST, '/documentsdb', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Upsert Date Operations Database',
        ]);

        $this->assertEquals(201, $databaseId['headers']['status-code']);
        $databaseId = $databaseId['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', array_merge([
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

        $createDate = '2000-01-01T10:00:00.000+00:00';
        $updateDate = '2000-02-01T15:30:00.000+00:00';
        $date1 = '2000-01-01T10:00:00.000+00:00';
        $date2 = '2000-02-01T15:30:00.000+00:00';
        $date3 = '2000-03-01T20:45:00.000+00:00';

        // Test 1: Upsert new document with custom createdAt
        $upsertDoc1 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
        $updatedUpsertDoc1 = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/upsert1', array_merge([
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
        $upsertDoc2 = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
        $updatedUpsertDoc2 = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/upsert2', array_merge([
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
        $response = $this->client->call(Client::METHOD_PUT, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
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
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($createDate, $doc['body']['$createdAt'], "createdAt mismatch for $id");
        }

        foreach (['bulk_upsert2', 'bulk_upsert3'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertEquals($updateDate, $doc['body']['$updatedAt'], "updatedAt mismatch for $id");
        }

        foreach (['bulk_upsert4'] as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
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
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), $updateUpsertData);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(4, $response['body']['documents']);

        // Verify updated state
        foreach ($upsertIds as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
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
        $response = $this->client->call(Client::METHOD_PATCH, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), $updateUpsertDataNull);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(4, $response['body']['documents']);

        // Verify null handling
        foreach ($upsertIds as $id) {
            $doc = $this->client->call(Client::METHOD_GET, '/documentsdb/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $id, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $doc['headers']['status-code']);
            $this->assertNotEmpty($doc['body']['$createdAt'], "createdAt missing for $id");
            $this->assertNotEmpty($doc['body']['$updatedAt'], "updatedAt missing for $id");
        }

        // Cleanup
        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId . '/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->client->call(Client::METHOD_DELETE, '/documentsdb/' . $databaseId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));
    }

}
