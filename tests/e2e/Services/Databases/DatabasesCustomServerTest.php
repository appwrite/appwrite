<?php

namespace Tests\E2E\Services\Databases;

use Appwrite\Extend\Exception as AppwriteException;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
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

        $lastName = $this->client->call(Client::METHOD_POST, $attributesPath . '/string', array_merge([
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
        $attribute = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $actors ['body']['$id'] . '/attributes/' . $unneededId, array_merge([
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

    // Adds several minutes to test to replicate coverage in Utopia\Database unit tests
    // and messes with subsequent tests as DatabaseV1 queue gets overwhelmed
    // TODO@kodumbeats either fix or remove testAttributeCountLimit
    // Options to fix:
    // - Enable attribute creation in batches
    // - Use additional database workers
    // - Wait for worker to complete before moving onto next test
    // - Remove since this is unit tested in Utopia\Database
    //
    // public function testAttributeCountLimit()
    // {
    //     $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ]), [
    //         'collectionId' => ID::unique(),
    //         'name' => 'attributeCountLimit',
    //         'read' => ['any'],
    //         'write' => ['any'],
    //         'documentSecurity' => true,
    //     ]);

    //     $collectionId = $collection['body']['$id'];

    //     // load the collection up to the limit
    //     for ($i=0; $i < 1012; $i++) {
    //         $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
    //             'content-type' => 'application/json',
    //             'x-appwrite-project' => $this->getProject()['$id'],
    //             'x-appwrite-key' => $this->getProject()['apiKey']
    //         ]), [
    //             'key' => "attribute{$i}",
    //             'required' => false,
    //         ]);

    //         $this->assertEquals(201, $attribute['headers']['status-code']);
    //     }

    //     sleep(30);

    //     $tooMany = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/integer', array_merge([
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ]), [
    //         'key' => "tooMany",
    //         'required' => false,
    //     ]);

    //     $this->assertEquals(400, $tooMany['headers']['status-code']);
    //     $this->assertEquals('Attribute limit exceeded', $tooMany['body']['message']);
    // }

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
        $this->assertEquals('Attribute limit exceeded', $tooWide['body']['message']);
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

        foreach ($collection['body']['attributes'] as $attribute) {
            $this->assertEquals('available', $attribute['status'], 'attribute: ' . $attribute['key']);
        }

        // Test indexLimit = 64
        // MariaDB, MySQL, and MongoDB create 5 indexes per new collection
        // Add up to the limit, then check if the next index throws IndexLimitException
        for ($i = 0; $i < 58; $i++) {
            // $this->assertEquals(true, static::getDatabase()->createIndex('indexLimit', "index{$i}", Database::INDEX_KEY, ["test{$i}"], [16]));
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
        $this->assertEquals('Index limit exceeded', $tooMany['body']['message']);

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
            'default' => 100,
            'min' => 0,
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
            'max' => 0,
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
            'default' => 123.456,
            'min' => 0.0,
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
            'max' => 0.0,
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
}
