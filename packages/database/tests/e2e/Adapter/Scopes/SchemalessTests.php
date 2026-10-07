<?php

namespace Tests\E2E\Adapter\Scopes;

use Exception;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait SchemalessTests
{
    public function testSchemalessDocumentOperation(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid('schemaless');
        $database->createCollection($colName);
        $database->createAttribute($colName, 'key', Database::VAR_STRING, 50, true);
        $database->createAttribute($colName, 'value', Database::VAR_STRING, 50, false, 'value');

        $permissions = [Permission::read(Role::any()), Permission::write(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())];

        // Valid documents without any predefined attributes
        $docs = [
            new Document(['$id' => 'doc1', '$permissions' => $permissions, 'freeA' => 'doc1']),
            new Document(['$id' => 'doc2', '$permissions' => $permissions, 'freeB' => 'test']),
            new Document(['$id' => 'doc3', '$permissions' => $permissions]),
        ];
        $this->assertEquals(3, $database->createDocuments($colName, $docs));

        // Any extra attributes should be allowed (fully schemaless)
        $docs = [
            new Document(['$id' => 'doc11', 'title' => 'doc1', '$permissions' => $permissions]),
            new Document(['$id' => 'doc21', 'moviename' => 'doc2', 'moviedescription' => 'test', '$permissions' => $permissions]),
            new Document(['$id' => 'doc31', '$permissions' => $permissions]),
        ];

        $createdDocs = $database->createDocuments($colName, $docs);
        $this->assertEquals(3, $createdDocs);

        // Create a single document with extra attribute as well
        $single = $database->createDocument($colName, new Document(['$id' => 'docS', 'extra' => 'yes', '$permissions' => $permissions]));
        $this->assertEquals('docS', $single->getId());
        $this->assertEquals('yes', $single->getAttribute('extra'));

        $found = $database->find($colName);
        $this->assertCount(7, $found);
        $doc11 = $database->getDocument($colName, 'doc11');
        $this->assertEquals('doc1', $doc11->getAttribute('title'));

        $doc21 = $database->getDocument($colName, 'doc21');
        $this->assertEquals('doc2', $doc21->getAttribute('moviename'));
        $this->assertEquals('test', $doc21->getAttribute('moviedescription'));

        $updated = $database->updateDocument($colName, 'doc31', new Document(['moviename' => 'updated']));
        $this->assertEquals('updated', $updated->getAttribute('moviename'));

        $this->assertTrue($database->deleteDocument($colName, 'doc21'));
        $deleted = $database->getDocument($colName, 'doc21');
        $this->assertTrue($deleted->isEmpty());
        $remaining = $database->find($colName);
        $this->assertCount(6, $remaining);

        // Bulk update: set a new extra attribute on all remaining docs
        $modified = $database->updateDocuments($colName, new Document(['bulkExtra' => 'yes']));
        $this->assertEquals(6, $modified);
        $all = $database->find($colName);
        foreach ($all as $doc) {
            $this->assertEquals('yes', $doc->getAttribute('bulkExtra'));
        }

        // Upsert: create new and update existing with extra attributes preserved
        $upserts = [
            new Document(['$id' => 'docU1', 'extraU' => 1, '$permissions' => $permissions]),
            new Document(['$id' => 'doc1', 'extraU' => 2, '$permissions' => $permissions]),
        ];
        $countUpserts = $database->upsertDocuments($colName, $upserts);
        $this->assertEquals(2, $countUpserts);
        $docU1 = $database->getDocument($colName, 'docU1');
        $this->assertEquals(1, $docU1->getAttribute('extraU'));
        $doc1AfterUpsert = $database->getDocument($colName, 'doc1');
        $this->assertEquals(2, $doc1AfterUpsert->getAttribute('extraU'));

        // Increase/Decrease numeric attribute: add numeric attribute and mutate it
        $docS = $database->getDocument($colName, 'docS');
        $this->assertEquals(0, $docS->getAttribute('counter'));
        $docS = $database->increaseDocumentAttribute($colName, 'docS', 'counter', 5);
        $this->assertEquals(5, $docS->getAttribute('counter'));
        $docS = $database->decreaseDocumentAttribute($colName, 'docS', 'counter', 3);
        $this->assertEquals(2, $docS->getAttribute('counter'));

        $deletedByCounter = $database->deleteDocuments($colName, [Query::equal('counter', [2])]);
        $this->assertEquals(1, $deletedByCounter);

        $deletedCount = $database->deleteDocuments($colName, [Query::startsWith('$id', 'doc')]);
        $this->assertEquals(6, $deletedCount);
        $postDelete = $database->find($colName);
        $this->assertCount(0, $postDelete);

        $database->deleteCollection($colName);
    }

    public function testSchemalessDocumentInvalidInteralAttributeValidation(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        // test to ensure internal attributes are checked during creating schemaless document
        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid('schemaless');
        $database->createCollection($colName);
        try {
            $docs = [
                new Document(['$id' => true, 'freeA' => 'doc1']),
                new Document(['$id' => true, 'freeB' => 'test']),
                new Document(['$id' => true]),
            ];
            $database->createDocuments($colName, $docs);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(StructureException::class, $e);
        }

        try {
            $docs = [
                new Document(['$createdAt' => true, 'freeA' => 'doc1']),
                new Document(['$updatedAt' => true, 'freeB' => 'test']),
                new Document(['$permissions' => 12]),
            ];
            $database->createDocuments($colName, $docs);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(StructureException::class, $e);
        }

        $database->deleteCollection($colName);

    }

    public function testSchemalessSelectionOnUnknownAttributes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid('schemaless');
        $database->createCollection($colName);
        $permissions = [Permission::read(Role::any()), Permission::write(Role::any()), Permission::update(Role::any())];
        $docs = [
            new Document(['$id' => 'doc1', '$permissions' => $permissions, 'freeA' => 'doc1']),
            new Document(['$id' => 'doc2', '$permissions' => $permissions, 'freeB' => 'test']),
            new Document(['$id' => 'doc3', '$permissions' => $permissions]),
        ];
        $this->assertEquals(3, $database->createDocuments($colName, $docs));

        $docA = $database->getDocument($colName, 'doc1', [Query::select(['freeA'])]);
        $this->assertEquals('doc1', $docA->getAttribute('freeA'));

        $docC = $database->getDocument($colName, 'doc1', [Query::select(['freeC'])]);
        $this->assertNull($docC->getAttribute('freeC'));

        $docs = $database->find($colName, [Query::equal('$id', ['doc1','doc2']),Query::select(['freeC'])]);
        foreach ($docs as $doc) {
            $this->assertNull($doc->getAttribute('freeC'));
            // since not selected
            $this->assertArrayNotHasKey('freeA', $doc->getAttributes());
            $this->assertArrayNotHasKey('freeB', $doc->getAttributes());
        }

        $docA = $database->find($colName, [
            Query::equal('$id', ['doc1']),
            Query::select(['freeA'])
        ]);
        $this->assertEquals('doc1', $docA[0]->getAttribute('freeA'));

        $docC = $database->find($colName, [
            Query::equal('$id', ['doc1']),
            Query::select(['freeC'])
        ]);
        $this->assertArrayNotHasKey('freeC', $docC[0]->getAttributes());
    }

    public function testSchemalessIncrement(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid("schemaless_increment");
        $database->createCollection($colName);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        $docs = [
            new Document(['$id' => 'doc1', '$permissions' => $permissions, 'counter' => 10, 'score' => 5.5]),
            new Document(['$id' => 'doc2', '$permissions' => $permissions, 'counter' => 20, 'points' => 100]),
            new Document(['$id' => 'doc3', '$permissions' => $permissions, 'value' => 0]),
        ];
        $this->assertEquals(3, $database->createDocuments($colName, $docs));

        $doc1 = $database->increaseDocumentAttribute($colName, 'doc1', 'counter', 5);
        $this->assertEquals(15, $doc1->getAttribute('counter'));
        $this->assertEquals(5.5, $doc1->getAttribute('score'));

        $doc1 = $database->increaseDocumentAttribute($colName, 'doc1', 'score', 2.3);
        $this->assertEquals(7.8, $doc1->getAttribute('score'));

        $doc2 = $database->increaseDocumentAttribute($colName, 'doc2', 'points', 50);
        $this->assertEquals(150, $doc2->getAttribute('points'));

        $doc3 = $database->increaseDocumentAttribute($colName, 'doc3', 'newCounter', 1);
        $this->assertEquals(1, $doc3->getAttribute('newCounter'));
        $this->assertEquals(0, $doc3->getAttribute('value'));

        try {
            $database->increaseDocumentAttribute($colName, 'doc1', 'counter', 10, 20);
            $this->assertEquals(20, $database->getDocument($colName, 'doc1')->getAttribute('counter'));
        } catch (\Exception $e) {
            $this->assertInstanceOf(LimitException::class, $e);
        }

        $allDocs = $database->find($colName);
        $this->assertCount(3, $allDocs);

        $database->deleteCollection($colName);
    }

    public function testSchemalessDecrement(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid("schemaless_decrement");
        $database->createCollection($colName);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        $docs = [
            new Document(['$id' => 'doc1', '$permissions' => $permissions, 'counter' => 100, 'balance' => 250.75]),
            new Document(['$id' => 'doc2', '$permissions' => $permissions, 'score' => 50, 'extraData' => 'preserved']),
        ];
        $this->assertEquals(2, $database->createDocuments($colName, $docs));

        $doc1 = $database->decreaseDocumentAttribute($colName, 'doc1', 'counter', 25);
        $this->assertEquals(75, $doc1->getAttribute('counter'));
        $this->assertEquals(250.75, $doc1->getAttribute('balance'));

        $doc1 = $database->decreaseDocumentAttribute($colName, 'doc1', 'balance', 50.25);
        $this->assertEquals(200.5, $doc1->getAttribute('balance'));

        $doc2 = $database->decreaseDocumentAttribute($colName, 'doc2', 'score', 15);
        $this->assertEquals(35, $doc2->getAttribute('score'));
        $this->assertEquals('preserved', $doc2->getAttribute('extraData'));

        try {
            $database->decreaseDocumentAttribute($colName, 'doc2', 'score', 40, 0);
            $this->fail('Expected LimitException not thrown');
        } catch (\Exception $e) {
            $this->assertInstanceOf(LimitException::class, $e);
        }

        $doc2 = $database->decreaseDocumentAttribute($colName, 'doc2', 'score', 50);
        $this->assertEquals(-15, $doc2->getAttribute('score'));

        $retrievedDoc1 = $database->getDocument($colName, 'doc1');
        $this->assertEquals(75, $retrievedDoc1->getAttribute('counter'));
        $this->assertEquals(200.5, $retrievedDoc1->getAttribute('balance'));

        $database->deleteCollection($colName);
    }

    public function testSchemalessUpdateDocumentWithQuery(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid("schemaless_update");
        $database->createCollection($colName);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        $docs = [
            new Document(['$id' => 'doc1', '$permissions' => $permissions, 'type' => 'user', 'status' => 'active', 'score' => 100]),
            new Document(['$id' => 'doc2', '$permissions' => $permissions, 'type' => 'admin', 'status' => 'active', 'level' => 5]),
            new Document(['$id' => 'doc3', '$permissions' => $permissions, 'type' => 'user', 'status' => 'inactive', 'score' => 50]),
            new Document(['$id' => 'doc4', '$permissions' => $permissions, 'type' => 'user', 'status' => 'pending', 'newField' => 'test']),
        ];
        $this->assertEquals(4, $database->createDocuments($colName, $docs));

        $updatedDoc = $database->updateDocument($colName, 'doc1', new Document([
            'status' => 'updated',
            'lastModified' => '2023-01-01',
            'newAttribute' => 'added'
        ]));

        $this->assertEquals('updated', $updatedDoc->getAttribute('status'));
        $this->assertEquals('2023-01-01', $updatedDoc->getAttribute('lastModified'));
        $this->assertEquals('added', $updatedDoc->getAttribute('newAttribute'));
        $this->assertEquals('user', $updatedDoc->getAttribute('type')); // Existing attributes preserved
        $this->assertEquals(100, $updatedDoc->getAttribute('score'));

        $retrievedDoc = $database->getDocument($colName, 'doc1');
        $this->assertEquals('updated', $retrievedDoc->getAttribute('status'));
        $this->assertEquals('added', $retrievedDoc->getAttribute('newAttribute'));

        $updatedDoc2 = $database->updateDocument($colName, 'doc2', new Document([
            'customField1' => 'value1',
            'customField2' => 42,
            'customField3' => ['array', 'of', 'values']
        ]));

        $this->assertEquals('value1', $updatedDoc2->getAttribute('customField1'));
        $this->assertEquals(42, $updatedDoc2->getAttribute('customField2'));
        $this->assertEquals(['array', 'of', 'values'], $updatedDoc2->getAttribute('customField3'));
        $this->assertEquals('admin', $updatedDoc2->getAttribute('type')); // Original attributes preserved

        $database->deleteCollection($colName);
    }

    public function testSchemalessDeleteDocumentWithQuery(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid("schemaless_delete");
        $database->createCollection($colName);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        $docs = [
            new Document(['$id' => 'doc1', '$permissions' => $permissions, 'category' => 'temp', 'priority' => 1]),
            new Document(['$id' => 'doc2', '$permissions' => $permissions, 'category' => 'permanent', 'priority' => 5]),
            new Document(['$id' => 'doc3', '$permissions' => $permissions, 'category' => 'temp', 'priority' => 3]),
        ];
        $this->assertEquals(3, $database->createDocuments($colName, $docs));

        $result = $database->deleteDocument($colName, 'doc1');
        $this->assertTrue($result);

        $deletedDoc = $database->getDocument($colName, 'doc1');
        $this->assertTrue($deletedDoc->isEmpty());

        $remainingDocs = $database->find($colName);
        $this->assertCount(2, $remainingDocs);

        $tempDocs = $database->find($colName, [Query::equal('category', ['temp'])]);
        $this->assertCount(1, $tempDocs);
        $this->assertEquals('doc3', $tempDocs[0]->getId());

        $database->deleteCollection($colName);
    }

    public function testSchemalessUpdateDocumentsWithQuery(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if (!$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid("schemaless_bulk_update");
        $database->createCollection($colName);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        $docs = [];
        for ($i = 1; $i <= 10; $i++) {
            $docs[] = new Document([
                '$id' => "doc{$i}",
                '$permissions' => $permissions,
                'type' => $i <= 5 ? 'typeA' : 'typeB',
                'status' => 'pending',
                'score' => $i * 10,
                'customField' => "value{$i}"
            ]);
        }
        $this->assertEquals(10, $database->createDocuments($colName, $docs));

        $updatedCount = $database->updateDocuments($colName, new Document([
            'status' => 'processed',
            'processedAt' => '2023-01-01',
            'newBulkField' => 'bulk_value'
        ]), [Query::equal('type', ['typeA'])]);

        $this->assertEquals(5, $updatedCount);

        $processedDocs = $database->find($colName, [Query::equal('status', ['processed'])]);
        $this->assertCount(5, $processedDocs);

        foreach ($processedDocs as $doc) {
            $this->assertEquals('typeA', $doc->getAttribute('type'));
            $this->assertEquals('processed', $doc->getAttribute('status'));
            $this->assertEquals('2023-01-01', $doc->getAttribute('processedAt'));
            $this->assertEquals('bulk_value', $doc->getAttribute('newBulkField'));
            $this->assertNotNull($doc->getAttribute('score'));
            $this->assertNotNull($doc->getAttribute('customField'));
        }

        $pendingDocs = $database->find($colName, [Query::equal('status', ['pending'])]);
        $this->assertCount(5, $pendingDocs);

        foreach ($pendingDocs as $doc) {
            $this->assertEquals('typeB', $doc->getAttribute('type'));
            $this->assertEquals('pending', $doc->getAttribute('status'));
            $this->assertNull($doc->getAttribute('processedAt'));
            $this->assertNull($doc->getAttribute('newBulkField'));
        }

        $highScoreCount = $database->updateDocuments($colName, new Document([
            'tier' => 'premium'
        ]), [Query::greaterThan('score', 70)]);

        $this->assertEquals(3, $highScoreCount); // docs 8, 9, 10

        $premiumDocs = $database->find($colName, [Query::equal('tier', ['premium'])]);
        $this->assertCount(3, $premiumDocs);

        $allUpdateCount = $database->updateDocuments($colName, new Document([
            'globalFlag' => true,
            'lastUpdate' => '2023-12-31'
        ]));

        $this->assertEquals(10, $allUpdateCount);

        $allDocs = $database->find($colName);
        $this->assertCount(10, $allDocs);

        foreach ($allDocs as $doc) {
            $this->assertTrue($doc->getAttribute('globalFlag'));
            $this->assertEquals('2023-12-31', $doc->getAttribute('lastUpdate'));
        }

        $database->deleteCollection($colName);
    }

    public function testSchemalessDeleteDocumentsWithQuery(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if (!$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid("schemaless_bulk_delete");
        $database->createCollection($colName);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        $docs = [];
        for ($i = 1; $i <= 15; $i++) {
            $docs[] = new Document([
                '$id' => "doc{$i}",
                '$permissions' => $permissions,
                'category' => $i <= 5 ? 'temp' : ($i <= 10 ? 'archive' : 'active'),
                'priority' => $i % 3, // 0, 1, or 2
                'score' => $i * 5,
                'tags' => ["tag{$i}", 'common'],
                'metadata' => ['created' => "2023-01-{$i}"]
            ]);
        }
        $this->assertEquals(15, $database->createDocuments($colName, $docs));

        $deletedCount = $database->deleteDocuments($colName, [Query::equal('category', ['temp'])]);
        $this->assertEquals(5, $deletedCount);

        $remainingDocs = $database->find($colName);
        $this->assertCount(10, $remainingDocs);

        $tempDocs = $database->find($colName, [Query::equal('category', ['temp'])]);
        $this->assertCount(0, $tempDocs);

        $highScoreDeleted = $database->deleteDocuments($colName, [Query::greaterThan('score', 50)]);
        $this->assertEquals(5, $highScoreDeleted); // docs 11-15

        $remainingAfterScore = $database->find($colName);
        $this->assertCount(5, $remainingAfterScore); // docs 6-10 remain

        foreach ($remainingAfterScore as $doc) {
            $this->assertLessThanOrEqual(50, $doc->getAttribute('score'));
            $this->assertEquals('archive', $doc->getAttribute('category'));
        }

        $multiConditionDeleted = $database->deleteDocuments($colName, [
            Query::equal('category', ['archive']),
            Query::equal('priority', [1])
        ]);
        $this->assertEquals(2, $multiConditionDeleted); // docs 7 and 10

        $finalRemaining = $database->find($colName);
        $this->assertCount(3, $finalRemaining); // docs 6, 8, 9

        foreach ($finalRemaining as $doc) {
            $this->assertEquals('archive', $doc->getAttribute('category'));
            $this->assertNotEquals(1, $doc->getAttribute('priority'));
        }

        $allDeleted = $database->deleteDocuments($colName);
        $this->assertEquals(3, $allDeleted);

        $emptyResult = $database->find($colName);
        $this->assertCount(0, $emptyResult);

        $database->deleteCollection($colName);
    }

    public function testSchemalessOperationsWithCallback(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if (!$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid("schemaless_callbacks");
        $database->createCollection($colName);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        $docs = [];
        for ($i = 1; $i <= 8; $i++) {
            $docs[] = new Document([
                '$id' => "doc{$i}",
                '$permissions' => $permissions,
                'group' => $i <= 4 ? 'A' : 'B',
                'value' => $i * 10,
                'customData' => "data{$i}"
            ]);
        }
        $this->assertEquals(8, $database->createDocuments($colName, $docs));

        $updateResults = [];
        $updateCount = $database->updateDocuments(
            $colName,
            new Document(['processed' => true, 'timestamp' => '2023-01-01']),
            [Query::equal('group', ['A'])],
            onNext: function ($doc) use (&$updateResults) {
                $updateResults[] = $doc->getId();
            }
        );

        $this->assertEquals(4, $updateCount);
        $this->assertCount(4, $updateResults);
        $this->assertContains('doc1', $updateResults);
        $this->assertContains('doc2', $updateResults);
        $this->assertContains('doc3', $updateResults);
        $this->assertContains('doc4', $updateResults);

        $processedDocs = $database->find($colName, [Query::equal('processed', [true])]);
        $this->assertCount(4, $processedDocs);

        $deleteResults = [];
        $deleteCount = $database->deleteDocuments(
            $colName,
            [Query::greaterThan('value', 50)],
            onNext: function ($doc) use (&$deleteResults) {
                $deleteResults[] = [
                    'id' => $doc->getId(),
                    'value' => $doc->getAttribute('value'),
                    'customData' => $doc->getAttribute('customData')
                ];
            }
        );

        $this->assertEquals(3, $deleteCount); // docs 6, 7, 8
        $this->assertCount(3, $deleteResults);

        foreach ($deleteResults as $result) {
            $this->assertGreaterThan(50, $result['value']);
            $this->assertStringStartsWith('data', $result['customData']);
        }

        $remainingDocs = $database->find($colName);
        $this->assertCount(5, $remainingDocs); // docs 1-5

        foreach ($remainingDocs as $doc) {
            $this->assertLessThanOrEqual(50, $doc->getAttribute('value'));
        }

        $database->deleteCollection($colName);
    }

    public function testSchemalessIndexCreateListDelete(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Schemaless adapter still supports defining attributes/indexes metadata
        $col = uniqid('sl_idx');
        $database->createCollection($col);

        $database->createDocument($col, new Document([
            '$id' => 'a',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 't1',
            'rank' => 1,
        ]));
        $database->createDocument($col, new Document([
            '$id' => 'b',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 't2',
            'rank' => 2,
        ]));

        $this->assertTrue($database->createIndex($col, 'idx_title_unique', Database::INDEX_UNIQUE, ['title'], [128], [Database::ORDER_ASC]));
        $this->assertTrue($database->createIndex($col, 'idx_rank_key', Database::INDEX_KEY, ['rank'], [0], [Database::ORDER_ASC]));

        $collection = $database->getCollection($col);
        $indexes = $collection->getAttribute('indexes');
        $this->assertCount(2, $indexes);
        $ids = array_map(fn ($i) => $i['$id'], $indexes);
        $this->assertContains('idx_rank_key', $ids);
        $this->assertContains('idx_title_unique', $ids);

        $this->assertTrue($database->deleteIndex($col, 'idx_rank_key'));
        $collection = $database->getCollection($col);
        $this->assertCount(1, $collection->getAttribute('indexes'));
        $this->assertEquals('idx_title_unique', $collection->getAttribute('indexes')[0]['$id']);

        $this->assertTrue($database->deleteIndex($col, 'idx_title_unique'));
        $database->deleteCollection($col);
    }

    public function testSchemalessIndexDuplicatePrevention(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_idx_dup');
        $database->createCollection($col);

        $database->createDocument($col, new Document([
            '$id' => 'a',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'x'
        ]));

        $this->assertTrue($database->createIndex($col, 'duplicate', Database::INDEX_KEY, ['name'], [0], [Database::ORDER_ASC]));

        try {
            $database->createIndex($col, 'duplicate', Database::INDEX_KEY, ['name'], [0], [Database::ORDER_ASC]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }

        $database->deleteCollection($col);
    }

    public function testSchemalessObjectIndexes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Only run for schemaless adapters that support object attributes
        if ($database->getAdapter()->getSupportForAttributes() || !$database->getAdapter()->getSupportForObject()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_obj_idx');
        $database->createCollection($col);

        // Define object attributes in metadata
        $database->createAttribute($col, 'meta', Database::VAR_OBJECT, 0, false);
        $database->createAttribute($col, 'meta2', Database::VAR_OBJECT, 0, false);

        // Create regular key index on first object attribute
        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_meta_key',
                Database::INDEX_KEY,
                ['meta'],
                [0],
                [Database::ORDER_ASC]
            )
        );

        // Create unique index on second object attribute
        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_meta_unique',
                Database::INDEX_UNIQUE,
                ['meta2'],
                [0],
                [Database::ORDER_ASC]
            )
        );

        // Verify index metadata is stored on the collection
        $collection = $database->getCollection($col);
        $indexes = $collection->getAttribute('indexes');
        $this->assertCount(2, $indexes);
        $ids = array_map(fn ($i) => $i['$id'], $indexes);
        $this->assertContains('idx_meta_key', $ids);
        $this->assertContains('idx_meta_unique', $ids);

        // Clean up indexes and collection
        $this->assertTrue($database->deleteIndex($col, 'idx_meta_key'));
        $this->assertTrue($database->deleteIndex($col, 'idx_meta_unique'));
        $database->deleteCollection($col);
    }

    public function testSchemalessPermissions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_perms');
        $database->createCollection($col);

        // Create with permissive read only
        $doc = $database->createDocument($col, new Document([
            '$id' => 'd1',
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'field' => 'value'
        ]));

        $this->assertFalse($doc->isEmpty());

        // Without roles, cannot read
        $database->getAuthorization()->cleanRoles();
        $empty = $database->getDocument($col, 'd1');
        $this->assertTrue($empty->isEmpty());

        // With any role, can read
        $database->getAuthorization()->addRole(Role::any()->toString());
        $fetched = $database->getDocument($col, 'd1');
        $this->assertEquals('value', $fetched->getAttribute('field'));

        // Attempt update without update permission
        $database->getAuthorization()->cleanRoles();
        $database->getAuthorization()->addRole(Role::any()->toString());
        try {
            $database->updateDocument($col, 'd1', new Document(['field' => 'updated']));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(AuthorizationException::class, $e);
        }

        // Grant update permission and update
        $database->getAuthorization()->skip(function () use ($database, $col) {
            $database->updateDocument($col, 'd1', new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ]
            ]));
        });

        $updated = $database->updateDocument($col, 'd1', new Document(['field' => 'updated']));
        $this->assertEquals('updated', $updated->getAttribute('field'));

        // Creating without any roles should fail
        $database->getAuthorization()->cleanRoles();
        try {
            $database->createDocument($col, new Document([
                'field' => 'x'
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(AuthorizationException::class, $e);
        }

        $database->deleteCollection($col);
        $database->getAuthorization()->cleanRoles();
    }

    public function testSchemalessInternalAttributes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_internal_full');
        $database->createCollection($col);

        $database->getAuthorization()->addRole(Role::any()->toString());

        $doc = $database->createDocument($col, new Document([
            '$id' => 'i1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'alpha',
        ]));

        $this->assertEquals('i1', $doc->getId());
        $this->assertEquals($col, $doc->getCollection());
        $this->assertNotEmpty($doc->getSequence());
        $this->assertNotEmpty($doc->getAttribute('$createdAt'));
        $this->assertNotEmpty($doc->getAttribute('$updatedAt'));
        $perms = $doc->getPermissions();
        $this->assertGreaterThanOrEqual(1, count($perms));
        $this->assertContains(Permission::read(Role::any()), $perms);
        $this->assertContains(Permission::update(Role::any()), $perms);
        $this->assertContains(Permission::delete(Role::any()), $perms);

        $selected = $database->getDocument($col, 'i1', [
            Query::select(['name', '$id', '$sequence', '$collection', '$createdAt', '$updatedAt', '$permissions'])
        ]);
        $this->assertEquals('alpha', $selected->getAttribute('name'));
        $this->assertArrayHasKey('$id', $selected);
        $this->assertArrayHasKey('$sequence', $selected);
        $this->assertArrayHasKey('$collection', $selected);
        $this->assertArrayHasKey('$createdAt', $selected);
        $this->assertArrayHasKey('$updatedAt', $selected);
        $this->assertArrayHasKey('$permissions', $selected);

        $found = $database->find($col, [
            Query::equal('$id', ['i1']),
            Query::select(['$id', '$sequence', '$collection', '$createdAt', '$updatedAt', '$permissions'])
        ]);
        $this->assertCount(1, $found);
        $this->assertArrayHasKey('$id', $found[0]);
        $this->assertArrayHasKey('$sequence', $found[0]);
        $this->assertArrayHasKey('$collection', $found[0]);
        $this->assertArrayHasKey('$createdAt', $found[0]);
        $this->assertArrayHasKey('$updatedAt', $found[0]);
        $this->assertArrayHasKey('$permissions', $found[0]);

        $seq = $doc->getSequence();
        $bySeq = $database->find($col, [Query::equal('$sequence', [$seq])]);
        $this->assertCount(1, $bySeq);
        $this->assertEquals('i1', $bySeq[0]->getId());

        $createdAtBefore = $doc->getAttribute('$createdAt');
        $updatedAtBefore = $doc->getAttribute('$updatedAt');
        $updated = $database->updateDocument($col, 'i1', new Document(['name' => 'beta']));
        $this->assertEquals('beta', $updated->getAttribute('name'));
        $this->assertEquals($createdAtBefore, $updated->getAttribute('$createdAt'));
        $this->assertNotEquals($updatedAtBefore, $updated->getAttribute('$updatedAt'));

        $changed = $database->updateDocument($col, 'i1', new Document(['$id' => 'i1-new']));
        $this->assertEquals('i1-new', $changed->getId());
        $refetched = $database->getDocument($col, 'i1-new');
        $this->assertEquals('i1-new', $refetched->getId());

        try {
            $database->updateDocument($col, 'i1-new', new Document(['$permissions' => 'invalid']));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertTrue($e instanceof StructureException);
        }

        $database->setPreserveDates(true);
        $customCreated = '2000-01-01T00:00:00.000+00:00';
        $customUpdated = '2000-01-02T00:00:00.000+00:00';
        $d2 = $database->createDocument($col, new Document([
            '$id' => 'i2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            '$createdAt' => $customCreated,
            '$updatedAt' => $customUpdated,
            'v' => 1
        ]));
        $this->assertEquals($customCreated, $d2->getAttribute('$createdAt'));
        $this->assertEquals($customUpdated, $d2->getAttribute('$updatedAt'));

        $newUpdated = '2000-01-03T00:00:00.000+00:00';
        $d2u = $database->updateDocument($col, 'i2', new Document([
            'v' => 2,
            '$updatedAt' => $newUpdated
        ]));
        $this->assertEquals($customCreated, $d2u->getAttribute('$createdAt'));
        $this->assertEquals($newUpdated, $d2u->getAttribute('$updatedAt'));
        $database->setPreserveDates(false);

        $database->deleteCollection($col);
        $database->getAuthorization()->cleanRoles();
    }

    public function testSchemalessDates(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_dates');
        $database->createCollection($col);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        // Seed deterministic date strings
        $createdAt1 = '2000-01-01T10:00:00.000+00:00';
        $updatedAt1 = '2000-01-02T11:11:11.000+00:00';
        $curDate1   = '2000-01-05T05:05:05.000+00:00';

        // createDocument with preserved dates
        $doc1 = $database->withPreserveDates(function () use ($database, $col, $permissions, $createdAt1, $updatedAt1, $curDate1) {
            return $database->createDocument($col, new Document([
                '$id' => 'd1',
                '$permissions' => $permissions,
                '$createdAt' => $createdAt1,
                '$updatedAt' => $updatedAt1,
                'curDate' => $curDate1,
                'counter' => 0,
            ]));
        });

        $this->assertEquals('d1', $doc1->getId());
        $this->assertTrue(is_string($doc1->getAttribute('curDate')));
        // MongoDB converts ISO 8601 to 'Y-m-d H:i:s.v' format, so compare by parsing
        $curDate1Value = $doc1->getAttribute('curDate');
        $parsedCurDate1 = new \DateTime($curDate1Value);
        $parsedExpectedCurDate1 = new \DateTime($curDate1);
        $this->assertEquals($parsedExpectedCurDate1->getTimestamp(), $parsedCurDate1->getTimestamp());
        $this->assertTrue(is_string($doc1->getAttribute('$createdAt')));
        $this->assertTrue(is_string($doc1->getAttribute('$updatedAt')));
        // Internal attributes should preserve format better, but verify by parsing for MongoDB
        $createdAt1Value = $doc1->getAttribute('$createdAt');
        $updatedAt1Value = $doc1->getAttribute('$updatedAt');
        $parsedCreatedAt1 = new \DateTime($createdAt1Value);
        $parsedUpdatedAt1 = new \DateTime($updatedAt1Value);
        $parsedExpectedCreatedAt1 = new \DateTime($createdAt1);
        $parsedExpectedUpdatedAt1 = new \DateTime($updatedAt1);
        $this->assertEquals($parsedExpectedCreatedAt1->getTimestamp(), $parsedCreatedAt1->getTimestamp());
        $this->assertEquals($parsedExpectedUpdatedAt1->getTimestamp(), $parsedUpdatedAt1->getTimestamp());

        $fetched1 = $database->getDocument($col, 'd1');
        $fetchedCurDate1 = $fetched1->getAttribute('curDate');
        $this->assertTrue(is_string($fetchedCurDate1));
        $parsedFetchedCurDate1 = new \DateTime($fetchedCurDate1);
        $this->assertEquals($parsedExpectedCurDate1->getTimestamp(), $parsedFetchedCurDate1->getTimestamp());
        $this->assertTrue(is_string($fetched1->getAttribute('$createdAt')));
        $this->assertTrue(is_string($fetched1->getAttribute('$updatedAt')));
        $fetchedCreatedAt1 = $fetched1->getAttribute('$createdAt');
        $fetchedUpdatedAt1 = $fetched1->getAttribute('$updatedAt');
        $parsedFetchedCreatedAt1 = new \DateTime($fetchedCreatedAt1);
        $parsedFetchedUpdatedAt1 = new \DateTime($fetchedUpdatedAt1);
        $this->assertEquals($parsedExpectedCreatedAt1->getTimestamp(), $parsedFetchedCreatedAt1->getTimestamp());
        $this->assertEquals($parsedExpectedUpdatedAt1->getTimestamp(), $parsedFetchedUpdatedAt1->getTimestamp());

        // createDocuments with preserved dates
        $createdAt2 = '2001-02-03T04:05:06.000+00:00';
        $updatedAt2 = '2001-02-04T04:05:07.000+00:00';
        $curDate2   = '2001-02-05T06:07:08.000+00:00';

        $createdAt3 = '2002-03-04T05:06:07.000+00:00';
        $updatedAt3 = '2002-03-05T05:06:08.000+00:00';
        $curDate3   = '2002-03-06T07:08:09.000+00:00';

        $countCreated = $database->withPreserveDates(function () use ($database, $col, $permissions, $createdAt2, $updatedAt2, $curDate2, $createdAt3, $updatedAt3, $curDate3) {
            return $database->createDocuments($col, [
                new Document([
                    '$id' => 'd2',
                    '$permissions' => $permissions,
                    '$createdAt' => $createdAt2,
                    '$updatedAt' => $updatedAt2,
                    'curDate' => $curDate2,
                ]),
                new Document([
                    '$id' => 'd3',
                    '$permissions' => $permissions,
                    '$createdAt' => $createdAt3,
                    '$updatedAt' => $updatedAt3,
                    'curDate' => $curDate3,
                ]),
            ]);
        });
        $this->assertEquals(2, $countCreated);

        $fetched2 = $database->getDocument($col, 'd2');
        $fetchedCurDate2 = $fetched2->getAttribute('curDate');
        $parsedCurDate2 = new \DateTime($fetchedCurDate2);
        $parsedExpectedCurDate2 = new \DateTime($curDate2);
        $this->assertEquals($parsedExpectedCurDate2->getTimestamp(), $parsedCurDate2->getTimestamp());
        $fetchedCreatedAt2 = $fetched2->getAttribute('$createdAt');
        $fetchedUpdatedAt2 = $fetched2->getAttribute('$updatedAt');
        $parsedCreatedAt2 = new \DateTime($fetchedCreatedAt2);
        $parsedUpdatedAt2 = new \DateTime($fetchedUpdatedAt2);
        $parsedExpectedCreatedAt2 = new \DateTime($createdAt2);
        $parsedExpectedUpdatedAt2 = new \DateTime($updatedAt2);
        $this->assertEquals($parsedExpectedCreatedAt2->getTimestamp(), $parsedCreatedAt2->getTimestamp());
        $this->assertEquals($parsedExpectedUpdatedAt2->getTimestamp(), $parsedUpdatedAt2->getTimestamp());

        $fetched3 = $database->getDocument($col, 'd3');
        $fetchedCurDate3 = $fetched3->getAttribute('curDate');
        $parsedCurDate3 = new \DateTime($fetchedCurDate3);
        $parsedExpectedCurDate3 = new \DateTime($curDate3);
        $this->assertEquals($parsedExpectedCurDate3->getTimestamp(), $parsedCurDate3->getTimestamp());
        $fetchedCreatedAt3 = $fetched3->getAttribute('$createdAt');
        $fetchedUpdatedAt3 = $fetched3->getAttribute('$updatedAt');
        $parsedCreatedAt3 = new \DateTime($fetchedCreatedAt3);
        $parsedUpdatedAt3 = new \DateTime($fetchedUpdatedAt3);
        $parsedExpectedCreatedAt3 = new \DateTime($createdAt3);
        $parsedExpectedUpdatedAt3 = new \DateTime($updatedAt3);
        $this->assertEquals($parsedExpectedCreatedAt3->getTimestamp(), $parsedCreatedAt3->getTimestamp());
        $this->assertEquals($parsedExpectedUpdatedAt3->getTimestamp(), $parsedUpdatedAt3->getTimestamp());

        // updateDocument with preserved $updatedAt and custom date field
        $newCurDate1   = '2000-02-01T00:00:00.000+00:00';
        $newUpdatedAt1 = '2000-02-02T02:02:02.000+00:00';
        $updated1 = $database->withPreserveDates(function () use ($database, $col, $newCurDate1, $newUpdatedAt1) {
            return $database->updateDocument($col, 'd1', new Document([
                'curDate' => $newCurDate1,
                '$updatedAt' => $newUpdatedAt1,
            ]));
        });
        $updatedCurDate1 = $updated1->getAttribute('curDate');
        $updatedUpdatedAt1 = $updated1->getAttribute('$updatedAt');
        $parsedUpdatedCurDate1 = new \DateTime($updatedCurDate1);
        $parsedUpdatedUpdatedAt1 = new \DateTime($updatedUpdatedAt1);
        $parsedExpectedNewCurDate1 = new \DateTime($newCurDate1);
        $parsedExpectedNewUpdatedAt1 = new \DateTime($newUpdatedAt1);
        $this->assertEquals($parsedExpectedNewCurDate1->getTimestamp(), $parsedUpdatedCurDate1->getTimestamp());
        $this->assertEquals($parsedExpectedNewUpdatedAt1->getTimestamp(), $parsedUpdatedUpdatedAt1->getTimestamp());
        $refetched1 = $database->getDocument($col, 'd1');
        $refetchedCurDate1 = $refetched1->getAttribute('curDate');
        $refetchedUpdatedAt1 = $refetched1->getAttribute('$updatedAt');
        $parsedRefetchedCurDate1 = new \DateTime($refetchedCurDate1);
        $parsedRefetchedUpdatedAt1 = new \DateTime($refetchedUpdatedAt1);
        $this->assertEquals($parsedExpectedNewCurDate1->getTimestamp(), $parsedRefetchedCurDate1->getTimestamp());
        $this->assertEquals($parsedExpectedNewUpdatedAt1->getTimestamp(), $parsedRefetchedUpdatedAt1->getTimestamp());

        // updateDocuments with preserved $updatedAt over a subset
        $bulkCurDate   = '2001-01-01T00:00:00.000+00:00';
        $bulkUpdatedAt = '2001-01-02T00:00:00.000+00:00';
        $updatedCount = $database->withPreserveDates(function () use ($database, $col, $bulkCurDate, $bulkUpdatedAt) {
            return $database->updateDocuments(
                $col,
                new Document([
                    'curDate' => $bulkCurDate,
                    '$updatedAt' => $bulkUpdatedAt,
                ]),
                [Query::equal('$id', ['d2', 'd3'])]
            );
        });
        $this->assertEquals(2, $updatedCount);
        $afterBulk2 = $database->getDocument($col, 'd2');
        $afterBulk3 = $database->getDocument($col, 'd3');
        $bulkCurDate2 = $afterBulk2->getAttribute('curDate');
        $bulkUpdatedAt2 = $afterBulk2->getAttribute('$updatedAt');
        $bulkCurDate3 = $afterBulk3->getAttribute('curDate');
        $bulkUpdatedAt3 = $afterBulk3->getAttribute('$updatedAt');
        $parsedBulkCurDate2 = new \DateTime($bulkCurDate2);
        $parsedBulkUpdatedAt2 = new \DateTime($bulkUpdatedAt2);
        $parsedBulkCurDate3 = new \DateTime($bulkCurDate3);
        $parsedBulkUpdatedAt3 = new \DateTime($bulkUpdatedAt3);
        $parsedExpectedBulkCurDate = new \DateTime($bulkCurDate);
        $parsedExpectedBulkUpdatedAt = new \DateTime($bulkUpdatedAt);
        $this->assertEquals($parsedExpectedBulkCurDate->getTimestamp(), $parsedBulkCurDate2->getTimestamp());
        $this->assertEquals($parsedExpectedBulkUpdatedAt->getTimestamp(), $parsedBulkUpdatedAt2->getTimestamp());
        $this->assertEquals($parsedExpectedBulkCurDate->getTimestamp(), $parsedBulkCurDate3->getTimestamp());
        $this->assertEquals($parsedExpectedBulkUpdatedAt->getTimestamp(), $parsedBulkUpdatedAt3->getTimestamp());

        // upsertDocument: create new then update existing with preserved dates
        $createdAt4 = '2003-03-03T03:03:03.000+00:00';
        $updatedAt4 = '2003-03-04T04:04:04.000+00:00';
        $curDate4   = '2003-03-05T05:05:05.000+00:00';
        $up1 = $database->withPreserveDates(function () use ($database, $col, $permissions, $createdAt4, $updatedAt4, $curDate4) {
            return $database->upsertDocument($col, new Document([
                '$id' => 'd4',
                '$permissions' => $permissions,
                '$createdAt' => $createdAt4,
                '$updatedAt' => $updatedAt4,
                'curDate' => $curDate4,
            ]));
        });
        $this->assertEquals('d4', $up1->getId());
        $up1CurDate4 = $up1->getAttribute('curDate');
        $up1CreatedAt4 = $up1->getAttribute('$createdAt');
        $up1UpdatedAt4 = $up1->getAttribute('$updatedAt');
        $parsedUp1CurDate4 = new \DateTime($up1CurDate4);
        $parsedUp1CreatedAt4 = new \DateTime($up1CreatedAt4);
        $parsedUp1UpdatedAt4 = new \DateTime($up1UpdatedAt4);
        $parsedExpectedCurDate4 = new \DateTime($curDate4);
        $parsedExpectedCreatedAt4 = new \DateTime($createdAt4);
        $parsedExpectedUpdatedAt4 = new \DateTime($updatedAt4);
        $this->assertEquals($parsedExpectedCurDate4->getTimestamp(), $parsedUp1CurDate4->getTimestamp());
        $this->assertEquals($parsedExpectedCreatedAt4->getTimestamp(), $parsedUp1CreatedAt4->getTimestamp());
        $this->assertEquals($parsedExpectedUpdatedAt4->getTimestamp(), $parsedUp1UpdatedAt4->getTimestamp());

        $updatedAt4b = '2003-03-06T06:06:06.000+00:00';
        $curDate4b   = '2003-03-07T07:07:07.000+00:00';
        $up2 = $database->withPreserveDates(function () use ($database, $col, $updatedAt4b, $curDate4b) {
            return $database->upsertDocument($col, new Document([
                '$id' => 'd4',
                'curDate' => $curDate4b,
                '$updatedAt' => $updatedAt4b,
            ]));
        });
        $up2CurDate4b = $up2->getAttribute('curDate');
        $up2UpdatedAt4b = $up2->getAttribute('$updatedAt');
        $parsedUp2CurDate4b = new \DateTime($up2CurDate4b);
        $parsedUp2UpdatedAt4b = new \DateTime($up2UpdatedAt4b);
        $parsedExpectedCurDate4b = new \DateTime($curDate4b);
        $parsedExpectedUpdatedAt4b = new \DateTime($updatedAt4b);
        $this->assertEquals($parsedExpectedCurDate4b->getTimestamp(), $parsedUp2CurDate4b->getTimestamp());
        $this->assertEquals($parsedExpectedUpdatedAt4b->getTimestamp(), $parsedUp2UpdatedAt4b->getTimestamp());
        $refetched4 = $database->getDocument($col, 'd4');
        $refetched4CurDate4b = $refetched4->getAttribute('curDate');
        $refetched4UpdatedAt4b = $refetched4->getAttribute('$updatedAt');
        $parsedRefetched4CurDate4b = new \DateTime($refetched4CurDate4b);
        $parsedRefetched4UpdatedAt4b = new \DateTime($refetched4UpdatedAt4b);
        $this->assertEquals($parsedExpectedCurDate4b->getTimestamp(), $parsedRefetched4CurDate4b->getTimestamp());
        $this->assertEquals($parsedExpectedUpdatedAt4b->getTimestamp(), $parsedRefetched4UpdatedAt4b->getTimestamp());

        // upsertDocuments: mix create and update with preserved dates
        $createdAt5 = '2004-04-01T01:01:01.000+00:00';
        $updatedAt5 = '2004-04-02T02:02:02.000+00:00';
        $curDate5   = '2004-04-03T03:03:03.000+00:00';
        $updatedAt2b = '2001-02-08T08:08:08.000+00:00';
        $curDate2b   = '2001-02-09T09:09:09.000+00:00';

        $upCount = $database->withPreserveDates(function () use ($database, $col, $permissions, $createdAt5, $updatedAt5, $curDate5, $updatedAt2b, $curDate2b) {
            return $database->upsertDocuments($col, [
                new Document([
                    '$id' => 'd5',
                    '$permissions' => $permissions,
                    '$createdAt' => $createdAt5,
                    '$updatedAt' => $updatedAt5,
                    'curDate' => $curDate5,
                ]),
                new Document([
                    '$id' => 'd2',
                    '$updatedAt' => $updatedAt2b,
                    'curDate' => $curDate2b,
                ]),
            ]);
        });
        $this->assertEquals(2, $upCount);

        $fetched5 = $database->getDocument($col, 'd5');
        $fetched5CurDate5 = $fetched5->getAttribute('curDate');
        $fetched5CreatedAt5 = $fetched5->getAttribute('$createdAt');
        $fetched5UpdatedAt5 = $fetched5->getAttribute('$updatedAt');
        $parsedFetched5CurDate5 = new \DateTime($fetched5CurDate5);
        $parsedFetched5CreatedAt5 = new \DateTime($fetched5CreatedAt5);
        $parsedFetched5UpdatedAt5 = new \DateTime($fetched5UpdatedAt5);
        $parsedExpectedCurDate5 = new \DateTime($curDate5);
        $parsedExpectedCreatedAt5 = new \DateTime($createdAt5);
        $parsedExpectedUpdatedAt5 = new \DateTime($updatedAt5);
        $this->assertEquals($parsedExpectedCurDate5->getTimestamp(), $parsedFetched5CurDate5->getTimestamp());
        $this->assertEquals($parsedExpectedCreatedAt5->getTimestamp(), $parsedFetched5CreatedAt5->getTimestamp());
        $this->assertEquals($parsedExpectedUpdatedAt5->getTimestamp(), $parsedFetched5UpdatedAt5->getTimestamp());

        $fetched2b = $database->getDocument($col, 'd2');
        $fetched2bCurDate2b = $fetched2b->getAttribute('curDate');
        $fetched2bUpdatedAt2b = $fetched2b->getAttribute('$updatedAt');
        $parsedFetched2bCurDate2b = new \DateTime($fetched2bCurDate2b);
        $parsedFetched2bUpdatedAt2b = new \DateTime($fetched2bUpdatedAt2b);
        $parsedExpectedCurDate2b = new \DateTime($curDate2b);
        $parsedExpectedUpdatedAt2b = new \DateTime($updatedAt2b);
        $this->assertEquals($parsedExpectedCurDate2b->getTimestamp(), $parsedFetched2bCurDate2b->getTimestamp());
        $this->assertEquals($parsedExpectedUpdatedAt2b->getTimestamp(), $parsedFetched2bUpdatedAt2b->getTimestamp());

        // increase/decrease should not affect date types; ensure they remain strings
        $afterInc = $database->increaseDocumentAttribute($col, 'd1', 'counter', 5);
        $this->assertEquals(5, $afterInc->getAttribute('counter'));
        $this->assertTrue(is_string($afterInc->getAttribute('curDate')));
        $this->assertTrue(is_string($afterInc->getAttribute('$createdAt')));
        $this->assertTrue(is_string($afterInc->getAttribute('$updatedAt')));

        $afterIncFetched = $database->getDocument($col, 'd1');
        $this->assertEquals(5, $afterIncFetched->getAttribute('counter'));
        $this->assertTrue(is_string($afterIncFetched->getAttribute('curDate')));
        $this->assertTrue(is_string($afterIncFetched->getAttribute('$createdAt')));
        $this->assertTrue(is_string($afterIncFetched->getAttribute('$updatedAt')));

        $afterDec = $database->decreaseDocumentAttribute($col, 'd1', 'counter', 2);
        $this->assertEquals(3, $afterDec->getAttribute('counter'));
        $this->assertTrue(is_string($afterDec->getAttribute('curDate')));
        $this->assertTrue(is_string($afterDec->getAttribute('$createdAt')));
        $this->assertTrue(is_string($afterDec->getAttribute('$updatedAt')));

        $afterDecFetched = $database->getDocument($col, 'd1');
        $this->assertEquals(3, $afterDecFetched->getAttribute('counter'));
        $this->assertTrue(is_string($afterDecFetched->getAttribute('curDate')));
        $this->assertTrue(is_string($afterDecFetched->getAttribute('$createdAt')));
        $this->assertTrue(is_string($afterDecFetched->getAttribute('$updatedAt')));

        $database->deleteCollection($col);
    }

    public function testSchemalessExists(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid('schemaless_exists');
        $database->createCollection($colName);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        // Create documents with and without the 'optionalField' attribute
        $docs = [
            new Document(['$id' => 'doc1', '$permissions' => $permissions, 'optionalField' => 'value1', 'name' => 'doc1']),
            new Document(['$id' => 'doc2', '$permissions' => $permissions, 'optionalField' => 'value2', 'name' => 'doc2']),
            new Document(['$id' => 'doc3', '$permissions' => $permissions, 'name' => 'doc3']), // no optionalField
            new Document(['$id' => 'doc4', '$permissions' => $permissions, 'optionalField' => null, 'name' => 'doc4']), // exists but null
            new Document(['$id' => 'doc5', '$permissions' => $permissions, 'name' => 'doc5']), // no optionalField
        ];
        $this->assertEquals(5, $database->createDocuments($colName, $docs));

        // Test exists - should return documents where optionalField exists (even if null)
        $documents = $database->find($colName, [
            Query::exists(['optionalField']),
        ]);

        $this->assertEquals(3, count($documents)); // doc1, doc2, doc4
        $ids = array_map(fn ($doc) => $doc->getId(), $documents);
        $this->assertContains('doc1', $ids);
        $this->assertContains('doc2', $ids);
        $this->assertContains('doc4', $ids);

        // Verify that doc4 is included even though optionalField is null
        $doc4 = array_filter($documents, fn ($doc) => $doc->getId() === 'doc4');
        $this->assertCount(1, $doc4);
        $doc4Array = array_values($doc4);
        $this->assertTrue(array_key_exists('optionalField', $doc4Array[0]->getAttributes()));

        // Test exists with another attribute
        $documents = $database->find($colName, [
            Query::exists(['name']),
        ]);
        $this->assertEquals(5, count($documents)); // All documents have 'name'

        // Test exists with non-existent attribute
        $documents = $database->find($colName, [
            Query::exists(['nonExistentField']),
        ]);
        $this->assertEquals(0, count($documents));

        // Multiple attributes in a single exists query (OR semantics)
        $documents = $database->find($colName, [
            Query::exists(['optionalField', 'name']),
        ]);
        // All documents have "name", some also have "optionalField"
        $this->assertEquals(5, count($documents));

        // Multiple attributes where only one exists on some documents
        $documents = $database->find($colName, [
            Query::exists(['optionalField', 'nonExistentField']),
        ]);
        // Only documents where optionalField exists should be returned
        $this->assertEquals(3, count($documents)); // doc1, doc2, doc4

        // Multiple attributes where none exist should return empty
        $documents = $database->find($colName, [
            Query::exists(['nonExistentField', 'alsoMissing']),
        ]);
        $this->assertEquals(0, count($documents));

        // Multiple attributes including one present on all docs still returns all (OR)
        $documents = $database->find($colName, [
            Query::exists(['name', 'nonExistentField', 'alsoMissing']),
        ]);
        $this->assertEquals(5, count($documents));

        // Multiple exists queries (AND semantics)
        $documents = $database->find($colName, [
            Query::exists(['optionalField']),
            Query::exists(['name']),
        ]);
        // Documents must have both attributes
        $this->assertEquals(3, count($documents)); // doc1, doc2, doc4

        // Nested OR with exists (optionalField OR nonExistentField) AND name
        $documents = $database->find($colName, [
            Query::and([
                Query::or([
                    Query::exists(['optionalField']),
                    Query::exists(['nonExistentField']),
                ]),
                Query::exists(['name']),
            ]),
        ]);
        $this->assertEquals(3, count($documents)); // doc1, doc2, doc4

        // Nested OR with only missing attributes should yield empty
        $documents = $database->find($colName, [
            Query::or([
                Query::exists(['nonExistentField']),
                Query::exists(['alsoMissing']),
            ]),
        ]);
        $this->assertEquals(0, count($documents));

        $database->deleteCollection($colName);
    }

    public function testSchemalessNotExists(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $colName = uniqid('schemaless_not_exists');
        $database->createCollection($colName);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        // Create documents with and without the 'optionalField' attribute
        $docs = [
            new Document(['$id' => 'doc1', '$permissions' => $permissions, 'optionalField' => 'value1', 'name' => 'doc1']),
            new Document(['$id' => 'doc2', '$permissions' => $permissions, 'optionalField' => 'value2', 'name' => 'doc2']),
            new Document(['$id' => 'doc3', '$permissions' => $permissions, 'name' => 'doc3']), // no optionalField
            new Document(['$id' => 'doc4', '$permissions' => $permissions, 'optionalField' => null, 'name' => 'doc4']), // exists but null
            new Document(['$id' => 'doc5', '$permissions' => $permissions, 'name' => 'doc5']), // no optionalField
        ];
        $this->assertEquals(5, $database->createDocuments($colName, $docs));

        // Test notExists - should return documents where optionalField does not exist
        $documents = $database->find($colName, [
            Query::notExists('optionalField'),
        ]);

        $this->assertEquals(2, count($documents)); // doc3, doc5
        $ids = array_map(fn ($doc) => $doc->getId(), $documents);
        $this->assertContains('doc3', $ids);
        $this->assertContains('doc5', $ids);

        // Verify that doc4 is NOT included (it exists even though null)
        $this->assertNotContains('doc4', $ids);

        // Test notExists with another attribute
        $documents = $database->find($colName, [
            Query::notExists('name'),
        ]);
        $this->assertEquals(0, count($documents)); // All documents have 'name'

        // Test notExists with non-existent attribute
        $documents = $database->find($colName, [
            Query::notExists('nonExistentField'),
        ]);
        $this->assertEquals(5, count($documents)); // All documents don't have this field

        // Multiple attributes in a single notExists query (OR semantics) - both missing
        $documents = $database->find($colName, [
            Query::notExists(['nonExistentField', 'alsoMissing']),
        ]);
        $this->assertEquals(5, count($documents));

        // Multiple attributes (OR) where only some documents miss one of them
        $documents = $database->find($colName, [
            Query::notExists(['name', 'optionalField']),
        ]);
        $this->assertEquals(2, count($documents)); // doc3, doc5

        // Multiple notExists queries (AND semantics) - must miss both
        $documents = $database->find($colName, [
            Query::notExists(['optionalField']),
            Query::notExists(['nonExistentField']),
        ]);
        $this->assertEquals(2, count($documents)); // doc3, doc5

        // Test combination of exists and notExists
        $documents = $database->find($colName, [
            Query::exists(['name']),
            Query::notExists('optionalField'),
        ]);
        $this->assertEquals(2, count($documents)); // doc3, doc5

        // Nested OR/AND with notExists: (notExists optionalField OR notExists nonExistent) AND name
        $documents = $database->find($colName, [
            Query::and([
                Query::or([
                    Query::notExists(['optionalField']),
                    Query::notExists(['nonExistentField']),
                ]),
                Query::exists(['name']),
            ]),
        ]);
        // notExists(nonExistentField) matches all docs, so OR is always true; AND with name returns all
        $this->assertEquals(5, count($documents)); // all docs match due to nonExistentField

        // Nested OR with notExists where all attributes exist => empty
        $documents = $database->find($colName, [
            Query::or([
                Query::notExists(['name']),
                Query::notExists(['optionalField']),
            ]),
        ]);
        $this->assertEquals(2, count($documents)); // only ones missing optionalField (doc3, doc5)

        $database->deleteCollection($colName);
    }

    /**
     * Test elemMatch query functionality
     *
     * @throws Exception
     */
    public function testElemMatch(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();
        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $collectionId = ID::unique();
        $database->createCollection($collectionId);

        // Create documents with array of objects
        $doc1 = $database->createDocument($collectionId, new Document([
            '$id' => 'order1',
            '$permissions' => [Permission::read(Role::any())],
            'items' => [
                ['sku' => 'ABC', 'qty' => 5, 'price' => 10.50],
                ['sku' => 'XYZ', 'qty' => 2, 'price' => 20.00],
            ]
        ]));

        $doc2 = $database->createDocument($collectionId, new Document([
            '$id' => 'order2',
            '$permissions' => [Permission::read(Role::any())],
            'items' => [
                ['sku' => 'ABC', 'qty' => 1, 'price' => 10.50],
                ['sku' => 'DEF', 'qty' => 10, 'price' => 15.00],
            ]
        ]));

        $doc3 = $database->createDocument($collectionId, new Document([
            '$id' => 'order3',
            '$permissions' => [Permission::read(Role::any())],
            'items' => [
                ['sku' => 'XYZ', 'qty' => 3, 'price' => 20.00],
            ]
        ]));

        // Test 1: elemMatch with equal and greaterThan - should match doc1
        $results = $database->find($collectionId, [
            Query::elemMatch('items', [
                Query::equal('sku', ['ABC']),
                Query::greaterThan('qty', 1),
            ])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('order1', $results[0]->getId());

        // Test 2: elemMatch with equal and greaterThan - should not match doc2 (qty is 1, not > 1)
        $results = $database->find($collectionId, [
            Query::elemMatch('items', [
                Query::equal('sku', ['ABC']),
                Query::greaterThan('qty', 1),
            ])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('order1', $results[0]->getId());

        // Test 3: elemMatch with equal only - should match doc1 and doc2
        $results = $database->find($collectionId, [
            Query::elemMatch('items', [
                Query::equal('sku', ['ABC']),
            ])
        ]);
        $this->assertCount(2, $results);
        $ids = array_map(fn ($doc) => $doc->getId(), $results);
        $this->assertContains('order1', $ids);
        $this->assertContains('order2', $ids);

        // Elematch means at least ONE element in the array matches this condition.
        // that means array having two docs qty -> 1 and qty -> 10  , it will be returned as it has atleast one doc with qty > 10
        $results = $database->find($collectionId, [
            Query::elemMatch('items', [
                Query::greaterThan('qty', 1),
            ])
        ]);
        $this->assertCount(3, $results);
        $ids = array_map(fn ($doc) => $doc->getId(), $results);
        $this->assertContains('order1', $ids);
        $this->assertContains('order2', $ids);
        $this->assertContains('order3', $ids);

        // Test 5: elemMatch with multiple conditions - should match doc2
        $results = $database->find($collectionId, [
            Query::elemMatch('items', [
                Query::equal('sku', ['DEF']),
                Query::greaterThan('qty', 5),
            ])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('order2', $results[0]->getId());

        // Test 6: elemMatch with lessThan
        $results = $database->find($collectionId, [
            Query::elemMatch('items', [
                Query::equal('sku', ['ABC']),
                Query::lessThan('qty', 3),
            ])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('order2', $results[0]->getId());

        // Test 7: elemMatch with equal and greaterThanEqual
        $results = $database->find($collectionId, [
            Query::elemMatch('items', [
                Query::equal('sku', ['ABC']),
                Query::greaterThanEqual('qty', 1),
            ])
        ]);
        $this->assertCount(2, $results);

        // Test 8: elemMatch with no matching conditions
        $results = $database->find($collectionId, [
            Query::elemMatch('items', [
                Query::equal('sku', ['NONEXISTENT']),
            ])
        ]);
        $this->assertCount(0, $results);

        // Test 9: elemMatch with price condition
        $results = $database->find($collectionId, [
            Query::elemMatch('items', [
                Query::equal('sku', ['XYZ']),
                Query::equal('price', [20.00]),
            ])
        ]);
        $this->assertCount(2, $results);
        $ids = array_map(fn ($doc) => $doc->getId(), $results);
        $this->assertContains('order1', $ids);
        $this->assertContains('order3', $ids);

        // Test 10: elemMatch with notEqual
        $results = $database->find($collectionId, [
            Query::elemMatch('items', [
                Query::notEqual('sku', ['ABC']),
                Query::greaterThan('qty', 2),
            ])
        ]);
        // order 1 has elements where sku == "ABC", qty: 5 => !=ABC fails and sku = XYZ ,qty: 2 => >2 fails
        $this->assertCount(2, $results);
        $ids = array_map(fn ($doc) => $doc->getId(), $results);
        $this->assertContains('order2', $ids);
        $this->assertContains('order3', $ids);

        // Clean up
        $database->deleteCollection($collectionId);
    }

    /**
     * Test elemMatch with complex nested conditions
     *
     * @throws Exception
     */
    public function testElemMatchComplex(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();
        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $collectionId = ID::unique();
        $database->createCollection($collectionId);

        // Create documents with complex nested structures
        $doc1 = $database->createDocument($collectionId, new Document([
            '$id' => 'store1',
            '$permissions' => [Permission::read(Role::any())],
            'products' => [
                ['name' => 'Widget', 'stock' => 100, 'category' => 'A', 'active' => true],
                ['name' => 'Gadget', 'stock' => 50, 'category' => 'B', 'active' => false],
            ]
        ]));

        $doc2 = $database->createDocument($collectionId, new Document([
            '$id' => 'store2',
            '$permissions' => [Permission::read(Role::any())],
            'products' => [
                ['name' => 'Widget', 'stock' => 200, 'category' => 'A', 'active' => true],
                ['name' => 'Thing', 'stock' => 25, 'category' => 'C', 'active' => true],
            ]
        ]));

        // Test: elemMatch with multiple conditions including boolean
        $results = $database->find($collectionId, [
            Query::elemMatch('products', [
                Query::equal('name', ['Widget']),
                Query::greaterThan('stock', 50),
                Query::equal('category', ['A']),
                Query::equal('active', [true]),
            ])
        ]);
        $this->assertCount(2, $results);

        // Test: elemMatch with between
        $results = $database->find($collectionId, [
            Query::elemMatch('products', [
                Query::equal('category', ['A']),
                Query::between('stock', 75, 150),
            ])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('store1', $results[0]->getId());

        // Test: elemMatch with OR grouping on name and stock threshold
        $results = $database->find($collectionId, [
            Query::elemMatch('products', [
                Query::or([
                    Query::equal('name', ['Widget']),
                    Query::equal('name', ['Thing']),
                ]),
                Query::greaterThanEqual('stock', 25),
            ])
        ]);
        // Both stores have at least one matching product:
        // - store1: Widget (stock 100)
        // - store2: Widget (stock 200) and Thing (stock 25)
        $this->assertCount(2, $results);

        // Test: elemMatch with nested AND/OR conditions
        $results = $database->find($collectionId, [
            Query::elemMatch('products', [
                Query::or([
                    Query::and([
                        Query::equal('name', ['Widget']),
                        Query::greaterThan('stock', 150),
                    ]),
                    Query::and([
                        Query::equal('name', ['Thing']),
                        Query::greaterThan('stock', 20),
                    ]),
                ]),
                Query::equal('active', [true]),
            ])
        ]);
        // Only store2 matches:
        // - Widget with stock 200 (>150) and active true
        // - Thing with stock 25 (>20) and active true
        $this->assertCount(1, $results);
        $this->assertEquals('store2', $results[0]->getId());

        // Clean up
        $database->deleteCollection($collectionId);
    }

    public function testSchemalessNestedObjectAttributeQueries(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        /** @var Database $database */
        $database = static::getDatabase();
        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_nested_obj');
        $database->createCollection($col);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        // Documents with nested objects
        $database->createDocument($col, new Document([
            '$id' => 'u1',
            '$permissions' => $permissions,
            'profile' => [
                'name' => 'Alice',
                'location' => [
                    'country' => 'US',
                    'city' => 'New York',
                    'coordinates' => [
                        'lat' => 40.7128,
                        'lng' => -74.0060,
                    ],
                ],
            ],
            'sessions' => [
                [
                    'device' => [
                        'os' => 'ios',
                        'version' => '17',
                    ],
                    'active' => true,
                ],
                [
                    'device' => [
                        'os' => 'android',
                        'version' => '14',
                    ],
                    'active' => false,
                ],
            ],
        ]));

        $database->createDocument($col, new Document([
            '$id' => 'u2',
            '$permissions' => $permissions,
            'profile' => [
                'name' => 'Bob',
                'location' => [
                    'country' => 'UK',
                    'city' => 'London',
                    'coordinates' => [
                        'lat' => 51.5074,
                        'lng' => -0.1278,
                    ],
                ],
            ],
            'sessions' => [
                [
                    'device' => [
                        'os' => 'android',
                        'version' => '15',
                    ],
                    'active' => true,
                ],
            ],
        ]));

        // Document without full nesting
        $database->createDocument($col, new Document([
            '$id' => 'u3',
            '$permissions' => $permissions,
            'profile' => [
                'name' => 'Charlie',
                'location' => [
                    'country' => 'US',
                ],
            ],
            'sessions' => [],
        ]));

        // Query using Mongo-style dotted paths: attribute.key.key
        $nycDocs = $database->find($col, [
            Query::equal('profile.location.city', ['New York']),
        ]);
        $this->assertCount(1, $nycDocs);
        $this->assertEquals('u1', $nycDocs[0]->getId());

        // Query on deeper nested numeric field
        $northOf50 = $database->find($col, [
            Query::greaterThan('profile.location.coordinates.lat', 50),
        ]);
        $this->assertCount(1, $northOf50);
        $this->assertEquals('u2', $northOf50[0]->getId());

        // exists on nested key should match docs where the full path exists
        $withCoordinates = $database->find($col, [
            Query::exists(['profile.location.coordinates.lng']),
        ]);
        $this->assertCount(2, $withCoordinates);
        $ids = array_map(fn (Document $doc) => $doc->getId(), $withCoordinates);
        $this->assertContains('u1', $ids);
        $this->assertContains('u2', $ids);
        $this->assertNotContains('u3', $ids);

        // Combination of filters on nested paths
        $usWithCoords = $database->find($col, [
            Query::equal('profile.location.country', ['US']),
            Query::exists(['profile.location.coordinates.lat']),
        ]);
        $this->assertCount(1, $usWithCoords);
        $this->assertEquals('u1', $usWithCoords[0]->getId());

        // contains on object attribute using nested structure: parent.key and [key => [key => 'value']]
        $matchedByNestedContains = $database->find($col, [
            Query::contains('profile', [[
                'location' => [
                    'city' => 'London',
                ],
            ]]),
        ]);
        $this->assertCount(1, $matchedByNestedContains);
        $this->assertEquals('u2', $matchedByNestedContains[0]->getId());

        // equal on object attribute using nested structure should behave similarly
        $matchedByNestedEqual = $database->find($col, [
            Query::equal('profile', [[
                'location' => [
                    'country' => 'US',
                ],
            ]]),
        ]);
        $this->assertCount(2, $matchedByNestedEqual);
        $idsEqual = array_map(fn (Document $doc) => $doc->getId(), $matchedByNestedEqual);
        $this->assertContains('u1', $idsEqual);
        $this->assertContains('u3', $idsEqual);

        // elemMatch on array of nested objects (sessions.device.os, sessions.active)
        $iosActive = $database->find($col, [
            Query::elemMatch('sessions', [
                Query::equal('device.os', ['ios']),
                Query::equal('active', [true]),
            ]),
        ]);
        $this->assertCount(1, $iosActive);
        $this->assertEquals('u1', $iosActive[0]->getId());

        // elemMatch where nested condition only matches u2 (android & active)
        $androidActive = $database->find($col, [
            Query::elemMatch('sessions', [
                Query::equal('device.os', ['android']),
                Query::equal('active', [true]),
            ]),
        ]);
        $this->assertCount(1, $androidActive);
        $this->assertEquals('u2', $androidActive[0]->getId());

        // elemMatch with condition that should not match any document
        $windowsSessions = $database->find($col, [
            Query::elemMatch('sessions', [
                Query::equal('device.os', ['windows']),
            ]),
        ]);
        $this->assertCount(0, $windowsSessions);

        $database->deleteCollection($col);
    }

    public function testUpsertFieldRemoval(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->markTestSkipped('Adapter supports attributes (schemaful mode). Field removal in upsert is tested in schemaful tests.');
        }

        $collectionName = ID::unique();
        $database->createCollection($collectionName, permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ]);

        $permissions = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        // Test 1: Basic field removal with upsertDocument
        // Create a document with multiple fields
        $doc1 = $database->createDocument($collectionName, new Document([
            '$id' => 'doc1',
            '$permissions' => $permissions,
            'title' => 'Original Title',
            'description' => 'Original Description',
            'category' => 'tech',
            'tags' => ['php', 'mongodb'],
            'metadata' => [
                'author' => 'John Doe',
                'version' => 1
            ]
        ]));

        $this->assertEquals('Original Title', $doc1->getAttribute('title'));
        $this->assertEquals('Original Description', $doc1->getAttribute('description'));
        $this->assertEquals('tech', $doc1->getAttribute('category'));
        $this->assertArrayHasKey('tags', $doc1->getArrayCopy());
        $this->assertArrayHasKey('metadata', $doc1->getArrayCopy());

        // Upsert with fewer fields - removed fields should be deleted
        $upserted = $database->upsertDocument($collectionName, new Document([
            '$id' => 'doc1',
            '$permissions' => $permissions,
            'title' => 'Updated Title',
            'category' => 'science',
            // description, tags, and metadata are removed
        ]));

        $this->assertEquals('Updated Title', $upserted->getAttribute('title'));
        $this->assertEquals('science', $upserted->getAttribute('category'));

        // Verify removed fields are actually deleted
        $retrieved = $database->getDocument($collectionName, 'doc1');
        $this->assertEquals('Updated Title', $retrieved->getAttribute('title'));
        $this->assertEquals('science', $retrieved->getAttribute('category'));
        $this->assertArrayNotHasKey('description', $retrieved->getArrayCopy());
        $this->assertArrayNotHasKey('tags', $retrieved->getArrayCopy());
        $this->assertArrayNotHasKey('metadata', $retrieved->getArrayCopy());

        // Test 2: Remove all custom fields except one
        $doc2 = $database->createDocument($collectionName, new Document([
            '$id' => 'doc2',
            '$permissions' => $permissions,
            'field1' => 'value1',
            'field2' => 'value2',
            'field3' => 'value3',
            'field4' => 'value4',
        ]));

        // Upsert keeping only field1
        $database->upsertDocument($collectionName, new Document([
            '$id' => 'doc2',
            '$permissions' => $permissions,
            'field1' => 'updated_value1',
        ]));

        $retrieved2 = $database->getDocument($collectionName, 'doc2');
        $this->assertEquals('updated_value1', $retrieved2->getAttribute('field1'));
        $this->assertArrayNotHasKey('field2', $retrieved2->getArrayCopy());
        $this->assertArrayNotHasKey('field3', $retrieved2->getArrayCopy());
        $this->assertArrayNotHasKey('field4', $retrieved2->getArrayCopy());

        // Test 3: Remove nested object fields
        $doc3 = $database->createDocument($collectionName, new Document([
            '$id' => 'doc3',
            '$permissions' => $permissions,
            'name' => 'Product',
            'details' => [
                'color' => 'red',
                'size' => 'large',
                'weight' => 10
            ],
            'specs' => [
                'cpu' => 'Intel',
                'ram' => '8GB'
            ]
        ]));

        // Upsert removing details but keeping specs
        $database->upsertDocument($collectionName, new Document([
            '$id' => 'doc3',
            '$permissions' => $permissions,
            'name' => 'Updated Product',
            'specs' => [
                'cpu' => 'AMD',
                'ram' => '16GB'
            ],
            // details is removed
        ]));

        $retrieved3 = $database->getDocument($collectionName, 'doc3');
        $this->assertEquals('Updated Product', $retrieved3->getAttribute('name'));
        $this->assertArrayHasKey('specs', $retrieved3->getArrayCopy());
        $this->assertEquals('AMD', $retrieved3->getAttribute('specs')['cpu']);
        $this->assertArrayNotHasKey('details', $retrieved3->getArrayCopy());

        // Test 4: Remove array fields
        $doc4 = $database->createDocument($collectionName, new Document([
            '$id' => 'doc4',
            '$permissions' => $permissions,
            'title' => 'Article',
            'tags' => ['tag1', 'tag2', 'tag3'],
            'categories' => ['cat1', 'cat2'],
            'comments' => ['comment1', 'comment2']
        ]));

        // Upsert removing tags and comments but keeping categories
        $database->upsertDocument($collectionName, new Document([
            '$id' => 'doc4',
            '$permissions' => $permissions,
            'title' => 'Updated Article',
            'categories' => ['cat3'],
        ]));

        $retrieved4 = $database->getDocument($collectionName, 'doc4');
        $this->assertEquals('Updated Article', $retrieved4->getAttribute('title'));
        $this->assertArrayHasKey('categories', $retrieved4->getArrayCopy());
        $this->assertEquals(['cat3'], $retrieved4->getAttribute('categories'));
        $this->assertArrayNotHasKey('tags', $retrieved4->getArrayCopy());
        $this->assertArrayNotHasKey('comments', $retrieved4->getArrayCopy());

        // Test 5: upsertDocuments with field removal (bulk upsert)
        $docs5 = [
            new Document([
                '$id' => 'bulk1',
                '$permissions' => $permissions,
                'fieldA' => 'valueA',
                'fieldB' => 'valueB',
                'fieldC' => 'valueC',
            ]),
            new Document([
                '$id' => 'bulk2',
                '$permissions' => $permissions,
                'fieldX' => 'valueX',
                'fieldY' => 'valueY',
                'fieldZ' => 'valueZ',
            ]),
        ];
        $database->createDocuments($collectionName, $docs5);

        // Upsert removing some fields from each
        $upsertDocs5 = [
            new Document([
                '$id' => 'bulk1',
                '$permissions' => $permissions,
                'fieldA' => 'updatedA',
                // fieldB and fieldC removed
            ]),
            new Document([
                '$id' => 'bulk2',
                '$permissions' => $permissions,
                'fieldX' => 'updatedX',
                'fieldZ' => 'updatedZ',
                // fieldY removed
            ]),
        ];
        $database->upsertDocuments($collectionName, $upsertDocs5);

        $retrievedBulk1 = $database->getDocument($collectionName, 'bulk1');
        $this->assertEquals('updatedA', $retrievedBulk1->getAttribute('fieldA'));
        $this->assertArrayNotHasKey('fieldB', $retrievedBulk1->getArrayCopy());
        $this->assertArrayNotHasKey('fieldC', $retrievedBulk1->getArrayCopy());

        $retrievedBulk2 = $database->getDocument($collectionName, 'bulk2');
        $this->assertEquals('updatedX', $retrievedBulk2->getAttribute('fieldX'));
        $this->assertEquals('updatedZ', $retrievedBulk2->getAttribute('fieldZ'));
        $this->assertArrayNotHasKey('fieldY', $retrievedBulk2->getArrayCopy());

        // Test 6: Upsert creating new document (should not unset anything)
        $newDoc = $database->upsertDocument($collectionName, new Document([
            '$id' => 'newDoc',
            '$permissions' => $permissions,
            'newField' => 'newValue',
        ]));

        $this->assertEquals('newValue', $newDoc->getAttribute('newField'));
        $retrievedNew = $database->getDocument($collectionName, 'newDoc');
        $this->assertEquals('newValue', $retrievedNew->getAttribute('newField'));
        $this->assertArrayHasKey('newField', $retrievedNew->getArrayCopy());

        // Test 7: Remove all custom fields (keep only system fields)
        $doc7 = $database->createDocument($collectionName, new Document([
            '$id' => 'doc7',
            '$permissions' => $permissions,
            'custom1' => 'value1',
            'custom2' => 'value2',
            'custom3' => 'value3',
        ]));

        // Upsert with only system fields (no custom fields)
        $database->upsertDocument($collectionName, new Document([
            '$id' => 'doc7',
            '$permissions' => $permissions,
            // No custom fields
        ]));

        $retrieved7 = $database->getDocument($collectionName, 'doc7');
        $this->assertArrayNotHasKey('custom1', $retrieved7->getArrayCopy());
        $this->assertArrayNotHasKey('custom2', $retrieved7->getArrayCopy());
        $this->assertArrayNotHasKey('custom3', $retrieved7->getArrayCopy());
        // System fields should still exist
        $this->assertEquals('doc7', $retrieved7->getId());
        $this->assertNotNull($retrieved7->getCreatedAt());
        $this->assertNotNull($retrieved7->getUpdatedAt());

        // Test 8: Mixed scenario - add new fields while removing others
        $doc8 = $database->createDocument($collectionName, new Document([
            '$id' => 'doc8',
            '$permissions' => $permissions,
            'oldField1' => 'old1',
            'oldField2' => 'old2',
            'keepField' => 'keep',
        ]));

        // Upsert removing oldField1 and oldField2, keeping keepField, adding newField
        $database->upsertDocument($collectionName, new Document([
            '$id' => 'doc8',
            '$permissions' => $permissions,
            'keepField' => 'updatedKeep',
            'newField' => 'newValue',
        ]));

        $retrieved8 = $database->getDocument($collectionName, 'doc8');
        $this->assertEquals('updatedKeep', $retrieved8->getAttribute('keepField'));
        $this->assertEquals('newValue', $retrieved8->getAttribute('newField'));
        $this->assertArrayNotHasKey('oldField1', $retrieved8->getArrayCopy());
        $this->assertArrayNotHasKey('oldField2', $retrieved8->getArrayCopy());

        // Test 9: Verify internal/system fields are never removed
        $doc9 = $database->createDocument($collectionName, new Document([
            '$id' => 'doc9',
            '$permissions' => $permissions,
            'data' => 'test',
        ]));

        $originalCreatedAt = $doc9->getCreatedAt();
        $originalUpdatedAt = $doc9->getUpdatedAt();

        // Upsert - internal fields should be preserved
        $database->upsertDocument($collectionName, new Document([
            '$id' => 'doc9',
            '$permissions' => $permissions,
            'newData' => 'newTest',
        ]));

        $retrieved9 = $database->getDocument($collectionName, 'doc9');
        // System fields should still exist
        $this->assertEquals('doc9', $retrieved9->getId());
        $this->assertEquals($originalCreatedAt, $retrieved9->getCreatedAt());
        // UpdatedAt should be different (document was updated)
        $this->assertNotEquals($originalUpdatedAt, $retrieved9->getUpdatedAt());
        $this->assertEquals('newTest', $retrieved9->getAttribute('newData'));
        // Old field should be removed
        $this->assertArrayNotHasKey('data', $retrieved9->getArrayCopy());

        // Clean up
        $database->deleteCollection($collectionName);
    }

    public function testSchemalessTTLIndexes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_ttl');
        $database->createCollection($col);

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

        $doc1 = $database->createDocument($col, new Document([
            '$id' => 'doc1',
            '$permissions' => $permissions,
            'expiresAt' => $future1->format(\DateTime::ATOM),
            'data' => 'will expire in 2 hours'
        ]));

        $doc2 = $database->createDocument($col, new Document([
            '$id' => 'doc2',
            '$permissions' => $permissions,
            'expiresAt' => $future2->format(\DateTime::ATOM),
            'data' => 'will expire in 1 hour'
        ]));

        $doc3 = $database->createDocument($col, new Document([
            '$id' => 'doc3',
            '$permissions' => $permissions,
            'expiresAt' => $past->format(\DateTime::ATOM),
            'data' => 'already expired'
        ]));

        // Verify documents were created
        $this->assertEquals('doc1', $doc1->getId());
        $this->assertEquals('doc2', $doc2->getId());
        $this->assertEquals('doc3', $doc3->getId());

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

    public function testSchemalessTTLIndexDuplicatePrevention(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_ttl_dup');
        $database->createCollection($col);

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

        $database->deleteCollection($col);
    }

    public function testSchemalessDatetimeCreationAndFetching(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_datetime');
        $database->createCollection($col);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        // Create documents with ISO 8601 datetime strings (20-40 chars)
        // MongoDB converts ISO 8601 to UTCDateTime and returns in 'Y-m-d H:i:s.v' format
        $datetime1 = '2024-01-15T10:30:00.000+00:00';
        $datetime2 = '2024-02-20T14:45:30.123Z';
        $datetime3 = '2024-03-25T08:15:45.000000+05:30';

        $doc1 = $database->createDocument($col, new Document([
            '$id' => 'dt1',
            '$permissions' => $permissions,
            'eventDate' => $datetime1,
            'name' => 'Event 1'
        ]));

        $doc2 = $database->createDocument($col, new Document([
            '$id' => 'dt2',
            '$permissions' => $permissions,
            'eventDate' => $datetime2,
            'name' => 'Event 2'
        ]));

        $doc3 = $database->createDocument($col, new Document([
            '$id' => 'dt3',
            '$permissions' => $permissions,
            'eventDate' => $datetime3,
            'name' => 'Event 3'
        ]));

        // Verify creation - check that datetime is stored and returned as string
        $this->assertEquals('dt1', $doc1->getId());
        $eventDate1 = $doc1->getAttribute('eventDate');
        $this->assertTrue(is_string($eventDate1));
        $this->assertGreaterThanOrEqual(20, strlen($eventDate1));
        $this->assertLessThanOrEqual(40, strlen($eventDate1));

        // Fetch and verify - MongoDB returns datetime in 'Y-m-d H:i:s.v' format
        $fetched1 = $database->getDocument($col, 'dt1');
        $fetchedEventDate1 = $fetched1->getAttribute('eventDate');
        $this->assertTrue(is_string($fetchedEventDate1));
        $this->assertEquals('Event 1', $fetched1->getAttribute('name'));

        // Verify datetime values are equivalent by parsing (MongoDB converts to UTC)
        $parsedInput1 = new \DateTime($datetime1);
        $parsedOutput1 = new \DateTime($fetchedEventDate1);
        $this->assertEquals($parsedInput1->getTimestamp(), $parsedOutput1->getTimestamp());

        $fetched2 = $database->getDocument($col, 'dt2');
        $fetchedEventDate2 = $fetched2->getAttribute('eventDate');
        $this->assertTrue(is_string($fetchedEventDate2));
        $parsedInput2 = new \DateTime($datetime2);
        $parsedOutput2 = new \DateTime($fetchedEventDate2);
        $this->assertEquals($parsedInput2->getTimestamp(), $parsedOutput2->getTimestamp());

        $fetched3 = $database->getDocument($col, 'dt3');
        $fetchedEventDate3 = $fetched3->getAttribute('eventDate');
        $this->assertTrue(is_string($fetchedEventDate3));
        // Verify it's a valid datetime string (format may vary slightly)
        $this->assertGreaterThanOrEqual(20, strlen($fetchedEventDate3));
        $this->assertLessThanOrEqual(40, strlen($fetchedEventDate3));
        $parsedInput3 = new \DateTime($datetime3);
        $parsedOutput3 = new \DateTime($fetchedEventDate3);
        // MongoDB converts to UTC, so timestamps should match
        $this->assertEquals($parsedInput3->getTimestamp(), $parsedOutput3->getTimestamp());

        // Find all datetime documents
        $allDocs = $database->find($col);
        $this->assertCount(3, $allDocs);

        // Verify all documents are present
        $allIds = array_map(fn ($doc) => $doc->getId(), $allDocs);
        $this->assertContains('dt1', $allIds);
        $this->assertContains('dt2', $allIds);
        $this->assertContains('dt3', $allIds);

        // Update datetime
        $newDatetime = '2024-12-31T23:59:59.999+00:00';
        $updated = $database->updateDocument($col, 'dt1', new Document([
            'eventDate' => $newDatetime
        ]));
        $updatedEventDate = $updated->getAttribute('eventDate');
        $this->assertTrue(is_string($updatedEventDate));
        $this->assertGreaterThanOrEqual(20, strlen($updatedEventDate));
        $this->assertLessThanOrEqual(40, strlen($updatedEventDate));

        $refetched = $database->getDocument($col, 'dt1');
        $refetchedEventDate = $refetched->getAttribute('eventDate');
        $this->assertTrue(is_string($refetchedEventDate));
        $parsedNewInput = new \DateTime($newDatetime);
        $parsedNewOutput = new \DateTime($refetchedEventDate);
        $this->assertEquals($parsedNewInput->getTimestamp(), $parsedNewOutput->getTimestamp());

        $database->deleteCollection($col);
    }

    public function testSchemalessTTLExpiry(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if (!$database->getAdapter()->getSupportForTTLIndexes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_ttl_expiry');
        $database->createCollection($col);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        // Create TTL index with 60 seconds expiry
        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_ttl_expiresAt',
                Database::INDEX_TTL,
                ['expiresAt'],
                [],
                [Database::ORDER_ASC],
                10
            )
        );

        $now = new \DateTime();
        $expiredTime = (clone $now)->modify('-10 seconds'); // Already expired
        $futureTime = (clone $now)->modify('+120 seconds'); // Will expire in 2 minutes

        // Create mixed documents: some with expiresAt, some without
        $doc1 = $database->createDocument($col, new Document([
            '$id' => 'expired_doc',
            '$permissions' => $permissions,
            'expiresAt' => $expiredTime->format(\DateTime::ATOM),
            'data' => 'This should expire',
            'type' => 'temporary'
        ]));

        $doc2 = $database->createDocument($col, new Document([
            '$id' => 'future_doc',
            '$permissions' => $permissions,
            'expiresAt' => $futureTime->format(\DateTime::ATOM),
            'data' => 'This should not expire yet',
            'type' => 'temporary'
        ]));

        $doc3 = $database->createDocument($col, new Document([
            '$id' => 'permanent_doc',
            '$permissions' => $permissions,
            'data' => 'This should never expire',
            'type' => 'permanent'
        ]));

        $doc4 = $database->createDocument($col, new Document([
            '$id' => 'another_permanent',
            '$permissions' => $permissions,
            'data' => 'This should also never expire',
            'type' => 'permanent'
        ]));

        // Verify all documents were created
        $this->assertEquals('expired_doc', $doc1->getId());
        $this->assertEquals('future_doc', $doc2->getId());
        $this->assertEquals('permanent_doc', $doc3->getId());
        $this->assertEquals('another_permanent', $doc4->getId());

        // Initial count should be 4
        $initialDocs = $database->find($col);
        $this->assertCount(4, $initialDocs);

        // Wait for TTL to expire with retry loop
        // Note: MongoDB TTL cleanup runs every 60 seconds, so we need to retry
        $maxRetries = 15; // 15 retries * 5 seconds = 75 seconds max
        $retryDelay = 5; // Wait 5 seconds between retries
        $expiredDocDeleted = false;

        for ($i = 0; $i < $maxRetries; $i++) {
            sleep($retryDelay);

            // Fetch collection to trigger TTL cleanup check
            $collection = $database->getCollection($col);
            $this->assertNotNull($collection);

            // Check if expired document is gone
            $remainingDocs = $database->find($col);
            $remainingIds = array_map(fn ($doc) => $doc->getId(), $remainingDocs);

            if (!in_array('expired_doc', $remainingIds)) {
                $expiredDocDeleted = true;
                break;
            }
        }

        // Assert that expired document was deleted
        $this->assertTrue($expiredDocDeleted, 'Expired document should have been deleted after TTL expiry');

        // After expiry, expired document should be gone
        // Documents without expiresAt should remain
        $remainingDocs = $database->find($col);
        $remainingIds = array_map(fn ($doc) => $doc->getId(), $remainingDocs);

        // The expired document should be deleted
        $this->assertNotContains('expired_doc', $remainingIds);

        // Documents without expiresAt should still exist
        $this->assertContains('permanent_doc', $remainingIds);
        $this->assertContains('another_permanent', $remainingIds);

        // Future document might still exist (depending on timing) or might be deleted
        // The key test is that permanent docs remain and expired doc is gone
        $this->assertGreaterThanOrEqual(2, count($remainingDocs)); // At least 2 permanent docs
        $this->assertLessThanOrEqual(3, count($remainingDocs)); // At most 3 (if future doc still exists)

        // Verify permanent documents are still accessible
        $permanent1 = $database->getDocument($col, 'permanent_doc');
        $this->assertFalse($permanent1->isEmpty());
        $this->assertEquals('permanent', $permanent1->getAttribute('type'));

        $permanent2 = $database->getDocument($col, 'another_permanent');
        $this->assertFalse($permanent2->isEmpty());
        $this->assertEquals('permanent', $permanent2->getAttribute('type'));

        // Verify expired document is gone
        $expired = $database->getDocument($col, 'expired_doc');
        $this->assertTrue($expired->isEmpty());

        $database->deleteCollection($col);
    }

    public function testSchemalessTTLWithCacheExpiry(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if (!$database->getAdapter()->getSupportForTTLIndexes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_ttl_cache_expiry');
        $database->createCollection($col);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        // Create TTL index with 10 seconds expiry (also used as cache TTL)
        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_ttl_expiresAt',
                Database::INDEX_TTL,
                ['expiresAt'],
                [],
                [Database::ORDER_ASC],
                10
            )
        );

        $now = new \DateTime();
        $expiredTime = (clone $now)->modify('-10 seconds'); // Already expired from TTL perspective

        $expiredDoc = $database->createDocument($col, new Document([
            '$id' => 'expired_doc',
            '$permissions' => $permissions,
            'expiresAt' => $expiredTime->format(\DateTime::ATOM),
            'data' => 'This should expire',
        ]));

        $permanentDoc = $database->createDocument($col, new Document([
            '$id' => 'permanent_doc',
            '$permissions' => $permissions,
            'data' => 'This should never expire',
        ]));

        $this->assertEquals('expired_doc', $expiredDoc->getId());
        $this->assertEquals('permanent_doc', $permanentDoc->getId());

        $expiredFetched = $database->getDocument($col, 'expired_doc');
        $this->assertTrue($expiredFetched->isEmpty(), 'Expired document should be considered expired immediately');
        $permanentFetched = $database->getDocument($col, 'permanent_doc');
        $this->assertFalse($permanentFetched->isEmpty());

        // Wait for TTL to expire with retry loop, always fetching via getDocument (uses cache)
        $maxRetries = 15; // 15 * 5s = 75 seconds max
        $retryDelay = 5;
        $expiredDocDeleted = false;

        for ($i = 0; $i < $maxRetries; $i++) {
            sleep($retryDelay);

            // Fetch collection to trigger TTL cleanup check in MongoDB
            $collection = $database->getCollection($col);
            $this->assertNotNull($collection);

            // Fetch through getDocument, which goes through the cache layer
            $expired = $database->getDocument($col, 'expired_doc');
            if ($expired->isEmpty()) {
                $expiredDocDeleted = true;
                break;
            }
        }

        // Expired document should eventually disappear when fetched through cache-aware getDocument
        $this->assertTrue($expiredDocDeleted, 'Expired document should have been deleted after TTL expiry when fetched via getDocument');

        // Permanent document should still be accessible
        $permanent = $database->getDocument($col, 'permanent_doc');
        $this->assertFalse($permanent->isEmpty());
        $this->assertEquals('This should never expire', $permanent->getAttribute('data'));

        $database->deleteCollection($col);
    }

    public function testStringAndDatetime(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_str_datetime');
        $database->createCollection($col);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        // Create documents with mix of formatted dates (ISO 8601) and non-formatted dates (regular strings)
        // Each document has two fields: str and datetime
        $docs = [
            new Document([
                '$id' => 'doc1',
                '$permissions' => $permissions,
                'str' => '2024-01-15T10:30:00.000+00:00', // ISO 8601 formatted date as string
                'datetime' => '2024-01-15T10:30:00.000+00:00' // ISO 8601 formatted date
            ]),
            new Document([
                '$id' => 'doc2',
                '$permissions' => $permissions,
                'str' => 'just a regular string', // Non-formatted string
                'datetime' => '2024-02-20T14:45:30.123Z' // ISO 8601 formatted date
            ]),
            new Document([
                '$id' => 'doc3',
                '$permissions' => $permissions,
                'str' => '2024-03-25T08:15:45.000000+05:30', // ISO 8601 formatted date as string
                'datetime' => 'not a date string' // Non-formatted string in datetime field
            ]),
            new Document([
                '$id' => 'doc4',
                '$permissions' => $permissions,
                'str' => 'another string value',
                'datetime' => '2024-12-31T23:59:59.999+00:00' // ISO 8601 formatted date
            ]),
            new Document([
                '$id' => 'doc5',
                '$permissions' => $permissions,
                'str' => '2024-06-15T12:00:00.000Z', // ISO 8601 formatted date as string
                'datetime' => '2024-06-15T12:00:00.000Z' // ISO 8601 formatted date
            ]),
        ];

        $createdCount = $database->createDocuments($col, $docs);
        $this->assertEquals(5, $createdCount);

        // Fetch all documents and validate
        $allDocs = $database->find($col);
        $this->assertCount(5, $allDocs);

        // Validate each document
        $doc1 = $database->getDocument($col, 'doc1');
        $this->assertEquals('doc1', $doc1->getId());
        $this->assertTrue(is_string($doc1->getAttribute('str')));
        $this->assertTrue(is_string($doc1->getAttribute('datetime')));
        // str field should remain as string (even if it looks like a date)
        $this->assertGreaterThanOrEqual(20, strlen($doc1->getAttribute('str')));
        $this->assertLessThanOrEqual(40, strlen($doc1->getAttribute('str')));
        // datetime field should be converted to MongoDB format if it's a valid ISO date
        $datetime1 = $doc1->getAttribute('datetime');
        $this->assertTrue(is_string($datetime1));
        $this->assertGreaterThanOrEqual(20, strlen($datetime1));
        $this->assertLessThanOrEqual(40, strlen($datetime1));
        // Verify it's a valid datetime by parsing
        $parsed1 = new \DateTime($datetime1);
        $this->assertInstanceOf(\DateTime::class, $parsed1);

        $doc2 = $database->getDocument($col, 'doc2');
        $this->assertEquals('doc2', $doc2->getId());
        $this->assertEquals('just a regular string', $doc2->getAttribute('str'));
        $datetime2 = $doc2->getAttribute('datetime');
        $this->assertTrue(is_string($datetime2));
        $parsed2 = new \DateTime($datetime2);
        $this->assertInstanceOf(\DateTime::class, $parsed2);

        $doc3 = $database->getDocument($col, 'doc3');
        $this->assertEquals('doc3', $doc3->getId());
        $str3 = $doc3->getAttribute('str');
        $this->assertTrue(is_string($str3));
        $this->assertGreaterThanOrEqual(20, strlen($str3));
        $this->assertLessThanOrEqual(40, strlen($str3));
        // datetime field contains non-date string, should remain as string
        $datetime3 = $doc3->getAttribute('datetime');
        $this->assertEquals('not a date string', $datetime3);
        $this->assertTrue(is_string($datetime3));

        $doc4 = $database->getDocument($col, 'doc4');
        $this->assertEquals('doc4', $doc4->getId());
        $this->assertEquals('another string value', $doc4->getAttribute('str'));
        $datetime4 = $doc4->getAttribute('datetime');
        $this->assertTrue(is_string($datetime4));
        $parsed4 = new \DateTime($datetime4);
        $this->assertInstanceOf(\DateTime::class, $parsed4);

        $doc5 = $database->getDocument($col, 'doc5');
        $this->assertEquals('doc5', $doc5->getId());
        $str5 = $doc5->getAttribute('str');
        $this->assertTrue(is_string($str5));
        $this->assertGreaterThanOrEqual(20, strlen($str5));
        $this->assertLessThanOrEqual(40, strlen($str5));
        $datetime5 = $doc5->getAttribute('datetime');
        $this->assertTrue(is_string($datetime5));
        $parsed5 = new \DateTime($datetime5);
        $this->assertInstanceOf(\DateTime::class, $parsed5);

        // Verify all documents are present using simple find
        $allDocs = $database->find($col);
        $this->assertCount(5, $allDocs);
        $allIds = array_map(fn ($doc) => $doc->getId(), $allDocs);
        $this->assertContains('doc1', $allIds);
        $this->assertContains('doc2', $allIds);
        $this->assertContains('doc3', $allIds);
        $this->assertContains('doc4', $allIds);
        $this->assertContains('doc5', $allIds);

        $database->deleteCollection($col);
    }

    public function testStringAndDateWithTTL(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if (!$database->getAdapter()->getSupportForTTLIndexes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_str_date_ttl');
        $database->createCollection($col);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        // Create TTL index on expiresAt field
        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_ttl_expiresAt',
                Database::INDEX_TTL,
                ['expiresAt'],
                [],
                [Database::ORDER_ASC],
                10
            )
        );

        $now = new \DateTime();
        $expiredTime = (clone $now)->modify('-10 seconds'); // Already expired
        $futureTime = (clone $now)->modify('+120 seconds'); // Will expire in 2 minutes

        // Create documents with mix of datetime values and random strings in expiresAt
        $docs = [
            new Document([
                '$id' => 'doc_datetime_expired',
                '$permissions' => $permissions,
                'expiresAt' => $expiredTime->format(\DateTime::ATOM), // Valid datetime - should expire
                'data' => 'This should expire',
                'type' => 'datetime'
            ]),
            new Document([
                '$id' => 'doc_datetime_future',
                '$permissions' => $permissions,
                'expiresAt' => $futureTime->format(\DateTime::ATOM), // Valid datetime - future
                'data' => 'This should not expire yet',
                'type' => 'datetime'
            ]),
            new Document([
                '$id' => 'doc_string_random',
                '$permissions' => $permissions,
                'expiresAt' => 'random_string_value_12345', // Random string - should not expire
                'data' => 'This should never expire',
                'type' => 'string'
            ]),
            new Document([
                '$id' => 'doc_string_another',
                '$permissions' => $permissions,
                'expiresAt' => 'another_random_string_xyz', // Random string - should not expire
                'data' => 'This should also never expire',
                'type' => 'string'
            ]),
            new Document([
                '$id' => 'doc_datetime_valid',
                '$permissions' => $permissions,
                'expiresAt' => $futureTime->format(\DateTime::ATOM), // Valid datetime - future
                'data' => 'This is a valid datetime',
                'type' => 'datetime'
            ]),
        ];

        $createdCount = $database->createDocuments($col, $docs);
        $this->assertEquals(5, $createdCount);

        // Verify all documents were created
        $initialDocs = $database->find($col);
        $this->assertCount(5, $initialDocs);

        // Verify documents with datetime values
        $docDatetimeExpired = $database->getDocument($col, 'doc_datetime_expired');
        // Document is already expired according to TTL, so it should be treated as expired (empty)
        $this->assertTrue($docDatetimeExpired->isEmpty());

        $docDatetimeFuture = $database->getDocument($col, 'doc_datetime_future');
        $this->assertFalse($docDatetimeFuture->isEmpty());
        $expiresAt2 = $docDatetimeFuture->getAttribute('expiresAt');
        $this->assertTrue(is_string($expiresAt2));
        $parsed2 = new \DateTime($expiresAt2);
        $this->assertInstanceOf(\DateTime::class, $parsed2);

        // Verify documents with random strings remain as strings
        $docStringRandom = $database->getDocument($col, 'doc_string_random');
        $this->assertFalse($docStringRandom->isEmpty());
        $expiresAt3 = $docStringRandom->getAttribute('expiresAt');
        $this->assertEquals('random_string_value_12345', $expiresAt3);
        $this->assertTrue(is_string($expiresAt3));
        // Should remain as the original string (not converted to datetime)
        $this->assertEquals('random_string_value_12345', $expiresAt3);

        $docStringAnother = $database->getDocument($col, 'doc_string_another');
        $this->assertFalse($docStringAnother->isEmpty());
        $expiresAt4 = $docStringAnother->getAttribute('expiresAt');
        $this->assertEquals('another_random_string_xyz', $expiresAt4);
        $this->assertTrue(is_string($expiresAt4));

        // Wait for the MongoDB TTL monitor to physically delete the expired datetime document.
        $maxRetries = 25;
        $retryDelay = 5;
        $expiredDocDeleted = false;

        for ($i = 0; $i < $maxRetries; $i++) {
            sleep($retryDelay);

            // Fetch collection to trigger TTL cleanup check
            $collection = $database->getCollection($col);
            $this->assertNotNull($collection);

            $remainingDocs = $database->find($col);
            $remainingIds = array_map(fn ($doc) => $doc->getId(), $remainingDocs);

            if (!in_array('doc_datetime_expired', $remainingIds)) {
                $expiredDocDeleted = true;
                break;
            }
        }

        // Assert that expired datetime document was eventually deleted by TTL monitor
        $this->assertTrue($expiredDocDeleted, 'Expired datetime document should have been deleted after TTL expiry');

        // After expiry, re-check remaining documents
        $remainingDocs = $database->find($col);
        $remainingIds = array_map(fn ($doc) => $doc->getId(), $remainingDocs);

        // The expired datetime document should be deleted from the collection
        $this->assertNotContains('doc_datetime_expired', $remainingIds);

        // Documents with random strings should still exist (TTL doesn't affect non-datetime values)
        $this->assertContains('doc_string_random', $remainingIds);
        $this->assertContains('doc_string_another', $remainingIds);

        // Verify random string documents are still accessible with original string values
        $remainingStringDoc1 = $database->getDocument($col, 'doc_string_random');
        $this->assertFalse($remainingStringDoc1->isEmpty());
        $this->assertEquals('random_string_value_12345', $remainingStringDoc1->getAttribute('expiresAt'));
        $this->assertEquals('string', $remainingStringDoc1->getAttribute('type'));

        $remainingStringDoc2 = $database->getDocument($col, 'doc_string_another');
        $this->assertFalse($remainingStringDoc2->isEmpty());
        $this->assertEquals('another_random_string_xyz', $remainingStringDoc2->getAttribute('expiresAt'));
        $this->assertEquals('string', $remainingStringDoc2->getAttribute('type'));

        // Future datetime documents might still exist or be deleted depending on timing
        // But at minimum, we should have the string documents
        $this->assertGreaterThanOrEqual(2, count($remainingDocs)); // At least 2 string docs
        $this->assertLessThanOrEqual(4, count($remainingDocs)); // At most 4 (if future datetime docs still exist)

        $database->deleteCollection($col);
    }

    public function testSchemalessMongoDotNotationIndexes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Only meaningful for schemaless adapters
        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_mongo_dot_idx');
        $database->createCollection($col);

        // Define top-level object attribute (metadata only; schemaless adapter won't enforce)
        $database->createAttribute($col, 'profile', Database::VAR_OBJECT, 0, false);

        // Seed documents
        $database->createDocuments($col, [
            new Document([
                '$id' => 'u1',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'alice@example.com',
                        'id' => 'alice'
                    ]
                ]
            ]),
            new Document([
                '$id' => 'u2',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'bob@example.com',
                        'id' => 'bob'
                    ]
                ]
            ]),
        ]);

        // Create KEY index on nested path
        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_profile_user_email_key',
                Database::INDEX_KEY,
                ['profile.user.email'],
                [0],
                [Database::ORDER_ASC]
            )
        );

        // Create UNIQUE index on nested path and verify enforcement
        $this->assertTrue(
            $database->createIndex(
                $col,
                'idx_profile_user_id_unique',
                Database::INDEX_UNIQUE,
                ['profile.user.id'],
                [0],
                [Database::ORDER_ASC]
            )
        );

        try {
            $database->createDocument($col, new Document([
                '$id' => 'u3',
                '$permissions' => [Permission::read(Role::any())],
                'profile' => [
                    'user' => [
                        'email' => 'eve@example.com',
                        'id' => 'alice' // duplicate unique nested id
                    ]
                ]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }

        // Validate dot-notation querying works (and is the shape that can use indexes)
        $results = $database->find($col, [
            Query::equal('profile.user.email', ['bob@example.com'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('u2', $results[0]->getId());

        $database->deleteCollection($col);
    }

    public function testQueryWithDatetime(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = uniqid('sl_query_datetime');
        $database->createCollection($col);

        $permissions = [
            Permission::read(Role::any()),
            Permission::write(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ];

        // Documents with datetime field (ISO 8601) for query tests
        // Dates: Jan 15 2024, Feb 20 2024, Mar 25 2024, Jun 15 2024, Dec 31 2024
        $docs = [
            new Document([
                '$id' => 'dt1',
                '$permissions' => $permissions,
                'name' => 'January',
                'datetime' => '2024-01-15T10:30:00.000+00:00'
            ]),
            new Document([
                '$id' => 'dt2',
                '$permissions' => $permissions,
                'name' => 'February',
                'datetime' => '2024-02-20T14:45:30.123Z'
            ]),
            new Document([
                '$id' => 'dt3',
                '$permissions' => $permissions,
                'name' => 'March',
                // Use a valid extended ISO 8601 datetime that will be normalized
                // to MongoDB UTCDateTime for comparison queries.
                'datetime' => '2024-03-25T08:15:45.000+00:00'
            ]),
            new Document([
                '$id' => 'dt4',
                '$permissions' => $permissions,
                'name' => 'June',
                'datetime' => '2024-06-15T12:00:00.000Z'
            ]),
            new Document([
                '$id' => 'dt5',
                '$permissions' => $permissions,
                'name' => 'December',
                'datetime' => '2024-12-31T23:59:59.999+00:00'
            ]),
        ];

        $createdCount = $database->createDocuments($col, $docs);
        $this->assertEquals(5, $createdCount);

        // Query: equal - find document with exact datetime (Jan 15 2024)
        $equalResults = $database->find($col, [
            Query::equal('datetime', ['2024-01-15T10:30:00.000+00:00'])
        ]);
        $this->assertCount(1, $equalResults);
        $this->assertEquals('dt1', $equalResults[0]->getId());
        $this->assertEquals('January', $equalResults[0]->getAttribute('name'));

        // Query: greaterThan - datetimes after 2024-03-01 (dt3, dt4, dt5)
        $greaterResults = $database->find($col, [
            Query::greaterThan('datetime', '2024-03-01T00:00:00.000Z')
        ]);
        $this->assertCount(3, $greaterResults);
        $greaterIds = array_map(fn ($d) => $d->getId(), $greaterResults);
        $this->assertContains('dt3', $greaterIds);
        $this->assertContains('dt4', $greaterIds);
        $this->assertContains('dt5', $greaterIds);

        // Query: lessThan - datetimes before 2024-03-01 (dt1, dt2)
        $lessResults = $database->find($col, [
            Query::lessThan('datetime', '2024-03-01T00:00:00.000Z')
        ]);
        $this->assertCount(2, $lessResults);
        $lessIds = array_map(fn ($d) => $d->getId(), $lessResults);
        $this->assertContains('dt1', $lessIds);
        $this->assertContains('dt2', $lessIds);

        // Query: greaterThanEqual - datetimes on or after 2024-02-20 (dt2, dt3, dt4, dt5)
        $gteResults = $database->find($col, [
            Query::greaterThanEqual('datetime', '2024-02-20T14:45:30.123Z')
        ]);
        $this->assertCount(4, $gteResults);
        $gteIds = array_map(fn ($d) => $d->getId(), $gteResults);
        $this->assertContains('dt2', $gteIds);
        $this->assertContains('dt3', $gteIds);
        $this->assertContains('dt4', $gteIds);
        $this->assertContains('dt5', $gteIds);

        // Query: lessThanEqual - datetimes on or before 2024-06-15 (dt1, dt2, dt3, dt4)
        $lteResults = $database->find($col, [
            Query::lessThanEqual('datetime', '2024-06-15T12:00:00.000Z')
        ]);
        $this->assertCount(4, $lteResults);
        $lteIds = array_map(fn ($d) => $d->getId(), $lteResults);
        $this->assertContains('dt1', $lteIds);
        $this->assertContains('dt2', $lteIds);
        $this->assertContains('dt3', $lteIds);
        $this->assertContains('dt4', $lteIds);

        // Query: between - datetimes in range [2024-02-01, 2024-07-01) (dt2, dt3, dt4)
        $betweenResults = $database->find($col, [
            Query::between('datetime', '2024-02-01T00:00:00.000Z', '2024-07-01T00:00:00.000Z')
        ]);
        $this->assertCount(3, $betweenResults);
        $betweenIds = array_map(fn ($d) => $d->getId(), $betweenResults);
        $this->assertContains('dt2', $betweenIds);
        $this->assertContains('dt3', $betweenIds);
        $this->assertContains('dt4', $betweenIds);

        // Query: equal with no match
        $noneResults = $database->find($col, [
            Query::equal('datetime', ['2020-01-01T00:00:00.000Z'])
        ]);
        $this->assertCount(0, $noneResults);

        $database->deleteCollection($col);
    }

    public function testSchemalessCreatedAndUpdatedAtQuery(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create a simple schemaless collection and one document.
        $database->createCollection('schemaless_time', permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ]);

        $database->createDocument('schemaless_time', new Document([
            '$id' => ID::unique(),
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Schemaless Movie',
        ]));

        $futureDate = '2050-01-01T00:00:00.000Z';
        $pastDate = '1900-01-01T00:00:00.000Z';
        $recentPastDate = '2020-01-01T00:00:00.000Z';
        $nearFutureDate = '2025-01-01T00:00:00.000Z';

        // --- createdBefore ---
        $documents = $database->find('schemaless_time', [
            Query::createdBefore($futureDate),
            Query::limit(1),
        ]);
        $this->assertGreaterThan(0, count($documents));

        $documents = $database->find('schemaless_time', [
            Query::createdBefore($pastDate),
            Query::limit(1),
        ]);
        $this->assertEquals(0, count($documents));

        // --- createdAfter ---
        $documents = $database->find('schemaless_time', [
            Query::createdAfter($pastDate),
            Query::limit(1),
        ]);
        $this->assertGreaterThan(0, count($documents));

        $documents = $database->find('schemaless_time', [
            Query::createdAfter($futureDate),
            Query::limit(1),
        ]);
        $this->assertEquals(0, count($documents));

        // --- updatedBefore ---
        $documents = $database->find('schemaless_time', [
            Query::updatedBefore($futureDate),
            Query::limit(1),
        ]);
        $this->assertGreaterThan(0, count($documents));

        $documents = $database->find('schemaless_time', [
            Query::updatedBefore($pastDate),
            Query::limit(1),
        ]);
        $this->assertEquals(0, count($documents));

        // --- updatedAfter ---
        $documents = $database->find('schemaless_time', [
            Query::updatedAfter($pastDate),
            Query::limit(1),
        ]);
        $this->assertGreaterThan(0, count($documents));

        $documents = $database->find('schemaless_time', [
            Query::updatedAfter($futureDate),
            Query::limit(1),
        ]);
        $this->assertEquals(0, count($documents));

        // --- createdBetween ---
        $documents = $database->find('schemaless_time', [
            Query::createdBetween($pastDate, $futureDate),
            Query::limit(25),
        ]);
        $this->assertGreaterThan(0, count($documents));

        $documents = $database->find('schemaless_time', [
            Query::createdBetween($pastDate, $pastDate),
            Query::limit(25),
        ]);
        $this->assertEquals(0, count($documents));

        $documents = $database->find('schemaless_time', [
            Query::createdBetween($recentPastDate, $nearFutureDate),
            Query::limit(25),
        ]);
        $count = count($documents);

        $documents = $database->find('schemaless_time', [
            Query::createdBetween($pastDate, $nearFutureDate),
            Query::limit(25),
        ]);
        $this->assertGreaterThanOrEqual($count, count($documents));

        // --- updatedBetween ---
        $documents = $database->find('schemaless_time', [
            Query::updatedBetween($pastDate, $futureDate),
            Query::limit(25),
        ]);
        $this->assertGreaterThan(0, count($documents));

        $documents = $database->find('schemaless_time', [
            Query::updatedBetween($pastDate, $pastDate),
            Query::limit(25),
        ]);
        $this->assertEquals(0, count($documents));

        $documents = $database->find('schemaless_time', [
            Query::updatedBetween($recentPastDate, $nearFutureDate),
            Query::limit(25),
        ]);
        $count = count($documents);

        $documents = $database->find('schemaless_time', [
            Query::updatedBetween($pastDate, $nearFutureDate),
            Query::limit(25),
        ]);
        $this->assertGreaterThanOrEqual($count, count($documents));

        $database->deleteCollection('schemaless_time');
    }
}
