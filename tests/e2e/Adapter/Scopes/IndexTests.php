<?php

namespace Tests\E2E\Adapter\Scopes;

use Exception;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Unique as UniqueException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Index;

trait IndexTests
{
    public function testCreateIndex(): void
    {
        $database = $this->getDatabase();

        $database->createCollection('indexes');

        /**
         * Check ticks sounding cast index for reserved words
         */
        $database->createAttribute('indexes', 'int', Database::VAR_INTEGER, 8, false, array:true);
        if ($database->getAdapter()->getSupportForIndexArray()) {
            $database->createIndex('indexes', 'indx8711', Database::INDEX_KEY, ['int'], [255]);
        }

        $database->createAttribute('indexes', 'name', Database::VAR_STRING, 10, false);

        $database->createIndex('indexes', 'index_1', Database::INDEX_KEY, ['name']);

        try {
            $database->createIndex('indexes', 'index3', Database::INDEX_KEY, ['$id', '$id']);
        } catch (Throwable $e) {
            self::assertTrue($e instanceof DatabaseException);
            self::assertEquals($e->getMessage(), 'Duplicate attributes provided');
        }

        try {
            $database->createIndex('indexes', 'index4', Database::INDEX_KEY, ['name', 'Name']);
        } catch (Throwable $e) {
            self::assertTrue($e instanceof DatabaseException);
            self::assertEquals($e->getMessage(), 'Duplicate attributes provided');
        }

        $database->deleteCollection('indexes');
    }

