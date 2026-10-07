<?php

namespace Tests\E2E\Adapter\Scopes;

use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Operator as OperatorException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Type as TypeException;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Operator;
use Utopia\Database\Query;

trait OperatorTests
{
    public function testUpdateWithOperators(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create test collection with various attribute types
        $collectionId = 'test_operators';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 0.0);
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'numbers', Database::VAR_INTEGER, 0, false, null, true, true);
        $database->createAttribute($collectionId, 'name', Database::VAR_STRING, 100, false, 'test');

        // Create test document
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => 10,
            'score' => 15.5,
            'tags' => ['initial', 'tag'],
            'numbers' => [1, 2, 3],
            'name' => 'Test Document'
        ]));

        // Test increment operator
        $updated = $database->updateDocument($collectionId, 'test_doc', new Document([
            'count' => Operator::increment(5)
        ]));
        $this->assertEquals(15, $updated->getAttribute('count'));

        // Test decrement operator
        $updated = $database->updateDocument($collectionId, 'test_doc', new Document([
            'count' => Operator::decrement(3)
        ]));
        $this->assertEquals(12, $updated->getAttribute('count'));

        // Test increment with float
        $updated = $database->updateDocument($collectionId, 'test_doc', new Document([
            'score' => Operator::increment(2.5)
        ]));
        $this->assertEquals(18.0, $updated->getAttribute('score'));

        // Test append operator
        $updated = $database->updateDocument($collectionId, 'test_doc', new Document([
            'tags' => Operator::arrayAppend(['new', 'appended'])
        ]));
        $this->assertEquals(['initial', 'tag', 'new', 'appended'], $updated->getAttribute('tags'));

        // Test prepend operator
        $updated = $database->updateDocument($collectionId, 'test_doc', new Document([
            'tags' => Operator::arrayPrepend(['first'])
        ]));
        $this->assertEquals(['first', 'initial', 'tag', 'new', 'appended'], $updated->getAttribute('tags'));

        // Test insert operator
        $updated = $database->updateDocument($collectionId, 'test_doc', new Document([
            'numbers' => Operator::arrayInsert(1, 99)
        ]));
        $this->assertEquals([1, 99, 2, 3], $updated->getAttribute('numbers'));

        // Test multiple operators in one update
        $updated = $database->updateDocument($collectionId, 'test_doc', new Document([
            'count' => Operator::increment(8),
            'score' => Operator::decrement(3.0),
            'numbers' => Operator::arrayAppend([4, 5]),
            'name' => 'Updated Name' // Regular update mixed with operators
        ]));

        $this->assertEquals(20, $updated->getAttribute('count'));
        $this->assertEquals(15.0, $updated->getAttribute('score'));
        $this->assertEquals([1, 99, 2, 3, 4, 5], $updated->getAttribute('numbers'));
        $this->assertEquals('Updated Name', $updated->getAttribute('name'));

        // Test edge cases

        // Test increment with default value (1)
        $updated = $database->updateDocument($collectionId, 'test_doc', new Document([
            'count' => Operator::increment() // Should increment by 1
        ]));
        $this->assertEquals(21, $updated->getAttribute('count'));

        // Test insert at beginning (index 0)
        $updated = $database->updateDocument($collectionId, 'test_doc', new Document([
            'numbers' => Operator::arrayInsert(0, 0)
        ]));
        $this->assertEquals([0, 1, 99, 2, 3, 4, 5], $updated->getAttribute('numbers'));

        // Test insert at end
        $numbers = $updated->getAttribute('numbers');
        $lastIndex = count($numbers);
        $updated = $database->updateDocument($collectionId, 'test_doc', new Document([
            'numbers' => Operator::arrayInsert($lastIndex, 100)
        ]));
        $this->assertEquals([0, 1, 99, 2, 3, 4, 5, 100], $updated->getAttribute('numbers'));

        $database->deleteCollection($collectionId);
    }

    public function testUpdateDocumentsWithOperators(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create test collection
        $collectionId = 'test_batch_operators';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'category', Database::VAR_STRING, 50, true);

        // Create multiple test documents
        $docs = [];
        for ($i = 1; $i <= 3; $i++) {
            $docs[] = $database->createDocument($collectionId, new Document([
                '$id' => "doc_{$i}",
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'count' => $i * 10,
                'tags' => ["tag_{$i}"],
                'category' => 'test'
            ]));
        }

        // Test updateDocuments with operators
        $count = $database->updateDocuments(
            $collectionId,
            new Document([
                'count' => Operator::increment(5),
                'tags' => Operator::arrayAppend(['batch_updated']),
                'category' => 'updated' // Regular update mixed with operators
            ])
        );

        $this->assertEquals(3, $count);

        // Verify all documents were updated
        $updated = $database->find($collectionId);
        $this->assertCount(3, $updated);

        foreach ($updated as $doc) {
            $originalCount = (int) str_replace('doc_', '', $doc->getId()) * 10;
            $this->assertEquals($originalCount + 5, $doc->getAttribute('count'));
            $this->assertContains('batch_updated', $doc->getAttribute('tags'));
            $this->assertEquals('updated', $doc->getAttribute('category'));
        }

        // Test with query filters
        $count = $database->updateDocuments(
            $collectionId,
            new Document([
                'count' => Operator::increment(10)
            ]),
            [Query::equal('$id', ['doc_1', 'doc_2'])]
        );

        $this->assertEquals(2, $count);

        // Verify only filtered documents were updated
        $doc1 = $database->getDocument($collectionId, 'doc_1');
        $doc2 = $database->getDocument($collectionId, 'doc_2');
        $doc3 = $database->getDocument($collectionId, 'doc_3');

        $this->assertEquals(25, $doc1->getAttribute('count')); // 10 + 5 + 10
        $this->assertEquals(35, $doc2->getAttribute('count')); // 20 + 5 + 10
        $this->assertEquals(35, $doc3->getAttribute('count')); // 30 + 5 (not updated in second batch)

        $database->deleteCollection($collectionId);
    }

    public function testUpdateDocumentsWithAllOperators(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create comprehensive test collection
        $collectionId = 'test_all_operators_bulk';
        $database->createCollection($collectionId);

        // Create attributes for all operator types
        $database->createAttribute($collectionId, 'counter', Database::VAR_INTEGER, 0, false, 10);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 5.0);
        $database->createAttribute($collectionId, 'multiplier', Database::VAR_FLOAT, 0, false, 2.0);
        $database->createAttribute($collectionId, 'divisor', Database::VAR_FLOAT, 0, false, 100.0);
        $database->createAttribute($collectionId, 'remainder', Database::VAR_INTEGER, 0, false, 20);
        $database->createAttribute($collectionId, 'power_val', Database::VAR_FLOAT, 0, false, 2.0);
        $database->createAttribute($collectionId, 'title', Database::VAR_STRING, 255, false, 'Title');
        $database->createAttribute($collectionId, 'content', Database::VAR_STRING, 500, false, 'old content');
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'categories', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'duplicates', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'numbers', Database::VAR_INTEGER, 0, false, null, true, true);
        $database->createAttribute($collectionId, 'intersect_items', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'diff_items', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'filter_numbers', Database::VAR_INTEGER, 0, false, null, true, true);
        $database->createAttribute($collectionId, 'active', Database::VAR_BOOLEAN, 0, false, false);
        $database->createAttribute($collectionId, 'last_update', Database::VAR_DATETIME, 0, false, null, true, false, null, [], ['datetime']);
        $database->createAttribute($collectionId, 'next_update', Database::VAR_DATETIME, 0, false, null, true, false, null, [], ['datetime']);
        $database->createAttribute($collectionId, 'now_field', Database::VAR_DATETIME, 0, false, null, true, false, null, [], ['datetime']);

        // Create test documents
        $docs = [];
        for ($i = 1; $i <= 3; $i++) {
            $docs[] = $database->createDocument($collectionId, new Document([
                '$id' => "bulk_doc_{$i}",
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'counter' => $i * 10,
                'score' => $i * 1.5,
                'multiplier' => $i * 1.0,
                'divisor' => $i * 50.0,
                'remainder' => $i * 7,
                'power_val' => $i + 1.0,
                'title' => "Title {$i}",
                'content' => "old content {$i}",
                'tags' => ["tag_{$i}", "common"],
                'categories' => ["cat_{$i}", "test"],
                'items' => ["item_{$i}", "shared", "item_{$i}"],
                'duplicates' => ["a", "b", "a", "c", "b", "d"],
                'numbers' => [1, 2, 3, 4, 5],
                'intersect_items' => ["a", "b", "c", "d"],
                'diff_items' => ["x", "y", "z", "w"],
                'filter_numbers' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                'active' => $i % 2 === 0,
                'last_update' => DateTime::addSeconds(new \DateTime(), -86400),
                'next_update' => DateTime::addSeconds(new \DateTime(), 86400)
            ]));
        }

        // Test bulk update with ALL operators
        $count = $database->updateDocuments(
            $collectionId,
            new Document([
                'counter' => Operator::increment(5, 50),           // Math with limit
                'score' => Operator::decrement(0.5, 0),            // Math with limit
                'multiplier' => Operator::multiply(2, 100),        // Math with limit
                'divisor' => Operator::divide(2, 10),              // Math with limit
                'remainder' => Operator::modulo(5),                // Math
                'power_val' => Operator::power(2, 100),            // Math with limit
                'title' => Operator::stringConcat(' - Updated'),         // String
                'content' => Operator::stringReplace('old', 'new'),      // String
                'tags' => Operator::arrayAppend(['bulk']),         // Array
                'categories' => Operator::arrayPrepend(['priority']), // Array
                'items' => Operator::arrayRemove('shared'),        // Array
                'duplicates' => Operator::arrayUnique(),           // Array
                'numbers' => Operator::arrayInsert(2, 99),         // Array insert at index 2
                'intersect_items' => Operator::arrayIntersect(['b', 'c', 'e']), // Array intersect
                'diff_items' => Operator::arrayDiff(['y', 'z']),   // Array diff (remove y, z)
                'filter_numbers' => Operator::arrayFilter('greaterThan', 5), // Array filter
                'active' => Operator::toggle(),                    // Boolean
                'last_update' => Operator::dateAddDays(1),         // Date
                'next_update' => Operator::dateSubDays(1),         // Date
                'now_field' => Operator::dateSetNow()              // Date
            ])
        );

        $this->assertEquals(3, $count);

        // Verify all operators worked correctly
        $updated = $database->find($collectionId, [Query::orderAsc('$id')]);
        $this->assertCount(3, $updated);

        // Check bulk_doc_1
        $doc1 = $updated[0];
        $this->assertEquals(15, $doc1->getAttribute('counter'));          // 10 + 5
        $this->assertEquals(1.0, $doc1->getAttribute('score'));           // 1.5 - 0.5
        $this->assertEquals(2.0, $doc1->getAttribute('multiplier'));      // 1.0 * 2
        $this->assertEquals(25.0, $doc1->getAttribute('divisor'));        // 50.0 / 2
        $this->assertEquals(2, $doc1->getAttribute('remainder'));         // 7 % 5
        $this->assertEquals(4.0, $doc1->getAttribute('power_val'));       // 2^2
        $this->assertEquals('Title 1 - Updated', $doc1->getAttribute('title'));
        $this->assertEquals('new content 1', $doc1->getAttribute('content'));
        $this->assertContains('bulk', $doc1->getAttribute('tags'));
        $this->assertContains('priority', $doc1->getAttribute('categories'));
        $this->assertNotContains('shared', $doc1->getAttribute('items'));
        $this->assertCount(4, $doc1->getAttribute('duplicates')); // Should have unique values
        $this->assertEquals([1, 2, 99, 3, 4, 5], $doc1->getAttribute('numbers')); // arrayInsert at index 2
        $this->assertEquals(['b', 'c'], $doc1->getAttribute('intersect_items')); // arrayIntersect
        $this->assertEquals(['x', 'w'], $doc1->getAttribute('diff_items')); // arrayDiff (removed y, z)
        $this->assertEquals([6, 7, 8, 9, 10], $doc1->getAttribute('filter_numbers')); // arrayFilter greaterThan 5
        $this->assertEquals(true, $doc1->getAttribute('active'));         // Was false, toggled to true

        // Check bulk_doc_2
        $doc2 = $updated[1];
        $this->assertEquals(25, $doc2->getAttribute('counter'));          // 20 + 5
        $this->assertEquals(2.5, $doc2->getAttribute('score'));           // 3.0 - 0.5
        $this->assertEquals(4.0, $doc2->getAttribute('multiplier'));      // 2.0 * 2
        $this->assertEquals(50.0, $doc2->getAttribute('divisor'));        // 100.0 / 2
        $this->assertEquals(4, $doc2->getAttribute('remainder'));         // 14 % 5
        $this->assertEquals(9.0, $doc2->getAttribute('power_val'));       // 3^2
        $this->assertEquals('Title 2 - Updated', $doc2->getAttribute('title'));
        $this->assertEquals('new content 2', $doc2->getAttribute('content'));
        $this->assertEquals(false, $doc2->getAttribute('active'));        // Was true, toggled to false

        // Check bulk_doc_3
        $doc3 = $updated[2];
        $this->assertEquals(35, $doc3->getAttribute('counter'));          // 30 + 5
        $this->assertEquals(4.0, $doc3->getAttribute('score'));           // 4.5 - 0.5
        $this->assertEquals(6.0, $doc3->getAttribute('multiplier'));      // 3.0 * 2
        $this->assertEquals(75.0, $doc3->getAttribute('divisor'));        // 150.0 / 2
        $this->assertEquals(1, $doc3->getAttribute('remainder'));         // 21 % 5
        $this->assertEquals(16.0, $doc3->getAttribute('power_val'));      // 4^2
        $this->assertEquals('Title 3 - Updated', $doc3->getAttribute('title'));
        $this->assertEquals('new content 3', $doc3->getAttribute('content'));
        $this->assertEquals(true, $doc3->getAttribute('active'));         // Was false, toggled to true

        // Verify date operations worked (just check they're not null and are strings)
        $this->assertNotNull($doc1->getAttribute('last_update'));
        $this->assertNotNull($doc1->getAttribute('next_update'));
        $this->assertNotNull($doc1->getAttribute('now_field'));

        $database->deleteCollection($collectionId);
    }

    public function testUpdateDocumentsOperatorsWithQueries(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create test collection
        $collectionId = 'test_operators_with_queries';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'category', Database::VAR_STRING, 50, true);
        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 0.0);
        $database->createAttribute($collectionId, 'active', Database::VAR_BOOLEAN, 0, false, false);

        // Create test documents
        for ($i = 1; $i <= 5; $i++) {
            $database->createDocument($collectionId, new Document([
                '$id' => "query_doc_{$i}",
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'category' => $i <= 3 ? 'A' : 'B',
                'count' => $i * 10,
                'score' => $i * 1.5,
                'active' => $i % 2 === 0
            ]));
        }

        // Test 1: Update only category A documents
        $count = $database->updateDocuments(
            $collectionId,
            new Document([
                'count' => Operator::increment(100),
                'score' => Operator::multiply(2)
            ]),
            [Query::equal('category', ['A'])]
        );

        $this->assertEquals(3, $count);

        // Verify only category A documents were updated
        $categoryA = $database->find($collectionId, [Query::equal('category', ['A']), Query::orderAsc('$id')]);
        $categoryB = $database->find($collectionId, [Query::equal('category', ['B']), Query::orderAsc('$id')]);

        $this->assertEquals(110, $categoryA[0]->getAttribute('count')); // 10 + 100
        $this->assertEquals(120, $categoryA[1]->getAttribute('count')); // 20 + 100
        $this->assertEquals(130, $categoryA[2]->getAttribute('count')); // 30 + 100
        $this->assertEquals(40, $categoryB[0]->getAttribute('count'));  // Not updated
        $this->assertEquals(50, $categoryB[1]->getAttribute('count'));  // Not updated

        // Test 2: Update only documents with count < 50
        $count = $database->updateDocuments(
            $collectionId,
            new Document([
                'active' => Operator::toggle(),
                'score' => Operator::multiply(10)
            ]),
            [Query::lessThan('count', 50)]
        );

        // Only doc_4 (count=40) matches, doc_5 has count=50 which is not < 50
        $this->assertEquals(1, $count);

        $doc4 = $database->getDocument($collectionId, 'query_doc_4');
        $this->assertEquals(false, $doc4->getAttribute('active')); // Was true, now false
        // Doc_4 initial score: 4*1.5 = 6.0
        // Category B so not updated in first batch
        // Second update: 6.0 * 10 = 60.0
        $this->assertEquals(60.0, $doc4->getAttribute('score'));

        // Verify doc_5 was not updated
        $doc5 = $database->getDocument($collectionId, 'query_doc_5');
        $this->assertEquals(false, $doc5->getAttribute('active')); // Still false
        $this->assertEquals(7.5, $doc5->getAttribute('score'));    // Still 5*1.5=7.5 (category B, not updated)

        $database->deleteCollection($collectionId);
    }

    public function testUpdateDocumentsOperatorsWithSelect(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'test_operators_with_select';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'category', Database::VAR_STRING, 50, true);
        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 0.0);

        for ($i = 1; $i <= 3; $i++) {
            $database->createDocument($collectionId, new Document([
                '$id' => "select_doc_{$i}",
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'category' => 'A',
                'count' => $i * 10,
                'score' => $i * 1.5,
            ]));
        }

        // Update with an operator while selecting only a subset of attributes.
        // The operator path refetches to compute values; it must still honor the caller's select.
        $updated = [];
        $count = $database->updateDocuments(
            $collectionId,
            new Document([
                'count' => Operator::increment(100),
            ]),
            [Query::select(['count']), Query::orderAsc('$id')],
            onNext: function (Document $doc) use (&$updated) {
                $updated[] = $doc;
            }
        );

        $this->assertEquals(3, $count);
        $this->assertCount(3, $updated);

        // A plain find with the same select defines the expected projection.
        $found = $database->find($collectionId, [Query::select(['count']), Query::orderAsc('$id')]);

        foreach ($updated as $index => $doc) {
            // Computed operator value is present and correct.
            $this->assertEquals(($index + 1) * 10 + 100, $doc->getAttribute('count'));

            // The operator path must return the same projection as a normal select find,
            // i.e. it must not leak the non-selected attributes (regression check).
            $this->assertEquals(
                \array_keys($found[$index]->getArrayCopy()),
                \array_keys($doc->getArrayCopy())
            );
        }

        $database->deleteCollection($collectionId);
    }

    public function testUpdateDocumentsOperatorsBatchLargerThanDefaultLimit(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'test_operators_large_batch';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);

        // More documents than find()'s default limit (25) so the refetch must page/limit correctly.
        $total = 60;
        for ($i = 1; $i <= $total; $i++) {
            $database->createDocument($collectionId, new Document([
                '$id' => "batch_doc_{$i}",
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'count' => $i,
            ]));
        }

        // Update every document with an operator. The refetch must return computed values for the
        // whole batch, not just the first 25 rows (which would otherwise fall back to stale values).
        $updated = [];
        $count = $database->updateDocuments(
            $collectionId,
            new Document([
                'count' => Operator::increment(1000),
            ]),
            [],
            batchSize: $total,
            onNext: function (Document $doc) use (&$updated) {
                $updated[$doc->getId()] = $doc;
            }
        );

        $this->assertEquals($total, $count);
        $this->assertCount($total, $updated);

        // Every document must reflect the computed operator value.
        for ($i = 1; $i <= $total; $i++) {
            $doc = $updated["batch_doc_{$i}"] ?? null;
            $this->assertNotNull($doc, "Missing callback document batch_doc_{$i}");
            $this->assertEquals($i + 1000, $doc->getAttribute('count'), "Stale value for batch_doc_{$i}");

            // And it must be persisted, not just returned.
            $fresh = $database->getDocument($collectionId, "batch_doc_{$i}");
            $this->assertEquals($i + 1000, $fresh->getAttribute('count'));
        }

        $database->deleteCollection($collectionId);
    }

    public function testUpdateDocumentOperatorDoesNotDoubleDecodeFilters(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // A deliberately non-idempotent filter: decoding a second time corrupts the value
        // (json_decode of the already-plaintext value yields null). This surfaces a double-decode.
        $database->addFilter(
            'operator_double_decode',
            function (mixed $value) {
                return json_encode(['data' => base64_encode((string) $value)]);
            },
            function (mixed $value) {
                if (is_null($value)) {
                    return;
                }
                $decoded = json_decode($value, true);
                return base64_decode($decoded['data']);
            }
        );

        $collectionId = 'test_operator_double_decode';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'secret', Database::VAR_STRING, 128, false, filters: ['operator_double_decode']);

        $database->createDocument($collectionId, new Document([
            '$id' => 'doc1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => 5,
            'secret' => 'hunter2',
        ]));

        // The operator refetch happens inside the transaction and already decodes via find().
        // Decoding the returned document again would run the filter twice and corrupt 'secret'.
        $updated = $database->updateDocument($collectionId, 'doc1', new Document([
            'count' => Operator::increment(10),
        ]));

        $this->assertEquals(15, $updated->getAttribute('count'));
        $this->assertEquals('hunter2', $updated->getAttribute('secret'));

        // A normal read decodes exactly once — the operator path must match it.
        $fresh = $database->getDocument($collectionId, 'doc1');
        $this->assertEquals('hunter2', $fresh->getAttribute('secret'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorErrorHandling(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create test collection
        $collectionId = 'test_operator_errors';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'text_field', Database::VAR_STRING, 100, true);
        $database->createAttribute($collectionId, 'number_field', Database::VAR_INTEGER, 0, true);
        $database->createAttribute($collectionId, 'array_field', Database::VAR_STRING, 50, false, null, true, true);

        // Create test document
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'error_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text_field' => 'hello',
            'number_field' => 42,
            'array_field' => ['item1', 'item2']
        ]));

        // Test increment on non-numeric field
        $this->expectException(StructureException::class);
        $this->expectExceptionMessage("Cannot apply increment operator to non-numeric field 'text_field'");

        $database->updateDocument($collectionId, 'error_test_doc', new Document([
            'text_field' => Operator::increment(1)
        ]));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayErrorHandling(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create test collection
        $collectionId = 'test_array_operator_errors';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'text_field', Database::VAR_STRING, 100, true);
        $database->createAttribute($collectionId, 'array_field', Database::VAR_STRING, 50, false, null, true, true);

        // Create test document
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'array_error_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text_field' => 'hello',
            'array_field' => ['item1', 'item2']
        ]));

        // Test append on non-array field
        $this->expectException(StructureException::class);
        $this->expectExceptionMessage("Cannot apply arrayAppend operator to non-array field 'text_field'");

        $database->updateDocument($collectionId, 'array_error_test_doc', new Document([
            'text_field' => Operator::arrayAppend(['new_item'])
        ]));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorInsertErrorHandling(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create test collection
        $collectionId = 'test_insert_operator_errors';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'array_field', Database::VAR_STRING, 50, false, null, true, true);

        // Create test document
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'insert_error_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'array_field' => ['item1', 'item2']
        ]));

        // Test insert with negative index
        $this->expectException(StructureException::class);
        $this->expectExceptionMessage("Cannot apply arrayInsert operator: index must be a non-negative integer");

        $database->updateDocument($collectionId, 'insert_error_test_doc', new Document([
            'array_field' => Operator::arrayInsert(-1, 'new_item')
        ]));

        $database->deleteCollection($collectionId);
    }

    /**
     * Comprehensive edge case tests for operator validation failures
     */
    public function testOperatorValidationEdgeCases(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create comprehensive test collection
        $collectionId = 'test_operator_edge_cases';
        $database->createCollection($collectionId);

        // Create various attribute types for testing
        $database->createAttribute($collectionId, 'string_field', Database::VAR_STRING, 100, false, 'default');
        $database->createAttribute($collectionId, 'int_field', Database::VAR_INTEGER, 0, false, 10);
        $database->createAttribute($collectionId, 'float_field', Database::VAR_FLOAT, 0, false, 1.5);
        $database->createAttribute($collectionId, 'bool_field', Database::VAR_BOOLEAN, 0, false, false);
        $database->createAttribute($collectionId, 'array_field', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'date_field', Database::VAR_DATETIME, 0, false, null, true, false, null, [], ['datetime']);

        // Create test document
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'edge_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'string_field' => 'hello',
            'int_field' => 42,
            'float_field' => 3.14,
            'bool_field' => true,
            'array_field' => ['a', 'b', 'c'],
            'date_field' => '2023-01-01 00:00:00'
        ]));

        // Test: Math operator on string field
        try {
            $database->updateDocument($collectionId, 'edge_test_doc', new Document([
                'string_field' => Operator::increment(5)
            ]));
            $this->fail('Expected exception for increment on string field');
        } catch (StructureException $e) {
            $this->assertStringContainsString("Cannot apply increment operator to non-numeric field 'string_field'", $e->getMessage());
        }

        // Test: String operator on numeric field
        try {
            $database->updateDocument($collectionId, 'edge_test_doc', new Document([
                'int_field' => Operator::stringConcat(' suffix')
            ]));
            $this->fail('Expected exception for concat on integer field');
        } catch (StructureException $e) {
            $this->assertStringContainsString("Cannot apply stringConcat operator", $e->getMessage());
        }

        // Test: Array operator on non-array field
        try {
            $database->updateDocument($collectionId, 'edge_test_doc', new Document([
                'string_field' => Operator::arrayAppend(['new'])
            ]));
            $this->fail('Expected exception for arrayAppend on string field');
        } catch (StructureException $e) {
            $this->assertStringContainsString("Cannot apply arrayAppend operator to non-array field 'string_field'", $e->getMessage());
        }

        // Test: Boolean operator on non-boolean field
        try {
            $database->updateDocument($collectionId, 'edge_test_doc', new Document([
                'int_field' => Operator::toggle()
            ]));
            $this->fail('Expected exception for toggle on integer field');
        } catch (StructureException $e) {
            $this->assertStringContainsString("Cannot apply toggle operator to non-boolean field 'int_field'", $e->getMessage());
        }

        // Test: Date operator on non-date field
        try {
            $database->updateDocument($collectionId, 'edge_test_doc', new Document([
                'string_field' => Operator::dateAddDays(5)
            ]));
            $this->fail('Expected exception for dateAddDays on string field');
        } catch (StructureException $e) {
            // Date operators check if string can be parsed as date
            $this->assertStringContainsString("Cannot apply dateAddDays operator to non-datetime field 'string_field'", $e->getMessage());
        }

        $database->deleteCollection($collectionId);
    }

    public function testOperatorDivisionModuloByZero(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_division_zero';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'number', Database::VAR_FLOAT, 0, false, 100.0);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'zero_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'number' => 100.0
        ]));

        // Test: Division by zero
        try {
            $database->updateDocument($collectionId, 'zero_test_doc', new Document([
                'number' => Operator::divide(0)
            ]));
            $this->fail('Expected exception for division by zero');
        } catch (OperatorException $e) {
            $this->assertStringContainsString("Division by zero is not allowed", $e->getMessage());
        }

        // Test: Modulo by zero
        try {
            $database->updateDocument($collectionId, 'zero_test_doc', new Document([
                'number' => Operator::modulo(0)
            ]));
            $this->fail('Expected exception for modulo by zero');
        } catch (OperatorException $e) {
            $this->assertStringContainsString("Modulo by zero is not allowed", $e->getMessage());
        }

        // Test: Valid division
        $updated = $database->updateDocument($collectionId, 'zero_test_doc', new Document([
            'number' => Operator::divide(2)
        ]));
        $this->assertEquals(50.0, $updated->getAttribute('number'));

        // Test: Valid modulo
        $updated = $database->updateDocument($collectionId, 'zero_test_doc', new Document([
            'number' => Operator::modulo(7)
        ]));
        $this->assertEquals(1.0, $updated->getAttribute('number')); // 50 % 7 = 1

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayInsertOutOfBounds(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_array_insert_bounds';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'bounds_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['a', 'b', 'c'] // Length = 3
        ]));

        // Test: Insert at out of bounds index
        try {
            $database->updateDocument($collectionId, 'bounds_test_doc', new Document([
                'items' => Operator::arrayInsert(10, 'new') // Index 10 > length 3
            ]));
            $this->fail('Expected exception for out of bounds insert');
        } catch (StructureException $e) {
            $this->assertStringContainsString("Cannot apply arrayInsert operator: index 10 is out of bounds for array of length 3", $e->getMessage());
        }

        // Test: Insert at valid index (end)
        $updated = $database->updateDocument($collectionId, 'bounds_test_doc', new Document([
            'items' => Operator::arrayInsert(3, 'd') // Insert at end
        ]));
        $this->assertEquals(['a', 'b', 'c', 'd'], $updated->getAttribute('items'));

        // Test: Insert at valid index (middle)
        $updated = $database->updateDocument($collectionId, 'bounds_test_doc', new Document([
            'items' => Operator::arrayInsert(2, 'x') // Insert at index 2
        ]));
        $this->assertEquals(['a', 'b', 'x', 'c', 'd'], $updated->getAttribute('items'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorValueLimits(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_operator_limits';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'counter', Database::VAR_INTEGER, 0, false, 10);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 5.0);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'limits_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'counter' => 10,
            'score' => 5.0
        ]));

        // Test: Increment with max limit
        $updated = $database->updateDocument($collectionId, 'limits_test_doc', new Document([
            'counter' => Operator::increment(100, 50) // Increment by 100 but max is 50
        ]));
        $this->assertEquals(10, $updated->getAttribute('counter')); // Unchanged — would exceed 50

        // Test: Decrement with min limit
        $updated = $database->updateDocument($collectionId, 'limits_test_doc', new Document([
            'score' => Operator::decrement(10, 0) // Decrement score by 10 but min is 0
        ]));
        $this->assertEquals(5.0, $updated->getAttribute('score')); // Unchanged — would go below 0

        // Test: Multiply with max limit
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'limits_test_doc2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'counter' => 10,
            'score' => 5.0
        ]));

        $updated = $database->updateDocument($collectionId, 'limits_test_doc2', new Document([
            'counter' => Operator::multiply(10, 75) // 10 * 10 = 100, but max is 75
        ]));
        $this->assertEquals(10, $updated->getAttribute('counter')); // Unchanged — would exceed 75

        // Test: Power with max limit
        $updated = $database->updateDocument($collectionId, 'limits_test_doc2', new Document([
            'score' => Operator::power(3, 100) // 5^3 = 125, but max is 100
        ]));
        $this->assertEquals(5.0, $updated->getAttribute('score')); // Unchanged — would exceed 100

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayFilterValidation(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_array_filter';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'numbers', Database::VAR_INTEGER, 0, false, null, true, true);
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'filter_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'numbers' => [1, 2, 3, 4, 5],
            'tags' => ['apple', 'banana', 'cherry']
        ]));

        // Test: Filter with equals condition on numbers
        $updated = $database->updateDocument($collectionId, 'filter_test_doc', new Document([
            'numbers' => Operator::arrayFilter('equal', 3) // Keep only 3
        ]));
        $this->assertEquals([3], $updated->getAttribute('numbers'));

        // Test: Filter with not-equals condition on strings
        $updated = $database->updateDocument($collectionId, 'filter_test_doc', new Document([
            'tags' => Operator::arrayFilter('notEqual', 'banana') // Remove 'banana'
        ]));
        $this->assertEquals(['apple', 'cherry'], $updated->getAttribute('tags'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorReplaceValidation(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_replace';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'text', Database::VAR_STRING, 255, false, 'default text');
        $database->createAttribute($collectionId, 'number', Database::VAR_INTEGER, 0, false, 0);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'replace_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text' => 'The quick brown fox',
            'number' => 42
        ]));

        // Test: Valid replace operation
        $updated = $database->updateDocument($collectionId, 'replace_test_doc', new Document([
            'text' => Operator::stringReplace('quick', 'slow')
        ]));
        $this->assertEquals('The slow brown fox', $updated->getAttribute('text'));

        // Test: Replace on non-string field
        try {
            $database->updateDocument($collectionId, 'replace_test_doc', new Document([
                'number' => Operator::stringReplace('4', '5')
            ]));
            $this->fail('Expected exception for replace on integer field');
        } catch (StructureException $e) {
            $this->assertStringContainsString("Cannot apply stringReplace operator to non-string field 'number'", $e->getMessage());
        }

        // Test: Replace with empty string
        $updated = $database->updateDocument($collectionId, 'replace_test_doc', new Document([
            'text' => Operator::stringReplace('slow', '')
        ]));
        $this->assertEquals('The  brown fox', $updated->getAttribute('text')); // Two spaces where 'slow' was

        $database->deleteCollection($collectionId);
    }

    public function testOperatorNullValueHandling(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_null_handling';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'nullable_int', Database::VAR_INTEGER, 0, false, null, false, false);
        $database->createAttribute($collectionId, 'nullable_string', Database::VAR_STRING, 100, false, null, false, false);
        $database->createAttribute($collectionId, 'nullable_bool', Database::VAR_BOOLEAN, 0, false, null, false, false);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'null_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'nullable_int' => null,
            'nullable_string' => null,
            'nullable_bool' => null
        ]));

        // Test: Increment on null numeric field (should treat as 0)
        $updated = $database->updateDocument($collectionId, 'null_test_doc', new Document([
            'nullable_int' => Operator::increment(5)
        ]));
        $this->assertEquals(5, $updated->getAttribute('nullable_int'));

        // Test: Concat on null string field (should treat as empty string)
        $updated = $database->updateDocument($collectionId, 'null_test_doc', new Document([
            'nullable_string' => Operator::stringConcat('hello')
        ]));
        $this->assertEquals('hello', $updated->getAttribute('nullable_string'));

        // Test: Toggle on null boolean field (should treat as false)
        $updated = $database->updateDocument($collectionId, 'null_test_doc', new Document([
            'nullable_bool' => Operator::toggle()
        ]));
        $this->assertEquals(true, $updated->getAttribute('nullable_bool'));

        // Test operators on non-null values
        $updated = $database->updateDocument($collectionId, 'null_test_doc', new Document([
            'nullable_int' => Operator::multiply(2)  // 5 * 2 = 10
        ]));
        $this->assertEquals(10, $updated->getAttribute('nullable_int'));

        $updated = $database->updateDocument($collectionId, 'null_test_doc', new Document([
            'nullable_string' => Operator::stringReplace('hello', 'hi')
        ]));
        $this->assertEquals('hi', $updated->getAttribute('nullable_string'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorComplexScenarios(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_complex_operators';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'stats', Database::VAR_INTEGER, 0, false, null, true, true);
        $database->createAttribute($collectionId, 'metadata', Database::VAR_STRING, 100, false, null, true, true);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 0.0);
        $database->createAttribute($collectionId, 'name', Database::VAR_STRING, 255, false, '');

        // Create document with complex data
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'complex_test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'stats' => [10, 20, 20, 30, 20, 40],
            'metadata' => ['key1', 'key2', 'key3'],
            'score' => 50.0,
            'name' => 'Test'
        ]));

        // Test: Multiple operations on same array
        $updated = $database->updateDocument($collectionId, 'complex_test_doc', new Document([
            'stats' => Operator::arrayUnique() // Should remove duplicate 20s
        ]));
        $stats = $updated->getAttribute('stats');
        $this->assertCount(4, $stats); // [10, 20, 30, 40]
        $this->assertEquals([10, 20, 30, 40], $stats);

        // Test: Array intersection
        $updated = $database->updateDocument($collectionId, 'complex_test_doc', new Document([
            'stats' => Operator::arrayIntersect([20, 30, 50]) // Keep only 20 and 30
        ]));
        $this->assertEquals([20, 30], $updated->getAttribute('stats'));

        // Test: Array difference
        $doc2 = $database->createDocument($collectionId, new Document([
            '$id' => 'complex_test_doc2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'stats' => [1, 2, 3, 4, 5],
            'metadata' => ['a', 'b', 'c'],
            'score' => 100.0,
            'name' => 'Test2'
        ]));

        $updated = $database->updateDocument($collectionId, 'complex_test_doc2', new Document([
            'stats' => Operator::arrayDiff([2, 4, 6]) // Remove 2 and 4
        ]));
        $this->assertEquals([1, 3, 5], $updated->getAttribute('stats'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorIncrement(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_increment_operator';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => 5
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'count' => Operator::increment(3)
        ]));

        $this->assertEquals(8, $updated->getAttribute('count'));

        // Edge case: null value
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => null
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'count' => Operator::increment(3)
        ]));

        $this->assertEquals(3, $updated->getAttribute('count'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorStringConcat(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_string_concat_operator';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'title', Database::VAR_STRING, 255, false, '');

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'title' => 'Hello'
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'title' => Operator::stringConcat(' World')
        ]));

        $this->assertEquals('Hello World', $updated->getAttribute('title'));

        // Edge case: null value
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'title' => null
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'title' => Operator::stringConcat('Test')
        ]));

        $this->assertEquals('Test', $updated->getAttribute('title'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorModulo(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_modulo_operator';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'number', Database::VAR_INTEGER, 0, false, 0);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'number' => 10
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'number' => Operator::modulo(3)
        ]));

        $this->assertEquals(1, $updated->getAttribute('number'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorToggle(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_toggle_operator';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'active', Database::VAR_BOOLEAN, 0, false, false);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'active' => false
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'active' => Operator::toggle()
        ]));

        $this->assertEquals(true, $updated->getAttribute('active'));

        // Test toggle again
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'active' => Operator::toggle()
        ]));

        $this->assertEquals(false, $updated->getAttribute('active'));

        $database->deleteCollection($collectionId);
    }


    public function testOperatorArrayUnique(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_array_unique_operator';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['a', 'b', 'a', 'c', 'b']
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayUnique()
        ]));

        $result = $updated->getAttribute('items');
        $this->assertCount(3, $result);
        $this->assertContains('a', $result);
        $this->assertContains('b', $result);
        $this->assertContains('c', $result);

        $database->deleteCollection($collectionId);
    }

    // Comprehensive Operator Tests

    public function testOperatorIncrementComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Setup collection
        $collectionId = 'operator_increment_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false);
        $database->createAttribute($collectionId, 'text', Database::VAR_STRING, 255, false);

        // Success case - integer
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => 5
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'count' => Operator::increment(3)
        ]));

        $this->assertEquals(8, $updated->getAttribute('count'));

        // Success case - with max limit
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'count' => Operator::increment(5, 10)
        ]));
        $this->assertEquals(8, $updated->getAttribute('count')); // Unchanged — would exceed 10

        // Success case - float
        $doc2 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'score' => 2.5
        ]));

        $updated = $database->updateDocument($collectionId, $doc2->getId(), new Document([
            'score' => Operator::increment(1.5)
        ]));
        $this->assertEquals(4.0, $updated->getAttribute('score'));

        // Edge case: null value
        $doc3 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => null
        ]));
        $updated = $database->updateDocument($collectionId, $doc3->getId(), new Document([
            'count' => Operator::increment(5)
        ]));
        $this->assertEquals(5, $updated->getAttribute('count'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorDecrementComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_decrement_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => 10
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'count' => Operator::decrement(3)
        ]));

        $this->assertEquals(7, $updated->getAttribute('count'));

        // Success case - with min limit
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'count' => Operator::decrement(10, 5)
        ]));
        $this->assertEquals(7, $updated->getAttribute('count')); // Unchanged — would go below 5

        // Edge case: null value
        $doc2 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => null
        ]));
        $updated = $database->updateDocument($collectionId, $doc2->getId(), new Document([
            'count' => Operator::decrement(3)
        ]));
        $this->assertEquals(-3, $updated->getAttribute('count'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorMultiplyComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_multiply_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, false);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 4.0
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'value' => Operator::multiply(2.5)
        ]));

        $this->assertEquals(10.0, $updated->getAttribute('value'));

        // Success case - with max limit
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'value' => Operator::multiply(3, 20)
        ]));
        $this->assertEquals(10.0, $updated->getAttribute('value')); // Unchanged — would exceed 20

        $database->deleteCollection($collectionId);
    }

    public function testOperatorDivideComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_divide_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, false);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 10.0
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'value' => Operator::divide(2)
        ]));

        $this->assertEquals(5.0, $updated->getAttribute('value'));

        // Success case - with min limit
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'value' => Operator::divide(10, 2)
        ]));
        $this->assertEquals(5.0, $updated->getAttribute('value')); // Unchanged — would go below 2

        $database->deleteCollection($collectionId);
    }

    public function testOperatorModuloComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_modulo_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'number', Database::VAR_INTEGER, 0, false);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'number' => 10
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'number' => Operator::modulo(3)
        ]));

        $this->assertEquals(1, $updated->getAttribute('number'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorPowerComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_power_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'number', Database::VAR_FLOAT, 0, false);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'number' => 2
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'number' => Operator::power(3)
        ]));

        $this->assertEquals(8, $updated->getAttribute('number'));

        // Success case - with max limit
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'number' => Operator::power(4, 50)
        ]));
        $this->assertEquals(8, $updated->getAttribute('number')); // Unchanged — would exceed 50

        $database->deleteCollection($collectionId);
    }

    /**
     * When an operation makes the value SMALLER, the max limit should not get in the way:
     * the new (smaller) value is below the max, so it must be saved. Older code had a bug
     * where, if the current value was already at or above the max, it refused to change it —
     * so multiply(0.5, 50) on 80 wrongly stayed 50 instead of becoming 40, and a square root
     * (power(0.5, 50)) on 100 stayed 50 instead of 10.
     */
    public function testOperatorBoundedShrinkApplies(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'operator_bounded_shrink';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, false, 0.0);

        $database->createDocument($collectionId, new Document([
            '$id' => 'shrink_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 80.0,
        ]));

        // 80 * 0.5 = 40. 40 is below the max of 50, so 40 is saved. (Old code wrongly kept 50.)
        $updated = $database->updateDocument($collectionId, 'shrink_doc', new Document([
            'value' => Operator::multiply(0.5, 50),
        ]));
        $this->assertEquals(40.0, $updated->getAttribute('value'));

        // Square root of 100 is 10. 10 is below the max of 50, so 10 is saved. (Old code kept 50.)
        $database->updateDocument($collectionId, 'shrink_doc', new Document([
            'value' => 100.0,
        ]));
        $updated = $database->updateDocument($collectionId, 'shrink_doc', new Document([
            'value' => Operator::power(0.5, 50),
        ]));
        $this->assertEquals(10.0, $updated->getAttribute('value'));

        // Adding a negative number lowers the value: 52 + (-5) = 47. 47 is below the max of 50,
        // so 47 is saved even though the starting value 52 was already above the max.
        // (Older code failed to save 47 here.)
        $database->updateDocument($collectionId, 'shrink_doc', new Document([
            'value' => 52.0,
        ]));
        $updated = $database->updateDocument($collectionId, 'shrink_doc', new Document([
            'value' => Operator::increment(-5, 50),
        ]));
        $this->assertEquals(47.0, $updated->getAttribute('value'));

        $database->deleteCollection($collectionId);
    }

    /**
     * A limit on one field only affects that one field. In a single update, if one operator
     * hits its limit and is skipped, the other operators and the normal fields in the same
     * update still get saved.
     */
    public function testOperatorGuardIsPerColumn(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'operator_guard_per_column';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 0.0);
        $database->createAttribute($collectionId, 'name', Database::VAR_STRING, 100, false, '');

        $database->createDocument($collectionId, new Document([
            '$id' => 'doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => 48,
            'score' => 10.0,
            'name' => 'before',
        ]));

        $updated = $database->updateDocument($collectionId, 'doc', new Document([
            'count' => Operator::increment(5, 50),  // 48 + 5 = 53, over the max 50, so count is left as 48
            'score' => Operator::multiply(2, 100),  // 10 * 2 = 20, under the max 100, so score becomes 20
            'name' => 'after',                      // a normal field, always saved
        ]));

        $this->assertEquals(48, $updated->getAttribute('count'));     // left unchanged (would have gone over the max)
        $this->assertEquals(20.0, $updated->getAttribute('score'));   // saved
        $this->assertEquals('after', $updated->getAttribute('name')); // saved

        $database->deleteCollection($collectionId);
    }

    /**
     * When updating many documents at once, each document is checked against the limit using
     * its own value. Here d2's count is over the limit so d2's count is left alone, but d2's
     * score is still under its limit and is saved. So the limit on one field of one document
     * does not stop other fields, or other documents, from being updated.
     */
    public function testOperatorGuardIsPerRowInBatch(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'operator_guard_per_row';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 0.0);

        foreach ([['d1', 10], ['d2', 48]] as [$id, $count]) {
            $database->createDocument($collectionId, new Document([
                '$id' => $id,
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'count' => $count,
                'score' => 10.0,
            ]));
        }

        $database->updateDocuments($collectionId, new Document([
            'count' => Operator::increment(5, 50),   // d1: 10+5=15 saved; d2: 48+5=53 is over 50, so d2.count stays 48
            'score' => Operator::increment(5, 100),  // both: 10+5=15, under 100, so saved
        ]));

        $d1 = $database->getDocument($collectionId, 'd1');
        $d2 = $database->getDocument($collectionId, 'd2');

        $this->assertEquals(15, $d1->getAttribute('count'));    // under the max, saved
        $this->assertEquals(15.0, $d1->getAttribute('score'));
        $this->assertEquals(48, $d2->getAttribute('count'));    // over the max, left as 48
        $this->assertEquals(15.0, $d2->getAttribute('score'));  // still saved, even though d2.count was left unchanged

        $database->deleteCollection($collectionId);
    }

    /**
     * The max value itself is allowed. Reaching exactly the max is saved; only going ABOVE
     * the max leaves the value unchanged.
     */
    public function testOperatorBoundIsInclusive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'operator_bound_inclusive';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'counter', Database::VAR_INTEGER, 0, false, 0);

        $database->createDocument($collectionId, new Document([
            '$id' => 'doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'counter' => 48,
        ]));

        // 48 + 1 = 49, below the max of 50, so 49 is saved
        $updated = $database->updateDocument($collectionId, 'doc', new Document([
            'counter' => Operator::increment(1, 50),
        ]));
        $this->assertEquals(49, $updated->getAttribute('counter'));

        // Reset. 48 + 2 = 50, exactly the max, so 50 is saved
        $database->updateDocument($collectionId, 'doc', new Document(['counter' => 48]));
        $updated = $database->updateDocument($collectionId, 'doc', new Document([
            'counter' => Operator::increment(2, 50),
        ]));
        $this->assertEquals(50, $updated->getAttribute('counter'));

        // Reset. 48 + 3 = 51, above the max of 50, so the value is left as 48
        $database->updateDocument($collectionId, 'doc', new Document(['counter' => 48]));
        $updated = $database->updateDocument($collectionId, 'doc', new Document([
            'counter' => Operator::increment(3, 50),
        ]));
        $this->assertEquals(48, $updated->getAttribute('counter'));

        $database->deleteCollection($collectionId);
    }

    /**
     * A square root (power 0.5) of a negative number, and zero raised to a negative power, are
     * not real numbers. With a max set, the operator must not crash the update — it leaves the
     * value unchanged. (Some databases throw a hard error if asked to compute these directly.)
     */
    public function testOperatorPowerOnZeroOrNegativeBase(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'operator_power_edge';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, false, 0.0);

        // The square root of a negative number is not a real number, so -4 is left as -4.
        $database->createDocument($collectionId, new Document([
            '$id' => 'neg',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => -4.0,
        ]));
        $updated = $database->updateDocument($collectionId, 'neg', new Document([
            'value' => Operator::power(0.5, 100),
        ]));
        $this->assertEquals(-4.0, $updated->getAttribute('value'));

        // 0 raised to a negative power is undefined, so 0 is left as 0.
        $database->createDocument($collectionId, new Document([
            '$id' => 'zero',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 0.0,
        ]));
        $updated = $database->updateDocument($collectionId, 'zero', new Document([
            'value' => Operator::power(-1, 100),
        ]));
        $this->assertEquals(0.0, $updated->getAttribute('value'));

        $database->deleteCollection($collectionId);
    }

    /**
     * A power with no maximum is computed directly by the engine on the live row value, so each
     * adapter behaves as its engine does when the result is not a real number (zero raised to a
     * negative power, or a negative base with a fractional exponent):
     *  - Most engines (MariaDB/MySQL/Postgres) and the in-memory adapters (Memory/Redis) reject it
     *    with a LimitException and leave the stored value untouched.
     *  - MongoDB rejects 0-to-a-negative-power the same way, but has no error for a negative base
     *    with a fractional exponent, so it stores NaN.
     *  - SQLite never raises on undefined math; it stores NULL (or leaves the value as-is).
     *
     * The one behaviour that must never happen on any adapter is silently storing a plausible but
     * wrong real number, so the assertions verify the stored value via a fresh read.
     */
    public function testOperatorUnboundedPowerOnUndefinedBase(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'operator_power_undefined';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, false, 0.0);

        // [id, starting value, operator]. Each result is mathematically undefined.
        $undefined = [
            ['zero', 0.0, Operator::power(-1)],  // 0 to a negative power
            ['neg', -4.0, Operator::power(0.5)], // square root of a negative number
        ];

        foreach ($undefined as [$id, $start, $operator]) {
            $database->createDocument($collectionId, new Document([
                '$id' => $id,
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'value' => $start,
            ]));

            $caught = false;
            try {
                $database->updateDocument($collectionId, $id, new Document(['value' => $operator]));
            } catch (\Throwable $e) {
                $caught = true;

                // Whatever the engine raised must surface as a LimitException — not a raw
                // PDO/Mongo/Json error.
                $this->assertInstanceOf(LimitException::class, $e);

                // The row must be left exactly as it was — the failed update is rolled back, no
                // partial write. Verify with a fresh read, not the returned document.
                $stored = $database->getDocument($collectionId, $id)->getAttribute('value');
                $this->assertEquals($start, $stored, "{$id}: value changed even though the update raised a LimitException");
            }

            if ($caught === false) {
                // Engines that never raise on undefined math (SQLite, and MongoDB for a negative
                // base) must still not store a wrong real number: the value is either untouched or
                // an explicit "not a number" marker (NULL / NaN). Verify with a fresh read.
                $stored = $database->getDocument($collectionId, $id)->getAttribute('value');
                $safe = $stored === null || $stored == $start || !\is_finite((float) $stored);
                $this->assertTrue($safe, "{$id}: undefined power neither raised a LimitException nor left a safe value; stored " . \var_export($stored, true));
            }
        }

        // A valid unbounded power still computes normally on every adapter: 2^3 = 8.
        $database->createDocument($collectionId, new Document([
            '$id' => 'valid',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 2.0,
        ]));
        $database->updateDocument($collectionId, 'valid', new Document(['value' => Operator::power(3)]));
        $this->assertEquals(8.0, $database->getDocument($collectionId, 'valid')->getAttribute('value'));

        $database->deleteCollection($collectionId);
    }

    /**
     * A bounded power (with a max) must still compute the result whenever it fits within the max —
     * it only leaves the value unchanged when the result would exceed the max, or when the input is
     * mathematically undefined. A base of 1 or less is NOT a reason to skip: 0.5^2 = 0.25 and
     * (-4)^2 = 16 are perfectly valid and within their bounds. Verified via a fresh read.
     */
    public function testOperatorBoundedPowerComputesWithinMax(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'operator_bounded_power';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, false, 0.0);

        // [id, starting value, operator, expected stored value].
        $cases = [
            ['fraction', 0.5, Operator::power(2, 1), 0.25],      // 0.5^2 = 0.25, within max 1 → applied
            ['negeven', -4.0, Operator::power(2, 20), 16.0],     // (-4)^2 = 16, within max 20 → applied
            ['negodd', -2.0, Operator::power(3, 100), -8.0],     // (-2)^3 = -8, within max 100 → applied
            ['negoddbig', -5.0, Operator::power(3, 100), -125.0], // (-5)^3 = -125, negative so within max 100 → applied
            ['within', 2.0, Operator::power(3, 100), 8.0],       // 2^3 = 8, within max 100 → applied
            ['exceeds', 5.0, Operator::power(3, 100), 5.0],      // 5^3 = 125 > 100 → left unchanged
            ['negexceeds', -10.0, Operator::power(100, 100), -10.0], // (-10)^100 far exceeds max → left unchanged (no overflow error)
            ['negfrac', -4.0, Operator::power(0.5, 100), -4.0],  // sqrt(-4) undefined → left unchanged
            ['zeroneg', 0.0, Operator::power(-1, 100), 0.0],     // 0^-1 undefined → left unchanged
            ['zeroexp', 0.0, Operator::power(0, 0.5), 0.0],      // 0^0 = 1 > max 0.5 → left unchanged (not 1)
            ['zeroexpok', 0.0, Operator::power(0, 5), 1.0],      // 0^0 = 1, within max 5 → applied
        ];

        foreach ($cases as [$id, $start, $operator, $expected]) {
            $database->createDocument($collectionId, new Document([
                '$id' => $id,
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'value' => $start,
            ]));
            $database->updateDocument($collectionId, $id, new Document(['value' => $operator]));

            $stored = $database->getDocument($collectionId, $id)->getAttribute('value');
            $this->assertEqualsWithDelta($expected, $stored, 0.000001, "bounded power case '{$id}' stored the wrong value");
        }

        $database->deleteCollection($collectionId);
    }

    /**
     * Passing more values than the allowed maximum (10000) to an array operator must be rejected
     * the same way on every adapter. Covers each operator that takes a caller-supplied value list.
     */
    public function testOperatorArraySizeLimit(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'operator_array_size_limit';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);

        $database->createDocument($collectionId, new Document([
            '$id' => 'doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'tags' => ['a'],
        ]));

        $tooMany = array_fill(0, Operator::MAX_ARRAY_OPERATOR_SIZE + 1, 'x'); // one over the limit

        $operators = [
            'arrayAppend' => Operator::arrayAppend($tooMany),
            'arrayPrepend' => Operator::arrayPrepend($tooMany),
            'arrayIntersect' => Operator::arrayIntersect($tooMany),
            'arrayDiff' => Operator::arrayDiff($tooMany),
            // arrayRemove wraps its argument, so the oversized list lands in values[0].
            'arrayRemove' => Operator::arrayRemove($tooMany),
            // A wrapped payload (the list nested in values[0]) must be capped too, not just the
            // spread form — otherwise count($values) would see 1 and slip past the limit.
            'arrayAppend (wrapped)' => Operator::arrayAppend([$tooMany]),
        ];

        foreach ($operators as $name => $operator) {
            try {
                $database->updateDocument($collectionId, 'doc', new Document(['tags' => $operator]));
                $this->fail("Expected an exception for {$name} exceeding the array operator size limit");
            } catch (StructureException $e) {
                $this->assertStringContainsString('exceeds maximum allowed size', $e->getMessage());
            }
        }

        $database->deleteCollection($collectionId);
    }

    /**
     * An invalid array filter condition is rejected the same way on every adapter, because the
     * operator validator checks it before any adapter runs.
     */
    public function testOperatorArrayFilterRejectsUnknownCondition(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'operator_filter_unknown_cond';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);

        $database->createDocument($collectionId, new Document([
            '$id' => 'doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'tags' => ['a', 'b', 'c'],
        ]));

        try {
            $database->updateDocument($collectionId, 'doc', new Document([
                'tags' => Operator::arrayFilter('bogusCondition', 'x'),
            ]));
            $this->fail('Expected an exception for an invalid array filter condition');
        } catch (StructureException $e) {
            $this->assertStringContainsString('filter condition', $e->getMessage());
        }

        $database->deleteCollection($collectionId);
    }

    /**
     * Every filter condition the validator accepts must actually work the same on all adapters.
     * Each condition is applied to [1,2,3,4,5] and must keep exactly the matching elements —
     * an adapter that doesn't handle a condition would keep the whole array instead.
     */
    public function testOperatorArrayFilterAllConditions(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'operator_filter_all_conditions';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'numbers', Database::VAR_INTEGER, 0, false, null, true, true);
        $database->createDocument($collectionId, new Document([
            '$id' => 'doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'numbers' => [1, 2, 3, 4, 5],
        ]));

        $cases = [
            ['equal', 3, [3]],
            ['notEqual', 3, [1, 2, 4, 5]],
            ['greaterThan', 3, [4, 5]],
            ['greaterThanEqual', 3, [3, 4, 5]],
            ['lessThan', 3, [1, 2]],
            ['lessThanEqual', 3, [1, 2, 3]],
            ['isNotNull', null, [1, 2, 3, 4, 5]],
            ['isNull', null, []],
        ];

        foreach ($cases as [$condition, $compare, $expected]) {
            $database->updateDocument($collectionId, 'doc', new Document(['numbers' => [1, 2, 3, 4, 5]])); // reset
            $updated = $database->updateDocument($collectionId, 'doc', new Document([
                'numbers' => Operator::arrayFilter($condition, $compare),
            ]));
            $this->assertEquals($expected, \array_values($updated->getAttribute('numbers')), "arrayFilter('{$condition}') gave the wrong result");
        }

        $database->deleteCollection($collectionId);
    }

    public function testOperatorStringConcatComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_concat_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'text', Database::VAR_STRING, 255, false);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text' => 'Hello'
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'text' => Operator::stringConcat(' World')
        ]));

        $this->assertEquals('Hello World', $updated->getAttribute('text'));

        // Edge case: null value
        $doc2 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text' => null
        ]));
        $updated = $database->updateDocument($collectionId, $doc2->getId(), new Document([
            'text' => Operator::stringConcat('Test')
        ]));
        $this->assertEquals('Test', $updated->getAttribute('text'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorReplaceComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_replace_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'text', Database::VAR_STRING, 255, false);

        // Success case - single replacement
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text' => 'Hello World'
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'text' => Operator::stringReplace('World', 'Universe')
        ]));

        $this->assertEquals('Hello Universe', $updated->getAttribute('text'));

        // Success case - multiple occurrences
        $doc2 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text' => 'test test test'
        ]));

        $updated = $database->updateDocument($collectionId, $doc2->getId(), new Document([
            'text' => Operator::stringReplace('test', 'demo')
        ]));

        $this->assertEquals('demo demo demo', $updated->getAttribute('text'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayAppendComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_append_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'tags' => ['initial']
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'tags' => Operator::arrayAppend(['new', 'items'])
        ]));

        $this->assertEquals(['initial', 'new', 'items'], $updated->getAttribute('tags'));

        // Edge case: empty array
        $doc2 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'tags' => []
        ]));
        $updated = $database->updateDocument($collectionId, $doc2->getId(), new Document([
            'tags' => Operator::arrayAppend(['first'])
        ]));
        $this->assertEquals(['first'], $updated->getAttribute('tags'));

        // Edge case: null array
        $doc3 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'tags' => null
        ]));
        $updated = $database->updateDocument($collectionId, $doc3->getId(), new Document([
            'tags' => Operator::arrayAppend(['test'])
        ]));
        $this->assertEquals(['test'], $updated->getAttribute('tags'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayPrependComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_prepend_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['existing']
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayPrepend(['first', 'second'])
        ]));

        $this->assertEquals(['first', 'second', 'existing'], $updated->getAttribute('items'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayInsertComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_insert_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'numbers', Database::VAR_INTEGER, 0, false, null, true, true);

        // Success case - middle insertion
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'numbers' => [1, 2, 4]
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => Operator::arrayInsert(2, 3)
        ]));

        $this->assertEquals([1, 2, 3, 4], $updated->getAttribute('numbers'));

        // Success case - beginning insertion
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => Operator::arrayInsert(0, 0)
        ]));

        $this->assertEquals([0, 1, 2, 3, 4], $updated->getAttribute('numbers'));

        // Success case - end insertion
        $numbers = $updated->getAttribute('numbers');
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => Operator::arrayInsert(count($numbers), 5)
        ]));

        $this->assertEquals([0, 1, 2, 3, 4, 5], $updated->getAttribute('numbers'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayRemoveComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_remove_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        // Success case - single occurrence
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['a', 'b', 'c']
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayRemove('b')
        ]));

        $this->assertEquals(['a', 'c'], $updated->getAttribute('items'));

        // Success case - multiple occurrences
        $doc2 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['x', 'y', 'x', 'z', 'x']
        ]));

        $updated = $database->updateDocument($collectionId, $doc2->getId(), new Document([
            'items' => Operator::arrayRemove('x')
        ]));

        $this->assertEquals(['y', 'z'], $updated->getAttribute('items'));

        // Success case - non-existent value
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayRemove('nonexistent')
        ]));

        $this->assertEquals(['a', 'c'], $updated->getAttribute('items')); // Should remain unchanged

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayUniqueComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_unique_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        // Success case - with duplicates
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['a', 'b', 'a', 'c', 'b', 'a']
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayUnique()
        ]));

        $result = $updated->getAttribute('items');
        sort($result); // Sort for consistent comparison
        $this->assertEquals(['a', 'b', 'c'], $result);

        // Success case - no duplicates
        $doc2 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['x', 'y', 'z']
        ]));

        $updated = $database->updateDocument($collectionId, $doc2->getId(), new Document([
            'items' => Operator::arrayUnique()
        ]));

        $this->assertEquals(['x', 'y', 'z'], $updated->getAttribute('items'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayIntersectComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_intersect_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['a', 'b', 'c', 'd']
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayIntersect(['b', 'c', 'e'])
        ]));

        $result = $updated->getAttribute('items');
        sort($result);
        $this->assertEquals(['b', 'c'], $result);

        // Success case - no intersection
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayIntersect(['x', 'y', 'z'])
        ]));

        $this->assertEquals([], $updated->getAttribute('items'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayDiffComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_diff_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['a', 'b', 'c', 'd']
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayDiff(['b', 'd'])
        ]));

        $result = $updated->getAttribute('items');
        sort($result);
        $this->assertEquals(['a', 'c'], $result);

        // Success case - empty diff array
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayDiff([])
        ]));

        $result = $updated->getAttribute('items');
        sort($result);
        $this->assertEquals(['a', 'c'], $result); // Should remain unchanged

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayFilterComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_filter_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'numbers', Database::VAR_INTEGER, 0, false, null, true, true);
        $database->createAttribute($collectionId, 'mixed', Database::VAR_STRING, 50, false, null, true, true);

        // Success case - equals condition
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'numbers' => [1, 2, 3, 2, 4],
            'mixed' => ['a', 'b', null, 'c', null]
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => Operator::arrayFilter('equal', 2)
        ]));

        $this->assertEquals([2, 2], $updated->getAttribute('numbers'));

        // Success case - isNotNull condition
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'mixed' => Operator::arrayFilter('isNotNull')
        ]));

        $this->assertEquals(['a', 'b', 'c'], $updated->getAttribute('mixed'));

        // Success case - greaterThan condition (reset array first)
        $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => [1, 2, 3, 2, 4]
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => Operator::arrayFilter('greaterThan', 2)
        ]));

        $this->assertEquals([3, 4], $updated->getAttribute('numbers'));

        // Success case - lessThan condition (reset array first)
        $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => [1, 2, 3, 2, 4]
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => Operator::arrayFilter('lessThan', 3)
        ]));

        $this->assertEquals([1, 2, 2], $updated->getAttribute('numbers'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorArrayFilterNumericComparisons(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_filter_numeric_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'integers', Database::VAR_INTEGER, 0, false, null, true, true);
        $database->createAttribute($collectionId, 'floats', Database::VAR_FLOAT, 0, false, null, true, true);

        // Create document with various numeric values
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'integers' => [1, 5, 10, 15, 20, 25],
            'floats' => [1.5, 5.5, 10.5, 15.5, 20.5, 25.5]
        ]));

        // Test greaterThan with integers
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'integers' => Operator::arrayFilter('greaterThan', 10)
        ]));
        $this->assertEquals([15, 20, 25], $updated->getAttribute('integers'));

        // Reset and test lessThan with integers
        $database->updateDocument($collectionId, $doc->getId(), new Document([
            'integers' => [1, 5, 10, 15, 20, 25]
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'integers' => Operator::arrayFilter('lessThan', 15)
        ]));
        $this->assertEquals([1, 5, 10], $updated->getAttribute('integers'));

        // Test greaterThan with floats
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'floats' => Operator::arrayFilter('greaterThan', 10.5)
        ]));
        $this->assertEquals([15.5, 20.5, 25.5], $updated->getAttribute('floats'));

        // Reset and test lessThan with floats
        $database->updateDocument($collectionId, $doc->getId(), new Document([
            'floats' => [1.5, 5.5, 10.5, 15.5, 20.5, 25.5]
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'floats' => Operator::arrayFilter('lessThan', 15.5)
        ]));
        $this->assertEquals([1.5, 5.5, 10.5], $updated->getAttribute('floats'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorToggleComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_toggle_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'active', Database::VAR_BOOLEAN, 0, false);

        // Success case - true to false
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'active' => true
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'active' => Operator::toggle()
        ]));

        $this->assertEquals(false, $updated->getAttribute('active'));

        // Success case - false to true
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'active' => Operator::toggle()
        ]));

        $this->assertEquals(true, $updated->getAttribute('active'));

        // Success case - null to true
        $doc2 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'active' => null
        ]));

        $updated = $database->updateDocument($collectionId, $doc2->getId(), new Document([
            'active' => Operator::toggle()
        ]));

        $this->assertEquals(true, $updated->getAttribute('active'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorDateAddDaysComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_date_add_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'date', Database::VAR_DATETIME, 0, false, null, true, false, null, [], ['datetime']);

        // Success case - positive days
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'date' => '2023-01-01 00:00:00'
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'date' => Operator::dateAddDays(5)
        ]));

        $this->assertEquals('2023-01-06T00:00:00.000+00:00', $updated->getAttribute('date'));

        // Success case - negative days (subtracting)
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'date' => Operator::dateAddDays(-3)
        ]));

        $this->assertEquals('2023-01-03T00:00:00.000+00:00', $updated->getAttribute('date'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorDateSubDaysComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_date_sub_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'date', Database::VAR_DATETIME, 0, false, null, true, false, null, [], ['datetime']);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'date' => '2023-01-10 00:00:00'
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'date' => Operator::dateSubDays(3)
        ]));

        $this->assertEquals('2023-01-07T00:00:00.000+00:00', $updated->getAttribute('date'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorDateSetNowComprehensive(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'operator_date_now_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'timestamp', Database::VAR_DATETIME, 0, false, null, true, false, null, [], ['datetime']);

        // Success case
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'timestamp' => '2020-01-01 00:00:00'
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'timestamp' => Operator::dateSetNow()
        ]));

        $result = $updated->getAttribute('timestamp');
        $this->assertNotEmpty($result);

        // Verify it's a recent timestamp (within last minute)
        $now = new \DateTime();
        $resultDate = new \DateTime($result);
        $diff = $now->getTimestamp() - $resultDate->getTimestamp();
        $this->assertLessThan(60, $diff); // Should be within 60 seconds

        $database->deleteCollection($collectionId);
    }


    public function testMixedOperators(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'mixed_operators_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false);
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'name', Database::VAR_STRING, 255, false);
        $database->createAttribute($collectionId, 'active', Database::VAR_BOOLEAN, 0, false);

        // Test multiple operators in one update
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => 5,
            'score' => 10.0,
            'tags' => ['initial'],
            'name' => 'Test',
            'active' => false
        ]));

        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'count' => Operator::increment(3),
            'score' => Operator::multiply(1.5),
            'tags' => Operator::arrayAppend(['new', 'item']),
            'name' => Operator::stringConcat(' Document'),
            'active' => Operator::toggle()
        ]));

        $this->assertEquals(8, $updated->getAttribute('count'));
        $this->assertEquals(15.0, $updated->getAttribute('score'));
        $this->assertEquals(['initial', 'new', 'item'], $updated->getAttribute('tags'));
        $this->assertEquals('Test Document', $updated->getAttribute('name'));
        $this->assertEquals(true, $updated->getAttribute('active'));

        $database->deleteCollection($collectionId);
    }

    public function testOperatorsBatch(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'batch_operators_test';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false);
        $database->createAttribute($collectionId, 'category', Database::VAR_STRING, 50, false);

        // Create multiple documents
        $docs = [];
        for ($i = 1; $i <= 3; $i++) {
            $docs[] = $database->createDocument($collectionId, new Document([
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'count' => $i * 5,
                'category' => 'test'
            ]));
        }

        // Test updateDocuments with operators
        $updateCount = $database->updateDocuments($collectionId, new Document([
            'count' => Operator::increment(10)
        ]), [
            Query::equal('category', ['test'])
        ]);

        $this->assertEquals(3, $updateCount);

        // Fetch the updated documents to verify the operator worked
        $updated = $database->find($collectionId, [
            Query::equal('category', ['test']),
            Query::orderAsc('count')
        ]);
        $this->assertCount(3, $updated);
        $this->assertEquals(15, $updated[0]->getAttribute('count')); // 5 + 10
        $this->assertEquals(20, $updated[1]->getAttribute('count')); // 10 + 10
        $this->assertEquals(25, $updated[2]->getAttribute('count')); // 15 + 10

        $database->deleteCollection($collectionId);
    }

    /**
     * Test ARRAY_INSERT at beginning of array
     *
     * This test verifies that inserting at index 0 actually adds the element
     */
    public function testArrayInsertAtBeginning(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'test_array_insert_beginning';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['second', 'third', 'fourth']
        ]));

        $this->assertEquals(['second', 'third', 'fourth'], $doc->getAttribute('items'));

        // Attempt to insert at index 0
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayInsert(0, 'first')
        ]));

        // Refetch to get the actual database value
        $refetched = $database->getDocument($collectionId, $doc->getId());

        // Should insert 'first' at index 0, shifting existing elements
        $this->assertEquals(
            ['first', 'second', 'third', 'fourth'],
            $refetched->getAttribute('items'),
            'ARRAY_INSERT should insert element at index 0'
        );

        $database->deleteCollection($collectionId);
    }

    /**
     * Test ARRAY_INSERT at middle of array
     *
     * This test verifies that inserting at index 2 in a 5-element array works
     */
    public function testArrayInsertAtMiddle(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'test_array_insert_middle';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_INTEGER, 0, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => [1, 2, 4, 5, 6]
        ]));

        $this->assertEquals([1, 2, 4, 5, 6], $doc->getAttribute('items'));

        // Attempt to insert at index 2 (middle position)
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayInsert(2, 3)
        ]));

        // Refetch to get the actual database value
        $refetched = $database->getDocument($collectionId, $doc->getId());

        // Should insert 3 at index 2, shifting remaining elements
        $this->assertEquals(
            [1, 2, 3, 4, 5, 6],
            $refetched->getAttribute('items'),
            'ARRAY_INSERT should insert element at index 2'
        );

        $database->deleteCollection($collectionId);
    }

    /**
     * Test ARRAY_INSERT at end of array
     *
     * This test verifies that inserting at the last index (end of array) works
     */
    public function testArrayInsertAtEnd(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'test_array_insert_end';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['apple', 'banana', 'cherry']
        ]));

        $this->assertEquals(['apple', 'banana', 'cherry'], $doc->getAttribute('items'));

        // Attempt to insert at end (index = length)
        $items = $doc->getAttribute('items');
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'items' => Operator::arrayInsert(count($items), 'date')
        ]));

        // Refetch to get the actual database value
        $refetched = $database->getDocument($collectionId, $doc->getId());

        // Should insert 'date' at end of array
        $this->assertEquals(
            ['apple', 'banana', 'cherry', 'date'],
            $refetched->getAttribute('items'),
            'ARRAY_INSERT should insert element at end of array'
        );

        $database->deleteCollection($collectionId);
    }

    /**
     * Test ARRAY_INSERT with multiple operations
     *
     * This test verifies that multiple sequential insert operations work correctly
     */
    public function testArrayInsertMultipleOperations(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'test_array_insert_multiple';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'numbers', Database::VAR_INTEGER, 0, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'numbers' => [1, 3, 5]
        ]));

        $this->assertEquals([1, 3, 5], $doc->getAttribute('numbers'));

        // First insert: add 2 at index 1
        $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => Operator::arrayInsert(1, 2)
        ]));

        // Refetch to get the actual database value
        $refetched = $database->getDocument($collectionId, $doc->getId());

        // Should insert 2 at index 1
        $this->assertEquals(
            [1, 2, 3, 5],
            $refetched->getAttribute('numbers'),
            'First ARRAY_INSERT should work'
        );

        // Second insert: add 4 at index 3
        $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => Operator::arrayInsert(3, 4)
        ]));

        // Refetch to get the actual database value
        $refetched = $database->getDocument($collectionId, $doc->getId());

        // Should insert 4 at index 3
        $this->assertEquals(
            [1, 2, 3, 4, 5],
            $refetched->getAttribute('numbers'),
            'Second ARRAY_INSERT should work'
        );

        // Third insert: add 0 at beginning
        $database->updateDocument($collectionId, $doc->getId(), new Document([
            'numbers' => Operator::arrayInsert(0, 0)
        ]));

        // Refetch to get the actual database value
        $refetched = $database->getDocument($collectionId, $doc->getId());

        // Should insert 0 at index 0
        $this->assertEquals(
            [0, 1, 2, 3, 4, 5],
            $refetched->getAttribute('numbers'),
            'Third ARRAY_INSERT should work'
        );

        $database->deleteCollection($collectionId);
    }

    /**
     * Bug #6: Post-Operator Validation Missing
     * Test that INCREMENT operator can exceed maximum value constraint
     *
     * The database validates document structure BEFORE operators are applied (line 4912 in Database.php),
     * but not AFTER. This test creates a document with an integer field that has a max constraint,
     * then uses INCREMENT to push the value beyond that maximum. The operation should fail with a
     * validation error, but currently succeeds because post-operator validation is missing.
     */
    public function testOperatorIncrementExceedsMaxValue(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_increment_max_violation';
        $database->createCollection($collectionId);

        // Create an integer attribute with a maximum value of 100
        // Using size=4 (signed int) with max constraint through Range validator
        $database->createAttribute($collectionId, 'score', Database::VAR_INTEGER, 4, false, 0, false, false);

        // Get the collection to verify attribute was created
        $collection = $database->getCollection($collectionId);
        $attributes = $collection->getAttribute('attributes', []);
        $scoreAttr = null;
        foreach ($attributes as $attr) {
            if ($attr['$id'] === 'score') {
                $scoreAttr = $attr;
                break;
            }
        }

        // Create a document with score at 95 (within valid range)
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'score' => 95
        ]));

        $this->assertEquals(95, $doc->getAttribute('score'));

        // Test case 1: Small increment that stays within MAX_INT should work
        $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
            'score' => Operator::increment(5)
        ]));
        // Refetch to get the actual computed value
        $updated = $database->getDocument($collectionId, $doc->getId());
        $this->assertEquals(100, $updated->getAttribute('score'));

        // Test case 2: Increment that would exceed Database::MAX_INT (2147483647)
        // This is the bug - the operator will create a value > MAX_INT which should be rejected
        // but post-operator validation is missing
        $doc2 = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'score' => Database::MAX_INT - 10 // Start near the maximum
        ]));

        $this->assertEquals(Database::MAX_INT - 10, $doc2->getAttribute('score'));

        // BUG EXPOSED: This increment will push the value beyond Database::MAX_INT
        // It should throw a StructureException for exceeding the integer range,
        // but currently succeeds because validation happens before operator application
        try {
            $updated = $database->updateDocument($collectionId, $doc2->getId(), new Document([
                'score' => Operator::increment(20) // Will result in MAX_INT + 10
            ]));

            // Refetch to get the actual computed value from the database
            $refetched = $database->getDocument($collectionId, $doc2->getId());
            $finalScore = $refetched->getAttribute('score');

            // Document the bug: The value should not exceed MAX_INT
            $this->assertLessThanOrEqual(
                Database::MAX_INT,
                $finalScore,
                "BUG EXPOSED: INCREMENT pushed score to {$finalScore}, exceeding MAX_INT (" . Database::MAX_INT . "). Post-operator validation is missing!"
            );
        } catch (StructureException $e) {
            // This is the CORRECT behavior - validation should catch the constraint violation
            $this->assertStringContainsString('overflow maximum value', $e->getMessage());
        }

        $database->deleteCollection($collectionId);
    }

    /**
     * Bug #6: Post-Operator Validation Missing
     * Test that CONCAT operator can exceed maximum string length
     *
     * This test creates a string attribute with a maximum length constraint,
     * then uses CONCAT to make the string longer than allowed. The operation should fail,
     * but currently succeeds because validation only happens before operators are applied.
     */
    public function testOperatorConcatExceedsMaxLength(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_concat_length_violation';
        $database->createCollection($collectionId);

        // Create a string attribute with max length of 20 characters
        $database->createAttribute($collectionId, 'title', Database::VAR_STRING, 20, false, '');

        // Create a document with a 15-character title (within limit)
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'title' => 'Hello World'  // 11 characters
        ]));

        $this->assertEquals('Hello World', $doc->getAttribute('title'));
        $this->assertEquals(11, strlen($doc->getAttribute('title')));

        // BUG EXPOSED: Concat a 15-character string to make total length 26 (exceeds max of 20)
        // This should throw a StructureException for exceeding max length,
        // but currently succeeds because validation only checks the input, not the result
        try {
            $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
                'title' => Operator::stringConcat(' - Extended Title')  // Adding 18 chars = 29 total
            ]));

            // Refetch to get the actual computed value from the database
            $refetched = $database->getDocument($collectionId, $doc->getId());
            $finalTitle = $refetched->getAttribute('title');
            $finalLength = strlen($finalTitle);

            // Document the bug: The resulting string should not exceed 20 characters
            $this->assertLessThanOrEqual(
                20,
                $finalLength,
                "BUG EXPOSED: CONCAT created string of length {$finalLength} ('{$finalTitle}'), exceeding max length of 20. Post-operator validation is missing!"
            );
        } catch (StructureException $e) {
            // This is the CORRECT behavior - validation should catch the length violation
            $this->assertStringContainsString('exceed maximum length', $e->getMessage());
        }

        $database->deleteCollection($collectionId);
    }

    /**
     * Bug #6: Post-Operator Validation Missing
     * Test that MULTIPLY operator can create values outside allowed range
     *
     * This test shows that multiplying a float can exceed the maximum allowed value
     * for the field type, bypassing schema constraints.
     */
    public function testOperatorMultiplyViolatesRange(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_multiply_range_violation';
        $database->createCollection($collectionId);

        // Create a signed integer attribute (max value = Database::MAX_INT = 2147483647)
        $database->createAttribute($collectionId, 'quantity', Database::VAR_INTEGER, 4, false, 1, false, false);

        // Create a document with quantity that when multiplied will exceed MAX_INT
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'quantity' => 1000000000  // 1 billion
        ]));

        $this->assertEquals(1000000000, $doc->getAttribute('quantity'));

        // BUG EXPOSED: Multiply by 10 to get 10 billion, which exceeds MAX_INT (2.147 billion)
        // This should throw a StructureException for exceeding the integer range,
        // but currently may succeed or cause overflow because validation is missing
        try {
            $updated = $database->updateDocument($collectionId, $doc->getId(), new Document([
                'quantity' => Operator::multiply(10)  // 1,000,000,000 * 10 = 10,000,000,000 > MAX_INT
            ]));

            // Refetch to get the actual computed value from the database
            $refetched = $database->getDocument($collectionId, $doc->getId());
            $finalQuantity = $refetched->getAttribute('quantity');

            // Document the bug: The value should not exceed MAX_INT
            $this->assertLessThanOrEqual(
                Database::MAX_INT,
                $finalQuantity,
                "BUG EXPOSED: MULTIPLY created value {$finalQuantity}, exceeding MAX_INT (" . Database::MAX_INT . "). Post-operator validation is missing!"
            );

            // Also verify the value didn't overflow into negative (integer overflow behavior)
            $this->assertGreaterThan(
                0,
                $finalQuantity,
                "BUG EXPOSED: MULTIPLY caused integer overflow to {$finalQuantity}. Post-operator validation should prevent this!"
            );
        } catch (StructureException $e) {
            // This is the CORRECT behavior - validation should catch the range violation
            $this->assertStringContainsString('overflow maximum value', $e->getMessage());
        }

        $database->deleteCollection($collectionId);
    }

    /**
     * Test MULTIPLY operator with negative multipliers and max limit
     * Tests: Negative multipliers should not trigger incorrect overflow checks
     */
    public function testOperatorMultiplyWithNegativeMultiplier(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_multiply_negative';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, false);

        // Test negative multiplier without max limit
        $doc1 = $database->createDocument($collectionId, new Document([
            '$id' => 'negative_multiply',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 10.0
        ]));

        $updated1 = $database->updateDocument($collectionId, 'negative_multiply', new Document([
            'value' => Operator::multiply(-2)
        ]));
        $this->assertEquals(-20.0, $updated1->getAttribute('value'), 'Multiply by negative should work correctly');

        // Test negative multiplier WITH max limit - should not incorrectly cap
        $doc2 = $database->createDocument($collectionId, new Document([
            '$id' => 'negative_with_max',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 10.0
        ]));

        $updated2 = $database->updateDocument($collectionId, 'negative_with_max', new Document([
            'value' => Operator::multiply(-2, 100)  // max=100, but result will be -20
        ]));
        $this->assertEquals(-20.0, $updated2->getAttribute('value'), 'Negative multiplier with max should not trigger overflow check');

        // Test positive value * negative multiplier - result is negative, should not cap
        $doc3 = $database->createDocument($collectionId, new Document([
            '$id' => 'pos_times_neg',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 50.0
        ]));

        $updated3 = $database->updateDocument($collectionId, 'pos_times_neg', new Document([
            'value' => Operator::multiply(-3, 100)  // 50 * -3 = -150, should not be capped at 100
        ]));
        $this->assertEquals(-150.0, $updated3->getAttribute('value'), 'Positive * negative should compute correctly (result is negative, no cap)');

        // Test negative value * negative multiplier that SHOULD hit max cap
        $doc4 = $database->createDocument($collectionId, new Document([
            '$id' => 'negative_overflow',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => -60.0
        ]));

        $updated4 = $database->updateDocument($collectionId, 'negative_overflow', new Document([
            'value' => Operator::multiply(-3, 100)  // -60 * -3 = 180, would exceed max 100, so unchanged
        ]));
        $this->assertEquals(-60.0, $updated4->getAttribute('value'), 'Negative * negative should leave value unchanged when result would exceed max');

        // Test zero multiplier with max
        $doc5 = $database->createDocument($collectionId, new Document([
            '$id' => 'zero_multiply',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 50.0
        ]));

        $updated5 = $database->updateDocument($collectionId, 'zero_multiply', new Document([
            'value' => Operator::multiply(0, 100)
        ]));
        $this->assertEquals(0.0, $updated5->getAttribute('value'), 'Multiply by zero should result in zero');

        $database->deleteCollection($collectionId);
    }

    /**
     * Test DIVIDE operator with negative divisors and min limit
     * Tests: Negative divisors should not trigger incorrect underflow checks
     */
    public function testOperatorDivideWithNegativeDivisor(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_divide_negative';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, false);

        // Test negative divisor without min limit
        $doc1 = $database->createDocument($collectionId, new Document([
            '$id' => 'negative_divide',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 20.0
        ]));

        $updated1 = $database->updateDocument($collectionId, 'negative_divide', new Document([
            'value' => Operator::divide(-2)
        ]));
        $this->assertEquals(-10.0, $updated1->getAttribute('value'), 'Divide by negative should work correctly');

        // Test negative divisor WITH min limit - should not incorrectly cap
        $doc2 = $database->createDocument($collectionId, new Document([
            '$id' => 'negative_with_min',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 20.0
        ]));

        $updated2 = $database->updateDocument($collectionId, 'negative_with_min', new Document([
            'value' => Operator::divide(-2, -50)  // min=-50, result will be -10
        ]));
        $this->assertEquals(-10.0, $updated2->getAttribute('value'), 'Negative divisor with min should not trigger underflow check');

        // Test positive value / negative divisor - result is negative, should not cap at min
        $doc3 = $database->createDocument($collectionId, new Document([
            '$id' => 'pos_div_neg',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 100.0
        ]));

        $updated3 = $database->updateDocument($collectionId, 'pos_div_neg', new Document([
            'value' => Operator::divide(-4, -10)  // 100 / -4 = -25, which is below min -10, so unchanged
        ]));
        $this->assertEquals(100.0, $updated3->getAttribute('value'), 'Positive / negative should leave value unchanged when result would be below min');

        // Test negative value / negative divisor that would go below min
        $doc4 = $database->createDocument($collectionId, new Document([
            '$id' => 'negative_underflow',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 40.0
        ]));

        $updated4 = $database->updateDocument($collectionId, 'negative_underflow', new Document([
            'value' => Operator::divide(-2, -10)  // 40 / -2 = -20, which is below min -10, so unchanged
        ]));
        $this->assertEquals(40.0, $updated4->getAttribute('value'), 'Positive / negative should leave value unchanged when result would be below min');

        $database->deleteCollection($collectionId);
    }

    /**
     * Bug #6: Post-Operator Validation Missing
     * Test that ARRAY_APPEND can add items that violate array item constraints
     *
     * This test creates an integer array attribute and uses ARRAY_APPEND to add a string,
     * which should fail type validation but currently succeeds in some cases.
     */
    public function testOperatorArrayAppendViolatesItemConstraints(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_array_item_type_violation';
        $database->createCollection($collectionId);

        // Create an array attribute for integers with max value constraint
        // Each item should be an integer within the valid range
        $database->createAttribute($collectionId, 'numbers', Database::VAR_INTEGER, 4, false, null, true, true);

        // Create a document with valid integer array
        $doc = $database->createDocument($collectionId, new Document([
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'numbers' => [10, 20, 30]
        ]));

        $this->assertEquals([10, 20, 30], $doc->getAttribute('numbers'));

        // Test case 1: Append integers that exceed MAX_INT
        // BUG EXPOSED: These values exceed the constraint but validation is not applied post-operator
        try {
            // Create a fresh document for this test
            $doc2 = $database->createDocument($collectionId, new Document([
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'numbers' => [100, 200]
            ]));

            // Try to append values that would exceed MAX_INT
            $hugeValue = Database::MAX_INT + 1000;  // Exceeds integer maximum

            $updated = $database->updateDocument($collectionId, $doc2->getId(), new Document([
                'numbers' => Operator::arrayAppend([$hugeValue])
            ]));

            // Refetch to get the actual computed value from the database
            $refetched = $database->getDocument($collectionId, $doc2->getId());
            $finalNumbers = $refetched->getAttribute('numbers');
            $lastNumber = end($finalNumbers);

            // Document the bug: Array items should not exceed MAX_INT
            $this->assertLessThanOrEqual(
                Database::MAX_INT,
                $lastNumber,
                "BUG EXPOSED: ARRAY_APPEND added value {$lastNumber} exceeding MAX_INT (" . Database::MAX_INT . "). Post-operator validation is missing!"
            );
        } catch (StructureException $e) {
            // This is the CORRECT behavior - validation should catch the constraint violation
            $this->assertStringContainsString('array items must be between', $e->getMessage());
        } catch (TypeException $e) {
            // Also acceptable - type validation catches the issue
            $this->assertStringContainsString('Invalid', $e->getMessage());
        }

        // Test case 2: Append multiple items where at least one violates constraints
        try {
            $doc3 = $database->createDocument($collectionId, new Document([
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'numbers' => [1, 2, 3]
            ]));

            // Append a mix of valid and invalid values
            // The last value exceeds MAX_INT
            $mixedValues = [40, 50, Database::MAX_INT + 100];

            $updated = $database->updateDocument($collectionId, $doc3->getId(), new Document([
                'numbers' => Operator::arrayAppend($mixedValues)
            ]));

            // Refetch to get the actual computed value from the database
            $refetched = $database->getDocument($collectionId, $doc3->getId());
            $finalNumbers = $refetched->getAttribute('numbers');

            // Document the bug: ALL array items should be validated
            foreach ($finalNumbers as $num) {
                $this->assertLessThanOrEqual(
                    Database::MAX_INT,
                    $num,
                    "BUG EXPOSED: ARRAY_APPEND added invalid value {$num} exceeding MAX_INT (" . Database::MAX_INT . "). Post-operator validation is missing!"
                );
            }
        } catch (StructureException $e) {
            // This is the CORRECT behavior
            $this->assertTrue(
                str_contains($e->getMessage(), 'invalid type') ||
                str_contains($e->getMessage(), 'array items must be between'),
                'Expected constraint violation message, got: ' . $e->getMessage()
            );
        } catch (TypeException $e) {
            // Also acceptable
            $this->assertStringContainsString('Invalid', $e->getMessage());
        }

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 1: Test operators with MAXIMUM and MINIMUM integer values
     * Tests: Integer overflow/underflow prevention, boundary arithmetic
     */
    public function testOperatorWithExtremeIntegerValues(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_extreme_integers';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'bigint_max', Database::VAR_INTEGER, 8, true);
        $database->createAttribute($collectionId, 'bigint_min', Database::VAR_INTEGER, 8, true);

        $maxValue = PHP_INT_MAX - 1000; // Near max but with room
        $minValue = PHP_INT_MIN + 1000; // Near min but with room

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'extreme_int_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'bigint_max' => $maxValue,
            'bigint_min' => $minValue
        ]));

        // Test increment near max with limit
        $updated = $database->updateDocument($collectionId, 'extreme_int_doc', new Document([
            'bigint_max' => Operator::increment(2000, PHP_INT_MAX - 500)
        ]));
        // Unchanged — base + 2000 would exceed max
        $this->assertLessThanOrEqual(PHP_INT_MAX - 500, $updated->getAttribute('bigint_max'));
        $this->assertEquals($maxValue, $updated->getAttribute('bigint_max'));

        // Test decrement near min with limit
        $updated = $database->updateDocument($collectionId, 'extreme_int_doc', new Document([
            'bigint_min' => Operator::decrement(2000, PHP_INT_MIN + 500)
        ]));
        // Unchanged — base - 2000 would go below min
        $this->assertGreaterThanOrEqual(PHP_INT_MIN + 500, $updated->getAttribute('bigint_min'));
        $this->assertEquals($minValue, $updated->getAttribute('bigint_min'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 2: Test NEGATIVE exponents in power operator
     * Tests: Fractional results, precision handling
     */
    public function testOperatorPowerWithNegativeExponent(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_negative_power';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, true);

        // Create document with value 8
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'neg_power_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 8.0
        ]));

        // Test negative exponent: 8^(-2) = 1/64 = 0.015625
        $updated = $database->updateDocument($collectionId, 'neg_power_doc', new Document([
            'value' => Operator::power(-2)
        ]));

        $this->assertEqualsWithDelta(0.015625, $updated->getAttribute('value'), 0.000001);

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 3: Test FRACTIONAL exponents in power operator
     * Tests: Square roots, cube roots via fractional powers
     */
    public function testOperatorPowerWithFractionalExponent(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_fractional_power';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, true);

        // Create document with value 16
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'frac_power_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 16.0
        ]));

        // Test fractional exponent: 16^(0.5) = sqrt(16) = 4
        $updated = $database->updateDocument($collectionId, 'frac_power_doc', new Document([
            'value' => Operator::power(0.5)
        ]));

        $this->assertEqualsWithDelta(4.0, $updated->getAttribute('value'), 0.000001);

        // Test cube root: 27^(1/3) = 3
        $database->updateDocument($collectionId, 'frac_power_doc', new Document([
            'value' => 27.0
        ]));

        $updated = $database->updateDocument($collectionId, 'frac_power_doc', new Document([
            'value' => Operator::power(1 / 3)
        ]));

        $this->assertEqualsWithDelta(3.0, $updated->getAttribute('value'), 0.000001);

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 4: Test EMPTY STRING operations
     * Tests: Concatenation with empty strings, replacement edge cases
     */
    public function testOperatorWithEmptyStrings(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_empty_strings';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'text', Database::VAR_STRING, 255, false, '');

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'empty_str_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text' => ''
        ]));

        // Test concatenation to empty string
        $updated = $database->updateDocument($collectionId, 'empty_str_doc', new Document([
            'text' => Operator::stringConcat('hello')
        ]));
        $this->assertEquals('hello', $updated->getAttribute('text'));

        // Test concatenation of empty string
        $updated = $database->updateDocument($collectionId, 'empty_str_doc', new Document([
            'text' => Operator::stringConcat('')
        ]));
        $this->assertEquals('hello', $updated->getAttribute('text'));

        // Test replace with empty search string (should do nothing or replace all)
        $database->updateDocument($collectionId, 'empty_str_doc', new Document([
            'text' => 'test'
        ]));

        $updated = $database->updateDocument($collectionId, 'empty_str_doc', new Document([
            'text' => Operator::stringReplace('', 'X')
        ]));
        // Empty search should not change the string
        $this->assertEquals('test', $updated->getAttribute('text'));

        // Test replace with empty replace string (deletion)
        $updated = $database->updateDocument($collectionId, 'empty_str_doc', new Document([
            'text' => Operator::stringReplace('t', '')
        ]));
        $this->assertEquals('es', $updated->getAttribute('text'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 5: Test UNICODE edge cases in string operations
     * Tests: Multi-byte character handling, emoji operations
     */
    public function testOperatorWithUnicodeCharacters(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_unicode';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'text', Database::VAR_STRING, 500, false, '');

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'unicode_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text' => '你好'
        ]));

        // Test concatenation with emoji
        $updated = $database->updateDocument($collectionId, 'unicode_doc', new Document([
            'text' => Operator::stringConcat('👋🌍')
        ]));
        $this->assertEquals('你好👋🌍', $updated->getAttribute('text'));

        // Test replace with Chinese characters
        $updated = $database->updateDocument($collectionId, 'unicode_doc', new Document([
            'text' => Operator::stringReplace('你好', '再见')
        ]));
        $this->assertEquals('再见👋🌍', $updated->getAttribute('text'));

        // Test with combining characters (é = e + ´)
        $database->updateDocument($collectionId, 'unicode_doc', new Document([
            'text' => 'cafe\u{0301}' // café with combining acute accent
        ]));

        $updated = $database->updateDocument($collectionId, 'unicode_doc', new Document([
            'text' => Operator::stringConcat(' ☕')
        ]));
        $this->assertStringContainsString('☕', $updated->getAttribute('text'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 6: Test array operations on EMPTY ARRAYS
     * Tests: Behavior with zero-length arrays
     */
    public function testOperatorArrayOperationsOnEmptyArrays(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_empty_arrays';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'empty_array_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => []
        ]));

        // Test append to empty array
        $updated = $database->updateDocument($collectionId, 'empty_array_doc', new Document([
            'items' => Operator::arrayAppend(['first'])
        ]));
        $this->assertEquals(['first'], $updated->getAttribute('items'));

        // Reset and test prepend to empty array
        $database->updateDocument($collectionId, 'empty_array_doc', new Document([
            'items' => []
        ]));

        $updated = $database->updateDocument($collectionId, 'empty_array_doc', new Document([
            'items' => Operator::arrayPrepend(['prepended'])
        ]));
        $this->assertEquals(['prepended'], $updated->getAttribute('items'));

        // Test insert at index 0 of empty array
        $database->updateDocument($collectionId, 'empty_array_doc', new Document([
            'items' => []
        ]));

        $updated = $database->updateDocument($collectionId, 'empty_array_doc', new Document([
            'items' => Operator::arrayInsert(0, 'zero')
        ]));
        $this->assertEquals(['zero'], $updated->getAttribute('items'));

        // Test unique on empty array
        $database->updateDocument($collectionId, 'empty_array_doc', new Document([
            'items' => []
        ]));

        $updated = $database->updateDocument($collectionId, 'empty_array_doc', new Document([
            'items' => Operator::arrayUnique()
        ]));
        $this->assertEquals([], $updated->getAttribute('items'));

        // Test remove from empty array (should stay empty)
        $updated = $database->updateDocument($collectionId, 'empty_array_doc', new Document([
            'items' => Operator::arrayRemove('nonexistent')
        ]));
        $this->assertEquals([], $updated->getAttribute('items'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 7: Test array operations with NULL and special values
     * Tests: How operators handle null, empty strings, and mixed types in arrays
     */
    public function testOperatorArrayWithNullAndSpecialValues(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_array_special_values';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'mixed', Database::VAR_STRING, 50, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'special_values_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'mixed' => ['', 'text', '', 'text']
        ]));

        // Test unique with empty strings (should deduplicate)
        $updated = $database->updateDocument($collectionId, 'special_values_doc', new Document([
            'mixed' => Operator::arrayUnique()
        ]));
        $this->assertContains('', $updated->getAttribute('mixed'));
        $this->assertContains('text', $updated->getAttribute('mixed'));
        // Should have only 2 unique values: '' and 'text'
        $this->assertCount(2, $updated->getAttribute('mixed'));

        // Test remove empty string
        $database->updateDocument($collectionId, 'special_values_doc', new Document([
            'mixed' => ['', 'a', '', 'b']
        ]));

        $updated = $database->updateDocument($collectionId, 'special_values_doc', new Document([
            'mixed' => Operator::arrayRemove('')
        ]));
        $this->assertNotContains('', $updated->getAttribute('mixed'));
        $this->assertEquals(['a', 'b'], $updated->getAttribute('mixed'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 8: Test MODULO with negative numbers
     * Tests: Sign preservation, mathematical correctness
     */
    public function testOperatorModuloWithNegativeNumbers(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_negative_modulo';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_INTEGER, 0, true);

        // Test -17 % 5 (different languages handle this differently)
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'neg_mod_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => -17
        ]));

        $updated = $database->updateDocument($collectionId, 'neg_mod_doc', new Document([
            'value' => Operator::modulo(5)
        ]));

        // In PHP/MySQL: -17 % 5 = -2
        $this->assertEquals(-2, $updated->getAttribute('value'));

        // Test positive % negative
        $database->updateDocument($collectionId, 'neg_mod_doc', new Document([
            'value' => 17
        ]));

        $updated = $database->updateDocument($collectionId, 'neg_mod_doc', new Document([
            'value' => Operator::modulo(-5)
        ]));

        // In PHP/MySQL: 17 % -5 = 2
        $this->assertEquals(2, $updated->getAttribute('value'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 9: Test FLOAT PRECISION issues
     * Tests: Rounding errors, precision loss in arithmetic
     */
    public function testOperatorFloatPrecisionLoss(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_float_precision';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'precision_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 0.1
        ]));

        // Test repeated additions that expose floating point errors
        // 0.1 + 0.1 + 0.1 should be 0.3, but might be 0.30000000000000004
        $updated = $database->updateDocument($collectionId, 'precision_doc', new Document([
            'value' => Operator::increment(0.1)
        ]));
        $updated = $database->updateDocument($collectionId, 'precision_doc', new Document([
            'value' => Operator::increment(0.1)
        ]));

        // Use delta for float comparison
        $this->assertEqualsWithDelta(0.3, $updated->getAttribute('value'), 0.000001);

        // Test division that creates repeating decimal
        $database->updateDocument($collectionId, 'precision_doc', new Document([
            'value' => 10.0
        ]));

        $updated = $database->updateDocument($collectionId, 'precision_doc', new Document([
            'value' => Operator::divide(3.0)
        ]));

        // 10/3 = 3.333...
        $this->assertEqualsWithDelta(3.333333, $updated->getAttribute('value'), 0.000001);

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 10: Test VERY LONG string concatenation
     * Tests: Performance with large strings, memory limits
     */
    public function testOperatorWithVeryLongStrings(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_long_strings';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'text', Database::VAR_STRING, 70000, false, '');

        // Create a long string (10k characters)
        $longString = str_repeat('A', 10000);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'long_str_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text' => $longString
        ]));

        // Concat another 10k
        $updated = $database->updateDocument($collectionId, 'long_str_doc', new Document([
            'text' => Operator::stringConcat(str_repeat('B', 10000))
        ]));

        $result = $updated->getAttribute('text');
        $this->assertEquals(20000, strlen($result));
        $this->assertStringStartsWith('AAA', $result);
        $this->assertStringEndsWith('BBB', $result);

        // Test replace on long string
        $updated = $database->updateDocument($collectionId, 'long_str_doc', new Document([
            'text' => Operator::stringReplace('A', 'X')
        ]));

        $result = $updated->getAttribute('text');
        $this->assertStringNotContainsString('A', $result);
        $this->assertStringContainsString('X', $result);

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 11: Test DATE operations at year boundaries
     * Tests: Year rollover, leap year handling, edge timestamps
     */
    public function testOperatorDateAtYearBoundaries(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_date_boundaries';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'date', Database::VAR_DATETIME, 0, false, null, true, false, null, [], ['datetime']);

        // Test date at end of year
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'date_boundary_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'date' => '2023-12-31 23:59:59'
        ]));

        // Add 1 day (should roll to next year)
        $updated = $database->updateDocument($collectionId, 'date_boundary_doc', new Document([
            'date' => Operator::dateAddDays(1)
        ]));

        $resultDate = $updated->getAttribute('date');
        $this->assertStringStartsWith('2024-01-01', $resultDate);

        // Test leap year: Feb 28, 2024 + 1 day = Feb 29, 2024 (leap year)
        $database->updateDocument($collectionId, 'date_boundary_doc', new Document([
            'date' => '2024-02-28 12:00:00'
        ]));

        $updated = $database->updateDocument($collectionId, 'date_boundary_doc', new Document([
            'date' => Operator::dateAddDays(1)
        ]));

        $resultDate = $updated->getAttribute('date');
        $this->assertStringStartsWith('2024-02-29', $resultDate);

        // Test non-leap year: Feb 28, 2023 + 1 day = Mar 1, 2023
        $database->updateDocument($collectionId, 'date_boundary_doc', new Document([
            'date' => '2023-02-28 12:00:00'
        ]));

        $updated = $database->updateDocument($collectionId, 'date_boundary_doc', new Document([
            'date' => Operator::dateAddDays(1)
        ]));

        $resultDate = $updated->getAttribute('date');
        $this->assertStringStartsWith('2023-03-01', $resultDate);

        // Test large day addition (cross multiple months)
        $database->updateDocument($collectionId, 'date_boundary_doc', new Document([
            'date' => '2023-01-01 00:00:00'
        ]));

        $updated = $database->updateDocument($collectionId, 'date_boundary_doc', new Document([
            'date' => Operator::dateAddDays(365)
        ]));

        $resultDate = $updated->getAttribute('date');
        $this->assertStringStartsWith('2024-01-01', $resultDate);

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 12: Test ARRAY INSERT at exact boundaries
     * Tests: Insert at length, insert at length+1 (should fail)
     */
    public function testOperatorArrayInsertAtExactBoundaries(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_array_insert_boundaries';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'boundary_insert_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['a', 'b', 'c']
        ]));

        // Test insert at exact length (index 3 of array with 3 elements = append)
        $updated = $database->updateDocument($collectionId, 'boundary_insert_doc', new Document([
            'items' => Operator::arrayInsert(3, 'd')
        ]));
        $this->assertEquals(['a', 'b', 'c', 'd'], $updated->getAttribute('items'));

        // Test insert beyond length (should throw exception)
        try {
            $database->updateDocument($collectionId, 'boundary_insert_doc', new Document([
                'items' => Operator::arrayInsert(10, 'z')
            ]));
            $this->fail('Expected exception for out of bounds insert');
        } catch (StructureException $e) {
            $this->assertStringContainsString('out of bounds', $e->getMessage());
        }

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 13: Test SEQUENTIAL operator applications
     * Tests: Multiple updates with operators in sequence
     */
    public function testOperatorSequentialApplications(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_sequential_ops';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'counter', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'text', Database::VAR_STRING, 255, false, '');

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'sequential_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'counter' => 10,
            'text' => 'start'
        ]));

        // Apply operators sequentially and verify cumulative effect
        $updated = $database->updateDocument($collectionId, 'sequential_doc', new Document([
            'counter' => Operator::increment(5)
        ]));
        $this->assertEquals(15, $updated->getAttribute('counter'));

        $updated = $database->updateDocument($collectionId, 'sequential_doc', new Document([
            'counter' => Operator::multiply(2)
        ]));
        $this->assertEquals(30, $updated->getAttribute('counter'));

        $updated = $database->updateDocument($collectionId, 'sequential_doc', new Document([
            'counter' => Operator::decrement(10)
        ]));
        $this->assertEquals(20, $updated->getAttribute('counter'));

        $updated = $database->updateDocument($collectionId, 'sequential_doc', new Document([
            'counter' => Operator::divide(2)
        ]));
        $this->assertEquals(10, $updated->getAttribute('counter'));

        // Sequential string operations
        $updated = $database->updateDocument($collectionId, 'sequential_doc', new Document([
            'text' => Operator::stringConcat('-middle')
        ]));
        $this->assertEquals('start-middle', $updated->getAttribute('text'));

        $updated = $database->updateDocument($collectionId, 'sequential_doc', new Document([
            'text' => Operator::stringConcat('-end')
        ]));
        $this->assertEquals('start-middle-end', $updated->getAttribute('text'));

        $updated = $database->updateDocument($collectionId, 'sequential_doc', new Document([
            'text' => Operator::stringReplace('-', '_')
        ]));
        $this->assertEquals('start_middle_end', $updated->getAttribute('text'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 14: Test operators with ZERO values
     * Tests: Zero in arithmetic, empty behavior
     */
    public function testOperatorWithZeroValues(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_zero_values';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'zero_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 0.0
        ]));

        // Increment from zero
        $updated = $database->updateDocument($collectionId, 'zero_doc', new Document([
            'value' => Operator::increment(5)
        ]));
        $this->assertEquals(5.0, $updated->getAttribute('value'));

        // Multiply by zero (should become zero)
        $updated = $database->updateDocument($collectionId, 'zero_doc', new Document([
            'value' => Operator::multiply(0)
        ]));
        $this->assertEquals(0.0, $updated->getAttribute('value'));

        // Power with zero base: 0^5 = 0
        $updated = $database->updateDocument($collectionId, 'zero_doc', new Document([
            'value' => Operator::power(5)
        ]));
        $this->assertEquals(0.0, $updated->getAttribute('value'));

        // Increment and test power with zero exponent: n^0 = 1
        $database->updateDocument($collectionId, 'zero_doc', new Document([
            'value' => 99.0
        ]));

        $updated = $database->updateDocument($collectionId, 'zero_doc', new Document([
            'value' => Operator::power(0)
        ]));
        $this->assertEquals(1.0, $updated->getAttribute('value'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 15: Test ARRAY INTERSECT and DIFF with empty result sets
     * Tests: What happens when operations produce empty arrays
     */
    public function testOperatorArrayIntersectAndDiffWithEmptyResults(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_array_empty_results';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'empty_result_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['a', 'b', 'c']
        ]));

        // Intersect with no common elements (result should be empty array)
        $updated = $database->updateDocument($collectionId, 'empty_result_doc', new Document([
            'items' => Operator::arrayIntersect(['x', 'y', 'z'])
        ]));
        $this->assertEquals([], $updated->getAttribute('items'));

        // Reset and test diff that removes all elements
        $database->updateDocument($collectionId, 'empty_result_doc', new Document([
            'items' => ['a', 'b', 'c']
        ]));

        $updated = $database->updateDocument($collectionId, 'empty_result_doc', new Document([
            'items' => Operator::arrayDiff(['a', 'b', 'c'])
        ]));
        $this->assertEquals([], $updated->getAttribute('items'));

        // Test intersect on empty array
        $updated = $database->updateDocument($collectionId, 'empty_result_doc', new Document([
            'items' => Operator::arrayIntersect(['x', 'y'])
        ]));
        $this->assertEquals([], $updated->getAttribute('items'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 16: Test REPLACE with patterns that appear multiple times
     * Tests: Replace all occurrences, not just first
     */
    public function testOperatorReplaceMultipleOccurrences(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_replace_multiple';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'text', Database::VAR_STRING, 255, false, '');

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'replace_multi_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'text' => 'the cat and the dog'
        ]));

        // Replace all occurrences of 'the'
        $updated = $database->updateDocument($collectionId, 'replace_multi_doc', new Document([
            'text' => Operator::stringReplace('the', 'a')
        ]));
        $this->assertEquals('a cat and a dog', $updated->getAttribute('text'));

        // Replace with overlapping patterns
        $database->updateDocument($collectionId, 'replace_multi_doc', new Document([
            'text' => 'aaa bbb aaa ccc aaa'
        ]));

        $updated = $database->updateDocument($collectionId, 'replace_multi_doc', new Document([
            'text' => Operator::stringReplace('aaa', 'X')
        ]));
        $this->assertEquals('X bbb X ccc X', $updated->getAttribute('text'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 17: Test INCREMENT/DECREMENT with FLOAT values that have many decimal places
     * Tests: Precision preservation in arithmetic
     */
    public function testOperatorIncrementDecrementWithPreciseFloats(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_precise_floats';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'precise_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'value' => 3.141592653589793
        ]));

        // Increment by precise float
        $updated = $database->updateDocument($collectionId, 'precise_doc', new Document([
            'value' => Operator::increment(2.718281828459045)
        ]));

        // π + e ≈ 5.859874482048838
        $this->assertEqualsWithDelta(5.859874482, $updated->getAttribute('value'), 0.000001);

        // Decrement by precise float
        $updated = $database->updateDocument($collectionId, 'precise_doc', new Document([
            'value' => Operator::decrement(1.414213562373095)
        ]));

        // (π + e) - √2 ≈ 4.44566
        $this->assertEqualsWithDelta(4.44566, $updated->getAttribute('value'), 0.0001);

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 18: Test ARRAY operations with single-element arrays
     * Tests: Boundary between empty and multi-element
     */
    public function testOperatorArrayWithSingleElement(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_single_element';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'single_elem_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['only']
        ]));

        // Remove the only element
        $updated = $database->updateDocument($collectionId, 'single_elem_doc', new Document([
            'items' => Operator::arrayRemove('only')
        ]));
        $this->assertEquals([], $updated->getAttribute('items'));

        // Reset and test unique on single element
        $database->updateDocument($collectionId, 'single_elem_doc', new Document([
            'items' => ['single']
        ]));

        $updated = $database->updateDocument($collectionId, 'single_elem_doc', new Document([
            'items' => Operator::arrayUnique()
        ]));
        $this->assertEquals(['single'], $updated->getAttribute('items'));

        // Test intersect with single element (match)
        $updated = $database->updateDocument($collectionId, 'single_elem_doc', new Document([
            'items' => Operator::arrayIntersect(['single'])
        ]));
        $this->assertEquals(['single'], $updated->getAttribute('items'));

        // Test intersect with single element (no match)
        $database->updateDocument($collectionId, 'single_elem_doc', new Document([
            'items' => ['single']
        ]));

        $updated = $database->updateDocument($collectionId, 'single_elem_doc', new Document([
            'items' => Operator::arrayIntersect(['other'])
        ]));
        $this->assertEquals([], $updated->getAttribute('items'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 19: Test TOGGLE on default boolean values
     * Tests: Toggle from default state
     */
    public function testOperatorToggleFromDefaultValue(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_toggle_default';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'flag', Database::VAR_BOOLEAN, 0, false, false);

        // Create doc without setting flag (should use default false)
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'toggle_default_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
        ]));

        // Verify default
        $this->assertEquals(false, $doc->getAttribute('flag'));

        // Toggle from default false to true
        $updated = $database->updateDocument($collectionId, 'toggle_default_doc', new Document([
            'flag' => Operator::toggle()
        ]));
        $this->assertEquals(true, $updated->getAttribute('flag'));

        // Toggle back
        $updated = $database->updateDocument($collectionId, 'toggle_default_doc', new Document([
            'flag' => Operator::toggle()
        ]));
        $this->assertEquals(false, $updated->getAttribute('flag'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Edge Case 20: Test operators with ATTRIBUTE that has max/min constraints
     * Tests: Interaction between operator limits and attribute constraints
     */
    public function testOperatorWithAttributeConstraints(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_attribute_constraints';
        $database->createCollection($collectionId);
        // Integer with size 0 (32-bit INT)
        $database->createAttribute($collectionId, 'small_int', Database::VAR_INTEGER, 0, true);

        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'constraint_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'small_int' => 100
        ]));

        // Test increment with max — 100 + 50 = 150 exceeds max 120, so unchanged
        $updated = $database->updateDocument($collectionId, 'constraint_doc', new Document([
            'small_int' => Operator::increment(50, 120)
        ]));
        $this->assertEquals(100, $updated->getAttribute('small_int'));

        // Test multiply that would exceed the max limit — unchanged
        $database->updateDocument($collectionId, 'constraint_doc', new Document([
            'small_int' => 1000
        ]));

        $updated = $database->updateDocument($collectionId, 'constraint_doc', new Document([
            'small_int' => Operator::multiply(1000, 5000)
        ]));
        $this->assertEquals(1000, $updated->getAttribute('small_int'));

        $database->deleteCollection($collectionId);
    }

    public function testBulkUpdateWithOperatorsCallbackReceivesFreshData(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create test collection
        $collectionId = 'test_bulk_callback';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 0.0);
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);

        // Create multiple test documents
        for ($i = 1; $i <= 5; $i++) {
            $database->createDocument($collectionId, new Document([
                '$id' => "doc_{$i}",
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'count' => $i * 10,
                'score' => $i * 5.5,
                'tags' => ["initial_{$i}"]
            ]));
        }

        $callbackResults = [];
        $count = $database->updateDocuments(
            $collectionId,
            new Document([
                'count' => Operator::increment(7),
                'score' => Operator::multiply(2),
                'tags' => Operator::arrayAppend(['updated'])
            ]),
            [],
            Database::INSERT_BATCH_SIZE,
            function (Document $doc, Document $old) use (&$callbackResults) {
                // Verify callback receives fresh computed values, not Operator objects
                $this->assertIsInt($doc->getAttribute('count'));
                $this->assertIsFloat($doc->getAttribute('score'));
                $this->assertIsArray($doc->getAttribute('tags'));

                // Verify values are actually computed
                $expectedCount = $old->getAttribute('count') + 7;
                $expectedScore = $old->getAttribute('score') * 2;
                $expectedTags = array_merge($old->getAttribute('tags'), ['updated']);

                $this->assertEquals($expectedCount, $doc->getAttribute('count'));
                $this->assertEquals($expectedScore, $doc->getAttribute('score'));
                $this->assertEquals($expectedTags, $doc->getAttribute('tags'));

                $callbackResults[] = $doc->getId();
            }
        );

        $this->assertEquals(5, $count);
        $this->assertCount(5, $callbackResults);
        $this->assertEquals(['doc_1', 'doc_2', 'doc_3', 'doc_4', 'doc_5'], $callbackResults);

        $database->deleteCollection($collectionId);
    }

    public function testBulkUpsertWithOperatorsCallbackReceivesFreshData(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create test collection
        $collectionId = 'test_upsert_callback';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'value', Database::VAR_FLOAT, 0, false, 0.0);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        // Create existing documents
        $database->createDocument($collectionId, new Document([
            '$id' => 'existing_1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => 100,
            'value' => 50.0,
            'items' => ['item1']
        ]));

        $database->createDocument($collectionId, new Document([
            '$id' => 'existing_2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => 200,
            'value' => 75.0,
            'items' => ['item2']
        ]));

        $callbackResults = [];

        // Upsert documents with operators (update existing, create new)
        $documents = [
            new Document([
                '$id' => 'existing_1',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'count' => Operator::increment(50),
                'value' => Operator::divide(2),
                'items' => Operator::arrayAppend(['new_item'])
            ]),
            new Document([
                '$id' => 'existing_2',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'count' => Operator::decrement(25),
                'value' => Operator::multiply(1.5),
                'items' => Operator::arrayPrepend(['prepended'])
            ]),
            new Document([
                '$id' => 'new_doc',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'count' => 500,
                'value' => 100.0,
                'items' => ['new']
            ])
        ];

        $count = $database->upsertDocuments(
            $collectionId,
            $documents,
            Database::INSERT_BATCH_SIZE,
            function (Document $doc, ?Document $old) use (&$callbackResults) {
                // Verify callback receives fresh computed values, not Operator objects
                $this->assertIsInt($doc->getAttribute('count'));
                $this->assertIsFloat($doc->getAttribute('value'));
                $this->assertIsArray($doc->getAttribute('items'));

                if ($doc->getId() === 'existing_1' && $old !== null) {
                    $this->assertEquals(150, $doc->getAttribute('count')); // 100 + 50
                    $this->assertEquals(25.0, $doc->getAttribute('value')); // 50 / 2
                    $this->assertEquals(['item1', 'new_item'], $doc->getAttribute('items'));
                } elseif ($doc->getId() === 'existing_2' && $old !== null) {
                    $this->assertEquals(175, $doc->getAttribute('count')); // 200 - 25
                    $this->assertEquals(112.5, $doc->getAttribute('value')); // 75 * 1.5
                    $this->assertEquals(['prepended', 'item2'], $doc->getAttribute('items'));
                } elseif ($doc->getId() === 'new_doc' && $old === null) {
                    $this->assertEquals(500, $doc->getAttribute('count'));
                    $this->assertEquals(100.0, $doc->getAttribute('value'));
                    $this->assertEquals(['new'], $doc->getAttribute('items'));
                }

                $callbackResults[] = $doc->getId();
            }
        );

        $this->assertEquals(3, $count);
        $this->assertCount(3, $callbackResults);

        $database->deleteCollection($collectionId);
    }

    public function testSingleUpsertWithOperators(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create test collection
        $collectionId = 'test_single_upsert';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'count', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 0.0);
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);

        // Test upsert with operators on new document (insert)
        $doc = $database->upsertDocument($collectionId, new Document([
            '$id' => 'test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => 100,
            'score' => 50.0,
            'tags' => ['tag1', 'tag2']
        ]));

        $this->assertEquals(100, $doc->getAttribute('count'));
        $this->assertEquals(50.0, $doc->getAttribute('score'));
        $this->assertEquals(['tag1', 'tag2'], $doc->getAttribute('tags'));

        // Test upsert with operators on existing document (update)
        $updated = $database->upsertDocument($collectionId, new Document([
            '$id' => 'test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => Operator::increment(25),
            'score' => Operator::multiply(2),
            'tags' => Operator::arrayAppend(['tag3'])
        ]));

        // Verify operators were applied correctly
        $this->assertEquals(125, $updated->getAttribute('count')); // 100 + 25
        $this->assertEquals(100.0, $updated->getAttribute('score')); // 50 * 2
        $this->assertEquals(['tag1', 'tag2', 'tag3'], $updated->getAttribute('tags'));

        // Verify values are not Operator objects
        $this->assertIsInt($updated->getAttribute('count'));
        $this->assertIsFloat($updated->getAttribute('score'));
        $this->assertIsArray($updated->getAttribute('tags'));

        // Test another upsert with different operators
        $updated = $database->upsertDocument($collectionId, new Document([
            '$id' => 'test_doc',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'count' => Operator::decrement(50),
            'score' => Operator::divide(4),
            'tags' => Operator::arrayPrepend(['tag0'])
        ]));

        $this->assertEquals(75, $updated->getAttribute('count')); // 125 - 50
        $this->assertEquals(25.0, $updated->getAttribute('score')); // 100 / 4
        $this->assertEquals(['tag0', 'tag1', 'tag2', 'tag3'], $updated->getAttribute('tags'));

        $database->deleteCollection($collectionId);
    }

    public function testUpsertOperatorsOnNewDocuments(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        // Create test collection with all attribute types needed for operators
        $collectionId = 'test_upsert_new_ops';
        $database->createCollection($collectionId);

        $database->createAttribute($collectionId, 'counter', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'score', Database::VAR_FLOAT, 0, false, 0.0);
        $database->createAttribute($collectionId, 'price', Database::VAR_FLOAT, 0, false, 0.0);
        $database->createAttribute($collectionId, 'quantity', Database::VAR_INTEGER, 0, false, 0);
        $database->createAttribute($collectionId, 'tags', Database::VAR_STRING, 50, false, null, true, true);
        $database->createAttribute($collectionId, 'numbers', Database::VAR_INTEGER, 0, false, null, true, true);
        $database->createAttribute($collectionId, 'name', Database::VAR_STRING, 100, false, '');

        // Test 1: INCREMENT on new document (should use 0 as default)
        $doc1 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_increment',
            '$permissions' => [Permission::read(Role::any())],
            'counter' => Operator::increment(10),
        ]));
        $this->assertEquals(10, $doc1->getAttribute('counter'), 'INCREMENT on new doc: 0 + 10 = 10');

        // Test 2: DECREMENT on new document (should use 0 as default)
        $doc2 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_decrement',
            '$permissions' => [Permission::read(Role::any())],
            'counter' => Operator::decrement(5),
        ]));
        $this->assertEquals(-5, $doc2->getAttribute('counter'), 'DECREMENT on new doc: 0 - 5 = -5');

        // Test 3: MULTIPLY on new document (should use 0 as default)
        $doc3 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_multiply',
            '$permissions' => [Permission::read(Role::any())],
            'score' => Operator::multiply(5),
        ]));
        $this->assertEquals(0.0, $doc3->getAttribute('score'), 'MULTIPLY on new doc: 0 * 5 = 0');

        // Test 4: DIVIDE on new document (should use 0 as default, but may handle division carefully)
        // Note: 0 / n = 0, so this should work
        $doc4 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_divide',
            '$permissions' => [Permission::read(Role::any())],
            'score' => Operator::divide(2),
        ]));
        $this->assertEquals(0.0, $doc4->getAttribute('score'), 'DIVIDE on new doc: 0 / 2 = 0');

        // Test 5: ARRAY_APPEND on new document (should use [] as default)
        $doc5 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_array_append',
            '$permissions' => [Permission::read(Role::any())],
            'tags' => Operator::arrayAppend(['tag1', 'tag2']),
        ]));
        $this->assertEquals(['tag1', 'tag2'], $doc5->getAttribute('tags'), 'ARRAY_APPEND on new doc: [] + [tag1, tag2]');

        // Test 6: ARRAY_PREPEND on new document (should use [] as default)
        $doc6 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_array_prepend',
            '$permissions' => [Permission::read(Role::any())],
            'tags' => Operator::arrayPrepend(['first']),
        ]));
        $this->assertEquals(['first'], $doc6->getAttribute('tags'), 'ARRAY_PREPEND on new doc: [first] + []');

        // Test 7: ARRAY_INSERT on new document (should use [] as default, insert at position 0)
        $doc7 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_array_insert',
            '$permissions' => [Permission::read(Role::any())],
            'numbers' => Operator::arrayInsert(0, 42),
        ]));
        $this->assertEquals([42], $doc7->getAttribute('numbers'), 'ARRAY_INSERT on new doc: insert 42 at position 0');

        // Test 8: ARRAY_REMOVE on new document (should use [] as default, nothing to remove)
        $doc8 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_array_remove',
            '$permissions' => [Permission::read(Role::any())],
            'tags' => Operator::arrayRemove(['nonexistent']),
        ]));
        $this->assertEquals([], $doc8->getAttribute('tags'), 'ARRAY_REMOVE on new doc: [] - [nonexistent] = []');

        // Test 9: ARRAY_UNIQUE on new document (should use [] as default)
        $doc9 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_array_unique',
            '$permissions' => [Permission::read(Role::any())],
            'tags' => Operator::arrayUnique(),
        ]));
        $this->assertEquals([], $doc9->getAttribute('tags'), 'ARRAY_UNIQUE on new doc: unique([]) = []');

        // Test 10: CONCAT on new document (should use empty string as default)
        $doc10 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_concat',
            '$permissions' => [Permission::read(Role::any())],
            'name' => Operator::stringConcat(' World'),
        ]));
        $this->assertEquals(' World', $doc10->getAttribute('name'), 'CONCAT on new doc: "" + " World" = " World"');

        // Test 11: REPLACE on new document (should use empty string as default)
        $doc11 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_replace',
            '$permissions' => [Permission::read(Role::any())],
            'name' => Operator::stringReplace('old', 'new'),
        ]));
        $this->assertEquals('', $doc11->getAttribute('name'), 'REPLACE on new doc: replace("old", "new") in "" = ""');

        // Test 12: Multiple operators on same new document
        $doc12 = $database->upsertDocument($collectionId, new Document([
            '$id' => 'doc_multi',
            '$permissions' => [Permission::read(Role::any())],
            'counter' => Operator::increment(100),
            'score' => Operator::increment(50.5),
            'tags' => Operator::arrayAppend(['multi1', 'multi2']),
            'name' => Operator::stringConcat('MultiTest'),
        ]));
        $this->assertEquals(100, $doc12->getAttribute('counter'));
        $this->assertEquals(50.5, $doc12->getAttribute('score'));
        $this->assertEquals(['multi1', 'multi2'], $doc12->getAttribute('tags'));
        $this->assertEquals('MultiTest', $doc12->getAttribute('name'));

        // Cleanup
        $database->deleteCollection($collectionId);
    }

    /**
     * Test bulk upsertDocuments with ALL operators
     */
    public function testUpsertDocumentsWithAllOperators(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'test_upsert_all_operators';
        $attributes = [
            new Document(['$id' => 'counter', 'type' => Database::VAR_INTEGER, 'size' => 0, 'required' => false, 'default' => 10, 'signed' => true, 'array' => false]),
            new Document(['$id' => 'score', 'type' => Database::VAR_FLOAT, 'size' => 0, 'required' => false, 'default' => 5.0, 'signed' => true, 'array' => false]),
            new Document(['$id' => 'multiplier', 'type' => Database::VAR_FLOAT, 'size' => 0, 'required' => false, 'default' => 2.0, 'signed' => true, 'array' => false]),
            new Document(['$id' => 'divisor', 'type' => Database::VAR_FLOAT, 'size' => 0, 'required' => false, 'default' => 100.0, 'signed' => true, 'array' => false]),
            new Document(['$id' => 'remainder', 'type' => Database::VAR_INTEGER, 'size' => 0, 'required' => false, 'default' => 20, 'signed' => true, 'array' => false]),
            new Document(['$id' => 'power_val', 'type' => Database::VAR_FLOAT, 'size' => 0, 'required' => false, 'default' => 2.0, 'signed' => true, 'array' => false]),
            new Document(['$id' => 'title', 'type' => Database::VAR_STRING, 'size' => 255, 'required' => false, 'default' => 'Title', 'signed' => true, 'array' => false]),
            new Document(['$id' => 'content', 'type' => Database::VAR_STRING, 'size' => 500, 'required' => false, 'default' => 'old content', 'signed' => true, 'array' => false]),
            new Document(['$id' => 'tags', 'type' => Database::VAR_STRING, 'size' => 50, 'required' => false, 'default' => null, 'signed' => true, 'array' => true]),
            new Document(['$id' => 'categories', 'type' => Database::VAR_STRING, 'size' => 50, 'required' => false, 'default' => null, 'signed' => true, 'array' => true]),
            new Document(['$id' => 'items', 'type' => Database::VAR_STRING, 'size' => 50, 'required' => false, 'default' => null, 'signed' => true, 'array' => true]),
            new Document(['$id' => 'duplicates', 'type' => Database::VAR_STRING, 'size' => 50, 'required' => false, 'default' => null, 'signed' => true, 'array' => true]),
            new Document(['$id' => 'numbers', 'type' => Database::VAR_INTEGER, 'size' => 0, 'required' => false, 'default' => null, 'signed' => true, 'array' => true]),
            new Document(['$id' => 'intersect_items', 'type' => Database::VAR_STRING, 'size' => 50, 'required' => false, 'default' => null, 'signed' => true, 'array' => true]),
            new Document(['$id' => 'diff_items', 'type' => Database::VAR_STRING, 'size' => 50, 'required' => false, 'default' => null, 'signed' => true, 'array' => true]),
            new Document(['$id' => 'filter_numbers', 'type' => Database::VAR_INTEGER, 'size' => 0, 'required' => false, 'default' => null, 'signed' => true, 'array' => true]),
            new Document(['$id' => 'active', 'type' => Database::VAR_BOOLEAN, 'size' => 0, 'required' => false, 'default' => false, 'signed' => true, 'array' => false]),
            new Document(['$id' => 'date_field1', 'type' => Database::VAR_DATETIME, 'size' => 0, 'required' => false, 'default' => null, 'signed' => true, 'array' => false, 'format' => '', 'filters' => ['datetime']]),
            new Document(['$id' => 'date_field2', 'type' => Database::VAR_DATETIME, 'size' => 0, 'required' => false, 'default' => null, 'signed' => true, 'array' => false, 'format' => '', 'filters' => ['datetime']]),
            new Document(['$id' => 'date_field3', 'type' => Database::VAR_DATETIME, 'size' => 0, 'required' => false, 'default' => null, 'signed' => true, 'array' => false, 'format' => '', 'filters' => ['datetime']]),
        ];
        $database->createCollection($collectionId, $attributes);

        $database->createDocument($collectionId, new Document([
            '$id' => 'upsert_doc_1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'counter' => 10,
            'score' => 1.5,
            'multiplier' => 1.0,
            'divisor' => 50.0,
            'remainder' => 7,
            'power_val' => 2.0,
            'title' => 'Title 1',
            'content' => 'old content 1',
            'tags' => ['tag_1', 'common'],
            'categories' => ['cat_1', 'test'],
            'items' => ['item_1', 'shared', 'item_1'],
            'duplicates' => ['a', 'b', 'a', 'c', 'b', 'd'],
            'numbers' => [1, 2, 3, 4, 5],
            'intersect_items' => ['a', 'b', 'c', 'd'],
            'diff_items' => ['x', 'y', 'z', 'w'],
            'filter_numbers' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'active' => false,
            'date_field1' => DateTime::addSeconds(new \DateTime(), -86400),
            'date_field2' => DateTime::addSeconds(new \DateTime(), 86400)
        ]));

        $database->createDocument($collectionId, new Document([
            '$id' => 'upsert_doc_2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'counter' => 20,
            'score' => 3.0,
            'multiplier' => 2.0,
            'divisor' => 100.0,
            'remainder' => 14,
            'power_val' => 3.0,
            'title' => 'Title 2',
            'content' => 'old content 2',
            'tags' => ['tag_2', 'common'],
            'categories' => ['cat_2', 'test'],
            'items' => ['item_2', 'shared', 'item_2'],
            'duplicates' => ['a', 'b', 'a', 'c', 'b', 'd'],
            'numbers' => [1, 2, 3, 4, 5],
            'intersect_items' => ['a', 'b', 'c', 'd'],
            'diff_items' => ['x', 'y', 'z', 'w'],
            'filter_numbers' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
            'active' => true,
            'date_field1' => DateTime::addSeconds(new \DateTime(), -86400),
            'date_field2' => DateTime::addSeconds(new \DateTime(), 86400)
        ]));

        // Prepare upsert documents: 2 updates + 1 new insert with ALL operators
        $documents = [
            // Update existing doc 1
            new Document([
                '$id' => 'upsert_doc_1',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'counter' => Operator::increment(5, 50),
                'score' => Operator::decrement(0.5, 0),
                'multiplier' => Operator::multiply(2, 100),
                'divisor' => Operator::divide(2, 10),
                'remainder' => Operator::modulo(5),
                'power_val' => Operator::power(2, 100),
                'title' => Operator::stringConcat(' - Updated'),
                'content' => Operator::stringReplace('old', 'new'),
                'tags' => Operator::arrayAppend(['upsert']),
                'categories' => Operator::arrayPrepend(['priority']),
                'items' => Operator::arrayRemove('shared'),
                'duplicates' => Operator::arrayUnique(),
                'numbers' => Operator::arrayInsert(2, 99),
                'intersect_items' => Operator::arrayIntersect(['b', 'c', 'e']),
                'diff_items' => Operator::arrayDiff(['y', 'z']),
                'filter_numbers' => Operator::arrayFilter('greaterThan', 5),
                'active' => Operator::toggle(),
                'date_field1' => Operator::dateAddDays(1),
                'date_field2' => Operator::dateSubDays(1),
                'date_field3' => Operator::dateSetNow()
            ]),
            // Update existing doc 2
            new Document([
                '$id' => 'upsert_doc_2',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'counter' => Operator::increment(5, 50),
                'score' => Operator::decrement(0.5, 0),
                'multiplier' => Operator::multiply(2, 100),
                'divisor' => Operator::divide(2, 10),
                'remainder' => Operator::modulo(5),
                'power_val' => Operator::power(2, 100),
                'title' => Operator::stringConcat(' - Updated'),
                'content' => Operator::stringReplace('old', 'new'),
                'tags' => Operator::arrayAppend(['upsert']),
                'categories' => Operator::arrayPrepend(['priority']),
                'items' => Operator::arrayRemove('shared'),
                'duplicates' => Operator::arrayUnique(),
                'numbers' => Operator::arrayInsert(2, 99),
                'intersect_items' => Operator::arrayIntersect(['b', 'c', 'e']),
                'diff_items' => Operator::arrayDiff(['y', 'z']),
                'filter_numbers' => Operator::arrayFilter('greaterThan', 5),
                'active' => Operator::toggle(),
                'date_field1' => Operator::dateAddDays(1),
                'date_field2' => Operator::dateSubDays(1),
                'date_field3' => Operator::dateSetNow()
            ]),
            // Insert new doc 3 (operators should use default values)
            new Document([
                '$id' => 'upsert_doc_3',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'counter' => 100,
                'score' => 50.0,
                'multiplier' => 5.0,
                'divisor' => 200.0,
                'remainder' => 30,
                'power_val' => 4.0,
                'title' => 'New Title',
                'content' => 'new content',
                'tags' => ['new_tag'],
                'categories' => ['new_cat'],
                'items' => ['new_item'],
                'duplicates' => ['x', 'y', 'z'],
                'numbers' => [10, 20, 30],
                'intersect_items' => ['p', 'q'],
                'diff_items' => ['m', 'n'],
                'filter_numbers' => [11, 12, 13],
                'active' => true,
                'date_field1' => DateTime::now(),
                'date_field2' => DateTime::now()
            ])
        ];

        // Execute bulk upsert
        $count = $database->upsertDocuments($collectionId, $documents);
        $this->assertEquals(3, $count);

        // Verify all operators worked correctly on updated documents
        $updated = $database->find($collectionId, [Query::orderAsc('$id')]);
        $this->assertCount(3, $updated);

        // Check upsert_doc_1 (was updated with operators)
        $doc1 = $updated[0];
        $this->assertEquals(15, $doc1->getAttribute('counter'));          // 10 + 5
        $this->assertEquals(1.0, $doc1->getAttribute('score'));           // 1.5 - 0.5
        $this->assertEquals(2.0, $doc1->getAttribute('multiplier'));      // 1.0 * 2
        $this->assertEquals(25.0, $doc1->getAttribute('divisor'));        // 50.0 / 2
        $this->assertEquals(2, $doc1->getAttribute('remainder'));         // 7 % 5
        $this->assertEquals(4.0, $doc1->getAttribute('power_val'));       // 2^2
        $this->assertEquals('Title 1 - Updated', $doc1->getAttribute('title'));
        $this->assertEquals('new content 1', $doc1->getAttribute('content'));
        $this->assertContains('upsert', $doc1->getAttribute('tags'));
        $this->assertContains('priority', $doc1->getAttribute('categories'));
        $this->assertNotContains('shared', $doc1->getAttribute('items'));
        $this->assertCount(4, $doc1->getAttribute('duplicates')); // Should have unique values
        $this->assertEquals([1, 2, 99, 3, 4, 5], $doc1->getAttribute('numbers')); // arrayInsert at index 2
        $this->assertEquals(['b', 'c'], $doc1->getAttribute('intersect_items')); // arrayIntersect
        $this->assertEquals(['x', 'w'], $doc1->getAttribute('diff_items')); // arrayDiff (removed y, z)
        $this->assertEquals([6, 7, 8, 9, 10], $doc1->getAttribute('filter_numbers')); // arrayFilter greaterThan 5
        $this->assertEquals(true, $doc1->getAttribute('active'));         // Was false, toggled to true
        $this->assertNotNull($doc1->getAttribute('date_field1'));         // dateAddDays
        $this->assertNotNull($doc1->getAttribute('date_field2'));         // dateSubDays
        $this->assertNotNull($doc1->getAttribute('date_field3'));         // dateSetNow

        // Check upsert_doc_2 (was updated with operators)
        $doc2 = $updated[1];
        $this->assertEquals(25, $doc2->getAttribute('counter'));          // 20 + 5
        $this->assertEquals(2.5, $doc2->getAttribute('score'));           // 3.0 - 0.5
        $this->assertEquals(4.0, $doc2->getAttribute('multiplier'));      // 2.0 * 2
        $this->assertEquals(50.0, $doc2->getAttribute('divisor'));        // 100.0 / 2
        $this->assertEquals(4, $doc2->getAttribute('remainder'));         // 14 % 5
        $this->assertEquals(9.0, $doc2->getAttribute('power_val'));       // 3^2
        $this->assertEquals('Title 2 - Updated', $doc2->getAttribute('title'));
        $this->assertEquals('new content 2', $doc2->getAttribute('content'));
        $this->assertEquals(false, $doc2->getAttribute('active'));        // Was true, toggled to false

        // Check upsert_doc_3 (was inserted without operators)
        $doc3 = $updated[2];
        $this->assertEquals(100, $doc3->getAttribute('counter'));
        $this->assertEquals(50.0, $doc3->getAttribute('score'));
        $this->assertEquals(5.0, $doc3->getAttribute('multiplier'));
        $this->assertEquals(200.0, $doc3->getAttribute('divisor'));
        $this->assertEquals(30, $doc3->getAttribute('remainder'));
        $this->assertEquals(4.0, $doc3->getAttribute('power_val'));
        $this->assertEquals('New Title', $doc3->getAttribute('title'));
        $this->assertEquals('new content', $doc3->getAttribute('content'));
        $this->assertEquals(['new_tag'], $doc3->getAttribute('tags'));
        $this->assertEquals(['new_cat'], $doc3->getAttribute('categories'));
        $this->assertEquals(['new_item'], $doc3->getAttribute('items'));
        $this->assertEquals(['x', 'y', 'z'], $doc3->getAttribute('duplicates'));
        $this->assertEquals([10, 20, 30], $doc3->getAttribute('numbers'));
        $this->assertEquals(['p', 'q'], $doc3->getAttribute('intersect_items'));
        $this->assertEquals(['m', 'n'], $doc3->getAttribute('diff_items'));
        $this->assertEquals([11, 12, 13], $doc3->getAttribute('filter_numbers'));
        $this->assertEquals(true, $doc3->getAttribute('active'));

        $database->deleteCollection($collectionId);
    }

    /**
     * Test that array operators return empty arrays instead of NULL
     * Tests: ARRAY_UNIQUE, ARRAY_INTERSECT, and ARRAY_DIFF return [] not NULL
     */
    public function testOperatorArrayEmptyResultsNotNull(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_array_not_null';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'items', Database::VAR_STRING, 50, false, null, true, true);

        // Test ARRAY_UNIQUE on empty array returns [] not NULL
        $doc1 = $database->createDocument($collectionId, new Document([
            '$id' => 'empty_unique',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => []
        ]));

        $updated1 = $database->updateDocument($collectionId, 'empty_unique', new Document([
            'items' => Operator::arrayUnique()
        ]));
        $this->assertIsArray($updated1->getAttribute('items'), 'ARRAY_UNIQUE should return array not NULL');
        $this->assertEquals([], $updated1->getAttribute('items'), 'ARRAY_UNIQUE on empty array should return []');

        // Test ARRAY_INTERSECT with no matches returns [] not NULL
        $doc2 = $database->createDocument($collectionId, new Document([
            '$id' => 'no_intersect',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['a', 'b', 'c']
        ]));

        $updated2 = $database->updateDocument($collectionId, 'no_intersect', new Document([
            'items' => Operator::arrayIntersect(['x', 'y', 'z'])
        ]));
        $this->assertIsArray($updated2->getAttribute('items'), 'ARRAY_INTERSECT should return array not NULL');
        $this->assertEquals([], $updated2->getAttribute('items'), 'ARRAY_INTERSECT with no matches should return []');

        // Test ARRAY_DIFF removing all elements returns [] not NULL
        $doc3 = $database->createDocument($collectionId, new Document([
            '$id' => 'diff_all',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'items' => ['a', 'b', 'c']
        ]));

        $updated3 = $database->updateDocument($collectionId, 'diff_all', new Document([
            'items' => Operator::arrayDiff(['a', 'b', 'c'])
        ]));
        $this->assertIsArray($updated3->getAttribute('items'), 'ARRAY_DIFF should return array not NULL');
        $this->assertEquals([], $updated3->getAttribute('items'), 'ARRAY_DIFF removing all elements should return []');

        // Cleanup
        $database->deleteCollection($collectionId);
    }

    /**
     * Test that updateDocuments with operators properly invalidates cache
     * Tests: Cache should be purged after operator updates to prevent stale data
     */
    public function testUpdateDocumentsWithOperatorsCacheInvalidation(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }


        $collectionId = 'test_operator_cache';
        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'counter', Database::VAR_INTEGER, 0, false, 0);

        // Create a document
        $doc = $database->createDocument($collectionId, new Document([
            '$id' => 'cache_test',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'counter' => 10
        ]));

        // First read to potentially cache
        $fetched1 = $database->getDocument($collectionId, 'cache_test');
        $this->assertEquals(10, $fetched1->getAttribute('counter'));

        // Use updateDocuments with operator
        $count = $database->updateDocuments(
            $collectionId,
            new Document([
                'counter' => Operator::increment(5)
            ]),
            [Query::equal('$id', ['cache_test'])]
        );

        $this->assertEquals(1, $count);

        // Read again - should get fresh value, not cached old value
        $fetched2 = $database->getDocument($collectionId, 'cache_test');
        $this->assertEquals(15, $fetched2->getAttribute('counter'), 'Cache should be invalidated after operator update');

        // Do another operator update
        $database->updateDocuments(
            $collectionId,
            new Document([
                'counter' => Operator::multiply(2)
            ])
        );

        // Verify cache was invalidated again
        $fetched3 = $database->getDocument($collectionId, 'cache_test');
        $this->assertEquals(30, $fetched3->getAttribute('counter'), 'Cache should be invalidated after second operator update');

        $database->deleteCollection($collectionId);
    }
}
