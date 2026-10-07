<?php

namespace Tests\E2E\Adapter\Scopes;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

trait VectorTests
{
    public function testVectorAttributes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Test that vector attributes can only be created on PostgreSQL
        $database->createCollection('vectorCollection');

        // Create a vector attribute with 3 dimensions
        $database->createAttribute('vectorCollection', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create a vector attribute with 128 dimensions
        $database->createAttribute('vectorCollection', 'large_embedding', Database::VAR_VECTOR, 128, false, null);

        // Verify the attributes were created
        $collection = $database->getCollection('vectorCollection');
        $attributes = $collection->getAttribute('attributes');

        $embeddingAttr = null;
        $largeEmbeddingAttr = null;

        foreach ($attributes as $attr) {
            if ($attr['key'] === 'embedding') {
                $embeddingAttr = $attr;
            } elseif ($attr['key'] === 'large_embedding') {
                $largeEmbeddingAttr = $attr;
            }
        }

        $this->assertNotNull($embeddingAttr);
        $this->assertNotNull($largeEmbeddingAttr);
        $this->assertEquals(Database::VAR_VECTOR, $embeddingAttr['type']);
        $this->assertEquals(3, $embeddingAttr['size']);
        $this->assertEquals(Database::VAR_VECTOR, $largeEmbeddingAttr['type']);
        $this->assertEquals(128, $largeEmbeddingAttr['size']);

        // Cleanup
        $database->deleteCollection('vectorCollection');
    }

    public function testVectorInvalidDimensions(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorErrorCollection');

        // Test invalid dimensions
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Vector dimensions must be a positive integer');
        $database->createAttribute('vectorErrorCollection', 'bad_embedding', Database::VAR_VECTOR, 0, true);

        // Cleanup
        $database->deleteCollection('vectorErrorCollection');
    }

    public function testVectorTooManyDimensions(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorLimitCollection');

        // Test too many dimensions (pgvector limit is 16000)
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Vector dimensions cannot exceed 16000');
        $database->createAttribute('vectorLimitCollection', 'huge_embedding', Database::VAR_VECTOR, 16001, true);

        // Cleanup
        $database->deleteCollection('vectorLimitCollection');
    }

    public function testVectorDocuments(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorDocuments');
        $database->createAttribute('vectorDocuments', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('vectorDocuments', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create documents with vector data
        $doc1 = $database->createDocument('vectorDocuments', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Document 1',
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        $doc2 = $database->createDocument('vectorDocuments', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Document 2',
            'embedding' => [0.0, 1.0, 0.0]
        ]));

        $doc3 = $database->createDocument('vectorDocuments', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Document 3',
            'embedding' => [0.0, 0.0, 1.0]
        ]));

        $this->assertNotEmpty($doc1->getId());
        $this->assertNotEmpty($doc2->getId());
        $this->assertNotEmpty($doc3->getId());

        $this->assertEquals([1.0, 0.0, 0.0], $doc1->getAttribute('embedding'));
        $this->assertEquals([0.0, 1.0, 0.0], $doc2->getAttribute('embedding'));
        $this->assertEquals([0.0, 0.0, 1.0], $doc3->getAttribute('embedding'));

