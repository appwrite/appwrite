<?php

namespace Tests\E2E\Adapter\Scopes;

use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait ObjectAttributeTests
{
    /**
     * Helper function to create an attribute if adapter supports attributes,
     * otherwise returns true to allow tests to continue
     *
     * @param Database $database
     * @param string $collectionId
     * @param string $attributeId
     * @param string $type
     * @param int $size
     * @param bool $required
     * @param mixed $default
     * @return bool
     */
    private function createAttribute(Database $database, string $collectionId, string $attributeId, string $type, int $size, bool $required, $default = null): bool
    {
        if (!$database->getAdapter()->getSupportForAttributes()) {
            return true;
        }

        $result = $database->createAttribute($collectionId, $attributeId, $type, $size, $required, $default);
        $this->assertEquals(true, $result);
        return $result;
    }

    public function testObjectAttribute(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Skip test if adapter doesn't support JSONB
        if (!$database->getAdapter()->getSupportForObject()) {
            $this->markTestSkipped('Adapter does not support object attributes');
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);

        // Create object attribute
        $this->createAttribute($database, $collectionId, 'meta', Database::VAR_OBJECT, 0, false);

        // Test 1: Create and read document with object attribute
        $doc1 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'meta' => [
                'age' => 25,
                'skills' => ['react', 'node'],
                'user' => [
                    'info' => [
                        'country' => 'IN'
                    ]
                ]
            ]
        ]));

        $this->assertIsArray($doc1->getAttribute('meta'));
        $this->assertEquals(25, $doc1->getAttribute('meta')['age']);
        $this->assertEquals(['react', 'node'], $doc1->getAttribute('meta')['skills']);
        $this->assertEquals('IN', $doc1->getAttribute('meta')['user']['info']['country']);

        // Test 2: Query::equal with simple key-value pair
        $results = $database->find($collectionId, [
            Query::equal('meta', [['age' => 25]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc1', $results[0]->getId());

        // Test 3: Query::equal with nested JSON
        $results = $database->find($collectionId, [
            Query::equal('meta', [[
                'user' => [
                    'info' => [
                        'country' => 'IN'
                    ]
                ]
            ]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc1', $results[0]->getId());

        // Test 4: Query::contains for array element
        $results = $database->find($collectionId, [
            Query::contains('meta', [['skills' => 'react']])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc1', $results[0]->getId());

        // Test 5: Create another document with different values
        $doc2 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'meta' => [
                'age' => 30,
                'skills' => ['python', 'java'],
                'user' => [
                    'info' => [
                        'country' => 'US'
                    ]
                ]
            ]
        ]));

        // Test 6: Query should return only doc1
        $results = $database->find($collectionId, [
            Query::equal('meta', [['age' => 25]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc1', $results[0]->getId());

        // Test 7: Query for doc2
        $results = $database->find($collectionId, [
            Query::equal('meta', [[
                'user' => [
                    'info' => [
                        'country' => 'US'
                    ]
                ]
            ]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc2', $results[0]->getId());

        // Test 8: Update document
        $updatedDoc = $database->updateDocument($collectionId, 'doc1', new Document([
            '$id' => 'doc1',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'age' => 26,
                'skills' => ['react', 'node', 'typescript'],
                'user' => [
                    'info' => [
                        'country' => 'CA'
                    ]
                ]
            ]
        ]));

        $this->assertEquals(26, $updatedDoc->getAttribute('meta')['age']);
        $this->assertEquals(['react', 'node', 'typescript'], $updatedDoc->getAttribute('meta')['skills']);
        $this->assertEquals('CA', $updatedDoc->getAttribute('meta')['user']['info']['country']);

        // Test 9: Query updated document
        $results = $database->find($collectionId, [
            Query::equal('meta', [['age' => 26]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc1', $results[0]->getId());

        // Test 10: Query with multiple conditions using contains
        $results = $database->find($collectionId, [
            Query::contains('meta', [['skills' => 'typescript']])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc1', $results[0]->getId());

        // Test 11: Negative test - query that shouldn't match
        $results = $database->find($collectionId, [
            Query::equal('meta', [['age' => 99]])
        ]);
        $this->assertCount(0, $results);

        // Test 11d: notEqual on scalar inside object should exclude doc1
        $results = $database->find($collectionId, [
            Query::notEqual('meta', ['age' => 26])
        ]);
        // Should return doc2 only
        $this->assertCount(1, $results);
        $this->assertEquals('doc2', $results[0]->getId());

        try {
            // test -> not equal allows one value only
            $results = $database->find($collectionId, [
                Query::notEqual('meta', [['age' => 26], ['age' => 27]])
            ]);
            $this->fail('No query thrown');
        } catch (Exception $e) {
            $this->assertInstanceOf(QueryException::class, $e);
        }

        // Test 11e: notEqual on nested object should exclude doc1
        $results = $database->find($collectionId, [
            Query::notEqual('meta', [
                'user' => [
                    'info' => [
                        'country' => 'CA'
                    ]
                ]
            ])
        ]);
        // Should return doc2 only
        $this->assertCount(1, $results);
        $this->assertEquals('doc2', $results[0]->getId());

        // Test 11a: Test getDocument by ID
        $fetchedDoc = $database->getDocument($collectionId, 'doc1');
        $this->assertEquals('doc1', $fetchedDoc->getId());
        $this->assertIsArray($fetchedDoc->getAttribute('meta'));
        $this->assertEquals(26, $fetchedDoc->getAttribute('meta')['age']);
        $this->assertEquals(['react', 'node', 'typescript'], $fetchedDoc->getAttribute('meta')['skills']);
        $this->assertEquals('CA', $fetchedDoc->getAttribute('meta')['user']['info']['country']);

        // Test 11b: Test Query::select to limit returned attributes
        $results = $database->find($collectionId, [
            Query::select(['$id', 'meta']),
            Query::equal('meta', [['age' => 26]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc1', $results[0]->getId());
        $this->assertIsArray($results[0]->getAttribute('meta'));
        $this->assertEquals(26, $results[0]->getAttribute('meta')['age']);

        // Test 11c: Test Query::select with only $id (exclude meta)
        $results = $database->find($collectionId, [
            Query::select(['$id']),
            Query::equal('meta', [['age' => 30]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc2', $results[0]->getId());
        // Meta should not be present when not selected
        $this->assertEmpty($results[0]->getAttribute('meta'));

        // Test 12: Test with null value
        $doc3 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc3',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => null
        ]));
        $this->assertNull($doc3->getAttribute('meta'));

        // Test 13: Test with empty object
        $doc4 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc4',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => []
        ]));
        $this->assertIsArray($doc4->getAttribute('meta'));
        $this->assertEmpty($doc4->getAttribute('meta'));

        // Test 14: Test deeply nested structure (5 levels)
        $doc5 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc5',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => [
                                'level5' => 'deep_value'
                            ]
                        ]
                    ]
                ]
            ]
        ]));
        $this->assertEquals('deep_value', $doc5->getAttribute('meta')['level1']['level2']['level3']['level4']['level5']);

        // Test 15: Query deeply nested structure
        $results = $database->find($collectionId, [
            Query::equal('meta', [[
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => [
                                'level5' => 'deep_value'
                            ]
                        ]
                    ]
                ]
            ]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc5', $results[0]->getId());

        // Test 16: Query partial nested path
        $results = $database->find($collectionId, [
            Query::equal('meta', [[
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => [
                                'level5' => 'deep_value'
                            ]
                        ]
                    ]
                ]
            ]])
        ]);
        $this->assertCount(1, $results);

        // Test 17: Test with mixed data types
        $doc6 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc6',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'string' => 'text',
                'number' => 42,
                'float' => 3.14,
                'boolean' => true,
                'null_value' => null,
                'array' => [1, 2, 3],
                'object' => ['key' => 'value']
            ]
        ]));
        $this->assertEquals('text', $doc6->getAttribute('meta')['string']);
        $this->assertEquals(42, $doc6->getAttribute('meta')['number']);
        $this->assertEquals(3.14, $doc6->getAttribute('meta')['float']);
        $this->assertTrue($doc6->getAttribute('meta')['boolean']);
        $this->assertNull($doc6->getAttribute('meta')['null_value']);

        // Test 18: Query with boolean value
        $results = $database->find($collectionId, [
            Query::equal('meta', [['boolean' => true]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc6', $results[0]->getId());

        // Test 19: Query with numeric value
        $results = $database->find($collectionId, [
            Query::equal('meta', [['number' => 42]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc6', $results[0]->getId());

        // Test 20: Query with float value
        $results = $database->find($collectionId, [
            Query::equal('meta', [['float' => 3.14]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc6', $results[0]->getId());

        // Test 21: Test contains with multiple array elements
        $doc7 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc7',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'tags' => ['php', 'javascript', 'python', 'go', 'rust']
            ]
        ]));
        $results = $database->find($collectionId, [
            Query::contains('meta', [['tags' => 'rust']])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc7', $results[0]->getId());

        // Test 22: Test contains with numeric array element
        $doc8 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc8',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'scores' => [85, 90, 95, 100]
            ]
        ]));
        $results = $database->find($collectionId, [
            Query::contains('meta', [['scores' => 95]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc8', $results[0]->getId());

        // Test 23: Negative test - contains query that shouldn't match
        $results = $database->find($collectionId, [
            Query::contains('meta', [['tags' => 'kotlin']])
        ]);
        $this->assertCount(0, $results);

        // Test 23b: notContains should exclude doc7 (which has 'rust')
        $results = $database->find($collectionId, [
            Query::notContains('meta', [['tags' => 'rust']])
        ]);
        // Should not include doc7; returns others (at least doc1, doc2, ...)
        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $doc) {
            if ($doc->getId() === 'doc7') {
                $this->fail('doc7 should not be returned by notContains for rust');
            }
        }

        // Test 24: Test complex nested array within object
        $doc9 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc9',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'projects' => [
                    [
                        'name' => 'Project A',
                        'technologies' => ['react', 'node'],
                        'active' => true
                    ],
                    [
                        'name' => 'Project B',
                        'technologies' => ['vue', 'python'],
                        'active' => false
                    ]
                ],
                'company' => 'TechCorp'
            ]
        ]));
        $this->assertIsArray($doc9->getAttribute('meta')['projects']);
        $this->assertCount(2, $doc9->getAttribute('meta')['projects']);
        $this->assertEquals('Project A', $doc9->getAttribute('meta')['projects'][0]['name']);

        // Test 25: Query using equal with nested key
        $results = $database->find($collectionId, [
            Query::equal('meta', [['company' => 'TechCorp']])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc9', $results[0]->getId());

        // Test 25b: Query the entire array structure using equal
        $results = $database->find($collectionId, [
            Query::equal('meta', [[
                'projects' => [
                    [
                        'name' => 'Project A',
                        'technologies' => ['react', 'node'],
                        'active' => true
                    ],
                    [
                        'name' => 'Project B',
                        'technologies' => ['vue', 'python'],
                        'active' => false
                    ]
                ]
            ]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc9', $results[0]->getId());

        // Test 26: Test with special characters in values
        $doc10 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc10',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'description' => 'Test with "quotes" and \'apostrophes\'',
                'emoji' => '🚀🎉',
                'symbols' => '@#$%^&*()'
            ]
        ]));
        $this->assertEquals('Test with "quotes" and \'apostrophes\'', $doc10->getAttribute('meta')['description']);
        $this->assertEquals('🚀🎉', $doc10->getAttribute('meta')['emoji']);

        // Test 27: Query with special characters
        $results = $database->find($collectionId, [
            Query::equal('meta', [['emoji' => '🚀🎉']])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc10', $results[0]->getId());

        // Test 28: Test equal query with complete object match
        $doc11 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc11',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'meta' => [
                'config' => [
                    'theme' => 'dark',
                    'language' => 'en'
                ]
            ]
        ]));
        $results = $database->find($collectionId, [
            Query::equal('meta', [['config' => ['theme' => 'dark', 'language' => 'en']]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc11', $results[0]->getId());

        // Test 29: Negative test - partial object match should still work (containment)
        $results = $database->find($collectionId, [
            Query::equal('meta', [['config' => ['theme' => 'dark']]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc11', $results[0]->getId());

        // Test 30: Test updating to empty object
        $updatedDoc11 = $database->updateDocument($collectionId, 'doc11', new Document([
            '$id' => 'doc11',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => []
        ]));
        $this->assertIsArray($updatedDoc11->getAttribute('meta'));
        $this->assertEmpty($updatedDoc11->getAttribute('meta'));

        // Test 31: Test with nested arrays of primitives
        $doc12 = $database->createDocument($collectionId, new Document([
            '$id' => 'doc12',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'matrix' => [
                    [1, 2, 3],
                    [4, 5, 6],
                    [7, 8, 9]
                ]
            ]
        ]));
        $this->assertIsArray($doc12->getAttribute('meta')['matrix']);
        $this->assertEquals([1, 2, 3], $doc12->getAttribute('meta')['matrix'][0]);

        // Test 32: Contains query with nested array
        $results = $database->find($collectionId, [
            Query::contains('meta', [['matrix' => [[4, 5, 6]]]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc12', $results[0]->getId());

        // Test 33: Test getDocument with various documents
        $fetchedDoc6 = $database->getDocument($collectionId, 'doc6');
        $this->assertEquals('doc6', $fetchedDoc6->getId());
        $this->assertEquals('text', $fetchedDoc6->getAttribute('meta')['string']);
        $this->assertEquals(42, $fetchedDoc6->getAttribute('meta')['number']);
        $this->assertTrue($fetchedDoc6->getAttribute('meta')['boolean']);

        $fetchedDoc10 = $database->getDocument($collectionId, 'doc10');
        $this->assertEquals('🚀🎉', $fetchedDoc10->getAttribute('meta')['emoji']);
        $this->assertEquals('Test with "quotes" and \'apostrophes\'', $fetchedDoc10->getAttribute('meta')['description']);

        // Test 34: Test Query::select with complex nested structures
        $results = $database->find($collectionId, [
            Query::select(['$id', '$permissions', 'meta']),
            Query::equal('meta', [[
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => [
                                'level5' => 'deep_value'
                            ]
                        ]
                    ]
                ]
            ]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc5', $results[0]->getId());
        $this->assertEquals('deep_value', $results[0]->getAttribute('meta')['level1']['level2']['level3']['level4']['level5']);

        // Test 35: Test selecting multiple documents and verifying object attributes
        $allDocs = $database->find($collectionId, [
            Query::select(['$id', 'meta']),
            Query::limit(25)
        ]);
        $this->assertGreaterThan(10, count($allDocs));

        // Verify that each document with meta has proper structure
        foreach ($allDocs as $doc) {
            $meta = $doc->getAttribute('meta');
            if ($meta !== null && $meta !== []) {
                $this->assertIsArray($meta, "Document {$doc->getId()} should have array meta");
            }
        }

        // Test 36: Test Query::select with only meta attribute
        $results = $database->find($collectionId, [
            Query::select(['meta']),
            Query::equal('meta', [['tags' => ['php', 'javascript', 'python', 'go', 'rust']]])
        ]);
        $this->assertCount(1, $results);
        $this->assertIsArray($results[0]->getAttribute('meta'));
        $this->assertEquals(['php', 'javascript', 'python', 'go', 'rust'], $results[0]->getAttribute('meta')['tags']);

        // Clean up
        $database->deleteCollection($collectionId);
    }

    public function testObjectAttributeGinIndex(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForObjectIndexes()) {
            $this->markTestSkipped('Adapter does not support object indexes');
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);

        // Create object attribute
        $this->createAttribute($database, $collectionId, 'data', Database::VAR_OBJECT, 0, false);

        // Test 1: Create Object index on object attribute
        $ginIndex = $database->createIndex($collectionId, 'idx_data_gin', Database::INDEX_OBJECT, ['data']);
        $this->assertTrue($ginIndex);

        // Test 2: Create documents with JSONB data
        $doc1 = $database->createDocument($collectionId, new Document([
            '$id' => 'gin1',
            '$permissions' => [Permission::read(Role::any())],
            'data' => [
                'tags' => ['php', 'javascript', 'python'],
                'config' => [
                    'env' => 'production',
                    'debug' => false
                ],
                'version' => '1.0.0'
            ]
        ]));

        $doc2 = $database->createDocument($collectionId, new Document([
            '$id' => 'gin2',
            '$permissions' => [Permission::read(Role::any())],
            'data' => [
                'tags' => ['java', 'kotlin', 'scala'],
                'config' => [
                    'env' => 'development',
                    'debug' => true
                ],
                'version' => '2.0.0'
            ]
        ]));

        // Test 3: Query with equal on indexed JSONB column
        $results = $database->find($collectionId, [
            Query::equal('data', [['config' => ['env' => 'production']]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('gin1', $results[0]->getId());

        // Test 4: Query with contains on indexed JSONB column
        $results = $database->find($collectionId, [
            Query::contains('data', [['tags' => 'php']])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('gin1', $results[0]->getId());

        // Test 5: Verify Object index improves performance for containment queries
        $results = $database->find($collectionId, [
            Query::contains('data', [['tags' => 'kotlin']])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('gin2', $results[0]->getId());

        // Test 6: Try to create Object index on non-object attribute (should fail)
        $this->createAttribute($database, $collectionId, 'name', Database::VAR_STRING, 255, false);

        $exceptionThrown = false;
        try {
            $database->createIndex($collectionId, 'idx_name_gin', Database::INDEX_OBJECT, ['name']);
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $this->assertInstanceOf(IndexException::class, $e);
            $this->assertStringContainsString('Object index can only be created on object attributes', $e->getMessage());
        }
        $this->assertTrue($exceptionThrown, 'Expected Index exception for Object index on non-object attribute');

        // Test 7: Try to create Object index on multiple attributes (should fail)
        $this->createAttribute($database, $collectionId, 'metadata', Database::VAR_OBJECT, 0, false);

        $exceptionThrown = false;
        try {
            $database->createIndex($collectionId, 'idx_multi_gin', Database::INDEX_OBJECT, ['data', 'metadata']);
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $this->assertInstanceOf(IndexException::class, $e);
            $this->assertStringContainsString('Object index can be created on a single object attribute', $e->getMessage());
        }
        $this->assertTrue($exceptionThrown, 'Expected Index exception for Object index on multiple attributes');

        // Test 8: Try to create Object index with orders (should fail)
        $exceptionThrown = false;
        try {
            $database->createIndex($collectionId, 'idx_ordered_gin', Database::INDEX_OBJECT, ['metadata'], [], [Database::ORDER_ASC]);
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $this->assertInstanceOf(IndexException::class, $e);
            $this->assertStringContainsString('Object index do not support explicit orders', $e->getMessage());
        }
        $this->assertTrue($exceptionThrown, 'Expected Index exception for Object index with orders');

        // Clean up
        $database->deleteCollection($collectionId);
    }

    public function testObjectAttributeInvalidCases(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Skip test if adapter doesn't support JSONB
        if (!$database->getAdapter()->getSupportForObject() || !$database->getAdapter()->getSupportForAttributes()) {
            $this->markTestSkipped('Adapter does not support object attributes');
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);

        // Create object attribute
        $this->createAttribute($database, $collectionId, 'meta', Database::VAR_OBJECT, 0, false);

        // Test 1: Try to create document with string instead of object (should fail)
        $exceptionThrown = false;
        try {
            $database->createDocument($collectionId, new Document([
                '$id' => 'invalid1',
                '$permissions' => [Permission::read(Role::any())],
                'meta' => 'this is a string not an object'
            ]));
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $this->assertInstanceOf(StructureException::class, $e);
        }
        $this->assertTrue($exceptionThrown, 'Expected Structure exception for string value');

        // Test 2: Try to create document with integer instead of object (should fail)
        $exceptionThrown = false;
        try {
            $database->createDocument($collectionId, new Document([
                '$id' => 'invalid2',
                '$permissions' => [Permission::read(Role::any())],
                'meta' => 12345
            ]));
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $this->assertInstanceOf(StructureException::class, $e);
        }
        $this->assertTrue($exceptionThrown, 'Expected Structure exception for integer value');

        // Test 3: Try to create document with boolean instead of object (should fail)
        $exceptionThrown = false;
        try {
            $database->createDocument($collectionId, new Document([
                '$id' => 'invalid3',
                '$permissions' => [Permission::read(Role::any())],
                'meta' => true
            ]));
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $this->assertInstanceOf(StructureException::class, $e);
        }
        $this->assertTrue($exceptionThrown, 'Expected Structure exception for boolean value');

        // Test 4: Create valid document for query tests
        $database->createDocument($collectionId, new Document([
            '$id' => 'valid1',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'name' => 'John',
                'age' => 30,
                'settings' => [
                    'notifications' => true,
                    'theme' => 'dark'
                ]
            ]
        ]));

        // Test 5: Query with non-matching nested structure
        $results = $database->find($collectionId, [
            Query::equal('meta', [['settings' => ['notifications' => false]]])
        ]);
        $this->assertCount(0, $results, 'Should not match when nested value differs');

        // Test 6: Query with non-existent key
        $results = $database->find($collectionId, [
            Query::equal('meta', [['nonexistent' => 'value']])
        ]);
        $this->assertCount(0, $results, 'Should not match non-existent keys');

        // Test 7: Contains query with non-matching array element
        $database->createDocument($collectionId, new Document([
            '$id' => 'valid2',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'fruits' => ['apple', 'banana', 'orange']
            ]
        ]));
        $results = $database->find($collectionId, [
            Query::contains('meta', [['fruits' => 'grape']])
        ]);
        $this->assertCount(0, $results, 'Should not match non-existent array element');

        // Test 8: Test order preservation in nested objects
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'order_test',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'z_last' => 'value',
                'a_first' => 'value',
                'm_middle' => 'value'
            ]
        ]));
        $meta = $doc->getAttribute('meta');
        $this->assertIsArray($meta);
        // Note: JSON objects don't guarantee key order, but we can verify all keys exist
        $this->assertArrayHasKey('z_last', $meta);
        $this->assertArrayHasKey('a_first', $meta);
        $this->assertArrayHasKey('m_middle', $meta);

        // Test 9: Test with very large nested structure
        $largeStructure = [];
        for ($i = 0; $i < 50; $i++) {
            $largeStructure["key_$i"] = [
                'id' => $i,
                'name' => "Item $i",
                'values' => range(1, 10)
            ];
        }
        $docLarge = $database->createDocument($collectionId, new Document([
            '$id' => 'large_structure',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => $largeStructure
        ]));
        $this->assertIsArray($docLarge->getAttribute('meta'));
        $this->assertCount(50, $docLarge->getAttribute('meta'));

        // Test 10: Query within large structure
        $results = $database->find($collectionId, [
            Query::equal('meta', [['key_25' => ['id' => 25, 'name' => 'Item 25', 'values' => range(1, 10)]]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('large_structure', $results[0]->getId());

        // Test 11: Test getDocument with large structure
        $fetchedLargeDoc = $database->getDocument($collectionId, 'large_structure');
        $this->assertEquals('large_structure', $fetchedLargeDoc->getId());
        $this->assertIsArray($fetchedLargeDoc->getAttribute('meta'));
        $this->assertCount(50, $fetchedLargeDoc->getAttribute('meta'));
        $this->assertEquals(25, $fetchedLargeDoc->getAttribute('meta')['key_25']['id']);
        $this->assertEquals('Item 25', $fetchedLargeDoc->getAttribute('meta')['key_25']['name']);

        // Test 12: Test Query::select with valid document
        $results = $database->find($collectionId, [
            Query::select(['$id', 'meta']),
            Query::equal('meta', [['name' => 'John']])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('valid1', $results[0]->getId());
        $this->assertIsArray($results[0]->getAttribute('meta'));
        $this->assertEquals('John', $results[0]->getAttribute('meta')['name']);
        $this->assertEquals(30, $results[0]->getAttribute('meta')['age']);

        // Test 13: Test getDocument returns proper structure
        $fetchedValid1 = $database->getDocument($collectionId, 'valid1');
        $this->assertEquals('valid1', $fetchedValid1->getId());
        $this->assertIsArray($fetchedValid1->getAttribute('meta'));
        $this->assertEquals('John', $fetchedValid1->getAttribute('meta')['name']);
        $this->assertTrue($fetchedValid1->getAttribute('meta')['settings']['notifications']);
        $this->assertEquals('dark', $fetchedValid1->getAttribute('meta')['settings']['theme']);

        // Test 14: Test Query::select excluding meta
        $results = $database->find($collectionId, [
            Query::select(['$id', '$permissions']),
            Query::equal('meta', [['fruits' => ['apple', 'banana', 'orange']]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('valid2', $results[0]->getId());
        // Meta should be empty when not selected
        $this->assertEmpty($results[0]->getAttribute('meta'));

        // Test 15: Test getDocument with non-existent ID returns empty document
        $nonExistent = $database->getDocument($collectionId, 'does_not_exist');
        $this->assertTrue($nonExistent->isEmpty());

        // Test 16: with multiple json
        $defaultSettings = ['config' => ['theme' => 'light', 'lang' => 'en']];
        $this->createAttribute($database, $collectionId, 'settings', Database::VAR_OBJECT, 0, false, $defaultSettings);
        $database->createDocument($collectionId, new Document(['$permissions' => [Permission::read(Role::any())]]));
        $database->createDocument($collectionId, new Document(['settings' => ['config' => ['theme' => 'dark', 'lang' => 'en']], '$permissions' => [Permission::read(Role::any())]]));
        $results = $database->find($collectionId, [
            Query::equal('settings', [['config' => ['theme' => 'light']], ['config' => ['theme' => 'dark']]])
        ]);
        $this->assertCount(2, $results);

        $results = $database->find($collectionId, [
            // Containment: both documents have config.lang == 'en'
            Query::contains('settings', [['config' => ['lang' => 'en']]])
        ]);
        $this->assertCount(2, $results);

        // Clean up
        $database->deleteCollection($collectionId);
    }

    public function testObjectAttributeDefaults(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Skip test if adapter doesn't support JSONB
        if (!$database->getAdapter()->getSupportForObject() || !$database->getAdapter()->getSupportForAttributes()) {
            $this->markTestSkipped('Adapter does not support object attributes');
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);

        // 1) Default empty object
        $this->createAttribute($database, $collectionId, 'metaDefaultEmpty', Database::VAR_OBJECT, 0, false, []);

        // 2) Default nested object
        $defaultSettings = ['config' => ['theme' => 'light', 'lang' => 'en']];
        $this->createAttribute($database, $collectionId, 'settings', Database::VAR_OBJECT, 0, false, $defaultSettings);

        // 3) Required without default (should fail when missing)
        $this->createAttribute($database, $collectionId, 'profile', Database::VAR_OBJECT, 0, true, null);

        // 4) Required with default (should auto-populate)
        $this->createAttribute($database, $collectionId, 'profile2', Database::VAR_OBJECT, 0, false, ['name' => 'anon']);

        // 5) Explicit null default
        $this->createAttribute($database, $collectionId, 'misc', Database::VAR_OBJECT, 0, false, null);

        // Create document missing all above attributes
        $exceptionThrown = false;
        try {
            $doc = $database->createDocument($collectionId, new Document([
                '$id' => 'def1',
                '$permissions' => [Permission::read(Role::any())],
            ]));
            // Should not reach here because 'profile' is required and missing
        } catch (\Exception $e) {
            $exceptionThrown = true;
            $this->assertInstanceOf(StructureException::class, $e);
        }
        $this->assertTrue($exceptionThrown, 'Expected Structure exception for missing required object attribute');

        // Create document providing required 'profile' but omit others to test defaults
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'def2',
            '$permissions' => [Permission::read(Role::any())],
            'profile' => ['name' => 'provided'],
        ]));

        // metaDefaultEmpty should default to []
        $this->assertIsArray($doc->getAttribute('metaDefaultEmpty'));
        $this->assertEmpty($doc->getAttribute('metaDefaultEmpty'));

        // settings should default to nested object
        $this->assertIsArray($doc->getAttribute('settings'));
        $this->assertEquals('light', $doc->getAttribute('settings')['config']['theme']);
        $this->assertEquals('en', $doc->getAttribute('settings')['config']['lang']);

        // profile provided explicitly
        $this->assertEquals('provided', $doc->getAttribute('profile')['name']);

        // profile2 required with default should be auto-populated
        $this->assertIsArray($doc->getAttribute('profile2'));
        $this->assertEquals('anon', $doc->getAttribute('profile2')['name']);

        // misc explicit null default remains null when omitted
        $this->assertNull($doc->getAttribute('misc'));

        // Query defaults work
        $results = $database->find($collectionId, [
            Query::equal('settings', [['config' => ['theme' => 'light']]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('def2', $results[0]->getId());

        // Clean up
        $database->deleteCollection($collectionId);
    }

    public function testObjectAttributeIntegersBeyondInt32(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForObject()) {
            $this->markTestSkipped('Adapter does not support object attributes');
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);
        $this->createAttribute($database, $collectionId, 'meta', Database::VAR_OBJECT, 0, false);

        // An object attribute has no per-key schema, so there is no typed cast
        // to lean on: whatever the adapter decodes is what reaches the client.
        $database->createDocument($collectionId, new Document([
            '$id' => 'bigInts',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'small' => -42,
                'count' => -3408048000,
                'nested' => ['deep' => 3408048000],
            ],
        ]));

        $database->purgeCachedDocument($collectionId, 'bigInts');
        $meta = $database->getDocument($collectionId, 'bigInts')->getAttribute('meta');

        $this->assertIsInt($meta['small']);
        $this->assertIsInt($meta['count']);
        $this->assertIsInt($meta['nested']['deep']);
        $this->assertEquals([
            'small' => -42,
            'count' => -3408048000,
            'nested' => ['deep' => 3408048000],
        ], $meta);

        // A BSON wrapper serialises as {"$numberLong":"..."}, which is what
        // would reach an API client. Key order is not asserted because jsonb
        // does not preserve it.
        $this->assertStringNotContainsString('$numberLong', json_encode($meta, JSON_THROW_ON_ERROR));

        $database->deleteCollection($collectionId);
    }

    public function testObjectAttributeEmptyObject(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForObject()) {
            $this->markTestSkipped('Adapter does not support object attributes');
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);
        $this->createAttribute($database, $collectionId, 'meta', Database::VAR_OBJECT, 0, false);

        $created = $database->createDocument($collectionId, new Document([
            '$id' => 'emptyObject',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => new \stdClass(),
        ]));

        $this->assertSame('{}', json_encode($created->getAttribute('meta')));

        $database->purgeCachedDocument($collectionId, 'emptyObject');
        $read = $database->getDocument($collectionId, 'emptyObject');

        $this->assertSame('{}', json_encode($read->getAttribute('meta')));
        $cached = $database->getDocument($collectionId, 'emptyObject');
        $this->assertSame('{}', json_encode($cached->getAttribute('meta')));

        $updated = $database->updateDocument($collectionId, 'emptyObject', new Document([
            'meta' => new \stdClass(),
        ]));
        $this->assertSame($cached->getUpdatedAt(), $updated->getUpdatedAt());
        $this->assertSame('{}', json_encode($updated->getAttribute('meta')));

        try {
            $database->updateDocument($collectionId, 'emptyObject', new Document([
                'meta' => [],
            ]));
            $this->fail('Changing an empty object to an empty array must require update permission');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $database->deleteCollection($collectionId);
    }

    public function testObjectAttributeNestedEmptyObjects(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForObject()) {
            $this->markTestSkipped('Adapter does not support object attributes');
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);
        $this->createAttribute($database, $collectionId, 'meta', Database::VAR_OBJECT, 0, false);

        $created = $database->createDocument($collectionId, new Document([
            '$id' => 'nestedEmptyObjects',
            '$permissions' => [Permission::read(Role::any())],
            'meta' => [
                'inner' => new \stdClass(),
                'arr' => [new \stdClass(), ['x' => 1]],
                'emptyArray' => [],
            ],
        ]));
        $createdMeta = $created->getAttribute('meta');
        $this->assertSame('{}', json_encode($createdMeta['inner']));
        $this->assertSame('{}', json_encode($createdMeta['arr'][0]));
        $this->assertSame('{"x":1}', json_encode($createdMeta['arr'][1]));
        $this->assertSame('[]', json_encode($createdMeta['emptyArray']));

        $database->purgeCachedDocument($collectionId, 'nestedEmptyObjects');
        $readMeta = $database->getDocument($collectionId, 'nestedEmptyObjects')->getAttribute('meta');
        $this->assertSame('{}', json_encode($readMeta['inner']));
        $this->assertSame('{}', json_encode($readMeta['arr'][0]));
        $this->assertSame('{"x":1}', json_encode($readMeta['arr'][1]));
        $this->assertSame('[]', json_encode($readMeta['emptyArray']));

        $cached = $database->getDocument($collectionId, 'nestedEmptyObjects');
        $cachedMeta = $cached->getAttribute('meta');
        $this->assertSame('{}', json_encode($cachedMeta['inner']));
        $this->assertSame('{}', json_encode($cachedMeta['arr'][0]));
        $this->assertSame('{"x":1}', json_encode($cachedMeta['arr'][1]));
        $this->assertSame('[]', json_encode($cachedMeta['emptyArray']));

        $updatedMeta = $cachedMeta;
        $updatedMeta['inner'] = new \stdClass();
        $updatedMeta['arr'][0] = new \stdClass();
        $updated = $database->updateDocument($collectionId, 'nestedEmptyObjects', new Document([
            'meta' => $updatedMeta,
        ]));
        $this->assertSame($cached->getUpdatedAt(), $updated->getUpdatedAt());

        $database->deleteCollection($collectionId);
    }

    public function testMetadataWithVector(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Skip if adapter doesn't support either vectors or object attributes
        if (!$database->getAdapter()->getSupportForVectors() || !$database->getAdapter()->getSupportForObject()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);

        // Attributes: 3D vector and nested metadata object
        $this->createAttribute($database, $collectionId, 'embedding', Database::VAR_VECTOR, 3, true);
        $this->createAttribute($database, $collectionId, 'metadata', Database::VAR_OBJECT, 0, false);

        // Seed documents
        $docA = $database->createDocument($collectionId, new Document([
            '$id' => 'vecA',
            '$permissions' => [Permission::read(Role::any())],
            'embedding' => [0.1, 0.2, 0.9],
            'metadata' => [
                'profile' => [
                    'user' => [
                        'info' => [
                            'country' => 'IN',
                            'score' => 100
                        ]
                    ]
                ],
                'tags' => ['ai', 'ml', 'db'],
                'settings' => [
                    'prefs' => [
                        'theme' => 'dark',
                        'features' => [
                            'experimental' => true
                        ]
                    ]
                ]
            ]
        ]));

        $docB = $database->createDocument($collectionId, new Document([
            '$id' => 'vecB',
            '$permissions' => [Permission::read(Role::any())],
            'embedding' => [0.2, 0.9, 0.1],
            'metadata' => [
                'profile' => [
                    'user' => [
                        'info' => [
                            'country' => 'US',
                            'score' => 80
                        ]
                    ]
                ],
                'tags' => ['search', 'analytics'],
                'settings' => [
                    'prefs' => [
                        'theme' => 'light'
                    ]
                ]
            ]
        ]));

        $docC = $database->createDocument($collectionId, new Document([
            '$id' => 'vecC',
            '$permissions' => [Permission::read(Role::any())],
            'embedding' => [0.9, 0.1, 0.2],
            'metadata' => [
                'profile' => [
                    'user' => [
                        'info' => [
                            'country' => 'CA',
                            'score' => 60
                        ]
                    ]
                ],
                'tags' => ['ml', 'cv'],
                'settings' => [
                    'prefs' => [
                        'theme' => 'dark',
                        'features' => [
                            'experimental' => false
                        ]
                    ]
                ]
            ]
        ]));

        // 1) Vector similarity: closest to [0.0, 0.0, 1.0] should be vecA
        $results = $database->find($collectionId, [
            Query::vectorCosine('embedding', [0.0, 0.0, 1.0]),
            Query::limit(1)
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('vecA', $results[0]->getId());

        // 2) Complex nested metadata equal (partial object containment)
        $results = $database->find($collectionId, [
            Query::equal('metadata', [[
                'profile' => [
                    'user' => [
                        'info' => [
                            'country' => 'IN'
                        ]
                    ]
                ]
            ]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('vecA', $results[0]->getId());

        // 3) Contains on nested array inside metadata
        $results = $database->find($collectionId, [
            Query::contains('metadata', [[
                'tags' => 'ml'
            ]])
        ]);
        $this->assertCount(2, $results); // vecA, vecC both have 'ml' in tags

        // 4) Combine vector query with nested metadata filters
        $results = $database->find($collectionId, [
            Query::vectorEuclidean('embedding', [0.0, 1.0, 0.0]),
            Query::equal('metadata', [[
                'settings' => [
                    'prefs' => [
                        'theme' => 'light'
                    ]
                ]
            ]]),
            Query::limit(1)
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('vecB', $results[0]->getId());

        // 5) Deep partial containment with boolean nested value
        $results = $database->find($collectionId, [
            Query::equal('metadata', [[
                'settings' => [
                    'prefs' => [
                        'features' => [
                            'experimental' => true
                        ]
                    ]
                ]
            ]])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('vecA', $results[0]->getId());

        // Cleanup
        $database->deleteCollection($collectionId);
    }

    public function testNestedObjectAttributeIndexes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->markTestSkipped('Adapter does not support attributes (schemaful required for nested object attribute indexes)');
        }

        if (!$database->getAdapter()->getSupportForObjectIndexes()) {
            $this->markTestSkipped('Adapter does not support object attributes');
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);

        // Base attributes
        $this->createAttribute($database, $collectionId, 'profile', Database::VAR_OBJECT, 0, false);
        $this->createAttribute($database, $collectionId, 'name', Database::VAR_STRING, 255, false);

        // 1) KEY index on a nested object path (dot notation)


        // 2) UNIQUE index on a nested object path should enforce uniqueness on insert
        $created = $database->createIndex($collectionId, 'idx_profile_email_unique', Database::INDEX_UNIQUE, ['profile.user.email']);
        $this->assertTrue($created);

        $database->createDocument($collectionId, new Document([
            '$id' => 'nest1',
            '$permissions' => [Permission::read(Role::any())],
            'profile' => [
                'user' => [
                    'email' => 'a@example.com',
                    'info' => [
                        'country' => 'IN'
                    ]
                ]
            ]
        ]));

        try {
            $database->createDocument($collectionId, new Document([
                '$id' => 'nest2',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'a@example.com', // duplicate
                        'info' => [
                            'country' => 'US'
                        ]
                    ]
                ]
            ]));
            $this->fail('Expected Duplicate exception for UNIQUE index on nested object path');
        } catch (Exception $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }

        // 3) INDEX_OBJECT must NOT be allowed on nested paths
        try {
            $database->createIndex($collectionId, 'idx_profile_nested_object', Database::INDEX_OBJECT, ['profile.user.email']);
        } catch (Exception $e) {
            $this->assertInstanceOf(IndexException::class, $e);
        }

        // 4) Nested path indexes must only be allowed when base attribute is VAR_OBJECT
        try {
            $database->createIndex($collectionId, 'idx_name_nested', Database::INDEX_KEY, ['name.first']);
            $this->fail('Expected Type exception for nested index on non-object base attribute');
        } catch (Exception $e) {
            $this->assertInstanceOf(IndexException::class, $e);
        }

        $database->deleteCollection($collectionId);
    }

    public function testQueryNestedAttribute(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->markTestSkipped('Adapter does not support attributes (schemaful required for nested object attribute indexes)');
        }

        if (!$database->getAdapter()->getSupportForObjectIndexes()) {
            $this->markTestSkipped('Adapter does not support object attributes');
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);

        // Base attributes
        $this->createAttribute($database, $collectionId, 'profile', Database::VAR_OBJECT, 0, false);
        $this->createAttribute($database, $collectionId, 'name', Database::VAR_STRING, 255, false);

        // Create index on nested email path
        $created = $database->createIndex($collectionId, 'idx_profile_email', Database::INDEX_KEY, ['profile.user.email']);
        $this->assertTrue($created);

        // Seed documents with different nested values
        $database->createDocuments($collectionId, [
            new Document([
                '$id' => 'd1',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'alice@example.com',
                        'info' => [
                            'country' => 'IN',
                            'city' => 'BLR'
                        ]
                    ]
                ],
                'name' => 'Alice'
            ]),
            new Document([
                '$id' => 'd2',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'bob@example.com',
                        'info' => [
                            'country' => 'US',
                            'city' => 'NYC'
                        ]
                    ]
                ],
                'name' => 'Bob'
            ]),
            new Document([
                '$id' => 'd3',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'carol@test.org',
                        'info' => [
                            'country' => 'CA',
                            'city' => 'TOR'
                        ]
                    ]
                ],
                'name' => 'Carol'
            ])
        ]);

        // Equal on nested email
        $results = $database->find($collectionId, [
            Query::equal('profile.user.email', ['bob@example.com'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('d2', $results[0]->getId());

        // Starts with on nested email
        $results = $database->find($collectionId, [
            Query::startsWith('profile.user.email', 'alice@')
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('d1', $results[0]->getId());

        // Ends with on nested email
        $results = $database->find($collectionId, [
            Query::endsWith('profile.user.email', 'test.org')
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('d3', $results[0]->getId());

        // Contains on nested country (as text)
        $results = $database->find($collectionId, [
            Query::contains('profile.user.info.country', ['US'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('d2', $results[0]->getId());

        // AND: country IN + email suffix
        $results = $database->find($collectionId, [
            Query::and([
                Query::equal('profile.user.info.country', ['IN']),
                Query::endsWith('profile.user.email', 'example.com'),
            ])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('d1', $results[0]->getId());

        // OR: match either country = CA or email starts with bob@
        $results = $database->find($collectionId, [
            Query::or([
                Query::equal('profile.user.info.country', ['CA']),
                Query::startsWith('profile.user.email', 'bob@'),
            ])
        ]);
        $this->assertCount(2, $results);
        $ids = \array_map(fn (Document $d) => $d->getId(), $results);
        \sort($ids);
        $this->assertEquals(['d2', 'd3'], $ids);

        // NOT: exclude emails ending with example.com
        $results = $database->find($collectionId, [
            Query::notEndsWith('profile.user.email', 'example.com')
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('d3', $results[0]->getId());

        $database->deleteCollection($collectionId);
    }

    public function testNestedObjectAttributeEdgeCases(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForObject()) {
            $this->markTestSkipped('Adapter does not support object attributes');
        }

        $collectionId = ID::unique();
        $database->createCollection($collectionId);

        // Base attributes
        $this->createAttribute($database, $collectionId, 'profile', Database::VAR_OBJECT, 0, false);
        $this->createAttribute($database, $collectionId, 'name', Database::VAR_STRING, 255, false);
        $this->createAttribute($database, $collectionId, 'age', Database::VAR_INTEGER, 0, false);

        // Edge Case 1: Deep nesting (5 levels deep)
        $created = $database->createIndex($collectionId, 'idx_deep_nest', Database::INDEX_KEY, ['profile.level1.level2.level3.level4.value']);
        $this->assertTrue($created);

        $database->createDocuments($collectionId, [
            new Document([
                '$id' => 'deep1',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'level1' => [
                        'level2' => [
                            'level3' => [
                                'level4' => [
                                    'value' => 'deep_value_1'
                                ]
                            ]
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'deep2',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'level1' => [
                        'level2' => [
                            'level3' => [
                                'level4' => [
                                    'value' => 'deep_value_2'
                                ]
                            ]
                        ]
                    ]
                ]
            ])
        ]);

        if ($database->getAdapter()->getSupportForAttributes()) {
            try {
                $database->find($collectionId, [
                    Query::equal('profile.level1.level2.level3.level4.value', [10])
                ]);
                $this->fail('Expected nesting as string');
            } catch (Exception $e) {
                $this->assertInstanceOf(QueryException::class, $e);
                $this->assertEquals('Invalid query: Query value is invalid for attribute "profile"', $e->getMessage());
            }
        }

        $results = $database->find($collectionId, [
            Query::equal('profile.level1.level2.level3.level4.value', ['deep_value_1'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('deep1', $results[0]->getId());

        // Edge Case 2: Multiple nested indexes on same base attribute
        $created = $database->createIndex($collectionId, 'idx_email', Database::INDEX_KEY, ['profile.user.email']);
        $this->assertTrue($created);
        $created = $database->createIndex($collectionId, 'idx_country', Database::INDEX_KEY, ['profile.user.info.country']);
        $this->assertTrue($created);
        $created = $database->createIndex($collectionId, 'idx_city', Database::INDEX_KEY, ['profile.user.info.city']);
        $this->assertTrue($created);

        $database->createDocuments($collectionId, [
            new Document([
                '$id' => 'multi1',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'multi1@test.com',
                        'info' => [
                            'country' => 'US',
                            'city' => 'NYC'
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'multi2',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'multi2@test.com',
                        'info' => [
                            'country' => 'CA',
                            'city' => 'TOR'
                        ]
                    ]
                ]
            ])
        ]);

        // Query using first nested index
        $results = $database->find($collectionId, [
            Query::equal('profile.user.email', ['multi1@test.com'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('multi1', $results[0]->getId());

        // Query using second nested index
        $results = $database->find($collectionId, [
            Query::equal('profile.user.info.country', ['US'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('multi1', $results[0]->getId());

        // Query using third nested index
        $results = $database->find($collectionId, [
            Query::equal('profile.user.info.city', ['TOR'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('multi2', $results[0]->getId());

        // Edge Case 3: Null/missing nested values
        $database->createDocuments($collectionId, [
            new Document([
                '$id' => 'null1',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => null, // null value
                        'info' => [
                            'country' => 'US'
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'null2',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        // missing email key entirely
                        'info' => [
                            'country' => 'CA'
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'null3',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => null // entire profile is null
            ])
        ]);

        // Query for null email should not match null1 (null values typically don't match equal queries)
        $results = $database->find($collectionId, [
            Query::equal('profile.user.email', ['non-existent@test.com'])
        ]);
        // Should not include null1, null2, or null3
        foreach ($results as $doc) {
            $this->assertNotEquals('null1', $doc->getId());
            $this->assertNotEquals('null2', $doc->getId());
            $this->assertNotEquals('null3', $doc->getId());
        }

        // Edge Case 4: Mixed queries (nested + regular attributes)
        $database->createDocuments($collectionId, [
            new Document([
                '$id' => 'mixed1',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'name' => 'Alice',
                'age' => 25,
                'profile' => [
                    'user' => [
                        'email' => 'alice.mixed@test.com',
                        'info' => [
                            'country' => 'US'
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'mixed2',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'name' => 'Bob',
                'age' => 30,
                'profile' => [
                    'user' => [
                        'email' => 'bob.mixed@test.com',
                        'info' => [
                            'country' => 'CA'
                        ]
                    ]
                ]
            ])
        ]);

        // Create indexes on regular attributes
        $database->createIndex($collectionId, 'idx_name', Database::INDEX_KEY, ['name']);
        $database->createIndex($collectionId, 'idx_age', Database::INDEX_KEY, ['age']);

        // Combined query: nested path + regular attribute
        $results = $database->find($collectionId, [
            Query::equal('profile.user.info.country', ['US']),
            Query::equal('name', ['Alice'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('mixed1', $results[0]->getId());

        // Combined query: nested path + regular attribute with AND
        $results = $database->find($collectionId, [
            Query::and([
                Query::equal('profile.user.email', ['bob.mixed@test.com']),
                Query::equal('age', [30])
            ])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('mixed2', $results[0]->getId());

        // Edge Case 5: Update operations affecting nested indexed paths
        $database->updateDocument($collectionId, 'mixed1', new Document([
            '$id' => 'mixed1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'Alice Updated',
            'age' => 26,
            'profile' => [
                'user' => [
                    'email' => 'alice.updated@test.com', // changed email
                    'info' => [
                        'country' => 'CA' // changed country
                    ]
                ]
            ]
        ]));

        // Query with old email should not match
        $results = $database->find($collectionId, [
            Query::equal('profile.user.email', ['alice.mixed@test.com'])
        ]);
        foreach ($results as $doc) {
            $this->assertNotEquals('mixed1', $doc->getId());
        }

        // Query with new email should match
        $results = $database->find($collectionId, [
            Query::equal('profile.user.email', ['alice.updated@test.com'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('mixed1', $results[0]->getId());

        // Query with new country should match
        $results = $database->find($collectionId, [
            Query::equal('profile.user.info.country', ['CA'])
        ]);
        $this->assertGreaterThanOrEqual(2, count($results)); // Should include mixed1 and mixed2

        // Edge Case 6: Query on non-indexed nested path
        $database->createDocuments($collectionId, [
            new Document([
                '$id' => 'noindex1',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'noindex1@test.com',
                        'info' => [
                            'country' => 'US',
                            'phone' => '+1234567890' // no index on this path
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'noindex2',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'noindex2@test.com',
                        'info' => [
                            'country' => 'CA',
                            'phone' => '+9876543210' // no index on this path
                        ]
                    ]
                ]
            ])
        ]);

        // Query on non-indexed nested path should still work
        $results = $database->find($collectionId, [
            Query::equal('profile.user.info.phone', ['+1234567890'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('noindex1', $results[0]->getId());

        // Edge Case 7: Complex query combinations with nested paths
        $database->createDocuments($collectionId, [
            new Document([
                '$id' => 'complex1',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'complex1@test.com',
                        'info' => [
                            'country' => 'US',
                            'city' => 'NYC',
                            'zip' => '10001'
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'complex2',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'complex2@test.com',
                        'info' => [
                            'country' => 'US',
                            'city' => 'LAX',
                            'zip' => '90001'
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'complex3',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'complex3@test.com',
                        'info' => [
                            'country' => 'CA',
                            'city' => 'TOR',
                            'zip' => 'M5H1A1'
                        ]
                    ]
                ]
            ])
        ]);

        // Complex AND with multiple nested paths
        $results = $database->find($collectionId, [
            Query::and([
                Query::equal('profile.user.info.country', ['US']),
                Query::equal('profile.user.info.city', ['NYC'])
            ])
        ]);

        $this->assertCount(2, $results);

        // Complex OR with nested paths
        $results = $database->find($collectionId, [
            Query::or([
                Query::equal('profile.user.info.city', ['NYC']),
                Query::equal('profile.user.info.city', ['TOR'])
            ])
        ]);
        $this->assertCount(4, $results);
        $ids = \array_map(fn (Document $d) => $d->getId(), $results);
        \sort($ids);
        $this->assertEquals(['complex1', 'complex3','multi1','multi2'], $ids);

        // Complex nested AND/OR combination
        $results = $database->find($collectionId, [
            Query::and([
                Query::equal('profile.user.info.country', ['US']),
                Query::or([
                    Query::equal('profile.user.info.city', ['NYC']),
                    Query::equal('profile.user.info.city', ['LAX'])
                ])
            ])
        ]);
        $this->assertCount(3, $results);
        $ids = \array_map(fn (Document $d) => $d->getId(), $results);
        \sort($ids);
        $this->assertEquals(['complex1', 'complex2', 'multi1'], $ids);

        // Edge Case 8: Order/limit/offset with nested queries
        $database->createDocuments($collectionId, [
            new Document([
                '$id' => 'order1',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'a@order.com',
                        'info' => [
                            'country' => 'US'
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'order2',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'b@order.com',
                        'info' => [
                            'country' => 'US'
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'order3',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'c@order.com',
                        'info' => [
                            'country' => 'US'
                        ]
                    ]
                ]
            ])
        ]);

        // Limit with nested query
        $results = $database->find($collectionId, [
            Query::equal('profile.user.info.country', ['US']),
            Query::limit(2)
        ]);
        $this->assertCount(2, $results);

        // Offset with nested query
        $results = $database->find($collectionId, [
            Query::equal('profile.user.info.country', ['US']),
            Query::offset(1),
            Query::limit(1)
        ]);
        $this->assertCount(1, $results);

        // Edge Case 9: Empty strings in nested paths
        $database->createDocuments($collectionId, [
            new Document([
                '$id' => 'empty1',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => '', // empty string
                        'info' => [
                            'country' => 'US'
                        ]
                    ]
                ]
            ])
        ]);

        // Query for empty string
        $results = $database->find($collectionId, [
            Query::equal('profile.user.email', [''])
        ]);
        $this->assertGreaterThanOrEqual(1, count($results));
        $found = false;
        foreach ($results as $doc) {
            if ($doc->getId() === 'empty1') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should find document with empty email');

        // Edge Case 10: Index deletion and re-creation
        $database->deleteIndex($collectionId, 'idx_email');

        // Query should still work without index (just slower)
        $results = $database->find($collectionId, [
            Query::equal('profile.user.email', ['alice.updated@test.com'])
        ]);
        $this->assertGreaterThanOrEqual(1, count($results));

        // Re-create index
        $created = $database->createIndex($collectionId, 'idx_email_recreated', Database::INDEX_KEY, ['profile.user.email']);
        $this->assertTrue($created);

        // Query should still work with recreated index
        $results = $database->find($collectionId, [
            Query::equal('profile.user.email', ['alice.updated@test.com'])
        ]);
        $this->assertGreaterThanOrEqual(1, count($results));

        // Edge Case 11: UNIQUE index with updates (duplicate prevention)
        if ($database->getAdapter()->getSupportForIdenticalIndexes()) {
            $created = $database->createIndex($collectionId, 'idx_unique_email', Database::INDEX_UNIQUE, ['profile.user.email']);
            $this->assertTrue($created);

            // Try to create duplicate
            try {
                $database->createDocument($collectionId, new Document([
                    '$id' => 'duplicate1',
                    '$permissions' => [Permission::read(Role::any())],
                    'profile' => [
                        'user' => [
                            'email' => 'alice.updated@test.com', // duplicate
                            'info' => [
                                'country' => 'XX'
                            ]
                        ]
                    ]
                ]));
                $this->fail('Expected Duplicate exception for UNIQUE index');
            } catch (Exception $e) {
                $this->assertInstanceOf(DuplicateException::class, $e);
            }
        }

        // Edge Case 12: Query with startsWith/endsWith/contains on nested paths
        $database->createDocuments($collectionId, [
            new Document([
                '$id' => 'text1',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'text1@example.org',
                        'info' => [
                            'country' => 'United States',
                            'city' => 'New York City'
                        ]
                    ]
                ]
            ]),
            new Document([
                '$id' => 'text2',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'text2@test.com',
                        'info' => [
                            'country' => 'United Kingdom',
                            'city' => 'London'
                        ]
                    ]
                ]
            ])
        ]);

        // startsWith on nested path
        $results = $database->find($collectionId, [
            Query::startsWith('profile.user.email', 'text1@')
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('text1', $results[0]->getId());

        // contains on nested path
        $results = $database->find($collectionId, [
            Query::contains('profile.user.info.country', ['United'])
        ]);
        $this->assertGreaterThanOrEqual(2, count($results));

        $database->deleteCollection($collectionId);
    }
}