    public function testCreateDeleteIndex(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('indexes');

        $this->assertEquals(true, $database->createAttribute('indexes', 'string', Database::VAR_STRING, 128, true));
        $this->assertEquals(true, $database->createAttribute('indexes', 'order', Database::VAR_STRING, 128, true));
        $this->assertEquals(true, $database->createAttribute('indexes', 'integer', Database::VAR_INTEGER, 0, true));
        $this->assertEquals(true, $database->createAttribute('indexes', 'float', Database::VAR_FLOAT, 0, true));
        $this->assertEquals(true, $database->createAttribute('indexes', 'boolean', Database::VAR_BOOLEAN, 0, true));

        // Indexes
        $this->assertEquals(true, $database->createIndex('indexes', 'index1', Database::INDEX_KEY, ['string', 'integer'], [128], [Database::ORDER_ASC]));
        $this->assertEquals(true, $database->createIndex('indexes', 'index2', Database::INDEX_KEY, ['float', 'integer'], [], [Database::ORDER_ASC, Database::ORDER_DESC]));
        $this->assertEquals(true, $database->createIndex('indexes', 'index3', Database::INDEX_KEY, ['integer', 'boolean'], [], [Database::ORDER_ASC, Database::ORDER_DESC, Database::ORDER_DESC]));
        $this->assertEquals(true, $database->createIndex('indexes', 'index4', Database::INDEX_UNIQUE, ['string'], [128], [Database::ORDER_ASC]));
        $this->assertEquals(true, $database->createIndex('indexes', 'index5', Database::INDEX_UNIQUE, ['$id', 'string'], [128], [Database::ORDER_ASC]));
        $this->assertEquals(true, $database->createIndex('indexes', 'order', Database::INDEX_UNIQUE, ['order'], [128], [Database::ORDER_ASC]));

        $collection = $database->getCollection('indexes');
        $this->assertCount(6, $collection->getAttribute('indexes'));

        // Delete Indexes
        $this->assertEquals(true, $database->deleteIndex('indexes', 'index1'));
        $this->assertEquals(true, $database->deleteIndex('indexes', 'index2'));
        $this->assertEquals(true, $database->deleteIndex('indexes', 'index3'));
        $this->assertEquals(true, $database->deleteIndex('indexes', 'index4'));
        $this->assertEquals(true, $database->deleteIndex('indexes', 'index5'));
        $this->assertEquals(true, $database->deleteIndex('indexes', 'order'));

        $collection = $database->getCollection('indexes');
        $this->assertCount(0, $collection->getAttribute('indexes'));

        // Test non-shared tables duplicates throw duplicate
        $database->createIndex('indexes', 'duplicate', Database::INDEX_KEY, ['string', 'boolean'], [128], [Database::ORDER_ASC]);
        try {
            $database->createIndex('indexes', 'duplicate', Database::INDEX_KEY, ['string', 'boolean'], [128], [Database::ORDER_ASC]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }

        // Test delete index when index does not exist
        $this->assertEquals(true, $database->createIndex('indexes', 'index1', Database::INDEX_KEY, ['string', 'integer'], [128], [Database::ORDER_ASC]));
        $this->assertEquals(true, $this->deleteIndex('indexes', 'index1'));
        $this->assertEquals(true, $database->deleteIndex('indexes', 'index1'));

        // Test delete index when attribute does not exist
        $this->assertEquals(true, $database->createIndex('indexes', 'index1', Database::INDEX_KEY, ['string', 'integer'], [128], [Database::ORDER_ASC]));
        $this->assertEquals(true, $database->deleteAttribute('indexes', 'string'));
        $this->assertEquals(true, $database->deleteIndex('indexes', 'index1'));

        $database->deleteCollection('indexes');
    }



    /**
     * @throws Exception|Throwable
     */
    public function testIndexValidation(): void
    {
        $attributes = [
            new Document([
                '$id' => ID::custom('title1'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 700,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('title2'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 500,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ];

        $indexes = [
            new Document([
                '$id' => ID::custom('index1'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['title1', 'title2'],
                'lengths' => [701,50],
                'orders' => [],
            ]),
        ];

        $collection = new Document([
            '$id' => ID::custom('index_length'),
            'name' => 'test',
            'attributes' => $attributes,
            'indexes' => $indexes
        ]);

        /** @var Database $database */
        $database = $this->getDatabase();

        $validator = new Index(
            $attributes,
            $indexes,
            $database->getAdapter()->getMaxIndexLength(),
            $database->getAdapter()->getInternalIndexesKeys(),
            $database->getAdapter()->getSupportForIndexArray(),
            $database->getAdapter()->getSupportForSpatialIndexNull(),
            $database->getAdapter()->getSupportForSpatialIndexOrder(),
            $database->getAdapter()->getSupportForVectors(),
            $database->getAdapter()->getSupportForAttributes(),
            $database->getAdapter()->getSupportForMultipleFulltextIndexes(),
            $database->getAdapter()->getSupportForIdenticalIndexes(),
            $database->getAdapter()->getSupportForObject(),
            $database->getAdapter()->getSupportForTrigramIndex(),
            $database->getAdapter()->getSupportForSpatialAttributes(),
            $database->getAdapter()->getSupportForIndex(),
            $database->getAdapter()->getSupportForUniqueIndex(),
            $database->getAdapter()->getSupportForFulltextIndex()
        );
        if ($database->getAdapter()->getSupportForIdenticalIndexes()) {
            $errorMessage = 'Index length 701 is larger than the size for title1: 700"';
            $this->assertFalse($validator->isValid($indexes[0]));
            $this->assertEquals($errorMessage, $validator->getDescription());
            try {
                $database->createCollection($collection->getId(), $attributes, $indexes, [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                ]);
                $this->fail('Failed to throw exception');
            } catch (Exception $e) {
                $this->assertEquals($errorMessage, $e->getMessage());
            }
        }

        $indexes = [
            new Document([
                '$id' => ID::custom('index1'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['title1', 'title2'],
                'lengths' => [700], // 700, 500 (length(title2))
                'orders' => [],
            ]),
        ];

        $collection->setAttribute('indexes', $indexes);

        if ($database->getAdapter()->getSupportForAttributes() && $database->getAdapter()->getMaxIndexLength() > 0) {
            $errorMessage = 'Index length is longer than the maximum: ' . $database->getAdapter()->getMaxIndexLength();
            $this->assertFalse($validator->isValid($indexes[0]));
            $this->assertEquals($errorMessage, $validator->getDescription());

            try {
                $database->createCollection($collection->getId(), $attributes, $indexes);
                $this->fail('Failed to throw exception');
            } catch (Exception $e) {
                $this->assertEquals($errorMessage, $e->getMessage());
            }
        }

        $attributes[] = new Document([
            '$id' => ID::custom('integer'),
            'type' => Database::VAR_INTEGER,
            'format' => '',
            'size' => 10000,
            'signed' => true,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => [],
        ]);

        $indexes = [
            new Document([
                '$id' => ID::custom('index1'),
                'type' => Database::INDEX_FULLTEXT,
                'attributes' => ['title1', 'integer'],
                'lengths' => [],
                'orders' => [],
            ]),
        ];

        $collection = new Document([
            '$id' => ID::custom('index_length'),
            'name' => 'test',
            'attributes' => $attributes,
            'indexes' => $indexes
        ]);

        // not using $indexes[0] as the index validator skips indexes with same id
        $newIndex = new Document([
            '$id' => ID::custom('newIndex1'),
            'type' => Database::INDEX_FULLTEXT,
            'attributes' => ['title1', 'integer'],
            'lengths' => [],
            'orders' => [],
        ]);

        $validator = new Index(
            $attributes,
            $indexes,
            $database->getAdapter()->getMaxIndexLength(),
            $database->getAdapter()->getInternalIndexesKeys(),
            $database->getAdapter()->getSupportForIndexArray(),
            $database->getAdapter()->getSupportForSpatialIndexNull(),
            $database->getAdapter()->getSupportForSpatialIndexOrder(),
            $database->getAdapter()->getSupportForVectors(),
            $database->getAdapter()->getSupportForAttributes(),
            $database->getAdapter()->getSupportForMultipleFulltextIndexes(),
            $database->getAdapter()->getSupportForIdenticalIndexes(),
            $database->getAdapter()->getSupportForObject(),
            $database->getAdapter()->getSupportForTrigramIndex(),
            $database->getAdapter()->getSupportForSpatialAttributes(),
            $database->getAdapter()->getSupportForIndex(),
            $database->getAdapter()->getSupportForUniqueIndex(),
            $database->getAdapter()->getSupportForFulltextIndex()
        );

        $this->assertFalse($validator->isValid($newIndex));

        if (!$database->getAdapter()->getSupportForFulltextIndex()) {
            $this->assertEquals('Fulltext index is not supported', $validator->getDescription());
        } elseif (!$database->getAdapter()->getSupportForMultipleFulltextIndexes()) {
            $this->assertEquals('There is already a fulltext index in the collection', $validator->getDescription());
        } elseif ($database->getAdapter()->getSupportForAttributes()) {
            $this->assertEquals('Attribute "integer" cannot be part of a fulltext index, must be of type string', $validator->getDescription());
        }

        try {
            $database->createCollection($collection->getId(), $attributes, $indexes);
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->fail('Failed to throw exception');
            }
        } catch (Exception $e) {
            if (!$database->getAdapter()->getSupportForFulltextIndex()) {
                $this->assertEquals('Fulltext index is not supported', $e->getMessage());
            } else {
                $this->assertEquals('Attribute "integer" cannot be part of a fulltext index, must be of type string', $e->getMessage());
            }
        }


        $indexes = [
            new Document([
                '$id' => ID::custom('index_negative_length'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['title1'],
                'lengths' => [-1],
                'orders' => [],
            ]),
        ];
        if ($database->getAdapter()->getSupportForAttributes()) {
            $errorMessage = 'Negative index length provided for title1';
            $this->assertFalse($validator->isValid($indexes[0]));
            $this->assertEquals($errorMessage, $validator->getDescription());

            try {
                $database->createCollection(ID::unique(), $attributes, $indexes);
                $this->fail('Failed to throw exception');
            } catch (Exception $e) {
                $this->assertEquals($errorMessage, $e->getMessage());
            }

            $indexes = [
                new Document([
                    '$id' => ID::custom('index_extra_lengths'),
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['title1', 'title2'],
                    'lengths' => [100, 100, 100],
                    'orders' => [],
                ]),
            ];
            $errorMessage = 'Invalid index lengths. Count of lengths must be equal or less than the number of attributes.';
            $this->assertFalse($validator->isValid($indexes[0]));
            $this->assertEquals($errorMessage, $validator->getDescription());

            try {
                $database->createCollection(ID::unique(), $attributes, $indexes);
                $this->fail('Failed to throw exception');
            } catch (Exception $e) {
                $this->assertEquals($errorMessage, $e->getMessage());
            }
        }
    }

    public function testCreateCollectionWithIndexOnSequence(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForIndex()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = $database->createCollection('sequenceIndexes', [
            new Document([
                '$id' => ID::custom('username'),
                'type' => Database::VAR_STRING,
                'size' => 128,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('email'),
                'type' => Database::VAR_STRING,
                'size' => 128,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
        ], [
            new Document([
                '$id' => ID::custom('_index 123'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['username', '$sequence'],
                'lengths' => [],
                'orders' => [Database::ORDER_ASC, Database::ORDER_DESC],
            ]),
            new Document([
                '$id' => ID::custom('_index 456'),
                'type' => Database::INDEX_UNIQUE,
                'attributes' => ['email', '$sequence'],
                'lengths' => [],
                'orders' => [Database::ORDER_ASC, Database::ORDER_DESC],
            ]),
        ]);

        $indexes = $collection->getAttribute('indexes');
        $this->assertCount(2, $indexes);
        $this->assertEquals('_index 123', $indexes[0]->getId());
        $this->assertEquals(['username', '$sequence'], $indexes[0]->getAttribute('attributes'));
        $this->assertEquals('_index 456', $indexes[1]->getId());
        $this->assertEquals(['email', '$sequence'], $indexes[1]->getAttribute('attributes'));

        $database->createDocument('sequenceIndexes', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'username' => 'chester',
            'email' => 'chester@example.com',
        ]));

        $documents = $database->find('sequenceIndexes', [
            Query::equal('username', ['chester']),
            Query::orderDesc('$sequence'),
        ]);

        $this->assertCount(1, $documents);
        $this->assertEquals('chester', $documents[0]->getAttribute('username'));

        /**
         * $sequence is unique on its own, so a unique index containing it never
         * conflicts. A duplicate here would mean the adapter built the index
         * without the $sequence column.
         */
        $database->createDocument('sequenceIndexes', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'username' => 'chester',
            'email' => 'chester@example.com',
        ]));

        $this->assertCount(2, $database->find('sequenceIndexes', [
            Query::equal('email', ['chester@example.com']),
        ]));

        $database->deleteCollection('sequenceIndexes');
    }

    public function testCreateIndexOnSequence(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForIndex()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $this->assertTrue($database->createAttribute(__FUNCTION__, 'username', Database::VAR_STRING, 128, false));
        $this->assertTrue($database->createAttribute(__FUNCTION__, 'email', Database::VAR_STRING, 128, false));

        $this->assertTrue($database->createIndex(
            __FUNCTION__,
            '_index 123',
            Database::INDEX_KEY,
            ['username', '$sequence'],
            [],
            [Database::ORDER_ASC, Database::ORDER_DESC]
        ));

        $this->assertTrue($database->createIndex(
            __FUNCTION__,
            '_index 456',
            Database::INDEX_UNIQUE,
            ['email', '$sequence'],
            [],
            [Database::ORDER_ASC, Database::ORDER_DESC]
        ));

        $indexes = $database->getCollection(__FUNCTION__)->getAttribute('indexes');
        $this->assertCount(2, $indexes);
        $this->assertEquals('_index 123', $indexes[0]->getId());
        $this->assertEquals(['username', '$sequence'], $indexes[0]->getAttribute('attributes'));
        $this->assertEquals('_index 456', $indexes[1]->getId());
        $this->assertEquals(['email', '$sequence'], $indexes[1]->getAttribute('attributes'));

        $database->createDocument(__FUNCTION__, new Document([
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'username' => 'chester',
            'email' => 'chester@example.com',
        ]));

        $documents = $database->find(__FUNCTION__, [
            Query::equal('username', ['chester']),
            Query::orderDesc('$sequence'),
        ]);

        $this->assertCount(1, $documents);
        $this->assertEquals('chester', $documents[0]->getAttribute('username'));

        /**
         * $sequence is unique on its own, so a unique index containing it never
         * conflicts. A duplicate here would mean the adapter built the index
         * without the $sequence column.
         */
        $database->createDocument(__FUNCTION__, new Document([
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'username' => 'chester',
            'email' => 'chester@example.com',
        ]));

        $this->assertCount(2, $database->find(__FUNCTION__, [
            Query::equal('email', ['chester@example.com']),
        ]));

        $database->deleteCollection(__FUNCTION__);
    }

    public function testCreateUniqueIndexOverDuplicates(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUniqueIndex()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);
        $database->createAttribute(__FUNCTION__, 'name', Database::VAR_STRING, 128, false);
        $database->createAttribute(__FUNCTION__, 'age', Database::VAR_INTEGER, 0, false);

        foreach (['first', 'second'] as $id) {
            $database->createDocument(__FUNCTION__, new Document([
                '$id' => $id,
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'chester',
                'age' => 7,
            ]));
        }

        foreach (['name', 'age'] as $attribute) {
            try {
                $database->createIndex(__FUNCTION__, "unique_{$attribute}", Database::INDEX_UNIQUE, [$attribute]);
                $this->fail('Failed to throw exception');
            } catch (Exception $e) {
                $this->assertInstanceOf(UniqueException::class, $e);
            }

            $indexes = $database->getCollection(__FUNCTION__)->getAttribute('indexes');
            $this->assertNotContains("unique_{$attribute}", \array_map(fn ($index) => $index->getId(), $indexes));
        }

        $database->deleteCollection(__FUNCTION__);
    }

    public function testIndexLengthZero(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $database->createAttribute(__FUNCTION__, 'title1', Database::VAR_STRING, $database->getAdapter()->getMaxIndexLength() + 300, true);

        try {
            $database->createIndex(__FUNCTION__, 'index_title1', Database::INDEX_KEY, ['title1'], [0]);
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertEquals('Index length is longer than the maximum: '.$database->getAdapter()->getMaxIndexLength(), $e->getMessage());
        }


        $database->createAttribute(__FUNCTION__, 'title2', Database::VAR_STRING, 100, true);
        $database->createIndex(__FUNCTION__, 'index_title2', Database::INDEX_KEY, ['title2'], [0]);

        try {
            $database->updateAttribute(__FUNCTION__, 'title2', Database::VAR_STRING, $database->getAdapter()->getMaxIndexLength() + 300, true);
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertEquals('Index length is longer than the maximum: '.$database->getAdapter()->getMaxIndexLength(), $e->getMessage());
        }
    }

    public function testRenameIndex(): void
    {
        $database = $this->getDatabase();

        $numbers = $database->createCollection('numbers');
        $database->createAttribute('numbers', 'verbose', Database::VAR_STRING, 128, true);
        $database->createAttribute('numbers', 'symbol', Database::VAR_INTEGER, 0, true);

        $database->createIndex('numbers', 'index1', Database::INDEX_KEY, ['verbose'], [128], [Database::ORDER_ASC]);
        $database->createIndex('numbers', 'index2', Database::INDEX_KEY, ['symbol'], [0], [Database::ORDER_ASC]);

        $index = $database->renameIndex('numbers', 'index1', 'index3');

        $this->assertTrue($index);

        $numbers = $database->getCollection('numbers');

        $this->assertEquals('index2', $numbers->getAttribute('indexes')[1]['$id']);
        $this->assertEquals('index3', $numbers->getAttribute('indexes')[0]['$id']);
        $this->assertCount(2, $numbers->getAttribute('indexes'));
    }


    /**
    * @depends testRenameIndex
    * @expectedException Exception
    */
    public function testRenameIndexMissing(): void
    {
        $database = $this->getDatabase();
        $this->expectExceptionMessage('Index not found');
        $index = $database->renameIndex('numbers', 'index1', 'index4');
    }

    /**
    * @depends testRenameIndex
    * @expectedException Exception
    */
    public function testRenameIndexExisting(): void
    {
        $database = $this->getDatabase();
        $this->expectExceptionMessage('Index name already used');
        $index = $database->renameIndex('numbers', 'index3', 'index2');
    }


    public function testExceptionIndexLimit(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('indexLimit');

        // add unique attributes for indexing
        for ($i = 0; $i < 64; $i++) {
            $this->assertEquals(true, $database->createAttribute('indexLimit', "test{$i}", Database::VAR_STRING, 16, true));
        }

        // Testing for indexLimit
        // Add up to the limit, then check if the next index throws IndexLimitException
        for ($i = 0; $i < ($this->getDatabase()->getLimitForIndexes()); $i++) {
            $this->assertEquals(true, $database->createIndex('indexLimit', "index{$i}", Database::INDEX_KEY, ["test{$i}"], [16]));
        }
        $this->expectException(LimitException::class);
        $this->assertEquals(false, $database->createIndex('indexLimit', "index64", Database::INDEX_KEY, ["test64"], [16]));

        $database->deleteCollection('indexLimit');
    }

    public function testListDocumentSearch(): void
    {
        $fulltextSupport = $this->getDatabase()->getAdapter()->getSupportForFulltextIndex();
        if (!$fulltextSupport) {
            $this->expectNotToPerformAssertions();
            return;
        }

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createIndex('documents', 'string', Database::INDEX_FULLTEXT, ['string']);
        $database->createDocument('documents', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'string' => '*test+alias@email-provider.com',
            'integer_signed' => 0,
            'integer_unsigned' => 0,
            'bigint_signed' => 0,
            'bigint_unsigned' => 0,
            'float_signed' => -5.55,
            'float_unsigned' => 5.55,
            'boolean' => true,
            'colors' => ['pink', 'green', 'blue'],
            'empty' => [],
        ]));

        /**
         * Allow reserved keywords for search
         */
        $documents = $database->find('documents', [
            Query::search('string', '*test+alias@email-provider.com'),
        ]);

        $this->assertEquals(1, count($documents));
    }

    public function testMaxQueriesValues(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $max = $database->getMaxQueryValues();

        $database->setMaxQueryValues(5);

        try {
            $database->find(
                'documents',
                [Query::equal('$id', [1, 2, 3, 4, 5, 6])]
            );
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertTrue($e instanceof QueryException);
            $this->assertEquals('Invalid query: Query on attribute has greater than 5 values: $id', $e->getMessage());
        }

        $database->setMaxQueryValues($max);
    }

    public function testEmptySearch(): void
    {
        $fulltextSupport = $this->getDatabase()->getAdapter()->getSupportForFulltextIndex();
        if (!$fulltextSupport) {
            $this->expectNotToPerformAssertions();
            return;
        }

        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->find('documents', [
            Query::search('string', ''),
        ]);
        $this->assertEquals(0, count($documents));

        $documents = $database->find('documents', [
            Query::search('string', '*'),
        ]);
        $this->assertEquals(0, count($documents));

        $documents = $database->find('documents', [
            Query::search('string', '<>'),
        ]);
        $this->assertEquals(0, count($documents));
    }

    public function testMultipleFulltextIndexValidation(): void
    {

        $fulltextSupport = $this->getDatabase()->getAdapter()->getSupportForFulltextIndex();
        if (!$fulltextSupport) {
            $this->expectNotToPerformAssertions();
            return;
        }

        /** @var Database $database */
        $database = $this->getDatabase();

        $collectionId = 'multiple_fulltext_test';
        try {
            $database->createCollection($collectionId);

            $database->createAttribute($collectionId, 'title', Database::VAR_STRING, 256, false);
            $database->createAttribute($collectionId, 'content', Database::VAR_STRING, 256, false);
            $database->createIndex($collectionId, 'fulltext_title', Database::INDEX_FULLTEXT, ['title']);

            $supportsMultipleFulltext = $database->getAdapter()->getSupportForMultipleFulltextIndexes();

            // Try to add second fulltext index
            try {
                $database->createIndex($collectionId, 'fulltext_content', Database::INDEX_FULLTEXT, ['content']);

                if ($supportsMultipleFulltext) {
                    $this->assertTrue(true, 'Multiple fulltext indexes are supported and second index was created successfully');
                } else {
                    $this->fail('Expected exception when creating second fulltext index, but none was thrown');
                }
            } catch (Throwable $e) {
                if (!$supportsMultipleFulltext) {
                    $this->assertTrue(true, 'Multiple fulltext indexes are not supported and exception was thrown as expected');
                } else {
                    $this->fail('Unexpected exception when creating second fulltext index: ' . $e->getMessage());
                }
            }

        } finally {
            // Clean up
            $database->deleteCollection($collectionId);
        }
    }

    public function testIdenticalIndexValidation(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collectionId = 'identical_index_test';

        try {
            $database->createCollection($collectionId);

            $database->createAttribute($collectionId, 'name', Database::VAR_STRING, 256, false);
            $database->createAttribute($collectionId, 'age', Database::VAR_INTEGER, 8, false);

            $database->createIndex($collectionId, 'index1', Database::INDEX_KEY, ['name', 'age'], [], [Database::ORDER_ASC, Database::ORDER_DESC]);

            $supportsIdenticalIndexes = $database->getAdapter()->getSupportForIdenticalIndexes();

            // Try to add identical index (failure)
            try {
                $database->createIndex($collectionId, 'index2', Database::INDEX_KEY, ['name', 'age'], [], [Database::ORDER_ASC, Database::ORDER_DESC]);
                if ($supportsIdenticalIndexes) {
                    $this->assertTrue(true, 'Identical indexes are supported and second index was created successfully');
                } else {
                    $this->fail('Expected exception but got none');
                }

            } catch (Throwable $e) {
                if (!$supportsIdenticalIndexes) {
                    $this->assertTrue(true, 'Identical indexes are not supported and exception was thrown as expected');
                } else {
                    $this->fail('Unexpected exception when creating identical index: ' . $e->getMessage());
                }

            }

            // Test with different attributes order - faliure
            try {
                $database->createIndex($collectionId, 'index3', Database::INDEX_KEY, ['age', 'name'], [], [ Database::ORDER_ASC, Database::ORDER_DESC]);
                $this->assertTrue(true, 'Index with different attributes was created successfully');
            } catch (Throwable $e) {
                if (!$supportsIdenticalIndexes) {
                    $this->assertTrue(true, 'Identical indexes are not supported and exception was thrown as expected');
                } else {
                    $this->fail('Unexpected exception when creating identical index: ' . $e->getMessage());
                }
            }

            // Test with different orders  order - faliure
            try {
                $database->createIndex($collectionId, 'index4', Database::INDEX_KEY, ['age', 'name'], [], [ Database::ORDER_DESC, Database::ORDER_ASC]);
                $this->assertTrue(true, 'Index with different attributes was created successfully');
            } catch (Throwable $e) {
                if (!$supportsIdenticalIndexes) {
                    $this->assertTrue(true, 'Identical indexes are not supported and exception was thrown as expected');
                } else {
                    $this->fail('Unexpected exception when creating identical index: ' . $e->getMessage());
                }
            }

            // Test with different attributes - success
            try {
                $database->createIndex($collectionId, 'index5', Database::INDEX_KEY, ['name'], [], [Database::ORDER_ASC]);
                $this->assertTrue(true, 'Index with different attributes was created successfully');
            } catch (Throwable $e) {
                $this->fail('Unexpected exception when creating index with different attributes: ' . $e->getMessage());
            }

            // Test with different orders - success
            try {
                $database->createIndex($collectionId, 'index6', Database::INDEX_KEY, ['name', 'age'], [], [Database::ORDER_ASC]);
                $this->assertTrue(true, 'Index with different orders was created successfully');
            } catch (Throwable $e) {
                $this->fail('Unexpected exception when creating index with different orders: ' . $e->getMessage());
            }
        } finally {
            // Clean up
            $database->deleteCollection($collectionId);
        }
    }

    public function testTrigramIndex(): void
    {
        $trigramSupport = $this->getDatabase()->getAdapter()->getSupportForTrigramIndex();
        if (!$trigramSupport) {
            $this->expectNotToPerformAssertions();
            return;
        }

        /** @var Database $database */
        $database = static::getDatabase();

        $collectionId = 'trigram_test';
        try {
            $database->createCollection($collectionId);

            $database->createAttribute($collectionId, 'name', Database::VAR_STRING, 256, false);
            $database->createAttribute($collectionId, 'description', Database::VAR_STRING, 512, false);

            // Create trigram index on name attribute
            $this->assertEquals(true, $database->createIndex($collectionId, 'trigram_name', Database::INDEX_TRIGRAM, ['name']));

            $collection = $database->getCollection($collectionId);
            $indexes = $collection->getAttribute('indexes');
            $this->assertCount(1, $indexes);
            $this->assertEquals('trigram_name', $indexes[0]['$id']);
            $this->assertEquals(Database::INDEX_TRIGRAM, $indexes[0]['type']);
            $this->assertEquals(['name'], $indexes[0]['attributes']);

            // Create another trigram index on description
            $this->assertEquals(true, $database->createIndex($collectionId, 'trigram_description', Database::INDEX_TRIGRAM, ['description']));

            $collection = $database->getCollection($collectionId);
            $indexes = $collection->getAttribute('indexes');
            $this->assertCount(2, $indexes);

            // Test that trigram index can be deleted
            $this->assertEquals(true, $database->deleteIndex($collectionId, 'trigram_name'));
            $this->assertEquals(true, $database->deleteIndex($collectionId, 'trigram_description'));

            $collection = $database->getCollection($collectionId);
            $indexes = $collection->getAttribute('indexes');
            $this->assertCount(0, $indexes);

        } finally {
            // Clean up
            $database->deleteCollection($collectionId);
        }
    }

    public function testTrigramIndexValidation(): void
    {
        $trigramSupport = $this->getDatabase()->getAdapter()->getSupportForTrigramIndex();
        if (!$trigramSupport) {
            $this->expectNotToPerformAssertions();
            return;
        }

        /** @var Database $database */
        $database = static::getDatabase();

        $collectionId = 'trigram_validation_test';
        try {
            $database->createCollection($collectionId);

            $database->createAttribute($collectionId, 'name', Database::VAR_STRING, 256, false);
            $database->createAttribute($collectionId, 'description', Database::VAR_STRING, 412, false);
            $database->createAttribute($collectionId, 'age', Database::VAR_INTEGER, 8, false);

            // Test: Trigram index on non-string attribute should fail
            try {
                $database->createIndex($collectionId, 'trigram_invalid', Database::INDEX_TRIGRAM, ['age']);
                $this->fail('Expected exception when creating trigram index on non-string attribute');
            } catch (Exception $e) {
                $this->assertStringContainsString('Trigram index can only be created on string type attributes', $e->getMessage());
            }

            // Test: Trigram index with multiple string attributes should succeed
            $this->assertEquals(true, $database->createIndex($collectionId, 'trigram_multi', Database::INDEX_TRIGRAM, ['name', 'description']));

            $collection = $database->getCollection($collectionId);
            $indexes = $collection->getAttribute('indexes');
            $trigramMultiIndex = null;
            foreach ($indexes as $idx) {
                if ($idx['$id'] === 'trigram_multi') {
                    $trigramMultiIndex = $idx;
                    break;
                }
            }
            $this->assertNotNull($trigramMultiIndex);
            $this->assertEquals(Database::INDEX_TRIGRAM, $trigramMultiIndex['type']);
            $this->assertEquals(['name', 'description'], $trigramMultiIndex['attributes']);

            // Test: Trigram index with mixed string and non-string attributes should fail
            try {
                $database->createIndex($collectionId, 'trigram_mixed', Database::INDEX_TRIGRAM, ['name', 'age']);
                $this->fail('Expected exception when creating trigram index with mixed attribute types');
            } catch (Exception $e) {
                $this->assertStringContainsString('Trigram index can only be created on string type attributes', $e->getMessage());
            }

            // Test: Trigram index with orders should fail
            try {
                $database->createIndex($collectionId, 'trigram_order', Database::INDEX_TRIGRAM, ['name'], [], [Database::ORDER_ASC]);
                $this->fail('Expected exception when creating trigram index with orders');
            } catch (Exception $e) {
                $this->assertStringContainsString('Trigram indexes do not support orders or lengths', $e->getMessage());
            }

            // Test: Trigram index with lengths should fail
            try {
                $database->createIndex($collectionId, 'trigram_length', Database::INDEX_TRIGRAM, ['name'], [128]);
                $this->fail('Expected exception when creating trigram index with lengths');
            } catch (Exception $e) {
                $this->assertStringContainsString('Trigram indexes do not support orders or lengths', $e->getMessage());
            }

        } finally {
            // Clean up
            $database->deleteCollection($collectionId);
        }
    }

    public function testTTLIndexes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForTTLIndexes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_ttl');
        $database->createCollection($col);

        $database->createAttribute($col, 'expiresAt', Database::VAR_DATETIME, 0, false);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_ttl_valid',
                Database::INDEX_TTL,
                ['expiresAt'],
                [],
                [Database::ORDER_ASC],
                3600 // 1 hour TTL
            )
        );

        $collection = $database->getCollection($col);
        $indexes = $collection->getAttribute('indexes');
        $this->assertCount(1, $indexes);
        $ttlIndex = $indexes[0];
        $this->assertEquals('idx_ttl_valid', $ttlIndex->getId());
        $this->assertEquals(Database::INDEX_TTL, $ttlIndex->getAttribute('type'));
        $this->assertEquals(3600, $ttlIndex->getAttribute('ttl'));

        $now = new \DateTime();
        $future1 = (clone $now)->modify('+2 hours');
        $future2 = (clone $now)->modify('+1 hour');
        $past = (clone $now)->modify('-1 hour');

        $database->createDocuments($col, [
            new Document([
                '$id' => 'doc1',
                '$permissions' => $permissions,
                'expiresAt' => $future1->format(\DateTime::ATOM),
            ]),
            new Document([
                '$id' => 'doc2',
                '$permissions' => $permissions,
                'expiresAt' => $future2->format(\DateTime::ATOM),
            ]),
            new Document([
                '$id' => 'doc3',
                '$permissions' => $permissions,
                'expiresAt' => $past->format(\DateTime::ATOM),
            ])
        ]);

        $this->assertTrue($database->deleteIndex($col, 'idx_ttl_valid'));

        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_ttl_min',
                Database::INDEX_TTL,
                ['expiresAt'],
                [],
                [Database::ORDER_ASC],
                1 // Minimum TTL
            )
        );

        $col2 = uniqid('sl_ttl_collection');

        $expiresAtAttr = new Document([
            '$id' => ID::custom('expiresAt'),
            'type' => Database::VAR_DATETIME,
            'size' => 0,
            'signed' => false,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => ['datetime'],
        ]);

        $ttlIndexDoc = new Document([
            '$id' => ID::custom('idx_ttl_collection'),
            'type' => Database::INDEX_TTL,
            'attributes' => ['expiresAt'],
            'lengths' => [],
            'orders' => [Database::ORDER_ASC],
            'ttl' => 7200 // 2 hours
        ]);

        $database->createCollection($col2, [$expiresAtAttr], [$ttlIndexDoc]);

        $collection2 = $database->getCollection($col2);
        $indexes2 = $collection2->getAttribute('indexes');
        $this->assertCount(1, $indexes2);
        $ttlIndex2 = $indexes2[0];
        $this->assertEquals('idx_ttl_collection', $ttlIndex2->getId());
        $this->assertEquals(7200, $ttlIndex2->getAttribute('ttl'));

        $database->deleteCollection($col);
        $database->deleteCollection($col2);
    }

    public function testTTLIndexDuplicatePrevention(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForTTLIndexes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_ttl_dup');
        $database->createCollection($col);

        $database->createAttribute($col, 'expiresAt', Database::VAR_DATETIME, 0, false);
        $database->createAttribute($col, 'deletedAt', Database::VAR_DATETIME, 0, false);

        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_ttl_expires',
                Database::INDEX_TTL,
                ['expiresAt'],
                [],
                [Database::ORDER_ASC],
                3600 // 1 hour
            )
        );

        try {
            $database->createIndex(
                $col,
                'idx_ttl_expires_duplicate',
                Database::INDEX_TTL,
                ['expiresAt'],
                [],
                [Database::ORDER_ASC],
                7200 // 2 hours
            );
            $this->fail('Expected exception for creating a second TTL index in a collection');
        } catch (Exception $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
            $this->assertStringContainsString('There can be only one TTL index in a collection', $e->getMessage());
        }

        try {
            $database->createIndex(
                $col,
                'idx_ttl_deleted',
                Database::INDEX_TTL,
                ['deletedAt'],
                [],
                [Database::ORDER_ASC],
                86400 // 24 hours
            );
            $this->fail('Expected exception for creating a second TTL index in a collection');
        } catch (Exception $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
            $this->assertStringContainsString('There can be only one TTL index in a collection', $e->getMessage());
        }

        $collection = $database->getCollection($col);
        $indexes = $collection->getAttribute('indexes');
        $this->assertCount(1, $indexes);

        $indexIds = array_map(fn ($idx) => $idx->getId(), $indexes);
        $this->assertContains('idx_ttl_expires', $indexIds);
        $this->assertNotContains('idx_ttl_deleted', $indexIds);

        try {
            $database->createIndex(
                $col,
                'idx_ttl_deleted_duplicate',
                Database::INDEX_TTL,
                ['deletedAt'],
                [],
                [Database::ORDER_ASC],
                172800 // 48 hours
            );
            $this->fail('Expected exception for creating a second TTL index in a collection');
        } catch (Exception $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
            $this->assertStringContainsString('There can be only one TTL index in a collection', $e->getMessage());
        }

        $this->assertTrue($database->deleteIndex($col, 'idx_ttl_expires'));

        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_ttl_deleted',
                Database::INDEX_TTL,
                ['deletedAt'],
                [],
                [Database::ORDER_ASC],
                1800 // 30 minutes
            )
        );

        $collection = $database->getCollection($col);
        $indexes = $collection->getAttribute('indexes');
        $this->assertCount(1, $indexes);

        $indexIds = array_map(fn ($idx) => $idx->getId(), $indexes);
        $this->assertNotContains('idx_ttl_expires', $indexIds);
        $this->assertContains('idx_ttl_deleted', $indexIds);

        $col3 = uniqid('sl_ttl_dup_collection');

        $expiresAtAttr = new Document([
            '$id' => ID::custom('expiresAt'),
            'type' => Database::VAR_DATETIME,
            'size' => 0,
            'signed' => false,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => ['datetime'],
        ]);

        $ttlIndex1 = new Document([
            '$id' => ID::custom('idx_ttl_1'),
            'type' => Database::INDEX_TTL,
            'attributes' => ['expiresAt'],
            'lengths' => [],
            'orders' => [Database::ORDER_ASC],
            'ttl' => 3600
        ]);

        $ttlIndex2 = new Document([
            '$id' => ID::custom('idx_ttl_2'),
            'type' => Database::INDEX_TTL,
            'attributes' => ['expiresAt'],
            'lengths' => [],
            'orders' => [Database::ORDER_ASC],
            'ttl' => 7200
        ]);

        try {
            $database->createCollection($col3, [$expiresAtAttr], [$ttlIndex1, $ttlIndex2]);
            $this->fail('Expected exception for duplicate TTL indexes in createCollection');
        } catch (Exception $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
            $this->assertStringContainsString('There can be only one TTL index in a collection', $e->getMessage());
        }

        // Cleanup
        $database->deleteCollection($col);
    }
}