        // Cleanup
        $database->deleteCollection('vectorDocuments');
    }

    public function testVectorQueries(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorQueries');
        $database->createAttribute('vectorQueries', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('vectorQueries', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create test documents with read permissions
        $doc1 = $database->createDocument('vectorQueries', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Test 1',
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        $doc2 = $database->createDocument('vectorQueries', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Test 2',
            'embedding' => [0.0, 1.0, 0.0]
        ]));

        $doc3 = $database->createDocument('vectorQueries', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Test 3',
            'embedding' => [0.5, 0.5, 0.0]
        ]));

        // Verify documents were created
        $this->assertNotEmpty($doc1->getId());
        $this->assertNotEmpty($doc2->getId());
        $this->assertNotEmpty($doc3->getId());

        // Test without vector queries first
        $allDocs = $database->find('vectorQueries');
        $this->assertCount(3, $allDocs, "Should have 3 documents in collection");

        // Test vector dot product query
        $results = $database->find('vectorQueries', [
            Query::vectorDot('embedding', [1.0, 0.0, 0.0]),
            Query::orderAsc('$id')
        ]);

        $this->assertCount(3, $results);

        // Test vector cosine distance query
        $results = $database->find('vectorQueries', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::orderAsc('$id')
        ]);

        $this->assertCount(3, $results);

        // Test vector euclidean distance query
        $results = $database->find('vectorQueries', [
            Query::vectorEuclidean('embedding', [1.0, 0.0, 0.0]),
            Query::orderAsc('$id')
        ]);

        $this->assertCount(3, $results);

        // Test vector queries with limit - should return only top results
        $results = $database->find('vectorQueries', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::limit(2)
        ]);

        $this->assertCount(2, $results);
        // The most similar vector should be the one closest to [1.0, 0.0, 0.0]
        $this->assertEquals('Test 1', $results[0]->getAttribute('name'));

        // Test vector query with limit of 1
        $results = $database->find('vectorQueries', [
            Query::vectorDot('embedding', [0.0, 1.0, 0.0]),
            Query::limit(1)
        ]);

        $this->assertCount(1, $results);
        $this->assertEquals('Test 2', $results[0]->getAttribute('name'));

        // Test vector query combined with other filters
        $results = $database->find('vectorQueries', [
            Query::vectorCosine('embedding', [0.5, 0.5, 0.0]),
            Query::notEqual('name', 'Test 1')
        ]);

        $this->assertCount(2, $results);
        // Should not contain Test 1
        foreach ($results as $result) {
            $this->assertNotEquals('Test 1', $result->getAttribute('name'));
        }

        // Test vector query with specific name filter
        $results = $database->find('vectorQueries', [
            Query::vectorEuclidean('embedding', [0.7, 0.7, 0.0]),
            Query::equal('name', ['Test 3'])
        ]);

        $this->assertCount(1, $results);
        $this->assertEquals('Test 3', $results[0]->getAttribute('name'));

        // Test vector query with offset - skip first result
        $results = $database->find('vectorQueries', [
            Query::vectorDot('embedding', [0.5, 0.5, 0.0]),
            Query::limit(2),
            Query::offset(1)
        ]);

        $this->assertCount(2, $results);
        // Should skip the most similar result

        // Test empty result with impossible filter combination
        $results = $database->find('vectorQueries', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::equal('name', ['Test 2']),
            Query::equal('name', ['Test 3'])  // Impossible condition
        ]);

        $this->assertCount(0, $results);

        // Test vector query with secondary ordering
        // Vector similarity takes precedence, name DESC is secondary
        $results = $database->find('vectorQueries', [
            Query::vectorDot('embedding', [0.4, 0.6, 0.0]),
            Query::orderDesc('name'),
            Query::limit(2)
        ]);

        $this->assertCount(2, $results);
        // Results should be ordered primarily by vector similarity
        // The vector [0.4, 0.6, 0.0] is most similar to Test 2 [0.0, 1.0, 0.0]
        // and Test 3 [0.5, 0.5, 0.0] using dot product
        // Test 2 dot product: 0.4*0.0 + 0.6*1.0 + 0.0*0.0 = 0.6
        // Test 3 dot product: 0.4*0.5 + 0.6*0.5 + 0.0*0.0 = 0.5
        // So Test 2 should come first (higher dot product with negative inner product operator)
        $this->assertEquals('Test 2', $results[0]->getAttribute('name'));

        // Cleanup
        $database->deleteCollection('vectorQueries');
    }

    public function testVectorDistance(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorDistance');
        $database->createAttribute('vectorDistance', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('vectorDistance', 'embedding', Database::VAR_VECTOR, 3, true);

        $vectors = [
            'identical' => [1.0, 0.0, 0.0],
            'scaled' => [2.0, 0.0, 0.0],
            'orthogonal' => [0.0, 1.0, 0.0],
            'opposite' => [-1.0, 0.0, 0.0],
        ];

        foreach ($vectors as $name => $embedding) {
            $database->createDocument('vectorDistance', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'name' => $name,
                'embedding' => $embedding,
            ]));
        }

        $target = [1.0, 0.0, 0.0];

        $results = $database->find('vectorDistance', [
            Query::vectorCosine('embedding', $target)
        ]);

        $this->assertCount(4, $results);

        $cosine = [];
        foreach ($results as $result) {
            $distance = $result->getAttribute(Database::VECTOR_DISTANCE);

            $this->assertIsFloat($distance, "Cosine distance for '{$result->getAttribute('name')}' must be a float");

            $cosine[$result->getAttribute('name')] = $distance;
        }

        // Cosine distance is 1 - cosine similarity, so magnitude is irrelevant
        $this->assertEqualsWithDelta(0.0, $cosine['identical'], 0.000001, 'Identical vector must have zero cosine distance');
        $this->assertEqualsWithDelta(0.0, $cosine['scaled'], 0.000001, 'Cosine distance must ignore magnitude');
        $this->assertEqualsWithDelta(1.0, $cosine['orthogonal'], 0.000001, 'Orthogonal vector must have cosine distance of 1');
        $this->assertEqualsWithDelta(2.0, $cosine['opposite'], 0.000001, 'Opposite vector must have cosine distance of 2');

        // The returned distance must agree with the order the rows came back in,
        // otherwise a caller ranking by the number would disagree with the database
        $distances = \array_map(
            fn (Document $result) => $result->getAttribute(Database::VECTOR_DISTANCE),
            $results
        );

        $sorted = $distances;
        \sort($sorted);
        $this->assertSame($sorted, $distances, 'Results must be returned in ascending distance order');

        // Cosine similarity, which is what a caller displays as a relevance score
        $this->assertEqualsWithDelta(1.0, 1 - $cosine['identical'], 0.000001);
        $this->assertEqualsWithDelta(0.0, 1 - $cosine['orthogonal'], 0.000001);
        $this->assertEqualsWithDelta(-1.0, 1 - $cosine['opposite'], 0.000001);

        $results = $database->find('vectorDistance', [
            Query::vectorEuclidean('embedding', $target)
        ]);

        $euclidean = [];
        foreach ($results as $result) {
            $euclidean[$result->getAttribute('name')] = $result->getAttribute(Database::VECTOR_DISTANCE);
        }

        $this->assertEqualsWithDelta(0.0, $euclidean['identical'], 0.000001, 'Identical vector must have zero euclidean distance');
        $this->assertEqualsWithDelta(1.0, $euclidean['scaled'], 0.000001, 'Euclidean distance must account for magnitude');
        $this->assertEqualsWithDelta(\sqrt(2), $euclidean['orthogonal'], 0.000001);
        $this->assertEqualsWithDelta(2.0, $euclidean['opposite'], 0.000001);

        // The dot product operator returns the negative inner product so that
        // ascending order still means most similar first
        $results = $database->find('vectorDistance', [
            Query::vectorDot('embedding', $target)
        ]);

        $dot = [];
        foreach ($results as $result) {
            $dot[$result->getAttribute('name')] = $result->getAttribute(Database::VECTOR_DISTANCE);
        }

        $this->assertEqualsWithDelta(-1.0, $dot['identical'], 0.000001);
        $this->assertEqualsWithDelta(-2.0, $dot['scaled'], 0.000001);
        $this->assertEqualsWithDelta(0.0, $dot['orthogonal'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $dot['opposite'], 0.000001);

        // A distance is only meaningful relative to a query vector, so a plain
        // find must not carry one
        $results = $database->find('vectorDistance');

        $this->assertCount(4, $results);
        foreach ($results as $result) {
            $this->assertNull(
                $result->getAttribute(Database::VECTOR_DISTANCE),
                'A find without a vector query must not return a distance'
            );
        }

        // Selecting a subset of attributes builds a different projection
        $results = $database->find('vectorDistance', [
            Query::select(['name']),
            Query::vectorCosine('embedding', $target),
            Query::limit(1),
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('identical', $results[0]->getAttribute('name'));
        $this->assertEqualsWithDelta(0.0, $results[0]->getAttribute(Database::VECTOR_DISTANCE), 0.000001);

        // Cosine distance to a zero vector divides by a zero magnitude, so the engine answers
        // NaN. That has no honest float representation and must not read back as 0.0, which
        // would claim the pair is identical
        $database->createDocument('vectorDistance', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'zero',
            'embedding' => [0.0, 0.0, 0.0],
        ]));

        $results = $database->find('vectorDistance', [
            Query::vectorCosine('embedding', $target)
        ]);

        $this->assertCount(5, $results);

        $zero = null;
        foreach ($results as $result) {
            if ($result->getAttribute('name') === 'zero') {
                $zero = $result;
            }
        }

        $this->assertNotNull($zero, 'The zero vector must still be returned');
        $this->assertNull(
            $zero->getAttribute(Database::VECTOR_DISTANCE),
            'An undefined distance must be null, not a number'
        );

        $database->deleteCollection('vectorDistance');
    }

    public function testVectorQueryValidation(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorValidation');
        $database->createAttribute('vectorValidation', 'embedding', Database::VAR_VECTOR, 3, true);
        $database->createAttribute('vectorValidation', 'name', Database::VAR_STRING, 255, true);

        // Test that vector queries fail on non-vector attributes
        $this->expectException(DatabaseException::class);
        $database->find('vectorValidation', [
            Query::vectorDot('name', [1.0, 0.0, 0.0])
        ]);

        // Cleanup
        $database->deleteCollection('vectorValidation');
    }

    public function testVectorIndexes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorIndexes');
        $database->createAttribute('vectorIndexes', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create different types of vector indexes
        // Euclidean distance index (L2 distance)
        $database->createIndex('vectorIndexes', 'embedding_euclidean', Database::INDEX_HNSW_EUCLIDEAN, ['embedding']);

        // Cosine distance index
        $database->createIndex('vectorIndexes', 'embedding_cosine', Database::INDEX_HNSW_COSINE, ['embedding']);

        // Inner product (dot product) index
        $database->createIndex('vectorIndexes', 'embedding_dot', Database::INDEX_HNSW_DOT, ['embedding']);

        // Verify indexes were created
        $collection = $database->getCollection('vectorIndexes');
        $indexes = $collection->getAttribute('indexes');

        $this->assertCount(3, $indexes);

        // Test that queries work with indexes
        $database->createDocument('vectorIndexes', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        $database->createDocument('vectorIndexes', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [0.0, 1.0, 0.0]
        ]));

        // Query should use the appropriate index based on the operator
        $results = $database->find('vectorIndexes', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::limit(1)
        ]);

        $this->assertCount(1, $results);

        // Cleanup
        $database->deleteCollection('vectorIndexes');
    }

    public function testVectorDimensionMismatch(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorDimMismatch');
        $database->createAttribute('vectorDimMismatch', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test creating document with wrong dimension count
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/must be an array of 3 numeric values/');

        $database->createDocument('vectorDimMismatch', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0, 0.0] // Only 2 dimensions, expects 3
        ]));

        // Cleanup
        $database->deleteCollection('vectorDimMismatch');
    }

    public function testVectorWithInvalidDataTypes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorInvalidTypes');
        $database->createAttribute('vectorInvalidTypes', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test with string values in vector
        try {
            $database->createDocument('vectorInvalidTypes', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => ['one', 'two', 'three']
            ]));
            $this->fail('Should have thrown exception for non-numeric vector values');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric values', strtolower($e->getMessage()));
        }

        // Test with mixed types
        try {
            $database->createDocument('vectorInvalidTypes', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [1.0, 'two', 3.0]
            ]));
            $this->fail('Should have thrown exception for mixed type vector values');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric values', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorInvalidTypes');
    }

    public function testVectorWithNullAndEmpty(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorNullEmpty');
        $database->createAttribute('vectorNullEmpty', 'embedding', Database::VAR_VECTOR, 3, false); // Not required

        // Test with null vector (should work for non-required attribute)
        $doc1 = $database->createDocument('vectorNullEmpty', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => null
        ]));

        $this->assertNull($doc1->getAttribute('embedding'));

        // Test with empty array (should fail)
        try {
            $database->createDocument('vectorNullEmpty', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => []
            ]));
            $this->fail('Should have thrown exception for empty vector');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric values', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorNullEmpty');
    }

    public function testLargeVectors(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Test with maximum allowed dimensions (16000 for pgvector)
        $database->createCollection('vectorLarge');
        $database->createAttribute('vectorLarge', 'embedding', Database::VAR_VECTOR, 1536, true); // Common embedding size

        // Create a large vector
        $largeVector = array_fill(0, 1536, 0.1);
        $largeVector[0] = 1.0; // Make first element different

        $doc = $database->createDocument('vectorLarge', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => $largeVector
        ]));

        $this->assertCount(1536, $doc->getAttribute('embedding'));
        $this->assertEquals(1.0, $doc->getAttribute('embedding')[0]);

        // Test vector search on large vectors
        $searchVector = array_fill(0, 1536, 0.0);
        $searchVector[0] = 1.0;

        $results = $database->find('vectorLarge', [
            Query::vectorCosine('embedding', $searchVector)
        ]);

        $this->assertCount(1, $results);

        // Cleanup
        $database->deleteCollection('vectorLarge');
    }

    public function testVectorUpdates(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorUpdates');
        $database->createAttribute('vectorUpdates', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create initial document
        $doc = $database->createDocument('vectorUpdates', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        $this->assertEquals([1.0, 0.0, 0.0], $doc->getAttribute('embedding'));

        // Update the vector
        $updated = $database->updateDocument('vectorUpdates', $doc->getId(), new Document([
            'embedding' => [0.0, 1.0, 0.0]
        ]));

        $this->assertEquals([0.0, 1.0, 0.0], $updated->getAttribute('embedding'));

        // Test partial update (should replace entire vector)
        $updated2 = $database->updateDocument('vectorUpdates', $doc->getId(), new Document([
            'embedding' => [0.5, 0.5, 0.5]
        ]));

        $this->assertEquals([0.5, 0.5, 0.5], $updated2->getAttribute('embedding'));

        // Cleanup
        $database->deleteCollection('vectorUpdates');
    }

    public function testMultipleVectorAttributes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('multiVector');
        $database->createAttribute('multiVector', 'embedding1', Database::VAR_VECTOR, 3, true);
        $database->createAttribute('multiVector', 'embedding2', Database::VAR_VECTOR, 5, true);
        $database->createAttribute('multiVector', 'name', Database::VAR_STRING, 255, true);

        // Create documents with multiple vector attributes
        $doc1 = $database->createDocument('multiVector', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Doc 1',
            'embedding1' => [1.0, 0.0, 0.0],
            'embedding2' => [1.0, 0.0, 0.0, 0.0, 0.0]
        ]));

        $doc2 = $database->createDocument('multiVector', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Doc 2',
            'embedding1' => [0.0, 1.0, 0.0],
            'embedding2' => [0.0, 1.0, 0.0, 0.0, 0.0]
        ]));

        // Query by first vector
        $results = $database->find('multiVector', [
            Query::vectorCosine('embedding1', [1.0, 0.0, 0.0])
        ]);

        $this->assertCount(2, $results);
        $this->assertEquals('Doc 1', $results[0]->getAttribute('name'));

        // Query by second vector
        $results = $database->find('multiVector', [
            Query::vectorCosine('embedding2', [0.0, 1.0, 0.0, 0.0, 0.0])
        ]);

        $this->assertCount(2, $results);
        $this->assertEquals('Doc 2', $results[0]->getAttribute('name'));

        // Cleanup
        $database->deleteCollection('multiVector');
    }

    public function testVectorQueriesWithPagination(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorPagination');
        $database->createAttribute('vectorPagination', 'embedding', Database::VAR_VECTOR, 3, true);
        $database->createAttribute('vectorPagination', 'index', Database::VAR_INTEGER, 0, true);

        // Create 10 documents
        for ($i = 0; $i < 10; $i++) {
            $database->createDocument('vectorPagination', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'index' => $i,
                'embedding' => [
                    cos($i * M_PI / 10),
                    sin($i * M_PI / 10),
                    0.0
                ]
            ]));
        }

        // Test pagination with vector queries
        $searchVector = [1.0, 0.0, 0.0];

        // First page
        $page1 = $database->find('vectorPagination', [
            Query::vectorCosine('embedding', $searchVector),
            Query::limit(3),
            Query::offset(0)
        ]);

        $this->assertCount(3, $page1);

        // Second page
        $page2 = $database->find('vectorPagination', [
            Query::vectorCosine('embedding', $searchVector),
            Query::limit(3),
            Query::offset(3)
        ]);

        $this->assertCount(3, $page2);

        // Ensure different documents
        $page1Ids = array_map(fn ($doc) => $doc->getId(), $page1);
        $page2Ids = array_map(fn ($doc) => $doc->getId(), $page2);
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids));

        // Test with cursor pagination
        $firstBatch = $database->find('vectorPagination', [
            Query::vectorCosine('embedding', $searchVector),
            Query::limit(5)
        ]);

        $this->assertCount(5, $firstBatch);

        $lastDoc = $firstBatch[4];
        $nextBatch = $database->find('vectorPagination', [
            Query::vectorCosine('embedding', $searchVector),
            Query::cursorAfter($lastDoc),
            Query::limit(5)
        ]);

        $this->assertCount(5, $nextBatch);
        $this->assertNotEquals($lastDoc->getId(), $nextBatch[0]->getId());

        // Cleanup
        $database->deleteCollection('vectorPagination');
    }

    public function testCombinedVectorAndTextSearch(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorTextSearch');
        $database->createAttribute('vectorTextSearch', 'title', Database::VAR_STRING, 255, true);
        $database->createAttribute('vectorTextSearch', 'category', Database::VAR_STRING, 50, true);
        $database->createAttribute('vectorTextSearch', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create fulltext index for title
        $database->createIndex('vectorTextSearch', 'title_fulltext', Database::INDEX_FULLTEXT, ['title']);

        // Create test documents
        $docs = [
            ['title' => 'Machine Learning Basics', 'category' => 'AI', 'embedding' => [1.0, 0.0, 0.0]],
            ['title' => 'Deep Learning Advanced', 'category' => 'AI', 'embedding' => [0.9, 0.1, 0.0]],
            ['title' => 'Web Development Guide', 'category' => 'Web', 'embedding' => [0.0, 1.0, 0.0]],
            ['title' => 'Database Design', 'category' => 'Data', 'embedding' => [0.0, 0.0, 1.0]],
            ['title' => 'AI Ethics', 'category' => 'AI', 'embedding' => [0.8, 0.2, 0.0]],
        ];

        foreach ($docs as $doc) {
            $database->createDocument('vectorTextSearch', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                ...$doc
            ]));
        }

        // Combine vector search with category filter
        $results = $database->find('vectorTextSearch', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::equal('category', ['AI']),
            Query::limit(2)
        ]);

        $this->assertCount(2, $results);
        $this->assertEquals('AI', $results[0]->getAttribute('category'));
        $this->assertEquals('Machine Learning Basics', $results[0]->getAttribute('title'));

        // Combine vector search with text search
        $results = $database->find('vectorTextSearch', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::search('title', 'Learning'),
            Query::limit(5)
        ]);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertStringContainsString('Learning', $result->getAttribute('title'));
        }

        // Complex query with multiple filters
        $results = $database->find('vectorTextSearch', [
            Query::vectorEuclidean('embedding', [0.5, 0.5, 0.0]),
            Query::notEqual('category', ['Web']),
            Query::limit(3)
        ]);

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertNotEquals('Web', $result->getAttribute('category'));
        }

        // Cleanup
        $database->deleteCollection('vectorTextSearch');
    }

    public function testVectorSpecialFloatValues(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorSpecialFloats');
        $database->createAttribute('vectorSpecialFloats', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test with very small values (near zero)
        $doc1 = $database->createDocument('vectorSpecialFloats', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1e-10, 1e-10, 1e-10]
        ]));

        $this->assertNotNull($doc1->getId());

        // Test with very large values
        $doc2 = $database->createDocument('vectorSpecialFloats', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1e10, 1e10, 1e10]
        ]));

        $this->assertNotNull($doc2->getId());

        // Test with negative values
        $doc3 = $database->createDocument('vectorSpecialFloats', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [-1.0, -0.5, -0.1]
        ]));

        $this->assertNotNull($doc3->getId());

        // Test with mixed sign values
        $doc4 = $database->createDocument('vectorSpecialFloats', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [-1.0, 0.0, 1.0]
        ]));

        $this->assertNotNull($doc4->getId());

        // Query with negative vector
        $results = $database->find('vectorSpecialFloats', [
            Query::vectorCosine('embedding', [-1.0, -1.0, -1.0])
        ]);

        $this->assertGreaterThan(0, count($results));

        // Cleanup
        $database->deleteCollection('vectorSpecialFloats');
    }

    public function testVectorIndexPerformance(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorPerf');
        $database->createAttribute('vectorPerf', 'embedding', Database::VAR_VECTOR, 128, true);
        $database->createAttribute('vectorPerf', 'name', Database::VAR_STRING, 255, true);

        // Create documents
        $numDocs = 100;
        for ($i = 0; $i < $numDocs; $i++) {
            $vector = [];
            for ($j = 0; $j < 128; $j++) {
                $vector[] = sin($i * $j * 0.01);
            }

            $database->createDocument('vectorPerf', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'name' => "Doc $i",
                'embedding' => $vector
            ]));
        }

        // Query without index
        $searchVector = array_fill(0, 128, 0.5);

        $startTime = microtime(true);
        $results1 = $database->find('vectorPerf', [
            Query::vectorCosine('embedding', $searchVector),
            Query::limit(10)
        ]);
        $timeWithoutIndex = microtime(true) - $startTime;

        $this->assertCount(10, $results1);

        // Create HNSW index
        $database->createIndex('vectorPerf', 'embedding_hnsw', Database::INDEX_HNSW_COSINE, ['embedding']);

        // Query with index (should be faster for larger datasets)
        $startTime = microtime(true);
        $results2 = $database->find('vectorPerf', [
            Query::vectorCosine('embedding', $searchVector),
            Query::limit(10)
        ]);
        $timeWithIndex = microtime(true) - $startTime;

        $this->assertCount(10, $results2);

        // Results should be the same
        $this->assertEquals(
            array_map(fn ($d) => $d->getId(), $results1),
            array_map(fn ($d) => $d->getId(), $results2)
        );

        // Cleanup
        $database->deleteCollection('vectorPerf');
    }

    public function testVectorQueryValidationExtended(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorValidation2');
        $database->createAttribute('vectorValidation2', 'embedding', Database::VAR_VECTOR, 3, true);
        $database->createAttribute('vectorValidation2', 'text', Database::VAR_STRING, 255, true);

        $database->createDocument('vectorValidation2', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'text' => 'Test',
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        // Test vector query with wrong dimension count
        try {
            $database->find('vectorValidation2', [
                Query::vectorCosine('embedding', [1.0, 0.0]) // Wrong dimension
            ]);
            $this->fail('Should have thrown exception for dimension mismatch');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('elements', strtolower($e->getMessage()));
        }

        // Test vector query on non-vector attribute
        try {
            $database->find('vectorValidation2', [
                Query::vectorCosine('text', [1.0, 0.0, 0.0])
            ]);
            $this->fail('Should have thrown exception for non-vector attribute');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('vector', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorValidation2');
    }

    public function testVectorNormalization(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorNorm');
        $database->createAttribute('vectorNorm', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create documents with normalized and non-normalized vectors
        $doc1 = $database->createDocument('vectorNorm', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0] // Already normalized
        ]));

        $doc2 = $database->createDocument('vectorNorm', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [3.0, 4.0, 0.0] // Not normalized (magnitude = 5)
        ]));

        // Cosine similarity should work regardless of normalization
        $results = $database->find('vectorNorm', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0])
        ]);

        $this->assertCount(2, $results);

        // For cosine similarity, [3, 4, 0] has similarity 3/5 = 0.6 with [1, 0, 0]
        // So [1, 0, 0] should be first (similarity = 1.0)
        $this->assertEquals([1.0, 0.0, 0.0], $results[0]->getAttribute('embedding'));

        // Cleanup
        $database->deleteCollection('vectorNorm');
    }

    public function testVectorWithInfinityValues(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorInfinity');
        $database->createAttribute('vectorInfinity', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test with INF value - should fail
        try {
            $database->createDocument('vectorInfinity', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [INF, 0.0, 0.0]
            ]));
            $this->fail('Should have thrown exception for INF value');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric', strtolower($e->getMessage()));
        }

        // Test with -INF value - should fail
        try {
            $database->createDocument('vectorInfinity', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [-INF, 0.0, 0.0]
            ]));
            $this->fail('Should have thrown exception for -INF value');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorInfinity');
    }

    public function testVectorWithNaNValues(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorNaN');
        $database->createAttribute('vectorNaN', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test with NaN value - should fail
        try {
            $database->createDocument('vectorNaN', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [NAN, 0.0, 0.0]
            ]));
            $this->fail('Should have thrown exception for NaN value');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorNaN');
    }

    public function testVectorWithAssociativeArray(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorAssoc');
        $database->createAttribute('vectorAssoc', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test with associative array - should fail
        try {
            $database->createDocument('vectorAssoc', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => ['x' => 1.0, 'y' => 0.0, 'z' => 0.0]
            ]));
            $this->fail('Should have thrown exception for associative array');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorAssoc');
    }

    public function testVectorWithSparseArray(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorSparse');
        $database->createAttribute('vectorSparse', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test with sparse array (missing indexes) - should fail
        try {
            $vector = [];
            $vector[0] = 1.0;
            $vector[2] = 1.0; // Skip index 1
            $database->createDocument('vectorSparse', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => $vector
            ]));
            $this->fail('Should have thrown exception for sparse array');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorSparse');
    }

    public function testVectorWithNestedArrays(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorNested');
        $database->createAttribute('vectorNested', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test with nested array - should fail
        try {
            $database->createDocument('vectorNested', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [[1.0], [0.0], [0.0]]
            ]));
            $this->fail('Should have thrown exception for nested array');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorNested');
    }

    public function testVectorWithBooleansInArray(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorBooleans');
        $database->createAttribute('vectorBooleans', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test with boolean values - should fail
        try {
            $database->createDocument('vectorBooleans', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [true, false, true]
            ]));
            $this->fail('Should have thrown exception for boolean values');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorBooleans');
    }

    public function testVectorWithStringNumbers(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorStringNums');
        $database->createAttribute('vectorStringNums', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test with numeric strings - should fail (strict validation)
        try {
            $database->createDocument('vectorStringNums', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => ['1.0', '2.0', '3.0']
            ]));
            $this->fail('Should have thrown exception for string numbers');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric', strtolower($e->getMessage()));
        }

        // Test with strings containing spaces
        try {
            $database->createDocument('vectorStringNums', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [' 1.0 ', '2.0', '3.0']
            ]));
            $this->fail('Should have thrown exception for string numbers with spaces');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorStringNums');
    }

    public function testVectorWithRelationships(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create parent collection with vectors
        $database->createCollection('vectorParent');
        $database->createAttribute('vectorParent', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('vectorParent', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create child collection
        $database->createCollection('vectorChild');
        $database->createAttribute('vectorChild', 'title', Database::VAR_STRING, 255, true);
        $database->createRelationship('vectorChild', 'vectorParent', Database::RELATION_MANY_TO_ONE, true, 'parent', 'children');

        // Create parent documents with vectors
        $parent1 = $database->createDocument('vectorParent', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Parent 1',
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        $parent2 = $database->createDocument('vectorParent', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Parent 2',
            'embedding' => [0.0, 1.0, 0.0]
        ]));

        // Create child documents
        $child1 = $database->createDocument('vectorChild', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'title' => 'Child 1',
            'parent' => $parent1->getId()
        ]));

        $child2 = $database->createDocument('vectorChild', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'title' => 'Child 2',
            'parent' => $parent2->getId()
        ]));

        // Query parents by vector similarity
        $results = $database->find('vectorParent', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0])
        ]);

        $this->assertCount(2, $results);
        $this->assertEquals('Parent 1', $results[0]->getAttribute('name'));

        // Verify relationships are intact
        $parent1Fetched = $database->getDocument('vectorParent', $parent1->getId());
        $children = $parent1Fetched->getAttribute('children');
        $this->assertCount(1, $children);
        $this->assertEquals('Child 1', $children[0]->getAttribute('title'));

        // Query with vector and relationship filter combined
        $results = $database->find('vectorParent', [
            Query::vectorCosine('embedding', [0.5, 0.5, 0.0]),
            Query::equal('name', ['Parent 1'])
        ]);

        $this->assertCount(1, $results);

        // Cleanup
        $database->deleteCollection('vectorChild');
        $database->deleteCollection('vectorParent');
    }

    public function testVectorWithTwoWayRelationships(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create two collections with two-way relationship and vectors
        $database->createCollection('vectorAuthors');
        $database->createAttribute('vectorAuthors', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('vectorAuthors', 'embedding', Database::VAR_VECTOR, 3, true);

        $database->createCollection('vectorBooks');
        $database->createAttribute('vectorBooks', 'title', Database::VAR_STRING, 255, true);
        $database->createAttribute('vectorBooks', 'embedding', Database::VAR_VECTOR, 3, true);
        $database->createRelationship('vectorBooks', 'vectorAuthors', Database::RELATION_MANY_TO_ONE, true, 'author', 'books');

        // Create documents
        $author = $database->createDocument('vectorAuthors', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Author 1',
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        $book1 = $database->createDocument('vectorBooks', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'title' => 'Book 1',
            'embedding' => [0.9, 0.1, 0.0],
            'author' => $author->getId()
        ]));

        $book2 = $database->createDocument('vectorBooks', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'title' => 'Book 2',
            'embedding' => [0.8, 0.2, 0.0],
            'author' => $author->getId()
        ]));

        // Query books by vector similarity
        $results = $database->find('vectorBooks', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::limit(1)
        ]);

        $this->assertCount(1, $results);
        $this->assertEquals('Book 1', $results[0]->getAttribute('title'));

        // Query authors and verify relationship
        $authorFetched = $database->getDocument('vectorAuthors', $author->getId());
        $books = $authorFetched->getAttribute('books');
        $this->assertCount(2, $books);

        // Cleanup
        $database->deleteCollection('vectorBooks');
        $database->deleteCollection('vectorAuthors');
    }

    public function testVectorAllZeros(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorZeros');
        $database->createAttribute('vectorZeros', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create document with all-zeros vector
        $doc = $database->createDocument('vectorZeros', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [0.0, 0.0, 0.0]
        ]));

        $this->assertEquals([0.0, 0.0, 0.0], $doc->getAttribute('embedding'));

        // Create another document with non-zero vector
        $doc2 = $database->createDocument('vectorZeros', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        // Query with zero vector - cosine similarity should handle gracefully
        $results = $database->find('vectorZeros', [
            Query::vectorCosine('embedding', [0.0, 0.0, 0.0])
        ]);

        // Should return documents, though similarity may be undefined
        $this->assertGreaterThan(0, count($results));

        // Query with non-zero vector against zero vectors
        $results = $database->find('vectorZeros', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0])
        ]);

        $this->assertCount(2, $results);

        // Cleanup
        $database->deleteCollection('vectorZeros');
    }

    public function testVectorCosineSimilarityDivisionByZero(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorCosineZero');
        $database->createAttribute('vectorCosineZero', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create multiple documents with zero vectors
        $database->createDocument('vectorCosineZero', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [0.0, 0.0, 0.0]
        ]));

        $database->createDocument('vectorCosineZero', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [0.0, 0.0, 0.0]
        ]));

        // Query with zero vector - should not cause division by zero error
        $results = $database->find('vectorCosineZero', [
            Query::vectorCosine('embedding', [0.0, 0.0, 0.0])
        ]);

        // Should handle gracefully and return results
        $this->assertCount(2, $results);

        // Cleanup
        $database->deleteCollection('vectorCosineZero');
    }

    public function testDeleteVectorAttribute(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorDeleteAttr');
        $database->createAttribute('vectorDeleteAttr', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('vectorDeleteAttr', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create document with vector
        $doc = $database->createDocument('vectorDeleteAttr', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Test',
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        $this->assertNotNull($doc->getAttribute('embedding'));

        // Delete the vector attribute
        $result = $database->deleteAttribute('vectorDeleteAttr', 'embedding');
        $this->assertTrue($result);

        // Verify attribute is gone
        $collection = $database->getCollection('vectorDeleteAttr');
        $attributes = $collection->getAttribute('attributes');
        foreach ($attributes as $attr) {
            $this->assertNotEquals('embedding', $attr['key']);
        }

        // Fetch document - should not have embedding anymore
        $docFetched = $database->getDocument('vectorDeleteAttr', $doc->getId());
        $this->assertNull($docFetched->getAttribute('embedding', null));

        // Cleanup
        $database->deleteCollection('vectorDeleteAttr');
    }

    public function testDeleteAttributeWithVectorIndexes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorDeleteIndexedAttr');
        $database->createAttribute('vectorDeleteIndexedAttr', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create multiple indexes on the vector attribute
        $database->createIndex('vectorDeleteIndexedAttr', 'idx1', Database::INDEX_HNSW_COSINE, ['embedding']);
        $database->createIndex('vectorDeleteIndexedAttr', 'idx2', Database::INDEX_HNSW_EUCLIDEAN, ['embedding']);

        // Create document
        $database->createDocument('vectorDeleteIndexedAttr', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        // Delete the attribute - should also delete indexes
        $result = $database->deleteAttribute('vectorDeleteIndexedAttr', 'embedding');
        $this->assertTrue($result);

        // Verify indexes are gone
        $collection = $database->getCollection('vectorDeleteIndexedAttr');
        $indexes = $collection->getAttribute('indexes');
        $this->assertCount(0, $indexes);

        // Cleanup
        $database->deleteCollection('vectorDeleteIndexedAttr');
    }

    public function testVectorSearchWithRestrictedPermissions(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create documents with different permissions inside Authorization::skip
        $database->getAuthorization()->skip(function () use ($database) {
            $database->createCollection('vectorPermissions', [], [], [], true);
            $database->createAttribute('vectorPermissions', 'name', Database::VAR_STRING, 255, true);
            $database->createAttribute('vectorPermissions', 'embedding', Database::VAR_VECTOR, 3, true);

            $database->createDocument('vectorPermissions', new Document([
                '$permissions' => [
                    Permission::read(Role::user('user1'))
                ],
                'name' => 'Doc 1',
                'embedding' => [1.0, 0.0, 0.0]
            ]));

            $database->createDocument('vectorPermissions', new Document([
                '$permissions' => [
                    Permission::read(Role::user('user2'))
                ],
                'name' => 'Doc 2',
                'embedding' => [0.9, 0.1, 0.0]
            ]));

            $database->createDocument('vectorPermissions', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'name' => 'Doc 3',
                'embedding' => [0.8, 0.2, 0.0]
            ]));
        });

        // Query as user1 - should only see doc1 and doc3
        $database->getAuthorization()->addRole(Role::user('user1')->toString());
        $database->getAuthorization()->addRole(Role::any()->toString());
        $results = $database->find('vectorPermissions', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0])
        ]);

        $this->assertCount(2, $results);
        $names = array_map(fn ($d) => $d->getAttribute('name'), $results);
        $this->assertContains('Doc 1', $names);
        $this->assertContains('Doc 3', $names);
        $this->assertNotContains('Doc 2', $names);

        // Query as user2 - should only see doc2 and doc3
        $database->getAuthorization()->cleanRoles();
        $database->getAuthorization()->addRole(Role::user('user2')->toString());
        $database->getAuthorization()->addRole(Role::any()->toString());
        $results = $database->find('vectorPermissions', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0])
        ]);

        $this->assertCount(2, $results);
        $names = array_map(fn ($d) => $d->getAttribute('name'), $results);
        $this->assertContains('Doc 2', $names);
        $this->assertContains('Doc 3', $names);
        $this->assertNotContains('Doc 1', $names);

        $database->getAuthorization()->cleanRoles();
        $database->getAuthorization()->addRole(Role::any()->toString());

        // Cleanup
        $database->deleteCollection('vectorPermissions');
    }

    public function testVectorPermissionFilteringAfterScoring(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorPermScoring');
        $database->createAttribute('vectorPermScoring', 'score', Database::VAR_INTEGER, 0, true);
        $database->createAttribute('vectorPermScoring', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create 5 documents, top 3 by similarity have restricted access
        for ($i = 0; $i < 5; $i++) {
            $perms = $i < 3
                ? [Permission::read(Role::user('restricted'))]
                : [Permission::read(Role::any())];

            $database->createDocument('vectorPermScoring', new Document([
                '$permissions' => $perms,
                'score' => $i,
                'embedding' => [1.0 - ($i * 0.1), $i * 0.1, 0.0]
            ]));
        }

        // Query with limit 3 as any user - should skip restricted docs and return accessible ones
        $database->getAuthorization()->addRole(Role::any()->toString());
        $results = $database->find('vectorPermScoring', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::limit(3)
        ]);

        // Should only get the 2 accessible documents
        $this->assertCount(2, $results);
        foreach ($results as $doc) {
            $this->assertGreaterThanOrEqual(3, $doc->getAttribute('score'));
        }

        $database->getAuthorization()->cleanRoles();

        // Cleanup
        $database->deleteCollection('vectorPermScoring');
    }

    public function testVectorCursorBeforePagination(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorCursorBefore');
        $database->createAttribute('vectorCursorBefore', 'index', Database::VAR_INTEGER, 0, true);
        $database->createAttribute('vectorCursorBefore', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create 10 documents
        for ($i = 0; $i < 10; $i++) {
            $database->createDocument('vectorCursorBefore', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'index' => $i,
                'embedding' => [1.0 - ($i * 0.05), $i * 0.05, 0.0]
            ]));
        }

        // Get first 5 results
        $firstBatch = $database->find('vectorCursorBefore', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::limit(5)
        ]);

        $this->assertCount(5, $firstBatch);

        // Get results before the 4th document (backward pagination)
        $fourthDoc = $firstBatch[3];
        $beforeBatch = $database->find('vectorCursorBefore', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::cursorBefore($fourthDoc),
            Query::limit(3)
        ]);

        // Should get the 3 documents before the 4th one
        $this->assertCount(3, $beforeBatch);
        $beforeIds = array_map(fn ($d) => $d->getId(), $beforeBatch);
        $this->assertNotContains($fourthDoc->getId(), $beforeIds);

        // Cleanup
        $database->deleteCollection('vectorCursorBefore');
    }

    public function testVectorBackwardPagination(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorBackward');
        $database->createAttribute('vectorBackward', 'value', Database::VAR_INTEGER, 0, true);
        $database->createAttribute('vectorBackward', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create documents
        for ($i = 0; $i < 20; $i++) {
            $database->createDocument('vectorBackward', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'value' => $i,
                'embedding' => [cos($i * 0.1), sin($i * 0.1), 0.0]
            ]));
        }

        // Get last batch
        $allResults = $database->find('vectorBackward', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::limit(20)
        ]);

        // Navigate backwards from the end
        $lastDoc = $allResults[19];
        $backwardBatch = $database->find('vectorBackward', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::cursorBefore($lastDoc),
            Query::limit(5)
        ]);

        $this->assertCount(5, $backwardBatch);

        // Continue backward pagination
        $firstOfBackward = $backwardBatch[0];
        $moreBackward = $database->find('vectorBackward', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::cursorBefore($firstOfBackward),
            Query::limit(5)
        ]);

        // Should get at least some results (may be less than 5 due to cursor position)
        $this->assertGreaterThan(0, count($moreBackward));
        $this->assertLessThanOrEqual(5, count($moreBackward));

        // Cleanup
        $database->deleteCollection('vectorBackward');
    }

    public function testVectorDimensionUpdate(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorDimUpdate');
        $database->createAttribute('vectorDimUpdate', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create document
        $doc = $database->createDocument('vectorDimUpdate', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        $this->assertCount(3, $doc->getAttribute('embedding'));

        // Try to update attribute dimensions - should fail (immutable)
        try {
            $database->updateAttribute('vectorDimUpdate', 'embedding', Database::VAR_VECTOR, 5, true);
            $this->fail('Should not allow changing vector dimensions');
        } catch (\Throwable $e) {
            // Expected - dimension changes not allowed (either validation or database error)
            $this->assertTrue(true);
        }

        // Cleanup
        $database->deleteCollection('vectorDimUpdate');
    }

    public function testVectorRequiredWithNullValue(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorRequiredNull');
        $database->createAttribute('vectorRequiredNull', 'embedding', Database::VAR_VECTOR, 3, true); // Required

        // Try to create document with null required vector - should fail
        try {
            $database->createDocument('vectorRequiredNull', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => null
            ]));
            $this->fail('Should have thrown exception for null required vector');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('required', strtolower($e->getMessage()));
        }

        // Try to create document without vector attribute - should fail
        try {
            $database->createDocument('vectorRequiredNull', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ]
            ]));
            $this->fail('Should have thrown exception for missing required vector');
        } catch (DatabaseException $e) {
            $this->assertTrue(true);
        }

        // Cleanup
        $database->deleteCollection('vectorRequiredNull');
    }

    public function testVectorConcurrentUpdates(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorConcurrent');
        $database->createAttribute('vectorConcurrent', 'embedding', Database::VAR_VECTOR, 3, true);
        $database->createAttribute('vectorConcurrent', 'version', Database::VAR_INTEGER, 0, true);

        // Create initial document
        $doc = $database->createDocument('vectorConcurrent', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0],
            'version' => 1
        ]));

        // Simulate concurrent updates
        $update1 = $database->updateDocument('vectorConcurrent', $doc->getId(), new Document([
            'embedding' => [0.0, 1.0, 0.0],
            'version' => 2
        ]));

        $update2 = $database->updateDocument('vectorConcurrent', $doc->getId(), new Document([
            'embedding' => [0.0, 0.0, 1.0],
            'version' => 3
        ]));

        // Last update should win
        $final = $database->getDocument('vectorConcurrent', $doc->getId());
        $this->assertEquals([0.0, 0.0, 1.0], $final->getAttribute('embedding'));
        $this->assertEquals(3, $final->getAttribute('version'));

        // Cleanup
        $database->deleteCollection('vectorConcurrent');
    }

    public function testDeleteVectorIndexes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorDeleteIdx');
        $database->createAttribute('vectorDeleteIdx', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create index
        $database->createIndex('vectorDeleteIdx', 'idx_cosine', Database::INDEX_HNSW_COSINE, ['embedding']);

        // Verify index exists
        $collection = $database->getCollection('vectorDeleteIdx');
        $indexes = $collection->getAttribute('indexes');
        $this->assertCount(1, $indexes);

        // Create documents
        $database->createDocument('vectorDeleteIdx', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        // Delete index
        $result = $database->deleteIndex('vectorDeleteIdx', 'idx_cosine');
        $this->assertTrue($result);

        // Verify index is gone
        $collection = $database->getCollection('vectorDeleteIdx');
        $indexes = $collection->getAttribute('indexes');
        $this->assertCount(0, $indexes);

        // Queries should still work (without index optimization)
        $results = $database->find('vectorDeleteIdx', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0])
        ]);

        $this->assertCount(1, $results);

        // Cleanup
        $database->deleteCollection('vectorDeleteIdx');
    }

    public function testMultipleVectorIndexes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorMultiIdx');
        $database->createAttribute('vectorMultiIdx', 'embedding1', Database::VAR_VECTOR, 3, true);
        $database->createAttribute('vectorMultiIdx', 'embedding2', Database::VAR_VECTOR, 3, true);

        // Create multiple indexes on different vector attributes
        $database->createIndex('vectorMultiIdx', 'idx1_cosine', Database::INDEX_HNSW_COSINE, ['embedding1']);
        $database->createIndex('vectorMultiIdx', 'idx2_euclidean', Database::INDEX_HNSW_EUCLIDEAN, ['embedding2']);

        // Verify both indexes exist
        $collection = $database->getCollection('vectorMultiIdx');
        $indexes = $collection->getAttribute('indexes');
        $this->assertCount(2, $indexes);

        // Create document
        $database->createDocument('vectorMultiIdx', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding1' => [1.0, 0.0, 0.0],
            'embedding2' => [0.0, 1.0, 0.0]
        ]));

        // Query using first index
        $results = $database->find('vectorMultiIdx', [
            Query::vectorCosine('embedding1', [1.0, 0.0, 0.0])
        ]);
        $this->assertCount(1, $results);

        // Query using second index
        $results = $database->find('vectorMultiIdx', [
            Query::vectorEuclidean('embedding2', [0.0, 1.0, 0.0])
        ]);
        $this->assertCount(1, $results);

        // Cleanup
        $database->deleteCollection('vectorMultiIdx');
    }

    public function testVectorIndexCreationFailure(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorIdxFail');
        $database->createAttribute('vectorIdxFail', 'embedding', Database::VAR_VECTOR, 3, true);
        $database->createAttribute('vectorIdxFail', 'text', Database::VAR_STRING, 255, true);

        // Try to create vector index on non-vector attribute - should fail
        try {
            $database->createIndex('vectorIdxFail', 'bad_idx', Database::INDEX_HNSW_COSINE, ['text']);
            $this->fail('Should not allow vector index on non-vector attribute');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('vector', strtolower($e->getMessage()));
        }

        // Try to create duplicate index
        $database->createIndex('vectorIdxFail', 'idx1', Database::INDEX_HNSW_COSINE, ['embedding']);
        try {
            $database->createIndex('vectorIdxFail', 'idx1', Database::INDEX_HNSW_COSINE, ['embedding']);
            $this->fail('Should not allow duplicate index');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('index', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorIdxFail');
    }

    public function testVectorQueryWithoutIndex(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorNoIndex');
        $database->createAttribute('vectorNoIndex', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create documents without any index
        $database->createDocument('vectorNoIndex', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        $database->createDocument('vectorNoIndex', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [0.0, 1.0, 0.0]
        ]));

        // Queries should still work (sequential scan)
        $results = $database->find('vectorNoIndex', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0])
        ]);

        $this->assertCount(2, $results);

        // Cleanup
        $database->deleteCollection('vectorNoIndex');
    }

    public function testVectorQueryEmpty(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorEmptyQuery');
        $database->createAttribute('vectorEmptyQuery', 'embedding', Database::VAR_VECTOR, 3, true);

        // No documents in collection
        $results = $database->find('vectorEmptyQuery', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0])
        ]);

        $this->assertCount(0, $results);

        // Cleanup
        $database->deleteCollection('vectorEmptyQuery');
    }

    public function testSingleDimensionVector(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorSingleDim');
        $database->createAttribute('vectorSingleDim', 'embedding', Database::VAR_VECTOR, 1, true);

        // Create documents with single-dimension vectors
        $doc1 = $database->createDocument('vectorSingleDim', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0]
        ]));

        $doc2 = $database->createDocument('vectorSingleDim', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [0.5]
        ]));

        $this->assertEquals([1.0], $doc1->getAttribute('embedding'));
        $this->assertEquals([0.5], $doc2->getAttribute('embedding'));

        // Query with single dimension
        $results = $database->find('vectorSingleDim', [
            Query::vectorCosine('embedding', [1.0])
        ]);

        $this->assertCount(2, $results);

        // Cleanup
        $database->deleteCollection('vectorSingleDim');
    }

    public function testVectorLongResultSet(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorLongResults');
        $database->createAttribute('vectorLongResults', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create 100 documents
        for ($i = 0; $i < 100; $i++) {
            $database->createDocument('vectorLongResults', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [
                    sin($i * 0.1),
                    cos($i * 0.1),
                    sin($i * 0.05)
                ]
            ]));
        }

        // Query all results
        $results = $database->find('vectorLongResults', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::limit(100)
        ]);

        $this->assertCount(100, $results);

        // Cleanup
        $database->deleteCollection('vectorLongResults');
    }

    public function testMultipleVectorQueriesOnSameCollection(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorMultiQuery');
        $database->createAttribute('vectorMultiQuery', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create documents
        for ($i = 0; $i < 10; $i++) {
            $database->createDocument('vectorMultiQuery', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [
                    cos($i * M_PI / 10),
                    sin($i * M_PI / 10),
                    0.0
                ]
            ]));
        }

        // Execute multiple different vector queries
        $results1 = $database->find('vectorMultiQuery', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::limit(5)
        ]);

        $results2 = $database->find('vectorMultiQuery', [
            Query::vectorEuclidean('embedding', [0.0, 1.0, 0.0]),
            Query::limit(5)
        ]);

        $results3 = $database->find('vectorMultiQuery', [
            Query::vectorDot('embedding', [0.5, 0.5, 0.0]),
            Query::limit(5)
        ]);

        // All should return results
        $this->assertCount(5, $results1);
        $this->assertCount(5, $results2);
        $this->assertCount(5, $results3);

        // Results should be different based on query vector
        $this->assertNotEquals(
            $results1[0]->getId(),
            $results2[0]->getId()
        );

        // Cleanup
        $database->deleteCollection('vectorMultiQuery');
    }

    public function testVectorNonNumericValidationE2E(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorNonNumeric');
        $database->createAttribute('vectorNonNumeric', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test null value in array
        try {
            $database->createDocument('vectorNonNumeric', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [1.0, null, 0.0]
            ]));
            $this->fail('Should reject null in vector array');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('numeric', strtolower($e->getMessage()));
        }

        // Test object in array
        try {
            $database->createDocument('vectorNonNumeric', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => [1.0, (object)['x' => 1], 0.0]
            ]));
            $this->fail('Should reject object in vector array');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        // Cleanup
        $database->deleteCollection('vectorNonNumeric');
    }

    public function testVectorLargeValues(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorLargeVals');
        $database->createAttribute('vectorLargeVals', 'embedding', Database::VAR_VECTOR, 3, true);

        // Test with very large float values (but not INF)
        $doc = $database->createDocument('vectorLargeVals', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1e38, -1e38, 1e37]
        ]));

        $this->assertNotNull($doc->getId());

        // Query should work
        $results = $database->find('vectorLargeVals', [
            Query::vectorCosine('embedding', [1e38, -1e38, 1e37])
        ]);

        $this->assertCount(1, $results);

        // Cleanup
        $database->deleteCollection('vectorLargeVals');
    }

    public function testVectorPrecisionLoss(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorPrecision');
        $database->createAttribute('vectorPrecision', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create vector with high precision values
        $highPrecision = [0.123456789012345, 0.987654321098765, 0.555555555555555];
        $doc = $database->createDocument('vectorPrecision', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => $highPrecision
        ]));

        // Retrieve and check precision (may have some loss)
        $retrieved = $doc->getAttribute('embedding');
        $this->assertCount(3, $retrieved);

        // Values should be close to original (allowing for float precision)
        for ($i = 0; $i < 3; $i++) {
            $this->assertEqualsWithDelta($highPrecision[$i], $retrieved[$i], 0.0001);
        }

        // Cleanup
        $database->deleteCollection('vectorPrecision');
    }

    public function testVector16000DimensionsBoundary(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Test exactly 16000 dimensions (pgvector limit)
        $database->createCollection('vector16000');
        $database->createAttribute('vector16000', 'embedding', Database::VAR_VECTOR, 16000, true);

        // Create a vector with exactly 16000 dimensions
        $largeVector = array_fill(0, 16000, 0.1);
        $largeVector[0] = 1.0;

        $doc = $database->createDocument('vector16000', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => $largeVector
        ]));

        $this->assertCount(16000, $doc->getAttribute('embedding'));

        // Query should work
        $searchVector = array_fill(0, 16000, 0.0);
        $searchVector[0] = 1.0;

        $results = $database->find('vector16000', [
            Query::vectorCosine('embedding', $searchVector),
            Query::limit(1)
        ]);

        $this->assertCount(1, $results);

        // Cleanup
        $database->deleteCollection('vector16000');
    }

    public function testVectorLargeDatasetIndexBuild(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorLargeDataset');
        $database->createAttribute('vectorLargeDataset', 'embedding', Database::VAR_VECTOR, 128, true);

        // Create 200 documents
        for ($i = 0; $i < 200; $i++) {
            $vector = [];
            for ($j = 0; $j < 128; $j++) {
                $vector[] = sin(($i + $j) * 0.01);
            }

            $database->createDocument('vectorLargeDataset', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'embedding' => $vector
            ]));
        }

        // Create index on large dataset
        $database->createIndex('vectorLargeDataset', 'idx_hnsw', Database::INDEX_HNSW_COSINE, ['embedding']);

        // Verify queries work
        $searchVector = array_fill(0, 128, 0.5);
        $results = $database->find('vectorLargeDataset', [
            Query::vectorCosine('embedding', $searchVector),
            Query::limit(10)
        ]);

        $this->assertCount(10, $results);

        // Cleanup
        $database->deleteCollection('vectorLargeDataset');
    }

    public function testVectorFilterDisabled(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorFilterDisabled');
        $database->createAttribute('vectorFilterDisabled', 'status', Database::VAR_STRING, 50, true);
        $database->createAttribute('vectorFilterDisabled', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create documents
        $database->createDocument('vectorFilterDisabled', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'status' => 'active',
            'embedding' => [1.0, 0.0, 0.0]
        ]));

        $database->createDocument('vectorFilterDisabled', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'status' => 'disabled',
            'embedding' => [0.9, 0.1, 0.0]
        ]));

        $database->createDocument('vectorFilterDisabled', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'status' => 'active',
            'embedding' => [0.8, 0.2, 0.0]
        ]));

        // Query with filter excluding disabled
        $results = $database->find('vectorFilterDisabled', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::notEqual('status', ['disabled'])
        ]);

        $this->assertCount(2, $results);
        foreach ($results as $doc) {
            $this->assertEquals('active', $doc->getAttribute('status'));
        }

        // Cleanup
        $database->deleteCollection('vectorFilterDisabled');
    }

    public function testVectorFilterOverride(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorFilterOverride');
        $database->createAttribute('vectorFilterOverride', 'category', Database::VAR_STRING, 50, true);
        $database->createAttribute('vectorFilterOverride', 'priority', Database::VAR_INTEGER, 0, true);
        $database->createAttribute('vectorFilterOverride', 'embedding', Database::VAR_VECTOR, 3, true);

        // Create documents
        for ($i = 0; $i < 5; $i++) {
            $database->createDocument('vectorFilterOverride', new Document([
                '$permissions' => [
                    Permission::read(Role::any())
                ],
                'category' => $i < 3 ? 'A' : 'B',
                'priority' => $i,
                'embedding' => [1.0 - ($i * 0.1), $i * 0.1, 0.0]
            ]));
        }

        // Query with multiple filters
        $results = $database->find('vectorFilterOverride', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::equal('category', ['A']),
            Query::greaterThan('priority', 0),
            Query::limit(2)
        ]);

        // Should get category A documents with priority > 0
        $this->assertCount(2, $results);
        foreach ($results as $doc) {
            $this->assertEquals('A', $doc->getAttribute('category'));
            $this->assertGreaterThan(0, $doc->getAttribute('priority'));
        }

        // Cleanup
        $database->deleteCollection('vectorFilterOverride');
    }

    public function testMultipleFiltersOnVectorAttribute(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorMultiFilters');
        $database->createAttribute('vectorMultiFilters', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('vectorMultiFilters', 'embedding1', Database::VAR_VECTOR, 3, true);
        $database->createAttribute('vectorMultiFilters', 'embedding2', Database::VAR_VECTOR, 3, true);

        // Create documents
        $database->createDocument('vectorMultiFilters', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Doc 1',
            'embedding1' => [1.0, 0.0, 0.0],
            'embedding2' => [0.0, 1.0, 0.0]
        ]));

        // Try to use multiple vector queries - should reject
        try {
            $database->find('vectorMultiFilters', [
                Query::vectorCosine('embedding1', [1.0, 0.0, 0.0]),
                Query::vectorCosine('embedding2', [0.0, 1.0, 0.0])
            ]);
            $this->fail('Should not allow multiple vector queries');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('multiple vector queries', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorMultiFilters');
    }

    public function testVectorQueryInNestedQuery(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorNested');
        $database->createAttribute('vectorNested', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('vectorNested', 'embedding1', Database::VAR_VECTOR, 3, true);
        $database->createAttribute('vectorNested', 'embedding2', Database::VAR_VECTOR, 3, true);

        // Create document
        $database->createDocument('vectorNested', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'name' => 'Doc 1',
            'embedding1' => [1.0, 0.0, 0.0],
            'embedding2' => [0.0, 1.0, 0.0]
        ]));

        // Try to use vector query in nested OR clause with another vector query - should reject
        try {
            $database->find('vectorNested', [
                Query::vectorCosine('embedding1', [1.0, 0.0, 0.0]),
                Query::or([
                    Query::vectorCosine('embedding2', [0.0, 1.0, 0.0]),
                    Query::equal('name', ['Doc 1'])
                ])
            ]);
            $this->fail('Should not allow multiple vector queries across nested queries');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('multiple vector queries', strtolower($e->getMessage()));
        }

        // Cleanup
        $database->deleteCollection('vectorNested');
    }

    public function testVectorQueryCount(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorCount');
        $database->createAttribute('vectorCount', 'embedding', Database::VAR_VECTOR, 3, true);

        $database->createDocument('vectorCount', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0],
        ]));

        $count = $database->count('vectorCount', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
        ]);

        $this->assertEquals(1, $count);

        $database->deleteCollection('vectorCount');
    }

    public function testVectorQuerySum(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorSum');
        $database->createAttribute('vectorSum', 'embedding', Database::VAR_VECTOR, 3, true);
        $database->createAttribute('vectorSum', 'value', Database::VAR_INTEGER, 0, true);

        // Create documents with different values
        $database->createDocument('vectorSum', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0],
            'value' => 10
        ]));

        $database->createDocument('vectorSum', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [0.0, 1.0, 0.0],
            'value' => 20
        ]));

        $database->createDocument('vectorSum', new Document([
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'embedding' => [0.5, 0.5, 0.0],
            'value' => 30
        ]));

        // Test sum with vector query - should sum all matching documents
        $sum = $database->sum('vectorSum', 'value', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
        ]);

        $this->assertEquals(60, $sum);

        // Test sum with vector query and filter combined
        $sum = $database->sum('vectorSum', 'value', [
            Query::vectorCosine('embedding', [1.0, 0.0, 0.0]),
            Query::greaterThan('value', 15),
        ]);

        $this->assertEquals(50, $sum);

        $database->deleteCollection('vectorSum');
    }

    public function testVectorUpsert(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForVectors()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('vectorUpsert');
        $database->createAttribute('vectorUpsert', 'embedding', Database::VAR_VECTOR, 3, true);

        $insertedDoc = $database->upsertDocument('vectorUpsert', new Document([
            '$id' => 'vectorUpsert',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any())
            ],
            'embedding' => [1.0, 0.0, 0.0],
        ]));

        $this->assertEquals([1.0, 0.0, 0.0], $insertedDoc->getAttribute('embedding'));

        $insertedDoc = $database->getDocument('vectorUpsert', 'vectorUpsert');
        $this->assertEquals([1.0, 0.0, 0.0], $insertedDoc->getAttribute('embedding'));

        $updatedDoc = $database->upsertDocument('vectorUpsert', new Document([
            '$id' => 'vectorUpsert',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any())
            ],
            'embedding' => [2.0, 0.0, 0.0],
        ]));

        $this->assertEquals([2.0, 0.0, 0.0], $updatedDoc->getAttribute('embedding'));

        $updatedDoc = $database->getDocument('vectorUpsert', 'vectorUpsert');
        $this->assertEquals([2.0, 0.0, 0.0], $updatedDoc->getAttribute('embedding'));

        $database->deleteCollection('vectorUpsert');
    }
}
