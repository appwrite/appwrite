<?php

namespace Tests\E2E\Adapter\Scopes;

use Exception;
use Utopia\Cache\Adapter\None as NoneCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQL;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait CollectionTests
{
    public function testCreateExistsDelete(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForSchemas()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->assertEquals(true, $database->exists($this->testDatabase));
        $this->assertEquals(true, $database->delete($this->testDatabase));
        $this->assertEquals(false, $database->exists($this->testDatabase));
        $this->assertEquals(true, $database->create());
    }

    /**
     * @depends testCreateExistsDelete
     */
    public function testCreateListExistsDeleteCollection(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->assertInstanceOf('Utopia\Database\Document', $database->createCollection('actors', permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ]));
        $this->assertCount(1, $database->listCollections());
        $this->assertEquals(true, $database->exists($this->testDatabase, 'actors'));

        // Collection names should not be unique
        $this->assertInstanceOf('Utopia\Database\Document', $database->createCollection('actors2', permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ]));
        $this->assertCount(2, $database->listCollections());
        $this->assertEquals(true, $database->exists($this->testDatabase, 'actors2'));
        $collection = $database->getCollection('actors2');
        $collection->setAttribute('name', 'actors'); // change name to one that exists
        $this->assertInstanceOf('Utopia\Database\Document', $database->updateDocument(
            $collection->getCollection(),
            $collection->getId(),
            $collection
        ));
        $this->assertEquals(true, $database->deleteCollection('actors2')); // Delete collection when finished
        $this->assertCount(1, $database->listCollections());

        $this->assertEquals(false, $database->getCollection('actors')->isEmpty());
        $this->assertEquals(true, $database->deleteCollection('actors'));
        $this->assertEquals(true, $database->getCollection('actors')->isEmpty());
        $this->assertEquals(false, $database->exists($this->testDatabase, 'actors'));
    }

    public function testDatabaseHostname(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();


        if (!$database->getAdapter()->getSupportForHostname()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $host = $database->getAdapter()->getHostname();
        $this->assertContains($host, ['mysql', 'mariadb', 'postgres', 'mongo']);
    }

    public function testCreateCollectionWithSchema(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $attributes = [
            new Document([
                '$id' => ID::custom('attribute1'),
                'type' => Database::VAR_STRING,
                'size' => 256,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('attribute2'),
                'type' => Database::VAR_INTEGER,
                'size' => 0,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('attribute3'),
                'type' => Database::VAR_BOOLEAN,
                'size' => 0,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('attribute4'),
                'type' => Database::VAR_ID,
                'size' => 0,
                'required' => false,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ]),
        ];

        $indexes = [
            new Document([
                '$id' => ID::custom('index1'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute1'],
                'lengths' => [256],
                'orders' => ['ASC'],
            ]),
            new Document([
                '$id' => ID::custom('index2'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute2'],
                'lengths' => [],
                'orders' => ['DESC'],
            ]),
            new Document([
                '$id' => ID::custom('index3'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute3', 'attribute2'],
                'lengths' => [],
                'orders' => ['DESC', 'ASC'],
            ]),
            new Document([
                '$id' => ID::custom('index4'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute4'],
                'lengths' => [],
                'orders' => ['DESC'],
            ]),
        ];

        $collection = $database->createCollection('withSchema', $attributes, $indexes);

        $this->assertEquals(false, $collection->isEmpty());
        $this->assertEquals('withSchema', $collection->getId());

        $this->assertIsArray($collection->getAttribute('attributes'));
        $this->assertCount(4, $collection->getAttribute('attributes'));
        $this->assertEquals('attribute1', $collection->getAttribute('attributes')[0]['$id']);
        $this->assertEquals(Database::VAR_STRING, $collection->getAttribute('attributes')[0]['type']);
        $this->assertEquals('attribute2', $collection->getAttribute('attributes')[1]['$id']);
        $this->assertEquals(Database::VAR_INTEGER, $collection->getAttribute('attributes')[1]['type']);
        $this->assertEquals('attribute3', $collection->getAttribute('attributes')[2]['$id']);
        $this->assertEquals(Database::VAR_BOOLEAN, $collection->getAttribute('attributes')[2]['type']);
        $this->assertEquals('attribute4', $collection->getAttribute('attributes')[3]['$id']);
        $this->assertEquals(Database::VAR_ID, $collection->getAttribute('attributes')[3]['type']);

        $this->assertIsArray($collection->getAttribute('indexes'));
        $this->assertCount(4, $collection->getAttribute('indexes'));
        $this->assertEquals('index1', $collection->getAttribute('indexes')[0]['$id']);
        $this->assertEquals(Database::INDEX_KEY, $collection->getAttribute('indexes')[0]['type']);
        $this->assertEquals('index2', $collection->getAttribute('indexes')[1]['$id']);
        $this->assertEquals(Database::INDEX_KEY, $collection->getAttribute('indexes')[1]['type']);
        $this->assertEquals('index3', $collection->getAttribute('indexes')[2]['$id']);
        $this->assertEquals(Database::INDEX_KEY, $collection->getAttribute('indexes')[2]['type']);
        $this->assertEquals('index4', $collection->getAttribute('indexes')[3]['$id']);
        $this->assertEquals(Database::INDEX_KEY, $collection->getAttribute('indexes')[3]['type']);


        $database->deleteCollection('withSchema');

        // Test collection with dash (+attribute +index)
        $collection2 = $database->createCollection('with-dash', [
            new Document([
                '$id' => ID::custom('attribute-one'),
                'type' => Database::VAR_STRING,
                'size' => 256,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
        ], [
            new Document([
                '$id' => ID::custom('index-one'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute-one'],
                'lengths' => [256],
                'orders' => ['ASC'],
            ])
        ]);

        $this->assertEquals(false, $collection2->isEmpty());
        $this->assertEquals('with-dash', $collection2->getId());
        $this->assertIsArray($collection2->getAttribute('attributes'));
        $this->assertCount(1, $collection2->getAttribute('attributes'));
        $this->assertEquals('attribute-one', $collection2->getAttribute('attributes')[0]['$id']);
        $this->assertEquals(Database::VAR_STRING, $collection2->getAttribute('attributes')[0]['type']);
        $this->assertIsArray($collection2->getAttribute('indexes'));
        $this->assertCount(1, $collection2->getAttribute('indexes'));
        $this->assertEquals('index-one', $collection2->getAttribute('indexes')[0]['$id']);
        $this->assertEquals(Database::INDEX_KEY, $collection2->getAttribute('indexes')[0]['type']);
        $database->deleteCollection('with-dash');
    }

    public function testCreateCollectionValidator(): void
    {
        $collections = [
            "validatorTest",
            "validator-test",
            "validator_test",
            "validator.test",
        ];

        $attributes = [
            new Document([
                '$id' => ID::custom('attribute1'),
                'type' => Database::VAR_STRING,
                'size' => 2500, // longer than 768
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('attribute-2'),
                'type' => Database::VAR_INTEGER,
                'size' => 0,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('attribute_3'),
                'type' => Database::VAR_BOOLEAN,
                'size' => 0,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('attribute.4'),
                'type' => Database::VAR_BOOLEAN,
                'size' => 0,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('attribute5'),
                'type' => Database::VAR_STRING,
                'size' => 2500,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ])
        ];

        $indexes = [
            new Document([
                '$id' => ID::custom('index1'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute1'],
                'lengths' => [256],
                'orders' => ['ASC'],
            ]),
            new Document([
                '$id' => ID::custom('index-2'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute-2'],
                'lengths' => [],
                'orders' => ['ASC'],
            ]),
            new Document([
                '$id' => ID::custom('index_3'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute_3'],
                'lengths' => [],
                'orders' => ['ASC'],
            ]),
            new Document([
                '$id' => ID::custom('index.4'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute.4'],
                'lengths' => [],
                'orders' => ['ASC'],
            ]),
            new Document([
                '$id' => ID::custom('index_2_attributes'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute1', 'attribute5'],
                'lengths' => [200, 300],
                'orders' => ['DESC'],
            ]),
        ];

        /** @var Database $database */
        $database = $this->getDatabase();

        foreach ($collections as $id) {
            $collection = $database->createCollection($id, $attributes, $indexes);

            $this->assertEquals(false, $collection->isEmpty());
            $this->assertEquals($id, $collection->getId());

            $this->assertIsArray($collection->getAttribute('attributes'));
            $this->assertCount(5, $collection->getAttribute('attributes'));
            $this->assertEquals('attribute1', $collection->getAttribute('attributes')[0]['$id']);
            $this->assertEquals(Database::VAR_STRING, $collection->getAttribute('attributes')[0]['type']);
            $this->assertEquals('attribute-2', $collection->getAttribute('attributes')[1]['$id']);
            $this->assertEquals(Database::VAR_INTEGER, $collection->getAttribute('attributes')[1]['type']);
            $this->assertEquals('attribute_3', $collection->getAttribute('attributes')[2]['$id']);
            $this->assertEquals(Database::VAR_BOOLEAN, $collection->getAttribute('attributes')[2]['type']);
            $this->assertEquals('attribute.4', $collection->getAttribute('attributes')[3]['$id']);
            $this->assertEquals(Database::VAR_BOOLEAN, $collection->getAttribute('attributes')[3]['type']);

            $this->assertIsArray($collection->getAttribute('indexes'));
            $this->assertCount(5, $collection->getAttribute('indexes'));
            $this->assertEquals('index1', $collection->getAttribute('indexes')[0]['$id']);
            $this->assertEquals(Database::INDEX_KEY, $collection->getAttribute('indexes')[0]['type']);
            $this->assertEquals('index-2', $collection->getAttribute('indexes')[1]['$id']);
            $this->assertEquals(Database::INDEX_KEY, $collection->getAttribute('indexes')[1]['type']);
            $this->assertEquals('index_3', $collection->getAttribute('indexes')[2]['$id']);
            $this->assertEquals(Database::INDEX_KEY, $collection->getAttribute('indexes')[2]['type']);
            $this->assertEquals('index.4', $collection->getAttribute('indexes')[3]['$id']);
            $this->assertEquals(Database::INDEX_KEY, $collection->getAttribute('indexes')[3]['type']);

            $database->deleteCollection($id);
        }
    }


    public function testCollectionNotFound(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        try {
            $database->find('not_exist', []);
            $this->fail('Failed to throw Exception');
        } catch (Exception $e) {
            $this->assertEquals('Collection not found', $e->getMessage());
        }
    }

    public function testSizeCollection(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('sizeTest1');
        $database->createCollection('sizeTest2');

        $size1 = $database->getSizeOfCollection('sizeTest1');
        $size2 = $database->getSizeOfCollection('sizeTest2');
        $sizeDifference = abs($size1 - $size2);
        // Size of an empty collection returns either 172032 or 167936 bytes randomly
        // Therefore asserting with a tolerance of 5000 bytes
        $byteDifference = 5000;

        if (!$database->analyzeCollection('sizeTest2')) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->assertLessThan($byteDifference, $sizeDifference);

        $database->createAttribute('sizeTest2', 'string1', Database::VAR_STRING, 20000, true);
        $database->createAttribute('sizeTest2', 'string2', Database::VAR_STRING, 254 + 1, true);
        $database->createAttribute('sizeTest2', 'string3', Database::VAR_STRING, 254 + 1, true);
        $database->createIndex('sizeTest2', 'index', Database::INDEX_KEY, ['string1', 'string2', 'string3'], [128, 128, 128]);

        $loopCount = 100;

        for ($i = 0; $i < $loopCount; $i++) {
            $database->createDocument('sizeTest2', new Document([
                '$id' => 'doc' . $i,
                'string1' => 'string1' . $i . str_repeat('A', 10000),
                'string2' => 'string2',
                'string3' => 'string3',
            ]));
        }

        $database->analyzeCollection('sizeTest2');

        $size2 = $this->getDatabase()->getSizeOfCollection('sizeTest2');

        $this->assertGreaterThan($size1, $size2);

        $this->getDatabase()->getAuthorization()->skip(function () use ($loopCount) {
            for ($i = 0; $i < $loopCount; $i++) {
                $this->getDatabase()->deleteDocument('sizeTest2', 'doc' . $i);
            }
        });

        sleep(5);

        $database->analyzeCollection('sizeTest2');

        $size3 = $this->getDatabase()->getSizeOfCollection('sizeTest2');

        $this->assertLessThan($size2, $size3);
    }

    public function testSizeCollectionOnDisk(): void
    {
        $this->getDatabase()->createCollection('sizeTestDisk1');
        $this->getDatabase()->createCollection('sizeTestDisk2');

        $size1 = $this->getDatabase()->getSizeOfCollectionOnDisk('sizeTestDisk1');
        $size2 = $this->getDatabase()->getSizeOfCollectionOnDisk('sizeTestDisk2');
        $sizeDifference = abs($size1 - $size2);
        // Size of an empty collection returns either 172032 or 167936 bytes randomly
        // Therefore asserting with a tolerance of 5000 bytes
        $byteDifference = 5000;
        $this->assertLessThan($byteDifference, $sizeDifference);

        $this->getDatabase()->createAttribute('sizeTestDisk2', 'string1', Database::VAR_STRING, 20000, true);
        $this->getDatabase()->createAttribute('sizeTestDisk2', 'string2', Database::VAR_STRING, 254 + 1, true);
        $this->getDatabase()->createAttribute('sizeTestDisk2', 'string3', Database::VAR_STRING, 254 + 1, true);
        $this->getDatabase()->createIndex('sizeTestDisk2', 'index', Database::INDEX_KEY, ['string1', 'string2', 'string3'], [128, 128, 128]);

        $loopCount = 40;

        for ($i = 0; $i < $loopCount; $i++) {
            $this->getDatabase()->createDocument('sizeTestDisk2', new Document([
                'string1' => 'string1' . $i,
                'string2' => 'string2' . $i,
                'string3' => 'string3' . $i,
            ]));
        }

        $size2 = $this->getDatabase()->getSizeOfCollectionOnDisk('sizeTestDisk2');

        $this->assertGreaterThan($size1, $size2);
    }

    public function testSizeFullText(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        // SQLite does not support fulltext indexes
        if (!$database->getAdapter()->getSupportForFulltextIndex()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('fullTextSizeTest');

        $size1 = $database->getSizeOfCollection('fullTextSizeTest');

        $database->createAttribute('fullTextSizeTest', 'string1', Database::VAR_STRING, 128, true);
        $database->createAttribute('fullTextSizeTest', 'string2', Database::VAR_STRING, 254, true);
        $database->createAttribute('fullTextSizeTest', 'string3', Database::VAR_STRING, 254, true);
        $database->createIndex('fullTextSizeTest', 'index', Database::INDEX_KEY, ['string1', 'string2', 'string3'], [128, 128, 128]);

        $loopCount = 10;

        for ($i = 0; $i < $loopCount; $i++) {
            $database->createDocument('fullTextSizeTest', new Document([
                'string1' => 'string1' . $i,
                'string2' => 'string2' . $i,
                'string3' => 'string3' . $i,
            ]));
        }

        $size2 = $database->getSizeOfCollectionOnDisk('fullTextSizeTest');

        $this->assertGreaterThan($size1, $size2);

        $database->createIndex('fullTextSizeTest', 'fulltext_index', Database::INDEX_FULLTEXT, ['string1']);

        $size3 = $database->getSizeOfCollectionOnDisk('fullTextSizeTest');

        $this->assertGreaterThan($size2, $size3);
    }

    public function testPurgeCollectionCache(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('redis');

        $this->assertEquals(true, $database->createAttribute('redis', 'name', Database::VAR_STRING, 128, true));
        $this->assertEquals(true, $database->createAttribute('redis', 'age', Database::VAR_INTEGER, 0, true));

        $database->createDocument('redis', new Document([
            '$id' => 'doc1',
            'name' => 'Richard',
            'age' => 15,
            '$permissions' => [
                Permission::read(Role::any()),
            ]
        ]));

        $document = $database->getDocument('redis', 'doc1');

        $this->assertEquals('Richard', $document->getAttribute('name'));
        $this->assertEquals(15, $document->getAttribute('age'));

        $this->assertEquals(true, $database->deleteAttribute('redis', 'age'));

        $document = $database->getDocument('redis', 'doc1');
        $this->assertEquals('Richard', $document->getAttribute('name'));
        $this->assertArrayNotHasKey('age', $document);

        $this->assertEquals(true, $database->createAttribute('redis', 'age', Database::VAR_INTEGER, 0, true));

        $document = $database->getDocument('redis', 'doc1');
        $this->assertEquals('Richard', $document->getAttribute('name'));
        $this->assertArrayHasKey('age', $document);
    }

    public function testSchemaAttributes(): void
    {
        if (!$this->getDatabase()->getAdapter()->getSupportForSchemaAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'schema_attributes';
        $db = $this->getDatabase();

        $this->assertEmpty($db->getSchemaAttributes('no_such_collection'));

        $db->createCollection($collection);

        $db->createAttribute($collection, 'username', Database::VAR_STRING, 128, true);
        $db->createAttribute($collection, 'story', Database::VAR_STRING, 20000, true);
        $db->createAttribute($collection, 'string_list', Database::VAR_STRING, 128, true, null, true, true);
        $db->createAttribute($collection, 'dob', Database::VAR_DATETIME, 0, false, '2000-06-12T14:12:55.000+00:00', true, false, null, [], ['datetime']);

        $attributes = [];
        foreach ($db->getSchemaAttributes($collection) as $attribute) {
            /**
             * @var Document $attribute
             */

            $attributes[$attribute->getId()] = $attribute;
        }

        $attribute = $attributes['username'];
        $this->assertEquals('username', $attribute['$id']);
        $this->assertEquals('varchar', $attribute['dataType']);
        $this->assertEquals('varchar(128)', $attribute['columnType']);
        $this->assertEquals('128', $attribute['characterMaximumLength']);
        $this->assertEquals('YES', $attribute['isNullable']);

        $attribute = $attributes['story'];
        $this->assertEquals('story', $attribute['$id']);
        $this->assertEquals('text', $attribute['dataType']);
        $this->assertEquals('text', $attribute['columnType']);
        $this->assertEquals('65535', $attribute['characterMaximumLength']);

        $attribute = $attributes['string_list'];
        $this->assertEquals('string_list', $attribute['$id']);
        $this->assertTrue(in_array($attribute['dataType'], ['json', 'longtext'])); // mysql vs maria
        $this->assertTrue(in_array($attribute['columnType'], ['json', 'longtext']));
        $this->assertTrue(in_array($attribute['characterMaximumLength'], [null, '4294967295']));
        $this->assertEquals('YES', $attribute['isNullable']);

        $attribute = $attributes['dob'];
        $this->assertEquals('dob', $attribute['$id']);
        $this->assertEquals('datetime', $attribute['dataType']);
        $this->assertEquals('datetime(3)', $attribute['columnType']);
        $this->assertEquals(null, $attribute['characterMaximumLength']);
        $this->assertEquals('3', $attribute['datetimePrecision']);

        if ($db->getSharedTables()) {
            $attribute = $attributes['_tenant'];
            $this->assertEquals('_tenant', $attribute['$id']);
            $this->assertEquals('int', $attribute['dataType']);
            $this->assertEquals('10', $attribute['numericPrecision']);
            $this->assertTrue(in_array($attribute['columnType'], ['int unsigned', 'int(11) unsigned']));
        }
    }

    public function testRowSizeToLarge(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getDocumentSizeLimit() === 0) {
            $this->expectNotToPerformAssertions();
            return;
        }
        /**
         * getDocumentSizeLimit = 65535
         * 65535 / 4 = 16383 MB4
         */
        $collection_1 = $database->createCollection('row_size_1');
        $collection_2 = $database->createCollection('row_size_2');

        $this->assertEquals(true, $database->createAttribute($collection_1->getId(), 'attr_1', Database::VAR_STRING, 16000, true));

        try {
            $database->createAttribute($collection_1->getId(), 'attr_2', Database::VAR_STRING, Database::LENGTH_KEY, true);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(LimitException::class, $e);
        }

        /**
         * Relation takes length of Database::LENGTH_KEY so exceeding getDocumentSizeLimit
         */

        try {
            $database->createRelationship(
                collection: $collection_2->getId(),
                relatedCollection: $collection_1->getId(),
                type: Database::RELATION_ONE_TO_ONE,
                twoWay: true,
            );

            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(LimitException::class, $e);
        }

        try {
            $database->createRelationship(
                collection: $collection_1->getId(),
                relatedCollection: $collection_2->getId(),
                type: Database::RELATION_ONE_TO_ONE,
                twoWay: true,
            );

            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(LimitException::class, $e);
        }
    }

    public function testCreateCollectionWithSchemaIndexes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $attributes = [
            new Document([
                '$id' => ID::custom('username'),
                'type' => Database::VAR_STRING,
                'size' => 100,
                'required' => false,
                'signed' => true,
                'array' => false,
            ]),
            new Document([
                '$id' => ID::custom('cards'),
                'type' => Database::VAR_STRING,
                'size' => 5000,
                'required' => false,
                'signed' => true,
                'array' => true,
            ]),
        ];

        $indexes = [
            new Document([
                '$id' => ID::custom('idx_username'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['username'],
                'lengths' => [100], // Will be removed since equal to attributes size
                'orders' => [],
            ]),
            new Document([
                '$id' => ID::custom('idx_username_uid'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['username', '$id'], // to solve the same attribute mongo issue
                'lengths' => [99, 200], // Length not equal to attributes length
                'orders' => [Database::ORDER_DESC],
            ]),
        ];

        if ($database->getAdapter()->getSupportForIndexArray()) {
            $indexes[] = new Document([
                '$id' => ID::custom('idx_cards'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['cards'],
                'lengths' => [500], // Will be changed to Database::ARRAY_INDEX_LENGTH (255)
                'orders' => [Database::ORDER_DESC],
            ]);
        }

        $collection = $database->createCollection(
            'collection98',
            $attributes,
            $indexes,
            permissions: [
                Permission::create(Role::any()),
            ]
        );

        $this->assertEquals($collection->getAttribute('indexes')[0]['attributes'][0], 'username');
        $this->assertEquals($collection->getAttribute('indexes')[0]['lengths'][0], null);

        $this->assertEquals($collection->getAttribute('indexes')[1]['attributes'][0], 'username');
        $this->assertEquals($collection->getAttribute('indexes')[1]['lengths'][0], 99);
        $this->assertEquals($collection->getAttribute('indexes')[1]['orders'][0], Database::ORDER_DESC);

        if ($database->getAdapter()->getSupportForIndexArray()) {
            $this->assertEquals($collection->getAttribute('indexes')[2]['attributes'][0], 'cards');
            $this->assertEquals($collection->getAttribute('indexes')[2]['lengths'][0], Database::MAX_ARRAY_INDEX_LENGTH);
            $this->assertEquals($collection->getAttribute('indexes')[2]['orders'][0], null);
        }
    }

    public function testCollectionUpdate(): Document
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collection = $database->createCollection('collectionUpdate', permissions: [
            Permission::create(Role::users()),
            Permission::read(Role::users()),
            Permission::update(Role::users()),
            Permission::delete(Role::users())
        ], documentSecurity: false);

        $this->assertInstanceOf(Document::class, $collection);

        $collection = $database->getCollection('collectionUpdate');

        $this->assertFalse($collection->getAttribute('documentSecurity'));
        $this->assertIsArray($collection->getPermissions());
        $this->assertCount(4, $collection->getPermissions());

        $collection = $database->updateCollection('collectionUpdate', [], true);

        $this->assertTrue($collection->getAttribute('documentSecurity'));
        $this->assertIsArray($collection->getPermissions());
        $this->assertEmpty($collection->getPermissions());

        $collection = $database->getCollection('collectionUpdate');

        $this->assertTrue($collection->getAttribute('documentSecurity'));
        $this->assertIsArray($collection->getPermissions());
        $this->assertEmpty($collection->getPermissions());

        return $collection;
    }

    public function testUpdateDeleteCollectionNotFound(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        try {
            $database->deleteCollection('not_found');
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Collection not found', $e->getMessage());
        }

        try {
            $database->updateCollection('not_found', [], true);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Collection not found', $e->getMessage());
        }
    }

    public function testGetCollectionId(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForGetConnectionId()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->assertIsString($database->getConnectionId());
    }

    public function testKeywords(): void
    {
        $database = $this->getDatabase();
        $keywords = $database->getKeywords();

        // Collection name tests
        $attributes = [
            new Document([
                '$id' => ID::custom('attribute1'),
                'type' => Database::VAR_STRING,
                'size' => 256,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
        ];

        $indexes = [
            new Document([
                '$id' => ID::custom('index1'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute1'],
                'lengths' => [256],
                'orders' => ['ASC'],
            ]),
        ];

        foreach ($keywords as $keyword) {
            $collection = $database->createCollection($keyword, $attributes, $indexes);
            $this->assertEquals($keyword, $collection->getId());

            $document = $database->createDocument($keyword, new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                '$id' => ID::custom('helloWorld'),
                'attribute1' => 'Hello World',
            ]));
            $this->assertEquals('helloWorld', $document->getId());

            $document = $database->getDocument($keyword, 'helloWorld');
            $this->assertEquals('helloWorld', $document->getId());

            $documents = $database->find($keyword);
            $this->assertCount(1, $documents);
            $this->assertEquals('helloWorld', $documents[0]->getId());

            $collection = $database->deleteCollection($keyword);
            $this->assertTrue($collection);
        }

        // TODO: updateCollection name tests

        // Attribute name tests
        foreach ($keywords as $keyword) {
            $collectionName = 'rk' . $keyword; // rk is shorthand for reserved-keyword. We do this since there are some limits (64 chars max)

            $collection = $database->createCollection($collectionName);
            $this->assertEquals($collectionName, $collection->getId());

            $attribute = $database->createAttribute($collectionName, $keyword, Database::VAR_STRING, 128, true);
            $this->assertEquals(true, $attribute);

            $document = new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                '$id' => 'reservedKeyDocument'
            ]);
            $document->setAttribute($keyword, 'Reserved:' . $keyword);

            $document = $database->createDocument($collectionName, $document);
            $this->assertEquals('reservedKeyDocument', $document->getId());
            $this->assertEquals('Reserved:' . $keyword, $document->getAttribute($keyword));

            $document = $database->getDocument($collectionName, 'reservedKeyDocument');
            $this->assertEquals('reservedKeyDocument', $document->getId());
            $this->assertEquals('Reserved:' . $keyword, $document->getAttribute($keyword));

            $documents = $database->find($collectionName);
            $this->assertCount(1, $documents);
            $this->assertEquals('reservedKeyDocument', $documents[0]->getId());
            $this->assertEquals('Reserved:' . $keyword, $documents[0]->getAttribute($keyword));

            $documents = $database->find($collectionName, [Query::equal($keyword, ["Reserved:{$keyword}"])]);
            $this->assertCount(1, $documents);
            $this->assertEquals('reservedKeyDocument', $documents[0]->getId());

            $documents = $database->find($collectionName, [
                Query::orderDesc($keyword)
            ]);
            $this->assertCount(1, $documents);
            $this->assertEquals('reservedKeyDocument', $documents[0]->getId());

            $collection = $database->deleteCollection($collectionName);
            $this->assertTrue($collection);
        }
    }

    public function testLabels(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->assertInstanceOf('Utopia\Database\Document', $database->createCollection(
            'labels_test',
        ));
        $database->createAttribute('labels_test', 'attr1', Database::VAR_STRING, 10, false);

        $database->createDocument('labels_test', new Document([
            '$id' => 'doc1',
            'attr1' => 'value1',
            '$permissions' => [
                Permission::read(Role::label('reader')),
            ],
        ]));

        $documents = $database->find('labels_test');

        $this->assertEmpty($documents);

        $this->getDatabase()->getAuthorization()->addRole(Role::label('reader')->toString());

        $documents = $database->find('labels_test');

        $this->assertCount(1, $documents);
    }

    public function testMetadata(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->setMetadata('key', 'value');

        $database->createCollection('testers');

        $this->assertEquals(['key' => 'value'], $database->getMetadata());

        $database->resetMetadata();

        $this->assertEquals([], $database->getMetadata());
    }

    public function testDeleteCollectionDeletesRelationships(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('devices');

        $database->createRelationship(
            collection: 'testers',
            relatedCollection: 'devices',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            twoWayKey: 'tester'
        );

        $testers = $database->getCollection('testers');
        $devices = $database->getCollection('devices');

        $this->assertEquals(1, \count($testers->getAttribute('attributes')));
        $this->assertEquals(1, \count($devices->getAttribute('attributes')));
        $this->assertEquals(1, \count($devices->getAttribute('indexes')));

        $database->deleteCollection('testers');

        $testers = $database->getCollection('testers');
        $devices = $database->getCollection('devices');

        $this->assertEquals(true, $testers->isEmpty());
        $this->assertEquals(0, \count($devices->getAttribute('attributes')));
        $this->assertEquals(0, \count($devices->getAttribute('indexes')));
    }


    public function testCascadeMultiDelete(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('cascadeMultiDelete1');
        $database->createCollection('cascadeMultiDelete2');
        $database->createCollection('cascadeMultiDelete3');

        $database->createRelationship(
            collection: 'cascadeMultiDelete1',
            relatedCollection: 'cascadeMultiDelete2',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            onDelete: Database::RELATION_MUTATE_CASCADE
        );

        $database->createRelationship(
            collection: 'cascadeMultiDelete2',
            relatedCollection: 'cascadeMultiDelete3',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            onDelete: Database::RELATION_MUTATE_CASCADE
        );

        $root = $database->createDocument('cascadeMultiDelete1', new Document([
            '$id' => 'cascadeMultiDelete1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::delete(Role::any())
            ],
            'cascadeMultiDelete2' => [
                [
                    '$id' => 'cascadeMultiDelete2',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::delete(Role::any())
                    ],
                    'cascadeMultiDelete3' => [
                        [
                            '$id' => 'cascadeMultiDelete3',
                            '$permissions' => [
                                Permission::read(Role::any()),
                                Permission::delete(Role::any())
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertCount(1, $root->getAttribute('cascadeMultiDelete2'));
        $this->assertCount(1, $root->getAttribute('cascadeMultiDelete2')[0]->getAttribute('cascadeMultiDelete3'));

        $this->assertEquals(true, $database->deleteDocument('cascadeMultiDelete1', $root->getId()));

        $multi2 = $database->getDocument('cascadeMultiDelete2', 'cascadeMultiDelete2');
        $this->assertEquals(true, $multi2->isEmpty());

        $multi3 = $database->getDocument('cascadeMultiDelete3', 'cascadeMultiDelete3');
        $this->assertEquals(true, $multi3->isEmpty());
    }

    /**
     * @throws AuthorizationException
     * @throws DatabaseException
     * @throws DuplicateException
     * @throws LimitException
     * @throws QueryException
     * @throws StructureException
     * @throws TimeoutException
     */
    public function testSharedTables(): void
    {
        /**
         * Default mode already tested, we'll test 'schema' and 'table' isolation here
         */
        /** @var Database $database */
        $database = $this->getDatabase();
        $sharedTables = $database->getSharedTables();
        $namespace = $database->getNamespace();
        $schema = $database->getDatabase();

        if (!$database->getAdapter()->getSupportForSchemas()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if ($database->exists('schema1')) {
            $database->setDatabase('schema1')->delete();
        }
        if ($database->exists('schema2')) {
            $database->setDatabase('schema2')->delete();
        }
        if ($database->exists('sharedTables')) {
            $database->setDatabase('sharedTables')->delete();
        }

        /**
         * Schema
         */
        $database
            ->setDatabase('schema1')
            ->setNamespace('')
            ->create();

        $this->assertEquals(true, $database->exists('schema1'));

        $database
            ->setDatabase('schema2')
            ->setNamespace('')
            ->create();

        $this->assertEquals(true, $database->exists('schema2'));

        /**
         * Table
         */
        $tenant1 = 1;
        $tenant2 = 2;

        $database
            ->setDatabase('sharedTables')
            ->setNamespace('')
            ->setSharedTables(true)
            ->setTenant($tenant1)
            ->create();

        $this->assertEquals(true, $database->exists('sharedTables'));

        $database->createCollection('people', [
            new Document([
                '$id' => 'name',
                'type' => Database::VAR_STRING,
                'size' => 128,
                'required' => true,
            ]),
            new Document([
                '$id' => 'lifeStory',
                'type' => Database::VAR_STRING,
                'size' => 65536,
                'required' => true,
            ])
        ], [
            new Document([
                '$id' => 'idx_name',
                'type' => Database::INDEX_KEY,
                'attributes' => ['name']
            ])
        ], [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);

        $this->assertCount(1, $database->listCollections());

        if ($database->getAdapter()->getSupportForFulltextIndex()) {
            $database->createIndex(
                collection: 'people',
                id: 'idx_lifeStory',
                type: Database::INDEX_FULLTEXT,
                attributes: ['lifeStory']
            );
        }

        $docId = ID::unique();

        $database->createDocument('people', new Document([
            '$id' => $docId,
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Spiderman',
            'lifeStory' => 'Spider-Man is a superhero appearing in American comic books published by Marvel Comics.'
        ]));

        $doc = $database->getDocument('people', $docId);
        $this->assertEquals('Spiderman', $doc['name']);
        $this->assertEquals($tenant1, $doc->getTenant());

        /**
         * Remove Permissions
         */
        $doc->setAttribute('$permissions', [
            Permission::read(Role::any())
        ]);

        $database->updateDocument('people', $docId, $doc);

        $doc = $database->getDocument('people', $docId);
        $this->assertEquals([Permission::read(Role::any())], $doc['$permissions']);
        $this->assertEquals($tenant1, $doc->getTenant());

        /**
         * Add Permissions
         */
        $doc->setAttribute('$permissions', [
            Permission::read(Role::any()),
            Permission::delete(Role::any()),
        ]);

        $database->updateDocument('people', $docId, $doc);

        $doc = $database->getDocument('people', $docId);
        $this->assertEquals([Permission::read(Role::any()), Permission::delete(Role::any())], $doc['$permissions']);

        $docs = $database->find('people');
        $this->assertCount(1, $docs);

        // Swap to tenant 2, no access
        $database->setTenant($tenant2);

        try {
            $database->getDocument('people', $docId);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Collection not found', $e->getMessage());
        }

        try {
            $database->find('people');
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Collection not found', $e->getMessage());
        }

        $this->assertCount(0, $database->listCollections());

        // Swap back to tenant 1, allowed
        $database->setTenant($tenant1);

        $doc = $database->getDocument('people', $docId);
        $this->assertEquals('Spiderman', $doc['name']);
        $docs = $database->find('people');
        $this->assertEquals(1, \count($docs));

        // Remove tenant but leave shared tables enabled
        $database->setTenant(null);

        try {
            $database->getDocument('people', $docId);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Collection not found', $e->getMessage());
        }

        // Reset state
        $database
            ->setSharedTables($sharedTables)
            ->setNamespace($namespace)
            ->setDatabase($schema);
    }
    /**
     * @throws LimitException
     * @throws DuplicateException
     * @throws DatabaseException
     */
    public function testCreateDuplicates(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('duplicates', permissions: [
            Permission::read(Role::any())
        ]);

        try {
            $database->createCollection('duplicates');
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }

        $this->assertNotEmpty($database->listCollections());

        $database->deleteCollection('duplicates');
    }
    public function testSharedTablesDuplicates(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $sharedTables = $database->getSharedTables();
        $namespace = $database->getNamespace();
        $schema = $database->getDatabase();

        if (!$database->getAdapter()->getSupportForSchemas()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if ($database->exists('sharedTables')) {
            $database->setDatabase('sharedTables')->delete();
        }

        $database
            ->setDatabase('sharedTables')
            ->setNamespace('')
            ->setSharedTables(true)
            ->setTenant(null)
            ->create();

        // Create collection
        $database->createCollection('duplicates', documentSecurity: false);
        $database->createAttribute('duplicates', 'name', Database::VAR_STRING, 10, false);
        $database->createIndex('duplicates', 'nameIndex', Database::INDEX_KEY, ['name']);

        $database->setTenant(2);

        try {
            $database->createCollection('duplicates', documentSecurity: false);
        } catch (DuplicateException) {
            // Ignore
        }

        try {
            $database->createAttribute('duplicates', 'name', Database::VAR_STRING, 10, false);
        } catch (DuplicateException) {
            // Ignore
        }

        try {
            $database->createIndex('duplicates', 'nameIndex', Database::INDEX_KEY, ['name']);
        } catch (DuplicateException) {
            // Ignore
        }

        $collection = $database->getCollection('duplicates');
        $this->assertEquals(1, \count($collection->getAttribute('attributes')));
        $this->assertEquals(1, \count($collection->getAttribute('indexes')));

        $database->setTenant(1);

        $collection = $database->getCollection('duplicates');
        $this->assertEquals(1, \count($collection->getAttribute('attributes')));
        $this->assertEquals(1, \count($collection->getAttribute('indexes')));

        $database
            ->setSharedTables($sharedTables)
            ->setNamespace($namespace)
            ->setDatabase($schema);
    }

    public function testSharedTablesMultiTenantCreateCollection(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $sharedTables = $database->getSharedTables();
        $namespace = $database->getNamespace();
        $schema = $database->getDatabase();
        $originalTenant = $database->getTenant();
        $createdDb = false;

        if ($sharedTables) {
            // Already in shared-tables mode (SharedTables/* test classes)
        } elseif ($database->getAdapter()->getSupportForSchemas()) {
            $dbName = 'stMultiTenant';
            if ($database->exists($dbName)) {
                $database->setDatabase($dbName)->delete();
            }
            $database
                ->setDatabase($dbName)
                ->setNamespace('')
                ->setSharedTables(true)
                ->setTenant(10)
                ->create();
            $createdDb = true;
        } else {
            $this->expectNotToPerformAssertions();
            return;
        }

        try {
            $tenant1 = $database->getAdapter()->getIdAttributeType() === Database::VAR_INTEGER ? 10 : 'tenant_10';
            $tenant2 = $database->getAdapter()->getIdAttributeType() === Database::VAR_INTEGER ? 20 : 'tenant_20';
            $colName = 'multiTenantCol';

            $database->setTenant($tenant1);

            $database->createCollection($colName, [
                new Document([
                    '$id' => 'name',
                    'type' => Database::VAR_STRING,
                    'size' => 128,
                    'required' => true,
                ]),
            ]);

            $col1 = $database->getCollection($colName);
            $this->assertFalse($col1->isEmpty());
            $this->assertEquals(1, \count($col1->getAttribute('attributes')));

            $database->setTenant($tenant2);

            $database->createCollection($colName, [
                new Document([
                    '$id' => 'name',
                    'type' => Database::VAR_STRING,
                    'size' => 128,
                    'required' => true,
                ]),
            ]);

            $col2 = $database->getCollection($colName);
            $this->assertFalse($col2->isEmpty());
            $this->assertEquals(1, \count($col2->getAttribute('attributes')));

            $database->setTenant($tenant1);
            $col1Again = $database->getCollection($colName);
            $this->assertFalse($col1Again->isEmpty());

            if ($createdDb) {
                $database->delete();
            } else {
                $database->setTenant($tenant1);
                $database->deleteCollection($colName);
                $database->setTenant($tenant2);
                $database->deleteCollection($colName);
            }
        } finally {
            $database
                ->setSharedTables($sharedTables)
                ->setNamespace($namespace)
                ->setDatabase($schema)
                ->setTenant($originalTenant);
        }
    }

    public function testSharedTablesMultiTenantCreate(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $sharedTables = $database->getSharedTables();
        $namespace = $database->getNamespace();
        $schema = $database->getDatabase();
        $originalTenant = $database->getTenant();

        try {
            $tenant1 = $database->getAdapter()->getIdAttributeType() === Database::VAR_INTEGER ? 100 : 'tenant_100';
            $tenant2 = $database->getAdapter()->getIdAttributeType() === Database::VAR_INTEGER ? 200 : 'tenant_200';

            if ($sharedTables) {
                // Already in shared-tables mode; create() should be idempotent.
                // No assertion on exists() since SQLite always returns false for
                // database-level exists. The test verifies create() doesn't throw.
                $database->setTenant($tenant1);
                $database->create();
                $database->setTenant($tenant2);
                $database->create();
                $this->assertTrue(true);
            } elseif ($database->getAdapter()->getSupportForSchemas()) {
                $dbName = 'stMultiCreate';
                if ($database->exists($dbName)) {
                    $database->setDatabase($dbName)->delete();
                }
                $database
                    ->setDatabase($dbName)
                    ->setNamespace('')
                    ->setSharedTables(true)
                    ->setTenant($tenant1)
                    ->create();
                $this->assertTrue($database->exists($dbName));
                $database->setTenant($tenant2);
                $database->create();
                $this->assertTrue($database->exists($dbName));
                $database->delete();
            } else {
                $this->expectNotToPerformAssertions();
                return;
            }
        } finally {
            $database
                ->setSharedTables($sharedTables)
                ->setNamespace($namespace)
                ->setDatabase($schema)
                ->setTenant($originalTenant);
        }
    }

    public function testEvents(): void
    {
        $this->getDatabase()->getAuthorization()->skip(function () {
            $database = $this->getDatabase();

            $events = [
                Database::EVENT_DATABASE_CREATE,
                Database::EVENT_DATABASE_LIST,
                Database::EVENT_COLLECTION_CREATE,
                Database::EVENT_COLLECTION_LIST,
                Database::EVENT_COLLECTION_READ,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_ATTRIBUTE_CREATE,
                Database::EVENT_ATTRIBUTE_UPDATE,
                Database::EVENT_INDEX_CREATE,
                Database::EVENT_DOCUMENT_CREATE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_DOCUMENT_UPDATE,
                Database::EVENT_DOCUMENT_READ,
                Database::EVENT_DOCUMENT_FIND,
                Database::EVENT_DOCUMENT_FIND,
                Database::EVENT_DOCUMENT_COUNT,
                Database::EVENT_DOCUMENT_SUM,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_DOCUMENT_INCREASE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_DOCUMENT_DECREASE,
                Database::EVENT_DOCUMENTS_CREATE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_DOCUMENTS_UPDATE,
                Database::EVENT_INDEX_DELETE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_DOCUMENT_DELETE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_DOCUMENTS_DELETE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_ATTRIBUTE_DELETE,
                Database::EVENT_COLLECTION_DELETE,
                Database::EVENT_DATABASE_DELETE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_DOCUMENTS_DELETE,
                Database::EVENT_DOCUMENT_PURGE,
                Database::EVENT_ATTRIBUTE_DELETE,
                Database::EVENT_COLLECTION_DELETE,
                Database::EVENT_DATABASE_DELETE
            ];

            $database->on(Database::EVENT_ALL, 'test', function ($event, $data) use (&$events) {
                $shifted = array_shift($events);
                $this->assertEquals($shifted, $event);
            });

            if ($this->getDatabase()->getAdapter()->getSupportForSchemas()) {
                $database->setDatabase('hellodb');
                $database->create();
            } else {
                \array_shift($events);
            }

            $database->list();

            $database->setDatabase($this->testDatabase);

            $collectionId = ID::unique();
            $database->createCollection($collectionId);
            $database->listCollections();
            $database->getCollection($collectionId);
            $database->createAttribute($collectionId, 'attr1', Database::VAR_INTEGER, 2, false);
            $database->updateAttributeRequired($collectionId, 'attr1', true);
            $indexId1 = 'index2_' . uniqid();
            $database->createIndex($collectionId, $indexId1, Database::INDEX_KEY, ['attr1']);

            $document = $database->createDocument($collectionId, new Document([
                '$id' => 'doc1',
                'attr1' => 10,
                '$permissions' => [
                    Permission::delete(Role::any()),
                    Permission::update(Role::any()),
                    Permission::read(Role::any()),
                ],
            ]));

            $executed = false;
            $database->on(Database::EVENT_ALL, 'should-not-execute', function ($event, $data) use (&$executed) {
                $executed = true;
            });

            $database->silent(function () use ($database, $collectionId, $document) {
                $database->updateDocument($collectionId, 'doc1', $document->setAttribute('attr1', 15));
                $database->getDocument($collectionId, 'doc1');
                $database->find($collectionId);
                $database->findOne($collectionId);
                $database->count($collectionId);
                $database->sum($collectionId, 'attr1');
                $database->increaseDocumentAttribute($collectionId, $document->getId(), 'attr1');
                $database->decreaseDocumentAttribute($collectionId, $document->getId(), 'attr1');
            }, ['should-not-execute']);

            $this->assertFalse($executed);

            $database->createDocuments($collectionId, [
                new Document([
                    'attr1' => 10,
                ]),
                new Document([
                    'attr1' => 20,
                ]),
            ]);

            $database->updateDocuments($collectionId, new Document([
                'attr1' => 15,
            ]));

            $database->deleteIndex($collectionId, $indexId1);
            $database->deleteDocument($collectionId, 'doc1');

            $database->deleteDocuments($collectionId);
            $database->deleteAttribute($collectionId, 'attr1');
            $database->deleteCollection($collectionId);
            $database->delete('hellodb');

            // Remove all listeners
            $database->on(Database::EVENT_ALL, 'test', null);
            $database->on(Database::EVENT_ALL, 'should-not-execute', null);
        });
    }

    public function testCreatedAtUpdatedAt(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->assertInstanceOf('Utopia\Database\Document', $database->createCollection('created_at'));
        $database->createAttribute('created_at', 'title', Database::VAR_STRING, 100, false);
        $document = $database->createDocument('created_at', new Document([
            '$id' => ID::custom('uid123'),

            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]));

        $this->assertNotEmpty($document->getSequence());
        $this->assertNotNull($document->getSequence());
    }

    /**
     * @depends testCreatedAtUpdatedAt
     */
    public function testCreatedAtUpdatedAtAssert(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->getDocument('created_at', 'uid123');
        $this->assertEquals(true, !$document->isEmpty());
        sleep(1);
        $document->setAttribute('title', 'new title');
        $database->updateDocument('created_at', 'uid123', $document);
        $document = $database->getDocument('created_at', 'uid123');

        $this->assertGreaterThan($document->getCreatedAt(), $document->getUpdatedAt());
        $this->expectException(DuplicateException::class);

        $database->createCollection('created_at');
    }


    public function testTransformations(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('docs', attributes: [
            new Document([
                '$id' => 'name',
                'type' => Database::VAR_STRING,
                'size' => 767,
                'required' => true,
            ])
        ]);

        $database->createDocument('docs', new Document([
            '$id' => 'doc1',
            'name' => 'value1',
        ]));

        $database->setMetadata('scope', 'api.users');

        $capturedSql = '';
        $database->before(Database::EVENT_DOCUMENT_READ, 'test', function (string $sql) use (&$capturedSql) {
            $sql .= ' AND 1=0';
            $capturedSql = $sql;
            return $sql;
        });

        $result = $database->getDocument('docs', 'doc1');

        $this->assertTrue($result->isEmpty());

        if ($database->getAdapter() instanceof SQL) {
            $this->assertStringContainsString('/* scope: api.users */', $capturedSql);
        }

        $database->before(Database::EVENT_DOCUMENT_READ, 'test', null);
        $database->resetMetadata();
    }

    public function testSetGlobalCollection(): void
    {
        $db = $this->getDatabase();

        $collectionId = 'globalCollection';

        // set collection as global
        $db->setGlobalCollections([$collectionId]);

        // metadata collection should not contain tenant in the cache key
        [$collectionKey, $documentKey, $hashKey] = $db->getCacheKeys(
            Database::METADATA,
            $collectionId,
            []
        );

        $this->assertNotEmpty($collectionKey);
        $this->assertNotEmpty($documentKey);
        $this->assertNotEmpty($hashKey);

        if ($db->getSharedTables()) {
            $this->assertStringNotContainsString((string)$db->getAdapter()->getTenant(), $collectionKey);
        }

        // non global collection should containt tenant in the cache key
        $nonGlobalCollectionId = 'nonGlobalCollection';
        [$collectionKeyRegular] = $db->getCacheKeys(
            Database::METADATA,
            $nonGlobalCollectionId
        );
        if ($db->getSharedTables()) {
            $this->assertStringContainsString((string)$db->getAdapter()->getTenant(), $collectionKeyRegular);
        }

        // Non metadata collection should contain tenant in the cache key
        [$collectionKey, $documentKey, $hashKey] = $db->getCacheKeys(
            $collectionId,
            ID::unique(),
            []
        );

        $this->assertNotEmpty($collectionKey);
        $this->assertNotEmpty($documentKey);
        $this->assertNotEmpty($hashKey);

        if ($db->getSharedTables()) {
            $this->assertStringContainsString((string)$db->getAdapter()->getTenant(), $collectionKey);
        }

        $db->resetGlobalCollections();
        $this->assertEmpty($db->getGlobalCollections());

    }

    public function testCreateCollectionWithLongId(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = '019a91aa-58cd-708d-a55c-5f7725ef937a';

        $attributes = [
            new Document([
                '$id' => 'name',
                'type' => Database::VAR_STRING,
                'size' => 256,
                'required' => true,
                'array' => false,
            ]),
            new Document([
                '$id' => 'age',
                'type' => Database::VAR_INTEGER,
                'size' => 0,
                'required' => false,
                'array' => false,
            ]),
            new Document([
                '$id' => 'isActive',
                'type' => Database::VAR_BOOLEAN,
                'size' => 0,
                'required' => false,
                'array' => false,
            ]),
        ];

        $indexes = [
            new Document([
                '$id' => ID::custom('idx_name'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['name'],
                'lengths' => [128],
                'orders' => ['ASC'],
            ]),
            new Document([
                '$id' => ID::custom('idx_name_age'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['name', 'age'],
                'lengths' => [128, null],
                'orders' => ['ASC', 'DESC'],
            ]),
        ];

        $collectionDocument = $database->createCollection(
            $collection,
            $attributes,
            $indexes,
            permissions: [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        );

        $this->assertEquals($collection, $collectionDocument->getId());
        $this->assertCount(3, $collectionDocument->getAttribute('attributes'));
        $this->assertCount(2, $collectionDocument->getAttribute('indexes'));

        $document = $database->createDocument($collection, new Document([
            '$id' => 'longIdDoc',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'LongId Test',
            'age' => 42,
            'isActive' => true,
        ]));

        $this->assertEquals('longIdDoc', $document->getId());
        $this->assertEquals('LongId Test', $document->getAttribute('name'));
        $this->assertEquals(42, $document->getAttribute('age'));
        $this->assertTrue($document->getAttribute('isActive'));

        $found = $database->find($collection, [
            Query::equal('name', ['LongId Test']),
        ]);

        $this->assertCount(1, $found);
        $this->assertEquals('longIdDoc', $found[0]->getId());

        $fetched = $database->getDocument($collection, 'longIdDoc');
        $this->assertEquals('LongId Test', $fetched->getAttribute('name'));

        $this->assertTrue($database->deleteCollection($collection));
    }

    /**
     * Two processes reconciling the same schema race: one reads the collection
     * as missing, a peer creates it and commits, and only then does the first
     * process try to create it. The loser must not mistake the peer's table for
     * an orphan and drop it.
     */
    public function testCreateCollectionConcurrentlyKeepsPeerData(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collection = 'concurrentCreate';

        // A peer process: same database, its own cache, so its writes do not
        // purge the negative cache entry this process is about to record.
        $peer = (new Database($database->getAdapter(), new Cache(new NoneCache())))
            ->setAuthorization(self::$authorization);

        $this->assertTrue($database->getCollection($collection)->isEmpty());

        $peer->createCollection($collection, [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'size' => 128,
                'required' => false,
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        $peer->createDocument($collection, new Document([
            '$id' => ID::custom('written'),
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'peer',
        ]));

        try {
            $database->createCollection($collection, [
                new Document([
                    '$id' => ID::custom('name'),
                    'type' => Database::VAR_STRING,
                    'size' => 128,
                    'required' => false,
                ]),
            ], permissions: [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ]);
            $this->fail('Expected DuplicateException for a collection a peer already created');
        } catch (DuplicateException) {
        }

        $survivor = $peer->getDocument($collection, 'written');
        $this->assertSame('peer', $survivor->getAttribute('name'), 'Peer document was destroyed by the losing creator');

        $metadata = $peer->getCollection($collection);
        $this->assertFalse($metadata->isEmpty(), 'Peer collection metadata was destroyed by the losing creator');

        // The loser's cache still held the collection as missing from the read
        // it took before the peer committed, and the peer's purge cannot reach
        // this instance. Losing the race has to clear it, or the collection
        // stays invisible here until the entry expires.
        $this->assertFalse($database->getCollection($collection)->isEmpty(), 'Losing creator kept a stale empty collection cached');
        $this->assertSame('peer', $database->getDocument($collection, 'written')->getAttribute('name'));

        $this->assertTrue($database->deleteCollection($collection));
    }

    /**
     * A physical collection with no metadata is indistinguishable from a peer
     * that has created the table and not yet committed its metadata row.
     * createCollection must leave that table alone.
     */
    public function testCreateCollectionDoesNotDropUncommittedPeerTable(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSharedTables()) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $collection = 'preCommitCreate';
        $name = new Document([
            '$id' => ID::custom('name'),
            'type' => Database::VAR_STRING,
            'size' => 128,
            'required' => false,
        ]);

        $database->getAdapter()->createCollection($collection, [$name], []);

        $schema = new Document([
            '$id' => $collection,
            '$collection' => Database::METADATA,
            'name' => $collection,
            'attributes' => [$name],
            'indexes' => [],
            'documentSecurity' => true,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $database->getAdapter()->createDocument($schema, new Document([
            '$id' => ID::custom('written'),
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'peer',
        ]));

        try {
            $database->createCollection($collection, [$name], permissions: [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ]);
        } catch (DuplicateException) {
            // SQL adapters report the existing table as Duplicate. Mongo's
            // createCollection is idempotent, so this process continues and
            // claims metadata. Either way the physical collection must stay.
        }

        $this->assertSame(
            'peer',
            $database->getAdapter()->getDocument($schema, 'written')->getAttribute('name'),
            'Physical collection was dropped while metadata was still uncommitted'
        );

        try {
            $database->deleteCollection($collection);
        } catch (\Throwable) {
            $database->getAdapter()->deleteCollection($collection);
        }
    }
}
