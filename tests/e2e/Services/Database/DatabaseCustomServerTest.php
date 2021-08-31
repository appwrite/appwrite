<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Client;

class DatabaseCustomServerTest extends Scope
{
    use DatabaseBase;
    use ProjectCustom;
    use SideServer;

    public function testListCollections()
    {
        /**
         * Test for SUCCESS
         */
        $test1 = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 1',
            'collectionId' => 'first',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document'
        ]);

        $test2 = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test 2',
            'collectionId' => 'second',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document'
        ]);

        $collections = $this->client->call(Client::METHOD_GET, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(2, $collections['body']['sum']);
        $this->assertEquals($test1['body']['$id'], $collections['body']['collections'][0]['$id']);
        $this->assertEquals($test2['body']['$id'], $collections['body']['collections'][1]['$id']);

        /**
         * Test for Order
         */
        $base = array_reverse($collections['body']['collections']);
        $collections = $this->client->call(Client::METHOD_GET, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderType' => 'DESC'
        ]);

        $this->assertEquals(2, $collections['body']['sum']);
        $this->assertEquals($base[0]['$id'], $collections['body']['collections'][0]['$id']);
        $this->assertEquals($base[1]['$id'], $collections['body']['collections'][1]['$id']);

        /**
         * Test for After
         */
        $base = $this->client->call(Client::METHOD_GET, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $collections = $this->client->call(Client::METHOD_GET, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'after' => $base['body']['collections'][0]['$id']
        ]);

        $this->assertCount(1, $collections['body']['collections']);
        $this->assertEquals($base['body']['collections'][1]['$id'], $collections['body']['collections'][0]['$id']);

        $collections = $this->client->call(Client::METHOD_GET, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'after' => $base['body']['collections'][1]['$id']
        ]);

        $this->assertCount(0, $collections['body']['collections']);
        $this->assertEmpty($collections['body']['collections']);
    }

    public function testDeleteAttribute(): array
    {
        /**
         * Test for SUCCESS
         */

        // Create collection
        $actors = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'Actors',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document'
        ]);

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertEquals($actors['body']['name'], 'Actors');

        $firstName = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actors['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'firstName',
            'size' => 256,
            'required' => true,
        ]);

        $lastName = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actors['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'lastName',
            'size' => 256,
            'required' => true,
        ]);

        $unneeded = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actors['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'unneeded',
            'size' => 256,
            'required' => true,
        ]);

        // Wait for database worker to finish creating attributes
        sleep(2);

        $index = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actors['body']['$id'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'indexId' => 'key_lastName',
            'type' => 'key',
            'attributes' => [
                'lastName',
            ],
        ]);

        // Wait for database worker to finish creating index
        sleep(2);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []); 

        $unneededId = $unneeded['body']['key'];

        $this->assertEquals($collection['headers']['status-code'], 200);
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertCount(3, $collection['body']['attributes']);
        $this->assertEquals($collection['body']['attributes'][0]['key'], $firstName['body']['key']);
        $this->assertEquals($collection['body']['attributes'][1]['key'], $lastName['body']['key']);
        $this->assertEquals($collection['body']['attributes'][2]['key'], $unneeded['body']['key']);
        $this->assertCount(1, $collection['body']['indexes']);
        $this->assertEquals($collection['body']['indexes'][0]['key'], $index['body']['key']);

        // Delete attribute
        $attribute = $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $actors ['body']['$id'] . '/attributes/' . $unneededId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals($attribute['headers']['status-code'], 204);

        sleep(2);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []); 

        $this->assertEquals($collection['headers']['status-code'], 200);
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertCount(2, $collection['body']['attributes']);
        $this->assertEquals($collection['body']['attributes'][0]['key'], $firstName['body']['key']);
        $this->assertEquals($collection['body']['attributes'][1]['key'], $lastName['body']['key']);

        return [
            'collectionId' => $actors['body']['$id'],
            'indexId' => $index['body']['key'],
        ];
    }

    /**
     * @depends testDeleteAttribute
     */
    public function testDeleteIndex($data): array
    {
        $index = $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $data['collectionId'] . '/indexes/'. $data['indexId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals($index['headers']['status-code'], 204);

        // Wait for database worker to finish deleting index
        sleep(2);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['collectionId'], array_merge([
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
    public function testDeleteCollection($data)
    {
        $collectionId = $data['collectionId'];

        // Add Documents to the collection
        $document1 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'firstName' => 'Tom',
                'lastName' => 'Holland',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $document2 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'firstName' => 'Samuel',
                'lastName' => 'Jackson',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $this->assertEquals($document1['headers']['status-code'], 201);
        $this->assertIsArray($document1['body']['$read']);
        $this->assertIsArray($document1['body']['$write']);
        $this->assertCount(1, $document1['body']['$read']);
        $this->assertCount(1, $document1['body']['$write']);
        $this->assertEquals($document1['body']['firstName'], 'Tom');
        $this->assertEquals($document1['body']['lastName'], 'Holland');

        $this->assertEquals($document2['headers']['status-code'], 201);
        $this->assertIsArray($document2['body']['$read']);
        $this->assertIsArray($document2['body']['$write']);
        $this->assertCount(1, $document2['body']['$read']);
        $this->assertCount(1, $document2['body']['$write']);
        $this->assertEquals($document2['body']['firstName'], 'Samuel');
        $this->assertEquals($document2['body']['lastName'], 'Jackson');

        // Delete the actors collection
        $response = $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $collectionId , array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 204);
        $this->assertEquals($response['body'],"");

        // Try to get the collection and check if it has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId , array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 404);
    }

    public function testAttributeCountLimit()
    {
        $collection = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'attributeCountLimit',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document',
        ]);

        $collectionId = $collection['body']['$id'];

        // load the collection up to the limit
        for ($i=0; $i < 1012; $i++) {
            $attribute = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'attributeId' => "attribute{$i}",
                'required' => false,
            ]);

            $this->assertEquals(201, $attribute['headers']['status-code']);
        }

        sleep(5);

        $tooMany = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => "tooMany",
            'required' => false,
        ]);

        $this->assertEquals(400, $tooMany['headers']['status-code']);
        $this->assertEquals('Attribute limit exceeded', $tooMany['body']['message']);
    }

    public function testAttributeRowWidthLimit()
    {
        $collection = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'attributeRowWidthLimit',
            'name' => 'attributeRowWidthLimit',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document',
        ]);

        $this->assertEquals($collection['headers']['status-code'], 201);
        $this->assertEquals($collection['body']['name'], 'attributeRowWidthLimit');

        $collectionId = $collection['body']['$id'];

        // Add wide string attributes to approach row width limit
        for ($i=0; $i < 15; $i++) {
            $attribute = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/string', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'attributeId' => "attribute{$i}",
                'size' => 1024,
                'required' => true,
            ]);

            $this->assertEquals($attribute['headers']['status-code'], 201);
        }

        sleep(5);

        $tooWide = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'tooWide',
            'size' => 1024,
            'required' => true,
        ]);

        // $this->assertEquals(400, $tooWide['headers']['status-code']);
        // $this->assertEquals('Attribute limit exceeded', $tooWide['body']['message']);
    }

    public function testIndexLimitException()
    {
        $collection = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'testLimitException',
            'name' => 'testLimitException',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document',
        ]);

        $this->assertEquals($collection['headers']['status-code'], 201);
        $this->assertEquals($collection['body']['name'], 'testLimitException');

        $collectionId = $collection['body']['$id'];

        // add unique attributes for indexing
        for ($i=0; $i < 64; $i++) {
            // $this->assertEquals(true, static::getDatabase()->createAttribute('indexLimit', "test{$i}", Database::VAR_STRING, 16, true));
            $attribute = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/string', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'attributeId' => "attribute{$i}",
                'size' => 64,
                'required' => true,
            ]);

            $this->assertEquals($attribute['headers']['status-code'], 201);
        }

        sleep(5);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals($collection['headers']['status-code'], 200);
        $this->assertEquals($collection['body']['name'], 'testLimitException');
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertCount(64, $collection['body']['attributes']);
        $this->assertCount(0, $collection['body']['indexes']);

        // testing for indexLimit = 64
        // MariaDB, MySQL, and MongoDB create 3 indexes per new collection
        // Add up to the limit, then check if the next index throws IndexLimitException
        for ($i=0; $i < 61; $i++) {
            // $this->assertEquals(true, static::getDatabase()->createIndex('indexLimit', "index{$i}", Database::INDEX_KEY, ["test{$i}"], [16]));
            $index = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/indexes', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'indexId' => "key_attribute{$i}",
                'type' => 'key',
                'attributes' => ["attribute{$i}"],
            ]);

            $this->assertEquals(201, $index['headers']['status-code']);
            $this->assertEquals("key_attribute{$i}", $index['body']['key']);
        }

        sleep(5);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals($collection['headers']['status-code'], 200);
        $this->assertEquals($collection['body']['name'], 'testLimitException');
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertCount(64, $collection['body']['attributes']);
        $this->assertCount(61, $collection['body']['indexes']);

        $tooMany = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'indexId' => 'tooMany',
            'type' => 'key',
            'attributes' => ['attribute61'],
        ]);

        $this->assertEquals(400, $tooMany['headers']['status-code']);
        $this->assertEquals('Index limit exceeded', $tooMany['body']['message']);
    }
}