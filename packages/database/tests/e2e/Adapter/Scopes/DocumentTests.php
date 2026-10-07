<?php

namespace Tests\E2E\Adapter\Scopes;

use Exception;
use PDOException;
use Throwable;
use Utopia\Database\Adapter\SQL;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Character as CharacterException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Exception\Type as TypeException;
use Utopia\Database\Exception\Unique as UniqueException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait DocumentTests
{
    public function testNonUtfChars(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportNonUtfCharacters()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);
        $this->assertEquals(true, $database->createAttribute(__FUNCTION__, 'title', Database::VAR_STRING, 128, true));

        $nonUtfString = "Hello\x00World\xC3\x28\xFF\xFE\xA0Test\x00End";

        try {
            $database->createDocument(__FUNCTION__, new Document([
                'title' => $nonUtfString,
            ]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertTrue($e instanceof CharacterException);
        }

        /**
         * Convert to UTF-8 and replace invalid bytes with empty string
         */
        $nonUtfString = mb_convert_encoding($nonUtfString, 'UTF-8', 'UTF-8');

        /**
         * Remove null bytes
         */
        $nonUtfString = str_replace("\0", '', $nonUtfString);

        $document = $database->createDocument(__FUNCTION__, new Document([
            'title' => $nonUtfString,
        ]));

        $this->assertFalse($document->isEmpty());
        $this->assertEquals('HelloWorld?(???TestEnd', $document->getAttribute('title'));
    }

    public function testBigintSequence(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection(__FUNCTION__);

        $sequence = 5_000_000_000_000_000;
        if ($database->getAdapter()->getIdAttributeType() == Database::VAR_UUID7) {
            $sequence = '01995753-881b-78cf-9506-2cffecf8f227';
        }

        $document = $database->createDocument(__FUNCTION__, new Document([
            '$sequence' => (string)$sequence,
            '$permissions' => [
                Permission::read(Role::any()),
            ],
        ]));

        $this->assertSame((string)$sequence, $document->getSequence());

        $document = $database->getDocument(__FUNCTION__, $document->getId());
        $this->assertSame((string)$sequence, $document->getSequence());

        $document = $database->findOne(__FUNCTION__, [Query::equal('$sequence', [(string)$sequence])]);
        $this->assertSame((string)$sequence, $document->getSequence());

        /**
         * Query with int $sequence value (supported by SQL adapters, rejected by MongoDB)
         */
        if ($database->getAdapter()->getIdAttributeType() == Database::VAR_INTEGER) {
            $this->assertTrue($sequence === 5_000_000_000_000_000);
            $document = $database->findOne(__FUNCTION__, [Query::equal('$sequence', [$sequence])]);
            $this->assertSame((string)$sequence, $document->getSequence());
        }
    }

    public function testCreateDocumentWithBigIntType(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection(__FUNCTION__);
        $this->assertEquals(true, $database->createAttribute(__FUNCTION__, 'bigint_signed', Database::VAR_BIGINT, 0, true));
        $this->assertEquals(true, $database->createAttribute(__FUNCTION__, 'bigint_unsigned', Database::VAR_BIGINT, 0, true, signed: false));

        $document = $database->createDocument(__FUNCTION__, new Document([
            '$id' => 'bigint-type-doc',
            '$permissions' => [Permission::read(Role::any())],
            'bigint_signed' => -Database::MAX_BIG_INT,
            'bigint_unsigned' => Database::MAX_BIG_INT,
        ]));

        $this->assertIsInt($document->getAttribute('bigint_signed'));
        $this->assertEquals(-Database::MAX_BIG_INT, $document->getAttribute('bigint_signed'));
        $this->assertIsInt($document->getAttribute('bigint_unsigned'));
        $this->assertEquals(Database::MAX_BIG_INT, $document->getAttribute('bigint_unsigned'));

        $results = $database->find(__FUNCTION__, [
            Query::equal('bigint_unsigned', [Database::MAX_BIG_INT])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('bigint-type-doc', $results[0]->getId());
    }

    public function testBigIntScenariosWithFiltering(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUnsignedBigInt()) {
            $this->markTestSkipped('Adapter does not support unsigned bigint');
        }

        $collection = 'bigint_scenarios_filters';
        $database->createCollection($collection);
        $this->assertEquals(true, $database->createAttribute($collection, 'signed_bigint', Database::VAR_BIGINT, 0, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'unsigned_bigint', Database::VAR_BIGINT, 0, true, signed: false));

        $collectionDoc = $database->getCollection($collection);
        $this->assertEquals($collection, $collectionDoc->getId());
        $attributes = $collectionDoc->getAttribute('attributes', []);
        $signedAttr = null;
        $unsignedAttr = null;
        foreach ($attributes as $attribute) {
            if (($attribute->getAttribute('$id') ?? '') === 'signed_bigint') {
                $signedAttr = $attribute;
            }
            if (($attribute->getAttribute('$id') ?? '') === 'unsigned_bigint') {
                $unsignedAttr = $attribute;
            }
        }

        $this->assertNotNull($signedAttr);
        $this->assertNotNull($unsignedAttr);
        $this->assertSame(0, $signedAttr->getAttribute('size'));
        $this->assertSame(0, $unsignedAttr->getAttribute('size'));

        // "Out of regular int limit" (32-bit) but valid bigint should still normalize to PHP int.
        $beyond32Bit = '2147483648';
        $signedMax = (string)\PHP_INT_MAX;
        $signedMin = (string)\PHP_INT_MIN;
        $unsignedValue = '18446744073709551615';

        $document = $database->createDocument($collection, new Document([
            '$id' => 'bigint-scenarios-doc',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'signed_bigint' => $beyond32Bit,
            'unsigned_bigint' => $unsignedValue,
        ]));

        $this->assertIsInt($document->getAttribute('signed_bigint'));
        $this->assertEquals((int)$beyond32Bit, $document->getAttribute('signed_bigint'));

        // Compare by string representation to stay adapter-agnostic (int/string return type differs).
        $this->assertEquals($unsignedValue, (string)$document->getAttribute('unsigned_bigint'));
        $this->assertTrue(\is_string($document->getAttribute('unsigned_bigint')));

        // Read path: fetch document and ensure unsigned bigint round-trips unchanged.
        $fetchedDocument = $database->getDocument($collection, $document->getId());
        $this->assertEquals($unsignedValue, (string)$fetchedDocument->getAttribute('unsigned_bigint'));

        // Update path should apply the same normalization for signed bigint numeric strings.
        $updated = $database->updateDocument($collection, $document->getId(), new Document([
            'signed_bigint' => $signedMax,
        ]));
        $this->assertIsInt($updated->getAttribute('signed_bigint'));
        $this->assertEquals((int)$signedMax, $updated->getAttribute('signed_bigint'));

        // Filtering tests: both int and numeric-string filters should match bigint fields.
        $resultIntFilter = $database->find($collection, [
            Query::equal('signed_bigint', [(int)$signedMax]),
        ]);
        $this->assertCount(1, $resultIntFilter);
        $this->assertEquals('bigint-scenarios-doc', $resultIntFilter[0]->getId());

        $resultStringFilter = $database->find($collection, [
            Query::equal('signed_bigint', [$signedMax]),
        ]);
        $this->assertCount(1, $resultStringFilter);
        $this->assertEquals('bigint-scenarios-doc', $resultStringFilter[0]->getId());

        $resultUnsignedFilter = $database->find($collection, [
            Query::equal('unsigned_bigint', [$unsignedValue]),
        ]);
        $this->assertCount(1, $resultUnsignedFilter);
        $this->assertEquals('bigint-scenarios-doc', $resultUnsignedFilter[0]->getId());

        // Lower signed boundary as numeric-string should also normalize to int.
        $updatedMin = $database->updateDocument($collection, $document->getId(), new Document([
            'signed_bigint' => $signedMin,
        ]));
        $this->assertIsInt($updatedMin->getAttribute('signed_bigint'));
        $this->assertEquals((int)$signedMin, $updatedMin->getAttribute('signed_bigint'));
    }

    public function testWithSingedBigInt(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collection = 'signed_bigint_only';
        $database->createCollection($collection);
        $this->assertEquals(true, $database->createAttribute($collection, 'signed_bigint', Database::VAR_BIGINT, 0, true));

        $signedMin = \PHP_INT_MIN;
        $signedMax = \PHP_INT_MAX;

        $document = $database->createDocument($collection, new Document([
            '$id' => 'signed-bigint-doc',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'signed_bigint' => $signedMax,
        ]));

        $this->assertIsInt($document->getAttribute('signed_bigint'));
        $this->assertEquals((int)$signedMax, $document->getAttribute('signed_bigint'));

        $updated = $database->updateDocument($collection, $document->getId(), new Document([
            'signed_bigint' => $signedMin,
        ]));

        $this->assertIsInt($updated->getAttribute('signed_bigint'));
        $this->assertEquals((int)$signedMin, $updated->getAttribute('signed_bigint'));

        $results = $database->find($collection, [
            Query::equal('signed_bigint', [$signedMin]),
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('signed-bigint-doc', $results[0]->getId());
    }

    public function testCreateDocument(): Document
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('documents');

        $this->assertEquals(true, $database->createAttribute('documents', 'string', Database::VAR_STRING, 128, true));
        $this->assertEquals(true, $database->createAttribute('documents', 'integer_signed', Database::VAR_INTEGER, 0, true));
        $this->assertEquals(true, $database->createAttribute('documents', 'integer_unsigned', Database::VAR_INTEGER, 4, true, signed: false));
        $this->assertEquals(true, $database->createAttribute('documents', 'bigint_signed', Database::VAR_INTEGER, 8, true));
        $this->assertEquals(true, $database->createAttribute('documents', 'bigint_unsigned', Database::VAR_INTEGER, 9, true, signed: false));
        $this->assertEquals(true, $database->createAttribute('documents', 'float_signed', Database::VAR_FLOAT, 0, true));
        $this->assertEquals(true, $database->createAttribute('documents', 'float_unsigned', Database::VAR_FLOAT, 0, true, signed: false));
        $this->assertEquals(true, $database->createAttribute('documents', 'boolean', Database::VAR_BOOLEAN, 0, true));
        $this->assertEquals(true, $database->createAttribute('documents', 'colors', Database::VAR_STRING, 32, true, null, true, true));
        $this->assertEquals(true, $database->createAttribute('documents', 'empty', Database::VAR_STRING, 32, false, null, true, true));
        $this->assertEquals(true, $database->createAttribute('documents', 'with-dash', Database::VAR_STRING, 128, false, null));
        $this->assertEquals(true, $database->createAttribute('documents', 'id', Database::VAR_ID, 0, false, null));

        $sequence = '1000000';
        if ($database->getAdapter()->getIdAttributeType() == Database::VAR_UUID7) {
            $sequence = '01890dd5-7331-7f3a-9c1b-123456789abc' ;
        }

        $document = $database->createDocument('documents', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::read(Role::user(ID::custom('1'))),
                Permission::read(Role::user(ID::custom('2'))),
                Permission::create(Role::any()),
                Permission::create(Role::user(ID::custom('1x'))),
                Permission::create(Role::user(ID::custom('2x'))),
                Permission::update(Role::any()),
                Permission::update(Role::user(ID::custom('1x'))),
                Permission::update(Role::user(ID::custom('2x'))),
                Permission::delete(Role::any()),
                Permission::delete(Role::user(ID::custom('1x'))),
                Permission::delete(Role::user(ID::custom('2x'))),
            ],
            'string' => 'text📝',
            'integer_signed' => -Database::MAX_INT,
            'integer_unsigned' => Database::MAX_INT,
            'bigint_signed' => -Database::MAX_BIG_INT,
            'bigint_unsigned' => Database::MAX_BIG_INT,
            'float_signed' => -5.55,
            'float_unsigned' => 5.55,
            'boolean' => true,
            'colors' => ['pink', 'green', 'blue'],
            'empty' => [],
            'with-dash' => 'Works',
            'id' => $sequence,
        ]));

        $this->assertNotEmpty($document->getId());
        $this->assertIsString($document->getAttribute('string'));
        $this->assertEquals('text📝', $document->getAttribute('string')); // Also makes sure an emoji is working
        $this->assertIsInt($document->getAttribute('integer_signed'));
        $this->assertEquals(-Database::MAX_INT, $document->getAttribute('integer_signed'));
        $this->assertIsInt($document->getAttribute('integer_unsigned'));
        $this->assertEquals(Database::MAX_INT, $document->getAttribute('integer_unsigned'));
        $this->assertIsInt($document->getAttribute('bigint_signed'));
        $this->assertEquals(-Database::MAX_BIG_INT, $document->getAttribute('bigint_signed'));
        $this->assertIsInt($document->getAttribute('bigint_signed'));
        $this->assertEquals(Database::MAX_BIG_INT, $document->getAttribute('bigint_unsigned'));
        $this->assertIsFloat($document->getAttribute('float_signed'));
        $this->assertEquals(-5.55, $document->getAttribute('float_signed'));
        $this->assertIsFloat($document->getAttribute('float_unsigned'));
        $this->assertEquals(5.55, $document->getAttribute('float_unsigned'));
        $this->assertIsBool($document->getAttribute('boolean'));
        $this->assertEquals(true, $document->getAttribute('boolean'));
        $this->assertIsArray($document->getAttribute('colors'));
        $this->assertEquals(['pink', 'green', 'blue'], $document->getAttribute('colors'));
        $this->assertEquals([], $document->getAttribute('empty'));
        $this->assertEquals('Works', $document->getAttribute('with-dash'));
        $this->assertIsString($document->getAttribute('id'));
        $this->assertEquals($sequence, $document->getAttribute('id'));


        $sequence = '56000';
        if ($database->getAdapter()->getIdAttributeType() == Database::VAR_UUID7) {
            $sequence = '01890dd5-7331-7f3a-9c1b-123456789def' ;
        }

        // Test create document with manual internal id
        $manualIdDocument = $database->createDocument('documents', new Document([
            '$id' => '56000',
            '$sequence' => $sequence,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::read(Role::user(ID::custom('1'))),
                Permission::read(Role::user(ID::custom('2'))),
                Permission::create(Role::any()),
                Permission::create(Role::user(ID::custom('1x'))),
                Permission::create(Role::user(ID::custom('2x'))),
                Permission::update(Role::any()),
                Permission::update(Role::user(ID::custom('1x'))),
                Permission::update(Role::user(ID::custom('2x'))),
                Permission::delete(Role::any()),
                Permission::delete(Role::user(ID::custom('1x'))),
                Permission::delete(Role::user(ID::custom('2x'))),
            ],
            'string' => 'text📝',
            'integer_signed' => -Database::MAX_INT,
            'integer_unsigned' => Database::MAX_INT,
            'bigint_signed' => -Database::MAX_BIG_INT,
            'bigint_unsigned' => Database::MAX_BIG_INT,
            'float_signed' => -5.55,
            'float_unsigned' => 5.55,
            'boolean' => true,
            'colors' => ['pink', 'green', 'blue'],
            'empty' => [],
            'with-dash' => 'Works',
        ]));

        $this->assertEquals($sequence, $manualIdDocument->getSequence());
        $this->assertNotEmpty($manualIdDocument->getId());
        $this->assertIsString($manualIdDocument->getAttribute('string'));
        $this->assertEquals('text📝', $manualIdDocument->getAttribute('string')); // Also makes sure an emoji is working
        $this->assertIsInt($manualIdDocument->getAttribute('integer_signed'));
        $this->assertEquals(-Database::MAX_INT, $manualIdDocument->getAttribute('integer_signed'));
        $this->assertIsInt($manualIdDocument->getAttribute('integer_unsigned'));
        $this->assertEquals(Database::MAX_INT, $manualIdDocument->getAttribute('integer_unsigned'));
        $this->assertIsInt($manualIdDocument->getAttribute('bigint_signed'));
        $this->assertEquals(-Database::MAX_BIG_INT, $manualIdDocument->getAttribute('bigint_signed'));
        $this->assertIsInt($manualIdDocument->getAttribute('bigint_unsigned'));
        $this->assertEquals(Database::MAX_BIG_INT, $manualIdDocument->getAttribute('bigint_unsigned'));
        $this->assertIsFloat($manualIdDocument->getAttribute('float_signed'));
        $this->assertEquals(-5.55, $manualIdDocument->getAttribute('float_signed'));
        $this->assertIsFloat($manualIdDocument->getAttribute('float_unsigned'));
        $this->assertEquals(5.55, $manualIdDocument->getAttribute('float_unsigned'));
        $this->assertIsBool($manualIdDocument->getAttribute('boolean'));
        $this->assertEquals(true, $manualIdDocument->getAttribute('boolean'));
        $this->assertIsArray($manualIdDocument->getAttribute('colors'));
        $this->assertEquals(['pink', 'green', 'blue'], $manualIdDocument->getAttribute('colors'));
        $this->assertEquals([], $manualIdDocument->getAttribute('empty'));
        $this->assertEquals('Works', $manualIdDocument->getAttribute('with-dash'));
        $this->assertEquals(null, $manualIdDocument->getAttribute('id'));

        $manualIdDocument = $database->getDocument('documents', '56000');

        $this->assertEquals($sequence, $manualIdDocument->getSequence());
        $this->assertNotEmpty($manualIdDocument->getId());
        $this->assertIsString($manualIdDocument->getAttribute('string'));
        $this->assertEquals('text📝', $manualIdDocument->getAttribute('string')); // Also makes sure an emoji is working
        $this->assertIsInt($manualIdDocument->getAttribute('integer_signed'));
        $this->assertEquals(-Database::MAX_INT, $manualIdDocument->getAttribute('integer_signed'));
        $this->assertIsInt($manualIdDocument->getAttribute('integer_unsigned'));
        $this->assertEquals(Database::MAX_INT, $manualIdDocument->getAttribute('integer_unsigned'));
        $this->assertIsInt($manualIdDocument->getAttribute('bigint_signed'));
        $this->assertEquals(-Database::MAX_BIG_INT, $manualIdDocument->getAttribute('bigint_signed'));
        $this->assertIsInt($manualIdDocument->getAttribute('bigint_unsigned'));
        $this->assertEquals(Database::MAX_BIG_INT, $manualIdDocument->getAttribute('bigint_unsigned'));
        $this->assertIsFloat($manualIdDocument->getAttribute('float_signed'));
        $this->assertEquals(-5.55, $manualIdDocument->getAttribute('float_signed'));
        $this->assertIsFloat($manualIdDocument->getAttribute('float_unsigned'));
        $this->assertEquals(5.55, $manualIdDocument->getAttribute('float_unsigned'));
        $this->assertIsBool($manualIdDocument->getAttribute('boolean'));
        $this->assertEquals(true, $manualIdDocument->getAttribute('boolean'));
        $this->assertIsArray($manualIdDocument->getAttribute('colors'));
        $this->assertEquals(['pink', 'green', 'blue'], $manualIdDocument->getAttribute('colors'));
        $this->assertEquals([], $manualIdDocument->getAttribute('empty'));
        $this->assertEquals('Works', $manualIdDocument->getAttribute('with-dash'));

        try {
            $database->createDocument('documents', new Document([
                'string' => '',
                'integer_signed' => 0,
                'integer_unsigned' => 0,
                'bigint_signed' => 0,
                'bigint_unsigned' => 0,
                'float_signed' => 0,
                'float_unsigned' => -5.55,
                'boolean' => true,
                'colors' => [],
                'empty' => [],
            ]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertTrue($e instanceof StructureException);
                $this->assertStringContainsString('Invalid document structure: Attribute "float_unsigned" has invalid type. Value must be a valid range between 0 and', $e->getMessage());
            }
        }

        try {
            $database->createDocument('documents', new Document([
                'string' => '',
                'integer_signed' => 0,
                'integer_unsigned' => 0,
                'bigint_signed' => 0,
                'bigint_unsigned' => -10,
                'float_signed' => 0,
                'float_unsigned' => 0,
                'boolean' => true,
                'colors' => [],
                'empty' => [],
            ]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertTrue($e instanceof StructureException);
                $this->assertEquals('Invalid document structure: Attribute "bigint_unsigned" has invalid type. Value must be a valid range between 0 and 9,223,372,036,854,775,807', $e->getMessage());
            }
        }

        try {
            $database->createDocument('documents', new Document([
                '$sequence' => '0',
                '$permissions' => [],
                'string' => '',
                'integer_signed' => 1,
                'integer_unsigned' => 1,
                'bigint_signed' => 1,
                'bigint_unsigned' => 1,
                'float_signed' => 1,
                'float_unsigned' => 1,
                'boolean' => true,
                'colors' => [],
                'empty' => [],
                'with-dash' => '',
            ]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertTrue($e instanceof StructureException);
                $this->assertEquals('Invalid document structure: Attribute "$sequence" has invalid type. Invalid sequence value', $e->getMessage());
            }
        }

        /**
         * Insert ID attribute with NULL
         */

        $documentIdNull = $database->createDocument('documents', new Document([
            'id' => null,
            '$permissions' => [Permission::read(Role::any())],
            'string' => '',
            'integer_signed' => 1,
            'integer_unsigned' => 1,
            'bigint_signed' => 1,
            'bigint_unsigned' => 1,
            'float_signed' => 1,
            'float_unsigned' => 1,
            'boolean' => true,
            'colors' => [],
            'empty' => [],
            'with-dash' => '',
        ]));
        $this->assertNotEmpty($documentIdNull->getSequence());
        $this->assertNull($documentIdNull->getAttribute('id'));

        $documentIdNull = $database->getDocument('documents', $documentIdNull->getId());
        $this->assertNotEmpty($documentIdNull->getId());
        $this->assertNull($documentIdNull->getAttribute('id'));

        $documentIdNull = $database->findOne('documents', [
            query::isNull('id')
        ]);
        $this->assertNotEmpty($documentIdNull->getId());
        $this->assertNull($documentIdNull->getAttribute('id'));

        $sequence = '0';
        if ($database->getAdapter()->getIdAttributeType() == Database::VAR_UUID7) {
            $sequence = '01890dd5-7331-7f3a-9c1b-123456789abc';
        }

        /**
         * Insert ID attribute with '0'
         */
        $documentId0 = $database->createDocument('documents', new Document([
            'id' => $sequence,
            '$permissions' => [Permission::read(Role::any())],
            'string' => '',
            'integer_signed' => 1,
            'integer_unsigned' => 1,
            'bigint_signed' => 1,
            'bigint_unsigned' => 1,
            'float_signed' => 1,
            'float_unsigned' => 1,
            'boolean' => true,
            'colors' => [],
            'empty' => [],
            'with-dash' => '',
        ]));
        $this->assertNotEmpty($documentId0->getSequence());

        $this->assertIsString($documentId0->getAttribute('id'));
        $this->assertEquals($sequence, $documentId0->getAttribute('id'));

        $documentId0 = $database->getDocument('documents', $documentId0->getId());
        $this->assertNotEmpty($documentId0->getSequence());
        $this->assertIsString($documentId0->getAttribute('id'));
        $this->assertEquals($sequence, $documentId0->getAttribute('id'));

        $documentId0 = $database->findOne('documents', [
            query::equal('id', [$sequence])
        ]);
        $this->assertNotEmpty($documentId0->getSequence());
        $this->assertIsString($documentId0->getAttribute('id'));
        $this->assertEquals($sequence, $documentId0->getAttribute('id'));


        return $document;
    }

    public function testCreateDocumentNumericalId(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('numericalIds');

        $this->assertEquals(true, $database->createAttribute('numericalIds', 'name', Database::VAR_STRING, 128, true));

        // Test creating a document with an entirely numerical ID
        $numericalIdDocument = $database->createDocument('numericalIds', new Document([
            '$id' => '123456789',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Test Document with Numerical ID',
        ]));

        $this->assertIsString($numericalIdDocument->getId());
        $this->assertEquals('123456789', $numericalIdDocument->getId());
        $this->assertEquals('Test Document with Numerical ID', $numericalIdDocument->getAttribute('name'));

        // Verify we can retrieve the document
        $retrievedDocument = $database->getDocument('numericalIds', '123456789');
        $this->assertIsString($retrievedDocument->getId());
        $this->assertEquals('123456789', $retrievedDocument->getId());
        $this->assertEquals('Test Document with Numerical ID', $retrievedDocument->getAttribute('name'));
    }

    public function testCreateDocuments(): void
    {
        $count = 3;
        $collection = 'testCreateDocuments';

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection($collection);

        $this->assertEquals(true, $database->createAttribute($collection, 'string', Database::VAR_STRING, 128, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'integer', Database::VAR_INTEGER, 0, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'bigint', Database::VAR_INTEGER, 8, true));

        // Create an array of documents with random attributes. Don't use the createDocument function
        $documents = [];

        for ($i = 0; $i < $count; $i++) {
            $documents[] = new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'string' => 'text📝',
                'integer' => 5,
                'bigint' => Database::MAX_BIG_INT,
            ]);
        }

        $results = [];

        $count = $database->createDocuments($collection, $documents, 3, onNext: function ($doc) use (&$results) {
            $results[] = $doc;
        });

        $this->assertEquals($count, \count($results));

        foreach ($results as $document) {
            $this->assertNotEmpty($document->getId());
            $this->assertIsString($document->getAttribute('string'));
            $this->assertEquals('text📝', $document->getAttribute('string')); // Also makes sure an emoji is working
            $this->assertIsInt($document->getAttribute('integer'));
            $this->assertEquals(5, $document->getAttribute('integer'));
            $this->assertIsInt($document->getAttribute('bigint'));
            $this->assertEquals(9223372036854775807, $document->getAttribute('bigint'));

            // The insert does not return the sequence for the rows it wrote, so it is looked
            // up afterwards. $onNext is the only way these documents reach the caller.
            $this->assertNotEmpty($document->getSequence());
            $this->assertEquals(
                $database->getDocument($collection, $document->getId())->getSequence(),
                $document->getSequence()
            );
        }

        $documents = $database->find($collection, [
            Query::orderAsc()
        ]);

        $this->assertEquals($count, \count($documents));

        foreach ($documents as $document) {
            $this->assertNotEmpty($document->getId());
            $this->assertIsString($document->getAttribute('string'));
            $this->assertEquals('text📝', $document->getAttribute('string')); // Also makes sure an emoji is working
            $this->assertIsInt($document->getAttribute('integer'));
            $this->assertEquals(5, $document->getAttribute('integer'));
            $this->assertIsInt($document->getAttribute('bigint'));
            $this->assertEquals(9223372036854775807, $document->getAttribute('bigint'));
        }
    }

    public function testCacheEmptyDocument(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $cache = $database->getCache();

        // The Redis adapter runs with a no-op cache (reads hit Redis directly),
        // so there is no cache layer to inspect.
        if (!$database->getAdapter()->getSupportForCaching()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'cacheEmpty';
        $database->createCollection($collection, permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ], documentSecurity: false);
        $this->assertEquals(true, $database->createAttribute($collection, 'name', Database::VAR_STRING, 128, false));

        // A read of a missing id records a negative ("not found") marker so
        // repeated lookups don't keep hitting the adapter.
        $this->assertTrue($database->getDocument($collection, 'ghost')->isEmpty());

        [, $documentKey, $hashKey] = $database->getCacheKeys($collection, 'ghost');
        $cached = $cache->load($documentKey, Database::TTL, $hashKey);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('$empty', $cached); // Database::CACHE_EMPTY_MARKER

        // Creating the id must invalidate that marker so the row is visible.
        $database->createDocument($collection, new Document([
            '$id' => 'ghost',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'real',
        ]));

        $this->assertFalse($cache->load($documentKey, Database::TTL, $hashKey));

        $document = $database->getDocument($collection, 'ghost');
        $this->assertFalse($document->isEmpty());
        $this->assertEquals('real', $document->getAttribute('name'));

        // Same guarantee through the batch create path.
        $this->assertTrue($database->getDocument($collection, 'batch')->isEmpty());
        [, $batchKey, $batchHash] = $database->getCacheKeys($collection, 'batch');
        $cached = $cache->load($batchKey, Database::TTL, $batchHash);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('$empty', $cached);

        $database->createDocuments($collection, [
            new Document([
                '$id' => 'batch',
                '$permissions' => [Permission::read(Role::any())],
                'name' => 'batched',
            ]),
        ]);

        $this->assertFalse($cache->load($batchKey, Database::TTL, $batchHash));
        $this->assertEquals('batched', $database->getDocument($collection, 'batch')->getAttribute('name'));

        // A locking read must never publish anything to the cache.
        $this->assertTrue($database->getDocument($collection, 'phantom', forUpdate: true)->isEmpty());
        [, $phantomKey, $phantomHash] = $database->getCacheKeys($collection, 'phantom');
        $this->assertFalse($cache->load($phantomKey, Database::TTL, $phantomHash));
    }

    public function testCacheEmptyDocumentSelect(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $cache = $database->getCache();

        // The Redis adapter runs with a no-op cache (reads hit Redis directly),
        // so there is no cache layer to inspect.
        if (!$database->getAdapter()->getSupportForCaching()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'cacheEmptySelect';
        $database->createCollection($collection, permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ], documentSecurity: false);

        $this->assertEquals(true, $database->createAttribute($collection, 'name', Database::VAR_STRING, 128, false));

        // The document key is select-independent, but the hashKey is not: a
        // projection is folded into it. So a projected read and a plain read of
        // the same missing id are cached under different slots of the same key.
        [, $documentKey, $plainHash] = $database->getCacheKeys($collection, 'ghost');

        // validateSelections() appends the internal attributes to the user
        // selection before it forms the key; mirror that set to address the
        // projected slot (getCacheKeys sorts, so order does not matter).
        $selects = ['name', '$id', '$sequence', '$collection', '$createdAt', '$updatedAt', '$permissions'];
        [, , $selectHash] = $database->getCacheKeys($collection, 'ghost', $selects);
        $this->assertNotEquals($plainHash, $selectHash);

        // Projected read caches its marker under the projected slot only.
        $this->assertTrue($database->getDocument($collection, 'ghost', [Query::select(['name'])])->isEmpty());
        $cached = $cache->load($documentKey, Database::TTL, $selectHash);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('$empty', $cached);
        $this->assertFalse(
            $cache->load($documentKey, Database::TTL, $plainHash),
            'A projected read must not populate the no-projection cache slot'
        );

        // Plain read fills the plain slot with its own marker. Both slots of the
        // document key now hold an "empty" marker.
        $this->assertTrue($database->getDocument($collection, 'ghost')->isEmpty());
        $cached = $cache->load($documentKey, Database::TTL, $plainHash);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('$empty', $cached);

        // Inserting the id purges the whole document key, so BOTH slots clear.
        $database->createDocument($collection, new Document([
            '$id' => 'ghost',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'real',
        ]));

        $this->assertFalse($cache->load($documentKey, Database::TTL, $plainHash));
        $this->assertFalse($cache->load($documentKey, Database::TTL, $selectHash));
    }

    public function testCacheEmptyGetCollection(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $cache = $database->getCache();

        // The Redis adapter runs with a no-op cache (reads hit Redis directly),
        // so there is no cache layer to inspect.
        if (!$database->getAdapter()->getSupportForCaching()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'cacheEmptyCollection';

        // getCollection() reads getDocument(METADATA, id) under the hood, so a
        // lookup of a non-existent collection negatively caches its absence
        // under the metadata key.
        $this->assertTrue($database->getCollection($collectionId)->isEmpty());

        [, $metaKey, $metaHash] = $database->getCacheKeys(Database::METADATA, $collectionId);
        $cached = $cache->load($metaKey, Database::TTL, $metaHash);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('$empty', $cached);

        // createCollection() writes the metadata row via createDocument(METADATA),
        // which must purge that marker — otherwise the collection would keep
        // reading back as "not found".
        $collection = $database->createCollection($collectionId, permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ], documentSecurity: false);
        $this->assertFalse($collection->isEmpty());

        $this->assertFalse($cache->load($metaKey, Database::TTL, $metaHash));

        $fetched = $database->getCollection($collectionId);
        $this->assertFalse($fetched->isEmpty());
        $this->assertEquals($collectionId, $fetched->getId());

        // Recreating it must now be rejected as a duplicate. This proves the
        // marker was genuinely invalidated: a lingering "not found" would make
        // createCollection's own existence check pass and wrongly proceed.
        try {
            $database->createCollection($collectionId);
            $this->fail('Expected DuplicateException when recreating an existing collection');
        } catch (DuplicateException) {
            // expected
        }
    }

    public function testCacheEmptyDocumentSecurity(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $auth = $database->getAuthorization();
        $cache = $database->getCache();

        // The Redis adapter runs with a no-op cache (reads hit Redis directly),
        // so there is no cache layer to inspect.
        if (!$database->getAdapter()->getSupportForCaching()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'cacheEmptyDocSecurity';

        // Document-level security with no collection-wide read: access is
        // decided per document.
        $auth->skip(function () use ($database, $collection) {
            $database->createCollection($collection, permissions: [], documentSecurity: true);
            $this->assertEquals(true, $database->createAttribute($collection, 'name', Database::VAR_STRING, 128, false));
            $database->createDocument($collection, new Document([
                '$id' => 'secret',
                '$permissions' => [
                    Permission::read(Role::user('userA')),
                ],
                'name' => 'classified',
            ]));
        });

        try {
            // userB cannot read 'secret'. The row exists, so this denial must
            // NOT record a negative marker under the shared (user-independent)
            // cache key — doing so would hide the row from userA.
            $auth->cleanRoles();
            $auth->addRole(Role::user('userB')->toString());

            $this->assertTrue($database->getDocument($collection, 'secret')->isEmpty());

            [, $documentKey, $hashKey] = $database->getCacheKeys($collection, 'secret');
            $cached = $cache->load($documentKey, Database::TTL, $hashKey);
            $this->assertFalse(
                \is_array($cached) && isset($cached['$empty']),
                'A permission-denied read of an existing document must not populate the negative cache'
            );

            // userA has read permission and must still see the document,
            // proving userB's forbidden read did not poison the cache.
            $auth->cleanRoles();
            $auth->addRole(Role::user('userA')->toString());

            $document = $database->getDocument($collection, 'secret');
            $this->assertFalse($document->isEmpty());
            $this->assertEquals('classified', $document->getAttribute('name'));

            // A genuinely missing id is user-independent, so it is still safe to
            // cache as empty even under document security.
            $this->assertTrue($database->getDocument($collection, 'ghost')->isEmpty());
            [, $ghostKey, $ghostHash] = $database->getCacheKeys($collection, 'ghost');
            $ghostCached = $cache->load($ghostKey, Database::TTL, $ghostHash);
            $this->assertIsArray($ghostCached);
            $this->assertArrayHasKey('$empty', $ghostCached);
        } finally {
            $auth->cleanRoles();
            $auth->addRole(Role::any()->toString());
        }
    }

    public function testCreateDocumentsWithAutoIncrement(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection(__FUNCTION__);

        $this->assertEquals(true, $database->createAttribute(__FUNCTION__, 'string', Database::VAR_STRING, 128, true));

        /** @var array<Document> $documents */
        $documents = [];
        $offset = 1000000;
        for ($i = $offset; $i <= ($offset + 10); $i++) {
            $sequence = (string)$i;
            if ($database->getAdapter()->getIdAttributeType() == Database::VAR_UUID7) {
                // Replace last 6 digits with $i to make it unique
                $suffix = str_pad(substr((string)$i, -6), 6, '0', STR_PAD_LEFT);
                $sequence = '01890dd5-7331-7f3a-9c1b-123456' . $suffix;
            }

            $hash[$i] = $sequence;

            $documents[] = new Document([
                '$sequence' => $sequence,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'string' => 'text',
            ]);
        }

        $count = $database->createDocuments(__FUNCTION__, $documents, 6);
        $this->assertEquals($count, \count($documents));

        $documents = $database->find(__FUNCTION__, [
            Query::orderAsc()
        ]);

        foreach ($documents as $index => $document) {
            $this->assertEquals($hash[$index + $offset], $document->getSequence());
            $this->assertNotEmpty($document->getId());
            $this->assertEquals('text', $document->getAttribute('string'));
        }
    }

    public function testCreateDocumentsWithDifferentAttributes(): void
    {
        $collection = 'testDiffAttributes';

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection($collection);

        $this->assertEquals(true, $database->createAttribute($collection, 'string', Database::VAR_STRING, 128, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'integer', Database::VAR_INTEGER, 0, false));
        $this->assertEquals(true, $database->createAttribute($collection, 'bigint', Database::VAR_INTEGER, 8, false));
        $this->assertEquals(true, $database->createAttribute($collection, 'string_default', Database::VAR_STRING, 128, false, 'default'));

        $documents = [
            new Document([
                '$id' => 'first',
                'string' => 'text📝',
                'integer' => 5,
                'string_default' => 'not_default',
            ]),
            new Document([
                '$id' => 'second',
                'string' => 'text📝',
            ]),
        ];

        $results = [];
        $count = $database->createDocuments($collection, $documents, onNext: function ($doc) use (&$results) {
            $results[] = $doc;
        });

        $this->assertEquals(2, $count);

        $this->assertEquals('text📝', $results[0]->getAttribute('string'));
        $this->assertEquals(5, $results[0]->getAttribute('integer'));
        $this->assertEquals('not_default', $results[0]->getAttribute('string_default'));
        $this->assertEquals('text📝', $results[1]->getAttribute('string'));
        $this->assertEquals(null, $results[1]->getAttribute('integer'));
        $this->assertEquals('default', $results[1]->getAttribute('string_default'));

        /**
         * Expect fail, mix of sequence and no sequence
         */
        $documents = [
            new Document([
                '$id' => 'third',
                '$sequence' => 'third',
                'string' => 'text📝',
            ]),
            new Document([
                '$id' => 'fourth',
                'string' => 'text📝',
            ]),
        ];

        try {
            $database->createDocuments($collection, $documents);
            $this->fail('Failed to throw exception');
        } catch (DatabaseException $e) {
        }

        $documents = array_reverse($documents);
        try {
            $database->createDocuments($collection, $documents);
            $this->fail('Failed to throw exception');
        } catch (DatabaseException $e) {
        }

        $database->deleteCollection($collection);
    }

    public function testSkipPermissions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);
        $database->createAttribute(__FUNCTION__, 'number', Database::VAR_INTEGER, 0, false);

        $data = [];
        for ($i = 1; $i <= 10; $i++) {
            $data[] = [
                '$id' => "$i",
                'number' => $i,
            ];
        }

        $documents = array_map(fn ($d) => new Document($d), $data);

        $results = [];
        $count = $database->createDocuments(__FUNCTION__, $documents, onNext: function ($doc) use (&$results) {
            $results[] = $doc;
        });

        $this->assertEquals($count, \count($results));
        $this->assertEquals(10, \count($results));

        /**
         * Update 1 row
         */
        $data[\array_key_last($data)]['number'] = 100;

        /**
         * Add 1 row
         */
        $data[] = [
            '$id' => "101",
            'number' => 101,
        ];

        $documents = array_map(fn ($d) => new Document($d), $data);

        $this->getDatabase()->getAuthorization()->disable();

        $results = [];
        $count = $database->upsertDocuments(
            __FUNCTION__,
            $documents,
            onNext: function ($doc) use (&$results) {
                $results[] = $doc;
            }
        );

        $this->getDatabase()->getAuthorization()->reset();

        $this->assertEquals(2, \count($results));
        $this->assertEquals(2, $count);

        foreach ($results as $result) {
            $this->assertArrayHasKey('$permissions', $result);
            $this->assertEquals([], $result->getAttribute('$permissions'));
        }
    }

    public function testUpsertDocuments(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);
        $database->createAttribute(__FUNCTION__, 'string', Database::VAR_STRING, 128, true);
        $database->createAttribute(__FUNCTION__, 'integer', Database::VAR_INTEGER, 0, true);
        $database->createAttribute(__FUNCTION__, 'bigint', Database::VAR_INTEGER, 8, true);

        $documents = [
            new Document([
                '$id' => 'first',
                'string' => 'text📝',
                'integer' => 5,
                'bigint' => Database::MAX_BIG_INT,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]),
            new Document([
                '$id' => 'second',
                'string' => 'text📝',
                'integer' => 5,
                'bigint' => Database::MAX_BIG_INT,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]),
        ];

        $results = [];
        $count = $database->upsertDocuments(
            __FUNCTION__,
            $documents,
            onNext: function ($doc) use (&$results) {
                $results[] = $doc;
            }
        );

        $this->assertEquals(2, $count);

        $createdAt = [];
        foreach ($results as $index => $document) {
            $createdAt[$index] = $document->getCreatedAt();
            $this->assertNotEmpty($document->getId());
            $this->assertNotEmpty($document->getSequence());
            $this->assertIsString($document->getAttribute('string'));
            $this->assertEquals('text📝', $document->getAttribute('string')); // Also makes sure an emoji is working
            $this->assertIsInt($document->getAttribute('integer'));
            $this->assertEquals(5, $document->getAttribute('integer'));
            $this->assertIsInt($document->getAttribute('bigint'));
            $this->assertEquals(Database::MAX_BIG_INT, $document->getAttribute('bigint'));
        }

        $documents = $database->find(__FUNCTION__);

        $this->assertEquals(2, count($documents));

        foreach ($documents as $document) {
            $this->assertNotEmpty($document->getId());
            $this->assertIsString($document->getAttribute('string'));
            $this->assertEquals('text📝', $document->getAttribute('string')); // Also makes sure an emoji is working
            $this->assertIsInt($document->getAttribute('integer'));
            $this->assertEquals(5, $document->getAttribute('integer'));
            $this->assertIsInt($document->getAttribute('bigint'));
            $this->assertEquals(Database::MAX_BIG_INT, $document->getAttribute('bigint'));
        }

        $documents[0]->setAttribute('string', 'new text📝');
        $documents[0]->setAttribute('integer', 10);
        $documents[1]->setAttribute('string', 'new text📝');
        $documents[1]->setAttribute('integer', 10);

        $results = [];
        $count = $database->upsertDocuments(__FUNCTION__, $documents, onNext: function ($doc) use (&$results) {
            $results[] = $doc;
        });

        $this->assertEquals(2, $count);

        foreach ($results as $document) {
            $this->assertNotEmpty($document->getId());
            $this->assertNotEmpty($document->getSequence());
            $this->assertIsString($document->getAttribute('string'));
            $this->assertEquals('new text📝', $document->getAttribute('string')); // Also makes sure an emoji is working
            $this->assertIsInt($document->getAttribute('integer'));
            $this->assertEquals(10, $document->getAttribute('integer'));
            $this->assertIsInt($document->getAttribute('bigint'));
            $this->assertEquals(Database::MAX_BIG_INT, $document->getAttribute('bigint'));
        }

        $documents = $database->find(__FUNCTION__);

        $this->assertEquals(2, count($documents));

        foreach ($documents as $index => $document) {
            $this->assertEquals($createdAt[$index], $document->getCreatedAt());
            $this->assertNotEmpty($document->getId());
            $this->assertIsString($document->getAttribute('string'));
            $this->assertEquals('new text📝', $document->getAttribute('string')); // Also makes sure an emoji is working
            $this->assertIsInt($document->getAttribute('integer'));
            $this->assertEquals(10, $document->getAttribute('integer'));
            $this->assertIsInt($document->getAttribute('bigint'));
            $this->assertEquals(Database::MAX_BIG_INT, $document->getAttribute('bigint'));
        }
    }

    public function testTextByteTruncationCreate(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        // Byte-capacity validation relies on attribute metadata, which
        // schemaless adapters don't store, so there is nothing to enforce.
        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        // A `text` attribute at its maximum allowed size. On MySQL/MariaDB this
        // maps to a TEXT column, which is limited to 65,535 *bytes*.
        $database->createAttribute(__FUNCTION__, 'text', Database::VAR_TEXT, 65535, false);

        // The Structure validator caps a TEXT column at its 65,535-byte capacity,
        // measuring the value's actual byte length. A 20,000-char emoji value is
        // 80,000 bytes (4 bytes per char in utf8mb4), so it exceeds the column's
        // byte capacity and must be rejected up front with a clean
        // StructureException, rather than letting the database raise error 1406
        // (data truncation).
        $value = \str_repeat('📝', 20000);
        $this->assertGreaterThan(65535, \strlen($value)); // exceeds the byte capacity

        $document = new Document([
            '$id' => 'first',
            'text' => $value,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        try {
            $database->createDocument(__FUNCTION__, $document);
            $this->fail('Expected StructureException for over-capacity text value');
        } catch (StructureException $e) {
            $this->assertStringContainsString('65535 bytes', $e->getMessage());
        }
    }

    public function testTextByteTruncationValid(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);
        $database->createAttribute(__FUNCTION__, 'text', Database::VAR_TEXT, 65535, false);

        // A value that fills the column's full byte capacity is stored and
        // round-trips intact. 65,535 ASCII chars are exactly 65,535 bytes, so
        // byte-based validation accepts the whole column, where the previous
        // char-based cap would have rejected anything over 16,383 chars.
        $okValue = \str_repeat('a', 65535);

        $document = new Document([
            '$id' => 'first',
            'text' => $okValue,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $created = $database->createDocument(__FUNCTION__, $document);
        $fetched = $database->getDocument(__FUNCTION__, $created->getId());
        $this->assertEquals($okValue, $fetched->getAttribute('text'));
    }

    public function testTextByteTruncationUpdate(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);
        $database->createAttribute(__FUNCTION__, 'text', Database::VAR_TEXT, 65535, false);

        $document = new Document([
            '$id' => 'first',
            'text' => \str_repeat('a', 16383),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $created = $database->createDocument(__FUNCTION__, $document);

        // An oversized value is rejected on update, the same as on create.
        $value = \str_repeat('📝', 20000);
        $this->assertGreaterThan(65535, \strlen($value)); // exceeds the byte capacity

        try {
            $database->updateDocument(__FUNCTION__, $created->getId(), $created->setAttribute('text', $value));
            $this->fail('Expected StructureException for over-capacity text value on update');
        } catch (StructureException $e) {
            $this->assertStringContainsString('65535 bytes', $e->getMessage());
        }
    }

    public function testUpsertDocumentsInc(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);
        $database->createAttribute(__FUNCTION__, 'string', Database::VAR_STRING, 128, false);
        $database->createAttribute(__FUNCTION__, 'integer', Database::VAR_INTEGER, 0, false);

        $documents = [
            new Document([
                '$id' => 'first',
                'string' => 'text📝',
                'integer' => 5,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]),
            new Document([
                '$id' => 'second',
                'string' => 'text📝',
                'integer' => 5,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]),
        ];

        $database->createDocuments(__FUNCTION__, $documents);

        $documents[0]->setAttribute('integer', 1);
        $documents[1]->setAttribute('integer', 1);

        $database->upsertDocumentsWithIncrease(
            collection: __FUNCTION__,
            attribute: 'integer',
            documents: $documents
        );

        $documents = $database->find(__FUNCTION__);

        foreach ($documents as $document) {
            $this->assertEquals(6, $document->getAttribute('integer'));
        }

        $documents[0]->setAttribute('integer', -1);
        $documents[1]->setAttribute('integer', -1);

        $database->upsertDocumentsWithIncrease(
            collection: __FUNCTION__,
            attribute: 'integer',
            documents: $documents
        );

        $documents = $database->find(__FUNCTION__);

        foreach ($documents as $document) {
            $this->assertEquals(5, $document->getAttribute('integer'));
        }
    }

    public function testUpsertDocumentsPermissions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);
        $database->createAttribute(__FUNCTION__, 'string', Database::VAR_STRING, 128, true);

        $document = new Document([
            '$id' => 'first',
            'string' => 'text📝',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);

        $database->upsertDocuments(__FUNCTION__, [$document]);

        try {
            $database->upsertDocuments(__FUNCTION__, [$document->setAttribute('string', 'updated')]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(AuthorizationException::class, $e);
        }

        $document = new Document([
            '$id' => 'second',
            'string' => 'text📝',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $database->upsertDocuments(__FUNCTION__, [$document]);

        $results = [];
        $count = $database->upsertDocuments(
            __FUNCTION__,
            [$document->setAttribute('string', 'updated')],
            onNext: function ($doc) use (&$results) {
                $results[] = $doc;
            }
        );

        $this->assertEquals(1, $count);
        $this->assertEquals('updated', $results[0]->getAttribute('string'));

        $document = new Document([
            '$id' => 'third',
            'string' => 'text📝',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $database->upsertDocuments(__FUNCTION__, [$document]);

        $newPermissions = [
            Permission::read(Role::any()),
            Permission::update(Role::user('user1')),
            Permission::delete(Role::user('user1')),
        ];

        $results = [];
        $count = $database->upsertDocuments(
            __FUNCTION__,
            [$document->setAttribute('$permissions', $newPermissions)],
            onNext: function ($doc) use (&$results) {
                $results[] = $doc;
            }
        );

        $this->assertEquals(1, $count);
        $this->assertEquals($newPermissions, $results[0]->getPermissions());

        $document = $database->getDocument(__FUNCTION__, 'third');

        $this->assertEquals($newPermissions, $document->getPermissions());
    }

    public function testUpsertDocumentsAttributeMismatch(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__, permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ], documentSecurity: false);
        $database->createAttribute(__FUNCTION__, 'first', Database::VAR_STRING, 128, true);
        $database->createAttribute(__FUNCTION__, 'last', Database::VAR_STRING, 128, false);

        $existingDocument = $database->createDocument(__FUNCTION__, new Document([
            '$id' => 'first',
            'first' => 'first',
            'last' => 'last',
        ]));

        $newDocument = new Document([
            '$id' => 'second',
            'first' => 'second',
        ]);

        // Ensure missing optionals on new document is allowed
        $docs = $database->upsertDocuments(__FUNCTION__, [
            $existingDocument->setAttribute('first', 'updated'),
            $newDocument,
        ]);

        $this->assertEquals(2, $docs);
        $this->assertEquals('updated', $existingDocument->getAttribute('first'));
        $this->assertEquals('last', $existingDocument->getAttribute('last'));
        $this->assertEquals('second', $newDocument->getAttribute('first'));
        $this->assertEquals('', $newDocument->getAttribute('last'));

        try {
            $database->upsertDocuments(__FUNCTION__, [
                $existingDocument->removeAttribute('first'),
                $newDocument
            ]);
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertTrue($e instanceof StructureException, $e->getMessage());
            }
        }

        // Ensure missing optionals on existing document is allowed
        $docs = $database->upsertDocuments(__FUNCTION__, [
            $existingDocument
                ->setAttribute('first', 'first')
                ->removeAttribute('last'),
            $newDocument
                ->setAttribute('last', 'last')
        ]);

        $this->assertEquals(2, $docs);
        $this->assertEquals('first', $existingDocument->getAttribute('first'));
        $this->assertEquals('last', $existingDocument->getAttribute('last'));
        $this->assertEquals('second', $newDocument->getAttribute('first'));
        $this->assertEquals('last', $newDocument->getAttribute('last'));

        // Ensure set null on existing document is allowed
        $docs = $database->upsertDocuments(__FUNCTION__, [
            $existingDocument
                ->setAttribute('first', 'first')
                ->setAttribute('last', null),
            $newDocument
                ->setAttribute('last', 'last')
        ]);

        $this->assertEquals(1, $docs);
        $this->assertEquals('first', $existingDocument->getAttribute('first'));
        $this->assertEquals(null, $existingDocument->getAttribute('last'));
        $this->assertEquals('second', $newDocument->getAttribute('first'));
        $this->assertEquals('last', $newDocument->getAttribute('last'));

        $doc3 = new Document([
            '$id' => 'third',
            'last' => 'last',
            'first' => 'third',
        ]);

        $doc4 = new Document([
            '$id' => 'fourth',
            'first' => 'fourth',
            'last' => 'last',
        ]);

        // Ensure mismatch of attribute orders is allowed
        $docs = $database->upsertDocuments(__FUNCTION__, [
            $doc3,
            $doc4
        ]);

        $this->assertEquals(2, $docs);
        $this->assertEquals('third', $doc3->getAttribute('first'));
        $this->assertEquals('last', $doc3->getAttribute('last'));
        $this->assertEquals('fourth', $doc4->getAttribute('first'));
        $this->assertEquals('last', $doc4->getAttribute('last'));

        $doc3 = $database->getDocument(__FUNCTION__, 'third');
        $doc4 = $database->getDocument(__FUNCTION__, 'fourth');

        $this->assertEquals('third', $doc3->getAttribute('first'));
        $this->assertEquals('last', $doc3->getAttribute('last'));
        $this->assertEquals('fourth', $doc4->getAttribute('first'));
        $this->assertEquals('last', $doc4->getAttribute('last'));
    }

    public function testUpsertDocumentsNoop(): void
    {
        if (!$this->getDatabase()->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->getDatabase()->createCollection(__FUNCTION__);
        $this->getDatabase()->createAttribute(__FUNCTION__, 'string', Database::VAR_STRING, 128, true);

        $document = new Document([
            '$id' => 'first',
            'string' => 'text📝',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $count = $this->getDatabase()->upsertDocuments(__FUNCTION__, [$document]);
        $this->assertEquals(1, $count);

        // No changes, should return 0
        $count = $this->getDatabase()->upsertDocuments(__FUNCTION__, [$document]);
        $this->assertEquals(0, $count);
    }

    public function testUpsertDuplicateIds(): void
    {
        $db = $this->getDatabase();
        if (!$db->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $db->createCollection(__FUNCTION__);
        $db->createAttribute(__FUNCTION__, 'num', Database::VAR_INTEGER, 0, true);

        $doc1 = new Document(['$id' => 'dup', 'num' => 1]);
        $doc2 = new Document(['$id' => 'dup', 'num' => 2]);

        try {
            $db->upsertDocuments(__FUNCTION__, [$doc1, $doc2]);
            $this->fail('Failed to throw exception');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DuplicateException::class, $e, $e->getMessage());
        }
    }

    public function testUpsertMixedPermissionDelta(): void
    {
        $db = $this->getDatabase();
        if (!$db->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $db->createCollection(__FUNCTION__);
        $db->createAttribute(__FUNCTION__, 'v', Database::VAR_INTEGER, 0, true);

        $d1 = $db->createDocument(__FUNCTION__, new Document([
            '$id' => 'a',
            'v' => 0,
            '$permissions' => [
                Permission::update(Role::any())
            ]
        ]));
        $d2 = $db->createDocument(__FUNCTION__, new Document([
            '$id' => 'b',
            'v' => 0,
            '$permissions' => [
                Permission::update(Role::any())
            ]
        ]));

        // d1 adds write, d2 removes update
        $d1->setAttribute('$permissions', [
            Permission::read(Role::any()),
            Permission::update(Role::any())
        ]);
        $d2->setAttribute('$permissions', [
            Permission::read(Role::any())
        ]);

        $db->upsertDocuments(__FUNCTION__, [$d1, $d2]);

        $this->assertEquals([
            Permission::read(Role::any()),
            Permission::update(Role::any()),
        ], $db->getDocument(__FUNCTION__, 'a')->getPermissions());

        $this->assertEquals([
            Permission::read(Role::any()),
        ], $db->getDocument(__FUNCTION__, 'b')->getPermissions());
    }

    public function testPreserveSequenceUpsert(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'preserve_sequence_upsert';

        $database->createCollection($collectionName);

        if ($database->getAdapter()->getSupportForAttributes()) {
            $database->createAttribute($collectionName, 'name', Database::VAR_STRING, 128, true);
        }

        // Create initial documents
        $doc1 = $database->createDocument($collectionName, new Document([
            '$id' => 'doc1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Alice',
        ]));

        $doc2 = $database->createDocument($collectionName, new Document([
            '$id' => 'doc2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Bob',
        ]));

        $originalSeq1 = $doc1->getSequence();
        $originalSeq2 = $doc2->getSequence();

        $this->assertNotEmpty($originalSeq1);
        $this->assertNotEmpty($originalSeq2);

        // Test: Without preserveSequence (default), $sequence should be ignored
        $database->setPreserveSequence(false);

        $database->upsertDocuments($collectionName, [
            new Document([
                '$id' => 'doc1',
                '$sequence' => 999, // Try to set a different sequence
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                'name' => 'Alice Updated',
            ]),
        ]);

        $doc1Updated = $database->getDocument($collectionName, 'doc1');
        $this->assertEquals('Alice Updated', $doc1Updated->getAttribute('name'));
        $this->assertEquals($originalSeq1, $doc1Updated->getSequence()); // Sequence unchanged

        // Test: With preserveSequence=true, $sequence from document should be used
        $database->setPreserveSequence(true);

        $database->upsertDocuments($collectionName, [
            new Document([
                '$id' => 'doc2',
                '$sequence' => $originalSeq2, // Keep original sequence
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                'name' => 'Bob Updated',
            ]),
        ]);

        $doc2Updated = $database->getDocument($collectionName, 'doc2');
        $this->assertEquals('Bob Updated', $doc2Updated->getAttribute('name'));
        $this->assertEquals($originalSeq2, $doc2Updated->getSequence()); // Sequence preserved

        // Test: withPreserveSequence helper
        $database->setPreserveSequence(false);

        $doc1 = $database->getDocument($collectionName, 'doc1');
        $currentSeq1 = $doc1->getSequence();

        $database->withPreserveSequence(function () use ($database, $collectionName, $currentSeq1) {
            $database->upsertDocuments($collectionName, [
                new Document([
                    '$id' => 'doc1',
                    '$sequence' => $currentSeq1,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                    ],
                    'name' => 'Alice Final',
                ]),
            ]);
        });

        $doc1Final = $database->getDocument($collectionName, 'doc1');
        $this->assertEquals('Alice Final', $doc1Final->getAttribute('name'));
        $this->assertEquals($currentSeq1, $doc1Final->getSequence());

        // Verify flag was reset after withPreserveSequence
        $this->assertFalse($database->getPreserveSequence());

        // Test: With preserveSequence=true, invalid $sequence should throw error (SQL adapters only)
        $database->setPreserveSequence(true);

        try {
            $database->upsertDocuments($collectionName, [
                new Document([
                    '$id' => 'doc1',
                    '$sequence' => 'abc', // Invalid sequence value
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                    ],
                    'name' => 'Alice Invalid',
                ]),
            ]);
            // Schemaless adapters may not validate sequence type, so only fail for schemaful
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->fail('Expected StructureException for invalid sequence');
            }
        } catch (Throwable $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertInstanceOf(StructureException::class, $e);
                $this->assertStringContainsString('sequence', $e->getMessage());
            }
        }

        $database->setPreserveSequence(false);
        $database->deleteCollection($collectionName);
    }

    /**
     * upsertDocuments() carries the sequence of every row it already read across to the
     * written document, so the follow-up getSequences() lookup only covers the rows that
     * were genuinely new. That leaves the batch it receives interleaved -- some documents
     * carry a sequence, some do not -- and the tenant placeholders it binds must line up
     * with the ones its SQL declares regardless of where the gaps fall.
     */
    public function testUpsertSequencesOnMixedBatch(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'upsert_mixed_sequences';

        $database->createCollection($collectionName, permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
        ]);

        if ($database->getAdapter()->getSupportForAttributes()) {
            $database->createAttribute($collectionName, 'name', Database::VAR_STRING, 128, true);
        }

        $permissions = [
            Permission::read(Role::any()),
            Permission::update(Role::any()),
        ];

        $existingSequences = [];
        foreach (['existing1', 'existing2'] as $id) {
            $created = $database->createDocument($collectionName, new Document([
                '$id' => $id,
                '$permissions' => $permissions,
                'name' => $id,
            ]));

            $this->assertNotEmpty($created->getSequence());
            $existingSequences[$id] = $created->getSequence();
        }

        // Existing and new rows interleaved, so the new ones sit at odd indexes in the batch.
        $upserted = [];
        $database->upsertDocuments(
            $collectionName,
            [
                new Document(['$id' => 'existing1', '$permissions' => $permissions, 'name' => 'existing1 updated']),
                new Document(['$id' => 'new1', '$permissions' => $permissions, 'name' => 'new1']),
                new Document(['$id' => 'existing2', '$permissions' => $permissions, 'name' => 'existing2 updated']),
                new Document(['$id' => 'new2', '$permissions' => $permissions, 'name' => 'new2']),
            ],
            onNext: function (Document $document) use (&$upserted) {
                $upserted[$document->getId()] = $document->getSequence();
            }
        );

        $this->assertCount(4, $upserted);

        foreach (['existing1', 'existing2', 'new1', 'new2'] as $id) {
            $this->assertNotEmpty($upserted[$id], "No sequence returned for {$id}");
            $this->assertEquals(
                $database->getDocument($collectionName, $id)->getSequence(),
                $upserted[$id],
                "Wrong sequence returned for {$id}"
            );
        }

        // An upsert must not move a row that was already there.
        $this->assertEquals($existingSequences['existing1'], $upserted['existing1']);
        $this->assertEquals($existingSequences['existing2'], $upserted['existing2']);

        $database->deleteCollection($collectionName);
    }

    public function testRespectNulls(): Document
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('documents_nulls');

        $this->assertEquals(true, $database->createAttribute('documents_nulls', 'string', Database::VAR_STRING, 128, false));
        $this->assertEquals(true, $database->createAttribute('documents_nulls', 'integer', Database::VAR_INTEGER, 0, false));
        $this->assertEquals(true, $database->createAttribute('documents_nulls', 'bigint', Database::VAR_INTEGER, 8, false));
        $this->assertEquals(true, $database->createAttribute('documents_nulls', 'float', Database::VAR_FLOAT, 0, false));
        $this->assertEquals(true, $database->createAttribute('documents_nulls', 'boolean', Database::VAR_BOOLEAN, 0, false));

        $document = $database->createDocument('documents_nulls', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::read(Role::user('1')),
                Permission::read(Role::user('2')),
                Permission::create(Role::any()),
                Permission::create(Role::user('1x')),
                Permission::create(Role::user('2x')),
                Permission::update(Role::any()),
                Permission::update(Role::user('1x')),
                Permission::update(Role::user('2x')),
                Permission::delete(Role::any()),
                Permission::delete(Role::user('1x')),
                Permission::delete(Role::user('2x')),
            ],
        ]));

        $this->assertNotEmpty($document->getId());
        $this->assertNull($document->getAttribute('string'));
        $this->assertNull($document->getAttribute('integer'));
        $this->assertNull($document->getAttribute('bigint'));
        $this->assertNull($document->getAttribute('float'));
        $this->assertNull($document->getAttribute('boolean'));
        return $document;
    }

    public function testCreateDocumentDefaults(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('defaults');

        $this->assertEquals(true, $database->createAttribute('defaults', 'string', Database::VAR_STRING, 128, false, 'default'));
        $this->assertEquals(true, $database->createAttribute('defaults', 'integer', Database::VAR_INTEGER, 0, false, 1));
        $this->assertEquals(true, $database->createAttribute('defaults', 'float', Database::VAR_FLOAT, 0, false, 1.5));
        $this->assertEquals(true, $database->createAttribute('defaults', 'boolean', Database::VAR_BOOLEAN, 0, false, true));
        $this->assertEquals(true, $database->createAttribute('defaults', 'colors', Database::VAR_STRING, 32, false, ['red', 'green', 'blue'], true, true));
        $this->assertEquals(true, $database->createAttribute('defaults', 'datetime', Database::VAR_DATETIME, 0, false, '2000-06-12T14:12:55.000+00:00', true, false, null, [], ['datetime']));

        $document = $database->createDocument('defaults', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]));

        $document2 = $database->getDocument('defaults', $document->getId());
        $this->assertCount(4, $document2->getPermissions());
        $this->assertEquals('read("any")', $document2->getPermissions()[0]);
        $this->assertEquals('create("any")', $document2->getPermissions()[1]);
        $this->assertEquals('update("any")', $document2->getPermissions()[2]);
        $this->assertEquals('delete("any")', $document2->getPermissions()[3]);

        $this->assertNotEmpty($document->getId());
        $this->assertIsString($document->getAttribute('string'));
        $this->assertEquals('default', $document->getAttribute('string'));
        $this->assertIsInt($document->getAttribute('integer'));
        $this->assertEquals(1, $document->getAttribute('integer'));
        $this->assertIsFloat($document->getAttribute('float'));
        $this->assertEquals(1.5, $document->getAttribute('float'));
        $this->assertIsArray($document->getAttribute('colors'));
        $this->assertCount(3, $document->getAttribute('colors'));
        $this->assertEquals('red', $document->getAttribute('colors')[0]);
        $this->assertEquals('green', $document->getAttribute('colors')[1]);
        $this->assertEquals('blue', $document->getAttribute('colors')[2]);
        $this->assertEquals('2000-06-12T14:12:55.000+00:00', $document->getAttribute('datetime'));

        // cleanup collection
        $database->deleteCollection('defaults');
    }

    /**
     * When a document's UID changes on update, its permission rows in the
     * collection's _perms table must follow the new UID. Otherwise the old
     * rows are orphaned and the renamed document is left with no permissions,
     * even when the permission set itself was not changed.
     */
    public function testUpdateDocumentChangeIdMigratesPermissions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $auth = $database->getAuthorization();

        $collection = 'update_change_id_perms';

        try {
            // documentSecurity with no collection-level permissions: reads are
            // governed purely by the document's rows in the _perms table.
            $database->createCollection($collection, permissions: [], documentSecurity: true);
            $this->assertEquals(true, $database->createAttribute($collection, 'name', Database::VAR_STRING, 128, false));

            // Create a document whose read permission is scoped to a single role,
            // so that find() must consult the _perms table to return it.
            $document = $auth->skip(fn () => $database->createDocument($collection, new Document([
                '$id' => 'old_id',
                'name' => 'test',
                '$permissions' => [
                    Permission::read(Role::user('alice')),
                    Permission::update(Role::user('alice')),
                    Permission::delete(Role::user('alice')),
                ],
            ])));
            $this->assertEquals('old_id', $document->getId());

            // Sanity: as alice the document is visible via the _perms table.
            $auth->addRole(Role::user('alice')->toString());
            $this->assertCount(1, $database->find($collection));

            // Rename the document WITHOUT changing its permission set.
            $renamed = $auth->skip(fn () => $database->updateDocument($collection, 'old_id', new Document(\array_merge(
                $document->getArrayCopy(),
                ['$id' => 'new_id'],
            ))));
            $this->assertEquals('new_id', $renamed->getId());

            // The old UID must no longer resolve to a document.
            $this->assertTrue($auth->skip(fn () => $database->getDocument($collection, 'old_id'))->isEmpty());

            // The new UID must exist and keep its permissions on the main row.
            $newDoc = $auth->skip(fn () => $database->getDocument($collection, 'new_id'));
            $this->assertFalse($newDoc->isEmpty());
            $this->assertContains(Permission::read(Role::user('alice')), $newDoc->getPermissions());

            // The crucial check: the permission rows must have migrated to the new
            // UID in the _perms table. As alice, find() (which joins _perms) must
            // still return exactly the renamed document. With orphaned rows under
            // the old UID this returns 0.
            $found = $database->find($collection);
            $this->assertCount(1, $found);
            $this->assertEquals('new_id', $found[0]->getId());

            /**
             * Second scenario: change the UID AND the permission set in the same
             * update. Drop alice's access and grant bob instead. The removed rows
             * must be gone, the added rows must land under the new UID, and nothing
             * may be left orphaned under the old UID.
             */
            $rekeyed = $auth->skip(fn () => $database->updateDocument($collection, 'new_id', new Document(\array_merge(
                $newDoc->getArrayCopy(),
                [
                    '$id' => 'final_id',
                    '$permissions' => [
                        Permission::read(Role::user('bob')),
                        Permission::read(Role::user('bob')), // Duplication check
                        Permission::update(Role::user('bob')),
                        Permission::delete(Role::user('bob')),
                    ],
                ],
            ))));
            $this->assertEquals('final_id', $rekeyed->getId());

            // The old UID must no longer resolve to a document.
            $this->assertTrue($auth->skip(fn () => $database->getDocument($collection, 'new_id'))->isEmpty());

            // The main row must reflect the new permission set.
            $finalDoc = $auth->skip(fn () => $database->getDocument($collection, 'final_id'));
            $this->assertFalse($finalDoc->isEmpty());
            $this->assertContains(Permission::read(Role::user('bob')), $finalDoc->getPermissions());
            $this->assertNotContains(Permission::read(Role::user('alice')), $finalDoc->getPermissions());

            // alice's permission rows were removed: as alice nothing is returned.
            $this->assertCount(0, $database->find($collection));

            // bob's permission rows landed under the new UID: as bob the renamed
            // document is returned via the _perms join.
            $auth->addRole(Role::user('bob')->toString());
            $foundAsBob = $database->find($collection);
            $this->assertCount(1, $foundAsBob);
            $this->assertEquals('final_id', $foundAsBob[0]->getId());
        } finally {
            $auth->removeRole(Role::user('alice')->toString());
            $auth->removeRole(Role::user('bob')->toString());

            $auth->skip(fn () => $database->deleteCollection($collection));
        }
    }

    public function testIncreaseDecrease(): Document
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collection = 'increase_decrease';
        $database->createCollection($collection);

        $this->assertEquals(true, $database->createAttribute($collection, 'increase', Database::VAR_INTEGER, 0, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'decrease', Database::VAR_INTEGER, 0, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'increase_text', Database::VAR_STRING, 255, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'increase_float', Database::VAR_FLOAT, 0, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'sizes', Database::VAR_INTEGER, 8, required: false, array: true));

        $document = $database->createDocument($collection, new Document([
            'increase' => 100,
            'decrease' => 100,
            'increase_float' => 100,
            'increase_text' => 'some text',
            'sizes' => [10, 20, 30],
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ]
        ]));

        $updatedAt = $document->getUpdatedAt();

        \usleep(2000); // Ensure $updatedAt differs when adapter timestamp precision is milliseconds

        $doc = $database->increaseDocumentAttribute($collection, $document->getId(), 'increase', 1, 101);
        $this->assertEquals(101, $doc->getAttribute('increase'));

        $document = $database->getDocument($collection, $document->getId());
        $this->assertEquals(101, $document->getAttribute('increase'));
        $this->assertNotEquals($updatedAt, $document->getUpdatedAt());

        $doc = $database->decreaseDocumentAttribute($collection, $document->getId(), 'decrease', 1, 98);
        $this->assertEquals(99, $doc->getAttribute('decrease'));
        $document = $database->getDocument($collection, $document->getId());
        $this->assertEquals(99, $document->getAttribute('decrease'));

        $doc = $database->increaseDocumentAttribute($collection, $document->getId(), 'increase_float', 5.5, 110);
        $this->assertEquals(105.5, $doc->getAttribute('increase_float'));
        $document = $database->getDocument($collection, $document->getId());
        $this->assertEquals(105.5, $document->getAttribute('increase_float'));

        $doc = $database->decreaseDocumentAttribute($collection, $document->getId(), 'increase_float', 1.1, 100);
        $this->assertEquals(104.4, $doc->getAttribute('increase_float'));
        $document = $database->getDocument($collection, $document->getId());
        $this->assertEquals(104.4, $document->getAttribute('increase_float'));

        return $document;
    }

    public function testCreateUpdateBigIntAndIncrementDecrement(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collection = 'bigint_update_increase_decrease';
        $database->createCollection($collection);

        $this->assertEquals(true, $database->createAttribute($collection, 'inc', Database::VAR_BIGINT, 8, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'dec', Database::VAR_BIGINT, 8, true));

        $document = $database->createDocument($collection, new Document([
            'inc' => 10,
            'dec' => 10,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ]
        ]));

        $this->assertIsInt($document->getAttribute('inc'));
        $this->assertEquals(10, $document->getAttribute('inc'));

        // Verify regular update works for bigint attributes
        $updated = $database->updateDocument($collection, $document->getId(), new Document([
            'inc' => 20,
        ]));
        $this->assertEquals(20, $updated->getAttribute('inc'));

        // Verify atomic increment/decrement supports bigint schema attributes
        $afterInc = $database->increaseDocumentAttribute($collection, $document->getId(), 'inc', 5, 30);
        $this->assertEquals(25, $afterInc->getAttribute('inc'));

        $afterDec = $database->decreaseDocumentAttribute($collection, $document->getId(), 'dec', 3, 7);
        $this->assertEquals(7, $afterDec->getAttribute('dec'));
    }

    /**
     * @depends testIncreaseDecrease
     */
    public function testIncreaseLimitMax(Document $document): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->expectException(Exception::class);
        $this->assertEquals(true, $database->increaseDocumentAttribute('increase_decrease', $document->getId(), 'increase', 10.5, 102.4));
    }

    /**
     * @depends testIncreaseDecrease
     */
    public function testDecreaseLimitMin(Document $document): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        try {
            $database->decreaseDocumentAttribute(
                'increase_decrease',
                $document->getId(),
                'decrease',
                10,
                99
            );
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(LimitException::class, $e);
        }

        try {
            $database->decreaseDocumentAttribute(
                'increase_decrease',
                $document->getId(),
                'decrease',
                1000,
                0
            );
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(LimitException::class, $e);
        }
    }

    /**
     * @depends testIncreaseDecrease
     */
    public function testIncreaseTextAttribute(Document $document): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        try {
            $this->assertEquals(false, $database->increaseDocumentAttribute('increase_decrease', $document->getId(), 'increase_text'));
            $this->fail('Expected TypeException not thrown');
        } catch (Exception $e) {
            $this->assertInstanceOf(TypeException::class, $e, $e->getMessage());
        }
    }

    /**
     * @depends testIncreaseDecrease
     */
    public function testIncreaseArrayAttribute(Document $document): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        try {
            $this->assertEquals(false, $database->increaseDocumentAttribute('increase_decrease', $document->getId(), 'sizes'));
            $this->fail('Expected TypeException not thrown');
        } catch (Exception $e) {
            $this->assertInstanceOf(TypeException::class, $e);
        }
    }

    /**
     * @depends testIncreaseDecrease
     */
    public function testIncreaseDecreasePreserveDates(Document $document): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->setPreserveDates(true);

        try {
            $before = $database->getDocument('increase_decrease', $document->getId());
            $updatedAt = $before->getUpdatedAt();
            $increase = $before->getAttribute('increase');
            $decrease = $before->getAttribute('decrease');

            $database->increaseDocumentAttribute('increase_decrease', $document->getId(), 'increase', 1);

            $after = $database->getDocument('increase_decrease', $document->getId());
            $this->assertSame($increase + 1, $after->getAttribute('increase'));
            $this->assertSame($updatedAt, $after->getUpdatedAt());

            $database->decreaseDocumentAttribute('increase_decrease', $document->getId(), 'decrease', 1);

            $after = $database->getDocument('increase_decrease', $document->getId());
            $this->assertSame($decrease - 1, $after->getAttribute('decrease'));
            $this->assertSame($updatedAt, $after->getUpdatedAt());
        } finally {
            $database->setPreserveDates(false);
        }
    }

    /**
      * @depends testCreateDocument
      */
    public function testGetDocument(Document $document): Document
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->getDocument('documents', $document->getId());

        $this->assertNotEmpty($document->getId());
        $this->assertIsString($document->getAttribute('string'));
        $this->assertEquals('text📝', $document->getAttribute('string'));
        $this->assertIsInt($document->getAttribute('integer_signed'));
        $this->assertEquals(-Database::MAX_INT, $document->getAttribute('integer_signed'));
        $this->assertIsFloat($document->getAttribute('float_signed'));
        $this->assertEquals(-5.55, $document->getAttribute('float_signed'));
        $this->assertIsFloat($document->getAttribute('float_unsigned'));
        $this->assertEquals(5.55, $document->getAttribute('float_unsigned'));
        $this->assertIsBool($document->getAttribute('boolean'));
        $this->assertEquals(true, $document->getAttribute('boolean'));
        $this->assertIsArray($document->getAttribute('colors'));
        $this->assertEquals(['pink', 'green', 'blue'], $document->getAttribute('colors'));
        $this->assertEquals('Works', $document->getAttribute('with-dash'));

        return $document;
    }

    /**
     * @depends testCreateDocument
     */
    public function testGetDocumentSelect(Document $document): Document
    {
        $documentId = $document->getId();

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->getDocument('documents', $documentId, [
            Query::select(['string', 'integer_signed']),
        ]);

        $this->assertFalse($document->isEmpty());
        $this->assertIsString($document->getAttribute('string'));
        $this->assertEquals('text📝', $document->getAttribute('string'));
        $this->assertIsInt($document->getAttribute('integer_signed'));
        $this->assertEquals(-Database::MAX_INT, $document->getAttribute('integer_signed'));
        $this->assertArrayNotHasKey('float', $document->getAttributes());
        $this->assertArrayNotHasKey('boolean', $document->getAttributes());
        $this->assertArrayNotHasKey('colors', $document->getAttributes());
        $this->assertArrayNotHasKey('with-dash', $document->getAttributes());
        $this->assertArrayHasKey('$id', $document);
        $this->assertArrayHasKey('$sequence', $document);
        $this->assertArrayHasKey('$createdAt', $document);
        $this->assertArrayHasKey('$updatedAt', $document);
        $this->assertArrayHasKey('$permissions', $document);
        $this->assertArrayHasKey('$collection', $document);

        $document = $database->getDocument('documents', $documentId, [
            Query::select(['string', 'integer_signed', '$id']),
        ]);

        $this->assertArrayHasKey('$id', $document);
        $this->assertArrayHasKey('$sequence', $document);
        $this->assertArrayHasKey('$createdAt', $document);
        $this->assertArrayHasKey('$updatedAt', $document);
        $this->assertArrayHasKey('$permissions', $document);
        $this->assertArrayHasKey('$collection', $document);
        $this->assertArrayHasKey('string', $document);
        $this->assertArrayHasKey('integer_signed', $document);
        $this->assertArrayNotHasKey('float', $document);

        return $document;
    }
    /**
     * @return array<string, mixed>
     */
    public function testFind(): array
    {
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('movies', permissions: [
            Permission::create(Role::any()),
            Permission::update(Role::users())
        ]);

        $this->assertEquals(true, $database->createAttribute('movies', 'name', Database::VAR_STRING, 128, true));
        $this->assertEquals(true, $database->createAttribute('movies', 'director', Database::VAR_STRING, 128, true));
        $this->assertEquals(true, $database->createAttribute('movies', 'year', Database::VAR_INTEGER, 0, true));
        $this->assertEquals(true, $database->createAttribute('movies', 'price', Database::VAR_FLOAT, 0, true));
        $this->assertEquals(true, $database->createAttribute('movies', 'active', Database::VAR_BOOLEAN, 0, true));
        $this->assertEquals(true, $database->createAttribute('movies', 'genres', Database::VAR_STRING, 32, true, null, true, true));
        $this->assertEquals(true, $database->createAttribute('movies', 'with-dash', Database::VAR_STRING, 128, true));
        $this->assertEquals(true, $database->createAttribute('movies', 'nullable', Database::VAR_STRING, 128, false));

        try {
            $database->createDocument('movies', new Document(['$id' => ['id_as_array']]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertEquals('$id must be of type string', $e->getMessage());
            $this->assertInstanceOf(StructureException::class, $e);
        }

        $document = $database->createDocument('movies', new Document([
            '$id' => ID::custom('frozen'),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::read(Role::user('1')),
                Permission::read(Role::user('2')),
                Permission::create(Role::any()),
                Permission::create(Role::user('1x')),
                Permission::create(Role::user('2x')),
                Permission::update(Role::any()),
                Permission::update(Role::user('1x')),
                Permission::update(Role::user('2x')),
                Permission::delete(Role::any()),
                Permission::delete(Role::user('1x')),
                Permission::delete(Role::user('2x')),
            ],
            'name' => 'Frozen',
            'director' => 'Chris Buck & Jennifer Lee',
            'year' => 2013,
            'price' => 39.50,
            'active' => true,
            'genres' => ['animation', 'kids'],
            'with-dash' => 'Works'
        ]));

        $database->createDocument('movies', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::read(Role::user('1')),
                Permission::read(Role::user('2')),
                Permission::create(Role::any()),
                Permission::create(Role::user('1x')),
                Permission::create(Role::user('2x')),
                Permission::update(Role::any()),
                Permission::update(Role::user('1x')),
                Permission::update(Role::user('2x')),
                Permission::delete(Role::any()),
                Permission::delete(Role::user('1x')),
                Permission::delete(Role::user('2x')),
            ],
            'name' => 'Frozen II',
            'director' => 'Chris Buck & Jennifer Lee',
            'year' => 2019,
            'price' => 39.50,
            'active' => true,
            'genres' => ['animation', 'kids'],
            'with-dash' => 'Works'
        ]));

        $database->createDocument('movies', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::read(Role::user('1')),
                Permission::read(Role::user('2')),
                Permission::create(Role::any()),
                Permission::create(Role::user('1x')),
                Permission::create(Role::user('2x')),
                Permission::update(Role::any()),
                Permission::update(Role::user('1x')),
                Permission::update(Role::user('2x')),
                Permission::delete(Role::any()),
                Permission::delete(Role::user('1x')),
                Permission::delete(Role::user('2x')),
            ],
            'name' => 'Captain America: The First Avenger',
            'director' => 'Joe Johnston',
            'year' => 2011,
            'price' => 25.94,
            'active' => true,
            'genres' => ['science fiction', 'action', 'comics'],
            'with-dash' => 'Works2'
        ]));

        $database->createDocument('movies', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::read(Role::user('1')),
                Permission::read(Role::user('2')),
                Permission::create(Role::any()),
                Permission::create(Role::user('1x')),
                Permission::create(Role::user('2x')),
                Permission::update(Role::any()),
                Permission::update(Role::user('1x')),
                Permission::update(Role::user('2x')),
                Permission::delete(Role::any()),
                Permission::delete(Role::user('1x')),
                Permission::delete(Role::user('2x')),
            ],
            'name' => 'Captain Marvel',
            'director' => 'Anna Boden & Ryan Fleck',
            'year' => 2019,
            'price' => 25.99,
            'active' => true,
            'genres' => ['science fiction', 'action', 'comics'],
            'with-dash' => 'Works2'
        ]));

        $database->createDocument('movies', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::read(Role::user('1')),
                Permission::read(Role::user('2')),
                Permission::create(Role::any()),
                Permission::create(Role::user('1x')),
                Permission::create(Role::user('2x')),
                Permission::update(Role::any()),
                Permission::update(Role::user('1x')),
                Permission::update(Role::user('2x')),
                Permission::delete(Role::any()),
                Permission::delete(Role::user('1x')),
                Permission::delete(Role::user('2x')),
            ],
            'name' => 'Work in Progress',
            'director' => 'TBD',
            'year' => 2025,
            'price' => 0.0,
            'active' => false,
            'genres' => [],
            'with-dash' => 'Works3'
        ]));

        $database->createDocument('movies', new Document([
            '$permissions' => [
                Permission::read(Role::user('x')),
                Permission::create(Role::any()),
                Permission::create(Role::user('1x')),
                Permission::create(Role::user('2x')),
                Permission::update(Role::any()),
                Permission::update(Role::user('1x')),
                Permission::update(Role::user('2x')),
                Permission::delete(Role::any()),
                Permission::delete(Role::user('1x')),
                Permission::delete(Role::user('2x')),
            ],
            'name' => 'Work in Progress 2',
            'director' => 'TBD',
            'year' => 2026,
            'price' => 0.0,
            'active' => false,
            'genres' => [],
            'with-dash' => 'Works3',
            'nullable' => 'Not null'
        ]));

        return [
            '$sequence' => $document->getSequence()
        ];
    }

    /**
    * @depends testFind
    */
    public function testFindOne(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->findOne('movies', [
            Query::offset(2),
            Query::orderAsc('name')
        ]);

        $this->assertFalse($document->isEmpty());
        $this->assertEquals('Frozen', $document->getAttribute('name'));

        $document = $database->findOne('movies', [
            Query::offset(10)
        ]);
        $this->assertTrue($document->isEmpty());
    }

    public function testFindBasicChecks(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->find('movies');
        $movieDocuments = $documents;

        $this->assertEquals(5, count($documents));
        $this->assertNotEmpty($documents[0]->getId());
        $this->assertEquals('movies', $documents[0]->getCollection());
        $this->assertEquals(['any', 'user:1', 'user:2'], $documents[0]->getRead());
        $this->assertEquals(['any', 'user:1x', 'user:2x'], $documents[0]->getWrite());
        $this->assertEquals('Frozen', $documents[0]->getAttribute('name'));
        $this->assertEquals('Chris Buck & Jennifer Lee', $documents[0]->getAttribute('director'));
        $this->assertIsString($documents[0]->getAttribute('director'));
        $this->assertEquals(2013, $documents[0]->getAttribute('year'));
        $this->assertIsInt($documents[0]->getAttribute('year'));
        $this->assertEquals(39.50, $documents[0]->getAttribute('price'));
        $this->assertIsFloat($documents[0]->getAttribute('price'));
        $this->assertEquals(true, $documents[0]->getAttribute('active'));
        $this->assertIsBool($documents[0]->getAttribute('active'));
        $this->assertEquals(['animation', 'kids'], $documents[0]->getAttribute('genres'));
        $this->assertIsArray($documents[0]->getAttribute('genres'));
        $this->assertEquals('Works', $documents[0]->getAttribute('with-dash'));

        // Alphabetical order
        $sortedDocuments = $movieDocuments;
        \usort($sortedDocuments, function ($doc1, $doc2) {
            return strcmp($doc1['$id'], $doc2['$id']);
        });

        $firstDocumentId = $sortedDocuments[0]->getId();
        $lastDocumentId = $sortedDocuments[\count($sortedDocuments) - 1]->getId();

        /**
         * Check $id: Notice, this orders ID names alphabetically, not by internal numeric ID
         */
        $documents = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderDesc('$id'),
        ]);
        $this->assertEquals($lastDocumentId, $documents[0]->getId());
        $documents = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderAsc('$id'),
        ]);
        $this->assertEquals($firstDocumentId, $documents[0]->getId());

        /**
         * Check internal numeric ID sorting
         */
        $documents = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderDesc(''),
        ]);
        $this->assertEquals($movieDocuments[\count($movieDocuments) - 1]->getId(), $documents[0]->getId());
        $documents = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderAsc(''),
        ]);
        $this->assertEquals($movieDocuments[0]->getId(), $documents[0]->getId());
    }

    public function testFindAttributeNamedAfterInternalKey(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection(__FUNCTION__);
        $this->assertEquals(true, $database->createAttribute(__FUNCTION__, 'collection', Database::VAR_STRING, 128, false));

        $database->createDocument(__FUNCTION__, new Document([
            '$id' => ID::custom('clash'),
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'collection' => 'value',
        ]));

        $database->createDocument(__FUNCTION__, new Document([
            '$id' => ID::custom('clashNull'),
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'collection' => null,
        ]));

        $documents = $database->find(__FUNCTION__, [Query::orderAsc('$id')]);

        $this->assertCount(2, $documents);
        $this->assertEquals('value', $documents[0]->getAttribute('collection'));
        // getAttribute() reads a dropped key and a null value the same way
        $this->assertTrue($documents[1]->offsetExists('collection'));
    }

    public function testFindAttributeNamedAfterTenantKey(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getSharedTables()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);
        $this->assertEquals(true, $database->createAttribute(__FUNCTION__, 'tenant', Database::VAR_STRING, 128, false));

        $database->createDocument(__FUNCTION__, new Document([
            '$id' => ID::custom('clash'),
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'tenant' => 'value',
        ]));

        // A select leaves _tenant out of the projection, so $tenant is null at decode
        $documents = $database->find(__FUNCTION__, [Query::select(['tenant'])]);

        $this->assertCount(1, $documents);
        $this->assertEquals('value', $documents[0]->getAttribute('tenant'));
    }

    public function testFindCheckPermissions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Check Permissions
         */
        $this->getDatabase()->getAuthorization()->addRole('user:x');
        $documents = $database->find('movies');

        $this->assertEquals(6, count($documents));
    }

    public function testFindCheckInteger(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Query with dash attribute
         */
        $documents = $database->find('movies', [
            Query::equal('with-dash', ['Works']),
        ]);

        $this->assertEquals(2, count($documents));

        $documents = $database->find('movies', [
            Query::equal('with-dash', ['Works2', 'Works3']),
        ]);

        $this->assertEquals(4, count($documents));

        /**
         * Check an Integer condition
         */
        $documents = $database->find('movies', [
            Query::equal('year', [2019]),
        ]);

        $this->assertEquals(2, count($documents));
        $this->assertEquals('Frozen II', $documents[0]['name']);
        $this->assertEquals('Captain Marvel', $documents[1]['name']);
    }

    public function testFindBoolean(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Boolean condition
         */
        $documents = $database->find('movies', [
            Query::equal('active', [true]),
        ]);

        $this->assertEquals(4, count($documents));
    }

    public function testFindStringQueryEqual(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * String condition
         */
        $documents = $database->find('movies', [
            Query::equal('director', ['TBD']),
        ]);

        $this->assertEquals(2, count($documents));

        $documents = $database->find('movies', [
            Query::equal('director', ['']),
        ]);

        $this->assertEquals(0, count($documents));
    }


    public function testFindNotEqual(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Not Equal query
         */
        $documents = $database->find('movies', [
            Query::notEqual('director', 'TBD'),
        ]);

        $this->assertGreaterThan(0, count($documents));

        foreach ($documents as $document) {
            $this->assertTrue($document['director'] !== 'TBD');
        }

        $documents = $database->find('movies', [
            Query::notEqual('director', ''),
        ]);

        $total = $database->count('movies');

        $this->assertEquals($total, count($documents));
    }

    public function testFindBetween(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->find('movies', [
            Query::between('price', 25.94, 25.99),
        ]);
        $this->assertEquals(2, count($documents));

        $documents = $database->find('movies', [
            Query::between('price', 30, 35),
        ]);
        $this->assertEquals(0, count($documents));

        $documents = $database->find('movies', [
            Query::between('$createdAt', '1975-12-06', '2050-12-06'),
        ]);
        $this->assertEquals(6, count($documents));

        $documents = $database->find('movies', [
            Query::between('$updatedAt', '1975-12-06T07:08:49.733+02:00', '2050-02-05T10:15:21.825+00:00'),
        ]);
        $this->assertEquals(6, count($documents));
    }

    public function testFindFloat(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Float condition
         */
        $documents = $database->find('movies', [
            Query::lessThan('price', 26.00),
            Query::greaterThan('price', 25.98),
        ]);

        $this->assertEquals(1, count($documents));
    }

    public function testFindContains(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForQueryContains()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $documents = $database->find('movies', [
            Query::contains('genres', ['comics'])
        ]);

        $this->assertEquals(2, count($documents));

        /**
         * Array contains OR condition
         */
        $documents = $database->find('movies', [
            Query::contains('genres', ['comics', 'kids']),
        ]);

        $this->assertEquals(4, count($documents));

        $documents = $database->find('movies', [
            Query::contains('genres', ['non-existent']),
        ]);

        $this->assertEquals(0, count($documents));

        try {
            $database->find('movies', [
                Query::contains('price', [10.5]),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertEquals('Invalid query: Cannot query contains on attribute "price" because it is not an array, string, or object.', $e->getMessage());
            $this->assertTrue($e instanceof DatabaseException);
        }
    }

    public function testFindFulltext(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Fulltext search
         */
        if ($this->getDatabase()->getAdapter()->getSupportForFulltextIndex()) {
            $success = $database->createIndex('movies', 'name', Database::INDEX_FULLTEXT, ['name']);
            $this->assertEquals(true, $success);

            $documents = $database->find('movies', [
                Query::search('name', 'captain'),
            ]);

            $this->assertEquals(2, count($documents));

            /**
             * Fulltext search (wildcard)
             */

            // TODO: Looks like the MongoDB implementation is a bit more complex, skipping that for now.
            // TODO: I think this needs a changes? how do we distinguish between regular full text and wildcard?

            if ($this->getDatabase()->getAdapter()->getSupportForFulltextWildCardIndex()) {
                $documents = $database->find('movies', [
                    Query::search('name', 'cap'),
                ]);

                $this->assertEquals(2, count($documents));
            }
        }

        $this->assertEquals(true, true); // Test must do an assertion
    }
    public function testFindFulltextSpecialChars(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForFulltextIndex()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'full_text';
        $database->createCollection($collection, permissions: [
            Permission::create(Role::any()),
            Permission::update(Role::users())
        ]);

        $this->assertTrue($database->createAttribute($collection, 'ft', Database::VAR_STRING, 128, true));
        $this->assertTrue($database->createIndex($collection, 'ft-index', Database::INDEX_FULLTEXT, ['ft']));

        $database->createDocument($collection, new Document([
            '$permissions' => [Permission::read(Role::any())],
            'ft' => 'Alf: chapter_4@nasa.com'
        ]));

        $documents = $database->find($collection, [
            Query::search('ft', 'chapter_4'),
        ]);
        $this->assertEquals(1, count($documents));

        $database->createDocument($collection, new Document([
            '$permissions' => [Permission::read(Role::any())],
            'ft' => 'al@ba.io +-*)(<>~'
        ]));

        $documents = $database->find($collection, [
            Query::search('ft', 'al@ba.io'), // === al ba io*
        ]);

        if ($database->getAdapter()->getSupportForFulltextWildcardIndex()) {
            $this->assertEquals(0, count($documents));
        } else {
            $this->assertEquals(1, count($documents));
        }

        $database->createDocument($collection, new Document([
            '$permissions' => [Permission::read(Role::any())],
            'ft' => 'donald duck'
        ]));

        $database->createDocument($collection, new Document([
            '$permissions' => [Permission::read(Role::any())],
            'ft' => 'donald trump'
        ]));

        $documents = $database->find($collection, [
            Query::search('ft', 'donald trump'),
            Query::orderAsc('ft'),
        ]);
        $this->assertEquals(2, count($documents));

        $documents = $database->find($collection, [
            Query::search('ft', '"donald trump"'), // Exact match
        ]);

        $this->assertEquals(1, count($documents));
    }

    /**
     * Regression: accented characters and non-operator special chars
     * previously caused SQLSTATE[42000] syntax error in FTS BOOLEAN MODE.
     *
     * @see https://appwrite.sentry.io/issues/5628237003
     */
    public function testFindFulltextAccentedAndSpecialChars(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForFulltextIndex()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'full_text_unicode';
        $database->createCollection($collection, permissions: [
            Permission::create(Role::any()),
            Permission::update(Role::users())
        ]);

        $this->assertTrue($database->createAttribute($collection, 'nombre', Database::VAR_STRING, 128, true));
        $this->assertTrue($database->createIndex($collection, 'nombre-ft', Database::INDEX_FULLTEXT, ['nombre']));

        $database->createDocument($collection, new Document([
            '$permissions' => [Permission::read(Role::any())],
            'nombre' => 'Luis García'
        ]));

        $database->createDocument($collection, new Document([
            '$permissions' => [Permission::read(Role::any())],
            'nombre' => 'Álvaro Yair Cuéllar'
        ]));

        $database->createDocument($collection, new Document([
            '$permissions' => [Permission::read(Role::any())],
            'nombre' => 'Fernando naïve über'
        ]));

        /**
         * Accented characters must not cause FTS parser errors
         */
        $documents = $database->find($collection, [
            Query::search('nombre', 'García'),
        ]);
        $this->assertGreaterThanOrEqual(1, count($documents));

        $documents = $database->find($collection, [
            Query::search('nombre', 'Álvaro'),
        ]);
        $this->assertGreaterThanOrEqual(1, count($documents));

        $documents = $database->find($collection, [
            Query::search('nombre', 'Cuéllar'),
        ]);
        $this->assertGreaterThanOrEqual(1, count($documents));

        /**
         * Non-operator special chars (! . #) were not stripped by old code,
         * producing values like "!!!...###*" that crash MySQL's FTS parser.
         */
        $documents = $database->find($collection, [
            Query::search('nombre', '!!!...###'),
        ]);
        $this->assertEquals(0, count($documents));

        $documents = $database->find($collection, [
            Query::search('nombre', '$$$%%%^^^'),
        ]);
        $this->assertEquals(0, count($documents));

        /**
         * FTS operator-only input also must not error
         */
        $documents = $database->find($collection, [
            Query::search('nombre', '+-*@<>~'),
        ]);
        $this->assertEquals(0, count($documents));

        /**
         * Mixed special chars + accented word should still find results
         */
        $documents = $database->find($collection, [
            Query::search('nombre', '@García!'),
        ]);
        $this->assertGreaterThanOrEqual(1, count($documents));
    }

    public function testFindMultipleConditions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Multiple conditions
         */
        $documents = $database->find('movies', [
            Query::equal('director', ['TBD']),
            Query::equal('year', [2026]),
        ]);

        $this->assertEquals(1, count($documents));

        /**
         * Multiple conditions and OR values
         */
        $documents = $database->find('movies', [
            Query::equal('name', ['Frozen II', 'Captain Marvel']),
        ]);

        $this->assertEquals(2, count($documents));
        $this->assertEquals('Frozen II', $documents[0]['name']);
        $this->assertEquals('Captain Marvel', $documents[1]['name']);
    }

    public function testFindByID(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * $id condition
         */
        $documents = $database->find('movies', [
            Query::equal('$id', ['frozen']),
        ]);

        $this->assertEquals(1, count($documents));
        $this->assertEquals('Frozen', $documents[0]['name']);
    }
    /**
     * @depends testFind
     * @param array<string, mixed> $data
     * @return void
     * @throws \Utopia\Database\Exception
     */
    public function testFindByInternalID(array $data): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Test that internal ID queries are handled correctly
         */
        $documents = $database->find('movies', [
            Query::equal('$sequence', [$data['$sequence']]),
        ]);

        $this->assertEquals(1, count($documents));
    }

    public function testFindOrderBy(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY
         */
        $documents = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('name')
        ]);

        $this->assertEquals(6, count($documents));
        $this->assertEquals('Frozen', $documents[0]['name']);
        $this->assertEquals('Frozen II', $documents[1]['name']);
        $this->assertEquals('Captain Marvel', $documents[2]['name']);
        $this->assertEquals('Captain America: The First Avenger', $documents[3]['name']);
        $this->assertEquals('Work in Progress', $documents[4]['name']);
        $this->assertEquals('Work in Progress 2', $documents[5]['name']);
    }
    public function testFindOrderByNatural(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY natural
         */
        $base = array_reverse($database->find('movies', [
            Query::limit(25),
            Query::offset(0),
        ]));
        $documents = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderDesc(''),
        ]);

        $this->assertEquals(6, count($documents));
        $this->assertEquals($base[0]['name'], $documents[0]['name']);
        $this->assertEquals($base[1]['name'], $documents[1]['name']);
        $this->assertEquals($base[2]['name'], $documents[2]['name']);
        $this->assertEquals($base[3]['name'], $documents[3]['name']);
        $this->assertEquals($base[4]['name'], $documents[4]['name']);
        $this->assertEquals($base[5]['name'], $documents[5]['name']);
    }
    public function testFindOrderByMultipleAttributes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY - Multiple attributes
         */
        $documents = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderDesc('name')
        ]);

        $this->assertEquals(6, count($documents));
        $this->assertEquals('Frozen II', $documents[0]['name']);
        $this->assertEquals('Frozen', $documents[1]['name']);
        $this->assertEquals('Captain Marvel', $documents[2]['name']);
        $this->assertEquals('Captain America: The First Avenger', $documents[3]['name']);
        $this->assertEquals('Work in Progress 2', $documents[4]['name']);
        $this->assertEquals('Work in Progress', $documents[5]['name']);
    }

    public function testFindOrderByCursorAfter(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY - After
         */
        $movies = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
        ]);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::cursorAfter($movies[1])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[2]['name'], $documents[0]['name']);
        $this->assertEquals($movies[3]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::cursorAfter($movies[3])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[4]['name'], $documents[0]['name']);
        $this->assertEquals($movies[5]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::cursorAfter($movies[4])
        ]);
        $this->assertEquals(1, count($documents));
        $this->assertEquals($movies[5]['name'], $documents[0]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::cursorAfter($movies[5])
        ]);
        $this->assertEmpty(count($documents));

        /**
         * Multiple order by, Test tie-break on year 2019
         */
        $movies = $database->find('movies', [
            Query::orderAsc('year'),
            Query::orderAsc('price'),
        ]);

        $this->assertEquals(6, count($movies));

        $this->assertEquals($movies[0]['name'], 'Captain America: The First Avenger');
        $this->assertEquals($movies[0]['year'], 2011);
        $this->assertEquals($movies[0]['price'], 25.94);

        $this->assertEquals($movies[1]['name'], 'Frozen');
        $this->assertEquals($movies[1]['year'], 2013);
        $this->assertEquals($movies[1]['price'], 39.5);

        $this->assertEquals($movies[2]['name'], 'Captain Marvel');
        $this->assertEquals($movies[2]['year'], 2019);
        $this->assertEquals($movies[2]['price'], 25.99);

        $this->assertEquals($movies[3]['name'], 'Frozen II');
        $this->assertEquals($movies[3]['year'], 2019);
        $this->assertEquals($movies[3]['price'], 39.5);

        $this->assertEquals($movies[4]['name'], 'Work in Progress');
        $this->assertEquals($movies[4]['year'], 2025);
        $this->assertEquals($movies[4]['price'], 0);

        $this->assertEquals($movies[5]['name'], 'Work in Progress 2');
        $this->assertEquals($movies[5]['year'], 2026);
        $this->assertEquals($movies[5]['price'], 0);

        $pos = 2;
        $documents = $database->find('movies', [
            Query::orderAsc('year'),
            Query::orderAsc('price'),
            Query::cursorAfter($movies[$pos])
        ]);

        $this->assertEquals(3, count($documents));

        foreach ($documents as $i => $document) {
            $this->assertEquals($document['name'], $movies[$i + 1 + $pos]['name']);
            $this->assertEquals($document['price'], $movies[$i + 1 + $pos]['price']);
            $this->assertEquals($document['year'], $movies[$i + 1 + $pos]['year']);
        }
    }


    public function testFindOrderByCursorBefore(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY - Before
         */
        $movies = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
        ]);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::cursorBefore($movies[5])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[3]['name'], $documents[0]['name']);
        $this->assertEquals($movies[4]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::cursorBefore($movies[3])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[1]['name'], $documents[0]['name']);
        $this->assertEquals($movies[2]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::cursorBefore($movies[2])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[0]['name'], $documents[0]['name']);
        $this->assertEquals($movies[1]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::cursorBefore($movies[1])
        ]);
        $this->assertEquals(1, count($documents));
        $this->assertEquals($movies[0]['name'], $documents[0]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::cursorBefore($movies[0])
        ]);
        $this->assertEmpty(count($documents));
    }

    public function testFindOrderByAfterNaturalOrder(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY - After by natural order
         */
        $movies = array_reverse($database->find('movies', [
            Query::limit(25),
            Query::offset(0),
        ]));

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc(''),
            Query::cursorAfter($movies[1])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[2]['name'], $documents[0]['name']);
        $this->assertEquals($movies[3]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc(''),
            Query::cursorAfter($movies[3])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[4]['name'], $documents[0]['name']);
        $this->assertEquals($movies[5]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc(''),
            Query::cursorAfter($movies[4])
        ]);
        $this->assertEquals(1, count($documents));
        $this->assertEquals($movies[5]['name'], $documents[0]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc(''),
            Query::cursorAfter($movies[5])
        ]);
        $this->assertEmpty(count($documents));
    }
    public function testFindOrderByBeforeNaturalOrder(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY - Before by natural order
         */
        $movies = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderDesc(''),
        ]);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc(''),
            Query::cursorBefore($movies[5])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[3]['name'], $documents[0]['name']);
        $this->assertEquals($movies[4]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc(''),
            Query::cursorBefore($movies[3])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[1]['name'], $documents[0]['name']);
        $this->assertEquals($movies[2]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc(''),
            Query::cursorBefore($movies[2])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[0]['name'], $documents[0]['name']);
        $this->assertEquals($movies[1]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc(''),
            Query::cursorBefore($movies[1])
        ]);
        $this->assertEquals(1, count($documents));
        $this->assertEquals($movies[0]['name'], $documents[0]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc(''),
            Query::cursorBefore($movies[0])
        ]);
        $this->assertEmpty(count($documents));
    }

    public function testFindOrderBySingleAttributeAfter(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY - Single Attribute After
         */
        $movies = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderDesc('year')
        ]);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('year'),
            Query::cursorAfter($movies[1])
        ]);

        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[2]['name'], $documents[0]['name']);
        $this->assertEquals($movies[3]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('year'),
            Query::cursorAfter($movies[3])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[4]['name'], $documents[0]['name']);
        $this->assertEquals($movies[5]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('year'),
            Query::cursorAfter($movies[4])
        ]);
        $this->assertEquals(1, count($documents));
        $this->assertEquals($movies[5]['name'], $documents[0]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('year'),
            Query::cursorAfter($movies[5])
        ]);
        $this->assertEmpty(count($documents));
    }


    public function testFindOrderBySingleAttributeBefore(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY - Single Attribute Before
         */
        $movies = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderDesc('year')
        ]);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('year'),
            Query::cursorBefore($movies[5])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[3]['name'], $documents[0]['name']);
        $this->assertEquals($movies[4]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('year'),
            Query::cursorBefore($movies[3])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[1]['name'], $documents[0]['name']);
        $this->assertEquals($movies[2]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('year'),
            Query::cursorBefore($movies[2])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[0]['name'], $documents[0]['name']);
        $this->assertEquals($movies[1]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('year'),
            Query::cursorBefore($movies[1])
        ]);
        $this->assertEquals(1, count($documents));
        $this->assertEquals($movies[0]['name'], $documents[0]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('year'),
            Query::cursorBefore($movies[0])
        ]);
        $this->assertEmpty(count($documents));
    }

    public function testFindOrderByMultipleAttributeAfter(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY - Multiple Attribute After
         */
        $movies = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year')
        ]);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year'),
            Query::cursorAfter($movies[1])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[2]['name'], $documents[0]['name']);
        $this->assertEquals($movies[3]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year'),
            Query::cursorAfter($movies[3])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[4]['name'], $documents[0]['name']);
        $this->assertEquals($movies[5]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year'),
            Query::cursorAfter($movies[4])
        ]);
        $this->assertEquals(1, count($documents));
        $this->assertEquals($movies[5]['name'], $documents[0]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year'),
            Query::cursorAfter($movies[5])
        ]);
        $this->assertEmpty(count($documents));
    }

    public function testFindOrderByMultipleAttributeBefore(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY - Multiple Attribute Before
         */
        $movies = $database->find('movies', [
            Query::limit(25),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year')
        ]);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year'),
            Query::cursorBefore($movies[5])
        ]);

        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[3]['name'], $documents[0]['name']);
        $this->assertEquals($movies[4]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year'),
            Query::cursorBefore($movies[4])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[2]['name'], $documents[0]['name']);
        $this->assertEquals($movies[3]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year'),
            Query::cursorBefore($movies[2])
        ]);
        $this->assertEquals(2, count($documents));
        $this->assertEquals($movies[0]['name'], $documents[0]['name']);
        $this->assertEquals($movies[1]['name'], $documents[1]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year'),
            Query::cursorBefore($movies[1])
        ]);
        $this->assertEquals(1, count($documents));
        $this->assertEquals($movies[0]['name'], $documents[0]['name']);

        $documents = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::orderAsc('year'),
            Query::cursorBefore($movies[0])
        ]);
        $this->assertEmpty(count($documents));
    }
    public function testFindOrderByAndCursor(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY + CURSOR
         */
        $documentsTest = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('price'),
        ]);
        $documents = $database->find('movies', [
            Query::limit(1),
            Query::offset(0),
            Query::orderDesc('price'),
            Query::cursorAfter($documentsTest[0])
        ]);

        $this->assertEquals($documentsTest[1]['$id'], $documents[0]['$id']);
    }
    public function testFindOrderByIdAndCursor(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY ID + CURSOR
         */
        $documentsTest = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('$id'),
        ]);
        $documents = $database->find('movies', [
            Query::limit(1),
            Query::offset(0),
            Query::orderDesc('$id'),
            Query::cursorAfter($documentsTest[0])
        ]);

        $this->assertEquals($documentsTest[1]['$id'], $documents[0]['$id']);
    }

    public function testFindOrderByCreateDateAndCursor(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY CREATE DATE + CURSOR
         */
        $documentsTest = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('$createdAt'),
        ]);

        $documents = $database->find('movies', [
            Query::limit(1),
            Query::offset(0),
            Query::orderDesc('$createdAt'),
            Query::cursorAfter($documentsTest[0])
        ]);

        $this->assertEquals($documentsTest[1]['$id'], $documents[0]['$id']);
    }

    public function testFindOrderByUpdateDateAndCursor(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * ORDER BY UPDATE DATE + CURSOR
         */
        $documentsTest = $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::orderDesc('$updatedAt'),
        ]);
        $documents = $database->find('movies', [
            Query::limit(1),
            Query::offset(0),
            Query::orderDesc('$updatedAt'),
            Query::cursorAfter($documentsTest[0])
        ]);

        $this->assertEquals($documentsTest[1]['$id'], $documents[0]['$id']);
    }

    /**
     * A batch insert stamps every row with the same `$createdAt` — createDocuments() takes
     * one timestamp for the whole batch — so ordering by that timestamp alone leaves the
     * order of those rows to the tie break Database::find() appends.
     *
     * The tie break follows the direction of the leading timestamp, so a descending order
     * returns the batch newest-inserted first. It used to be appended ascending regardless,
     * which both reversed the rows inside a tied group and produced a mixed-direction
     * ORDER BY that no ascending index can answer.
     */
    public function testFindOrderByCreateDateTieBreak(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('tieBreak', permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
        ], documentSecurity: false);

        $this->assertEquals(true, $database->createAttribute('tieBreak', 'name', Database::VAR_STRING, 128, true));

        $documents = [];
        foreach (['alpha', 'bravo', 'charlie'] as $name) {
            $documents[] = new Document([
                'name' => $name,
                '$permissions' => [Permission::read(Role::any())],
            ]);
        }

        $this->assertEquals(3, $database->createDocuments('tieBreak', $documents));

        $created = $database->find('tieBreak', [Query::orderAsc('$sequence')]);
        $this->assertCount(3, $created);

        // The batch shares one timestamp, otherwise the tie break is never consulted.
        $this->assertEquals($created[0]->getCreatedAt(), $created[2]->getCreatedAt());

        $descending = $database->find('tieBreak', [Query::orderDesc('$createdAt')]);
        $this->assertEquals(
            [$created[2]->getSequence(), $created[1]->getSequence(), $created[0]->getSequence()],
            \array_map(fn (Document $document) => $document->getSequence(), $descending)
        );

        $ascending = $database->find('tieBreak', [Query::orderAsc('$createdAt')]);
        $this->assertEquals(
            [$created[0]->getSequence(), $created[1]->getSequence(), $created[2]->getSequence()],
            \array_map(fn (Document $document) => $document->getSequence(), $ascending)
        );

        // The tie break sits behind the caller's own order, so `name` still decides the
        // rows the timestamp ties — placing a unique key ahead of it would leave it unread.
        $byName = $database->find('tieBreak', [
            Query::orderDesc('$createdAt'),
            Query::orderAsc('name'),
        ]);
        $this->assertEquals(
            ['alpha', 'bravo', 'charlie'],
            \array_map(fn (Document $document) => $document->getAttribute('name'), $byName)
        );
    }

    public function testFindCreatedBefore(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Test Query::createdBefore wrapper
         */
        $futureDate = '2050-01-01T00:00:00.000Z';
        $pastDate = '1900-01-01T00:00:00.000Z';

        $documents = $database->find('movies', [
            Query::createdBefore($futureDate),
            Query::limit(1)
        ]);

        $this->assertGreaterThan(0, count($documents));

        $documents = $database->find('movies', [
            Query::createdBefore($pastDate),
            Query::limit(1)
        ]);

        $this->assertEquals(0, count($documents));
    }

    public function testFindCreatedAfter(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Test Query::createdAfter wrapper
         */
        $futureDate = '2050-01-01T00:00:00.000Z';
        $pastDate = '1900-01-01T00:00:00.000Z';

        $documents = $database->find('movies', [
            Query::createdAfter($pastDate),
            Query::limit(1)
        ]);

        $this->assertGreaterThan(0, count($documents));

        $documents = $database->find('movies', [
            Query::createdAfter($futureDate),
            Query::limit(1)
        ]);

        $this->assertEquals(0, count($documents));
    }

    public function testFindUpdatedBefore(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Test Query::updatedBefore wrapper
         */
        $futureDate = '2050-01-01T00:00:00.000Z';
        $pastDate = '1900-01-01T00:00:00.000Z';

        $documents = $database->find('movies', [
            Query::updatedBefore($futureDate),
            Query::limit(1)
        ]);

        $this->assertGreaterThan(0, count($documents));

        $documents = $database->find('movies', [
            Query::updatedBefore($pastDate),
            Query::limit(1)
        ]);

        $this->assertEquals(0, count($documents));
    }

    public function testFindUpdatedAfter(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Test Query::updatedAfter wrapper
         */
        $futureDate = '2050-01-01T00:00:00.000Z';
        $pastDate = '1900-01-01T00:00:00.000Z';

        $documents = $database->find('movies', [
            Query::updatedAfter($pastDate),
            Query::limit(1)
        ]);

        $this->assertGreaterThan(0, count($documents));

        $documents = $database->find('movies', [
            Query::updatedAfter($futureDate),
            Query::limit(1)
        ]);

        $this->assertEquals(0, count($documents));
    }

    public function testFindCreatedBetween(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Test Query::createdBetween wrapper
         */
        $pastDate = '1900-01-01T00:00:00.000Z';
        $futureDate = '2050-01-01T00:00:00.000Z';
        $recentPastDate = '2020-01-01T00:00:00.000Z';
        $nearFutureDate = '2025-01-01T00:00:00.000Z';

        // All documents should be between past and future
        $documents = $database->find('movies', [
            Query::createdBetween($pastDate, $futureDate),
            Query::limit(25)
        ]);

        $this->assertGreaterThan(0, count($documents));

        // No documents should exist in this range
        $documents = $database->find('movies', [
            Query::createdBetween($pastDate, $pastDate),
            Query::limit(25)
        ]);

        $this->assertEquals(0, count($documents));

        // Documents created between recent past and near future
        $documents = $database->find('movies', [
            Query::createdBetween($recentPastDate, $nearFutureDate),
            Query::limit(25)
        ]);

        $count = count($documents);

        // Same count should be returned with expanded range
        $documents = $database->find('movies', [
            Query::createdBetween($pastDate, $nearFutureDate),
            Query::limit(25)
        ]);

        $this->assertGreaterThanOrEqual($count, count($documents));
    }

    public function testFindUpdatedBetween(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Test Query::updatedBetween wrapper
         */
        $pastDate = '1900-01-01T00:00:00.000Z';
        $futureDate = '2050-01-01T00:00:00.000Z';
        $recentPastDate = '2020-01-01T00:00:00.000Z';
        $nearFutureDate = '2025-01-01T00:00:00.000Z';

        // All documents should be between past and future
        $documents = $database->find('movies', [
            Query::updatedBetween($pastDate, $futureDate),
            Query::limit(25)
        ]);

        $this->assertGreaterThan(0, count($documents));

        // No documents should exist in this range
        $documents = $database->find('movies', [
            Query::updatedBetween($pastDate, $pastDate),
            Query::limit(25)
        ]);

        $this->assertEquals(0, count($documents));

        // Documents updated between recent past and near future
        $documents = $database->find('movies', [
            Query::updatedBetween($recentPastDate, $nearFutureDate),
            Query::limit(25)
        ]);

        $count = count($documents);

        // Same count should be returned with expanded range
        $documents = $database->find('movies', [
            Query::updatedBetween($pastDate, $nearFutureDate),
            Query::limit(25)
        ]);

        $this->assertGreaterThanOrEqual($count, count($documents));
    }

    public function testFindLimit(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Limit
         */
        $documents = $database->find('movies', [
            Query::limit(4),
            Query::offset(0),
            Query::orderAsc('name')
        ]);

        $this->assertEquals(4, count($documents));
        $this->assertEquals('Captain America: The First Avenger', $documents[0]['name']);
        $this->assertEquals('Captain Marvel', $documents[1]['name']);
        $this->assertEquals('Frozen', $documents[2]['name']);
        $this->assertEquals('Frozen II', $documents[3]['name']);
    }


    public function testFindLimitAndOffset(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Limit + Offset
         */
        $documents = $database->find('movies', [
            Query::limit(4),
            Query::offset(2),
            Query::orderAsc('name')
        ]);

        $this->assertEquals(4, count($documents));
        $this->assertEquals('Frozen', $documents[0]['name']);
        $this->assertEquals('Frozen II', $documents[1]['name']);
        $this->assertEquals('Work in Progress', $documents[2]['name']);
        $this->assertEquals('Work in Progress 2', $documents[3]['name']);
    }

    public function testFindOrQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Test that OR queries are handled correctly
         */
        $documents = $database->find('movies', [
            Query::equal('director', ['TBD', 'Joe Johnston']),
            Query::equal('year', [2025]),
        ]);
        $this->assertEquals(1, count($documents));
    }

    /**
     * @depends testUpdateDocument
     */
    public function testFindEdgeCases(Document $document): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collection = 'edgeCases';

        $database->createCollection($collection);

        $this->assertEquals(true, $database->createAttribute($collection, 'value', Database::VAR_STRING, 256, true));

        $values = [
            'NormalString',
            '{"type":"json","somekey":"someval"}',
            '{NormalStringInBraces}',
            '"NormalStringInDoubleQuotes"',
            '{"NormalStringInDoubleQuotesAndBraces"}',
            "'NormalStringInSingleQuotes'",
            "{'NormalStringInSingleQuotesAndBraces'}",
            "SingleQuote'InMiddle",
            'DoubleQuote"InMiddle',
            'Slash/InMiddle',
            'Backslash\InMiddle',
            'Colon:InMiddle',
            '"quoted":"colon"'
        ];

        foreach ($values as $value) {
            $database->createDocument($collection, new Document([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any())
                ],
                'value' => $value
            ]));
        }

        /**
         * Check Basic
         */
        $documents = $database->find($collection);

        $this->assertEquals(count($values), count($documents));
        $this->assertNotEmpty($documents[0]->getId());
        $this->assertEquals($collection, $documents[0]->getCollection());
        $this->assertEquals(['any'], $documents[0]->getRead());
        $this->assertEquals(['any'], $documents[0]->getUpdate());
        $this->assertEquals(['any'], $documents[0]->getDelete());
        $this->assertEquals($values[0], $documents[0]->getAttribute('value'));

        /**
         * Check `equals` query
         */
        foreach ($values as $value) {
            $documents = $database->find($collection, [
                Query::limit(25),
                Query::equal('value', [$value])
            ]);

            $this->assertEquals(1, count($documents));
            $this->assertEquals($value, $documents[0]->getAttribute('value'));
        }
    }

    public function testOrSingleQuery(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        try {
            $database->find('movies', [
                Query::or([
                    Query::equal('active', [true])
                ])
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Invalid query: Or queries require at least two queries', $e->getMessage());
        }
    }

    public function testOrMultipleQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $queries = [
            Query::or([
                Query::equal('active', [true]),
                Query::equal('name', ['Frozen II'])
            ])
        ];
        $this->assertCount(4, $database->find('movies', $queries));
        $this->assertEquals(4, $database->count('movies', $queries));

        $queries = [
            Query::equal('active', [true]),
            Query::or([
                Query::equal('name', ['Frozen']),
                Query::equal('name', ['Frozen II']),
                Query::equal('director', ['Joe Johnston'])
            ])
        ];

        $this->assertCount(3, $database->find('movies', $queries));
        $this->assertEquals(3, $database->count('movies', $queries));
    }

    public function testOrNested(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $queries = [
            Query::select(['director']),
            Query::equal('director', ['Joe Johnston']),
            Query::or([
                Query::equal('name', ['Frozen']),
                Query::or([
                    Query::equal('active', [true]),
                    Query::equal('active', [false]),
                ])
            ])
        ];

        $documents = $database->find('movies', $queries);
        $this->assertCount(1, $documents);
        $this->assertArrayNotHasKey('name', $documents[0]);

        $count = $database->count('movies', $queries);
        $this->assertEquals(1, $count);
    }

    public function testAndSingleQuery(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        try {
            $database->find('movies', [
                Query::and([
                    Query::equal('active', [true])
                ])
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Invalid query: And queries require at least two queries', $e->getMessage());
        }
    }

    public function testAndMultipleQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $queries = [
            Query::and([
                Query::equal('active', [true]),
                Query::equal('name', ['Frozen II'])
            ])
        ];
        $this->assertCount(1, $database->find('movies', $queries));
        $this->assertEquals(1, $database->count('movies', $queries));
    }

    public function testAndNested(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $queries = [
            Query::or([
                Query::equal('active', [false]),
                Query::and([
                    Query::equal('active', [true]),
                    Query::equal('name', ['Frozen']),
                ])
            ])
        ];

        $documents = $database->find('movies', $queries);
        $this->assertCount(3, $documents);

        $count = $database->count('movies', $queries);
        $this->assertEquals(3, $count);
    }

    public function testNestedIDQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        $database->createCollection('movies_nested_id', permissions: [
            Permission::create(Role::any()),
            Permission::update(Role::users())
        ]);

        $this->assertEquals(true, $database->createAttribute('movies_nested_id', 'name', Database::VAR_STRING, 128, true));

        $database->createDocument('movies_nested_id', new Document([
            '$id' => ID::custom('1'),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => '1',
        ]));

        $database->createDocument('movies_nested_id', new Document([
            '$id' => ID::custom('2'),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => '2',
        ]));

        $database->createDocument('movies_nested_id', new Document([
            '$id' => ID::custom('3'),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => '3',
        ]));

        $queries = [
            Query::or([
                Query::equal('$id', ["1"]),
                Query::equal('$id', ["2"])
            ])
        ];

        $documents = $database->find('movies_nested_id', $queries);
        $this->assertCount(2, $documents);

        // Make sure the query was not modified by reference
        $this->assertEquals($queries[0]->getValues()[0]->getAttribute(), '$id');

        $count = $database->count('movies_nested_id', $queries);
        $this->assertEquals(2, $count);
    }

    public function testFindNull(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->find('movies', [
            Query::isNull('nullable'),
        ]);

        $this->assertEquals(5, count($documents));
    }

    public function testFindNotNull(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->find('movies', [
            Query::isNotNull('nullable'),
        ]);

        $this->assertEquals(1, count($documents));
    }

    public function testFindStartsWith(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->find('movies', [
            Query::startsWith('name', 'Work'),
        ]);

        $this->assertEquals(2, count($documents));

        if ($this->getDatabase()->getAdapter() instanceof SQL) {
            $documents = $database->find('movies', [
                Query::startsWith('name', '%ork'),
            ]);
        } else {
            $documents = $database->find('movies', [
                Query::startsWith('name', '.*ork'),
            ]);
        }

        $this->assertEquals(0, count($documents));
    }

    public function testFindStartsWithWords(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->find('movies', [
            Query::startsWith('name', 'Work in Progress'),
        ]);

        $this->assertEquals(2, count($documents));
    }

    public function testFindEndsWith(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->find('movies', [
            Query::endsWith('name', 'Marvel'),
        ]);

        $this->assertEquals(1, count($documents));
    }

    public function testFindNotContains(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForQueryContains()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Test notContains with array attributes - should return documents that don't contain specified genres
        $documents = $database->find('movies', [
            Query::notContains('genres', ['comics'])
        ]);

        $this->assertEquals(4, count($documents)); // All movies except the 2 with 'comics' genre

        // Test notContains with multiple values (AND logic - exclude documents containing ANY of these)
        $documents = $database->find('movies', [
            Query::notContains('genres', ['comics', 'kids']),
        ]);

        $this->assertEquals(2, count($documents)); // Movies that have neither 'comics' nor 'kids'

        // Test notContains with non-existent genre - should return all documents
        $documents = $database->find('movies', [
            Query::notContains('genres', ['non-existent']),
        ]);

        $this->assertEquals(6, count($documents));

        // Test notContains with string attribute (substring search)
        $documents = $database->find('movies', [
            Query::notContains('name', ['Captain'])
        ]);
        $this->assertEquals(4, count($documents)); // All movies except those containing 'Captain'

        // Test notContains combined with other queries (AND logic)
        $documents = $database->find('movies', [
            Query::notContains('genres', ['comics']),
            Query::greaterThan('year', 2000)
        ]);
        $this->assertLessThanOrEqual(4, count($documents)); // Subset of movies without 'comics' and after 2000

        // Test notContains with case sensitivity
        $documents = $database->find('movies', [
            Query::notContains('genres', ['COMICS']) // Different case
        ]);
        $this->assertEquals(6, count($documents)); // All movies since case doesn't match

        // Test error handling for invalid attribute type
        try {
            $database->find('movies', [
                Query::notContains('price', [10.5]),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertEquals('Invalid query: Cannot query notContains on attribute "price" because it is not an array, string, or object.', $e->getMessage());
            $this->assertTrue($e instanceof DatabaseException);
        }
    }

    public function testFindNotSearch(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        // Only test if fulltext search is supported
        if ($this->getDatabase()->getAdapter()->getSupportForFulltextIndex()) {
            // Ensure fulltext index exists (may already exist from previous tests)
            try {
                $database->createIndex('movies', 'name', Database::INDEX_FULLTEXT, ['name']);
            } catch (Throwable $e) {
                // Index may already exist, ignore duplicate error
                if (!str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }

            // Test notSearch - should return documents that don't match the search term
            $documents = $database->find('movies', [
                Query::notSearch('name', 'captain'),
            ]);

            $this->assertEquals(4, count($documents)); // All movies except the 2 with 'captain' in name

            // Test notSearch with term that doesn't exist - should return all documents
            $documents = $database->find('movies', [
                Query::notSearch('name', 'nonexistent'),
            ]);

            $this->assertEquals(6, count($documents));

            // Test notSearch with partial term
            if ($this->getDatabase()->getAdapter()->getSupportForFulltextWildCardIndex()) {
                $documents = $database->find('movies', [
                    Query::notSearch('name', 'cap'),
                ]);

                $this->assertEquals(4, count($documents)); // All movies except those matching 'cap'
            }

            // Test notSearch with empty string - should return all documents
            $documents = $database->find('movies', [
                Query::notSearch('name', ''),
            ]);
            $this->assertEquals(6, count($documents)); // All movies since empty search matches nothing

            // Test notSearch combined with other filters
            $documents = $database->find('movies', [
                Query::notSearch('name', 'captain'),
                Query::lessThan('year', 2010)
            ]);
            $this->assertLessThanOrEqual(4, count($documents)); // Subset of non-captain movies before 2010

            // Test notSearch with special characters
            $documents = $database->find('movies', [
                Query::notSearch('name', '@#$%'),
            ]);
            $this->assertEquals(6, count($documents)); // All movies since special chars don't match
        }

        $this->assertEquals(true, true); // Test must do an assertion
    }

    public function testFindNotStartsWith(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        // Test notStartsWith - should return documents that don't start with 'Work'
        $documents = $database->find('movies', [
            Query::notStartsWith('name', 'Work'),
        ]);

        $this->assertEquals(4, count($documents)); // All movies except the 2 starting with 'Work'

        // Test notStartsWith with non-existent prefix - should return all documents
        $documents = $database->find('movies', [
            Query::notStartsWith('name', 'NonExistent'),
        ]);

        $this->assertEquals(6, count($documents));

        // Test notStartsWith with wildcard characters (should treat them literally)
        if ($this->getDatabase()->getAdapter() instanceof SQL) {
            $documents = $database->find('movies', [
                Query::notStartsWith('name', '%ork'),
            ]);
        } else {
            $documents = $database->find('movies', [
                Query::notStartsWith('name', '.*ork'),
            ]);
        }

        $this->assertEquals(6, count($documents)); // Should return all since no movie starts with these patterns

        // Test notStartsWith with empty string - should return no documents (all strings start with empty)
        $documents = $database->find('movies', [
            Query::notStartsWith('name', ''),
        ]);
        $this->assertEquals(0, count($documents)); // No documents since all strings start with empty string

        // Test notStartsWith with single character
        $documents = $database->find('movies', [
            Query::notStartsWith('name', 'C'),
        ]);
        $this->assertGreaterThanOrEqual(4, count($documents)); // Movies not starting with 'C'

        // Test notStartsWith with case sensitivity (may be case-insensitive depending on DB)
        $documents = $database->find('movies', [
            Query::notStartsWith('name', 'work'), // lowercase vs 'Work'
        ]);
        $this->assertGreaterThanOrEqual(4, count($documents)); // May match case-insensitively

        // Test notStartsWith combined with other queries
        $documents = $database->find('movies', [
            Query::notStartsWith('name', 'Work'),
            Query::equal('year', [2006])
        ]);
        $this->assertLessThanOrEqual(4, count($documents)); // Subset of non-Work movies from 2006
    }

    public function testFindNotEndsWith(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        // Test notEndsWith - should return documents that don't end with 'Marvel'
        $documents = $database->find('movies', [
            Query::notEndsWith('name', 'Marvel'),
        ]);

        $this->assertEquals(5, count($documents)); // All movies except the 1 ending with 'Marvel'

        // Test notEndsWith with non-existent suffix - should return all documents
        $documents = $database->find('movies', [
            Query::notEndsWith('name', 'NonExistent'),
        ]);

        $this->assertEquals(6, count($documents));

        // Test notEndsWith with partial suffix
        $documents = $database->find('movies', [
            Query::notEndsWith('name', 'vel'),
        ]);

        $this->assertEquals(5, count($documents)); // All movies except the 1 ending with 'vel' (from 'Marvel')

        // Test notEndsWith with empty string - should return no documents (all strings end with empty)
        $documents = $database->find('movies', [
            Query::notEndsWith('name', ''),
        ]);
        $this->assertEquals(0, count($documents)); // No documents since all strings end with empty string

        // Test notEndsWith with single character
        $documents = $database->find('movies', [
            Query::notEndsWith('name', 'l'),
        ]);
        $this->assertGreaterThanOrEqual(5, count($documents)); // Movies not ending with 'l'

        // Test notEndsWith with case sensitivity (may be case-insensitive depending on DB)
        $documents = $database->find('movies', [
            Query::notEndsWith('name', 'marvel'), // lowercase vs 'Marvel'
        ]);
        $this->assertGreaterThanOrEqual(5, count($documents)); // May match case-insensitively

        // Test notEndsWith combined with limit
        $documents = $database->find('movies', [
            Query::notEndsWith('name', 'Marvel'),
            Query::limit(3)
        ]);
        $this->assertEquals(3, count($documents)); // Limited to 3 results
        $this->assertLessThanOrEqual(5, count($documents)); // But still excluding Marvel movies
    }

    public function testFindOrderRandom(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForOrderRandom()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Test orderRandom with default limit
        $documents = $database->find('movies', [
            Query::orderRandom(),
            Query::limit(1),
        ]);
        $this->assertEquals(1, count($documents));
        $this->assertNotEmpty($documents[0]['name']); // Ensure we got a valid document

        // Test orderRandom with multiple documents
        $documents = $database->find('movies', [
            Query::orderRandom(),
            Query::limit(3),
        ]);
        $this->assertEquals(3, count($documents));

        // Test that orderRandom returns different results (not guaranteed but highly likely)
        $firstSet = $database->find('movies', [
            Query::orderRandom(),
            Query::limit(3),
        ]);
        $secondSet = $database->find('movies', [
            Query::orderRandom(),
            Query::limit(3),
        ]);

        // Extract IDs for comparison
        $firstIds = array_map(fn ($doc) => $doc['$id'], $firstSet);
        $secondIds = array_map(fn ($doc) => $doc['$id'], $secondSet);

        // While not guaranteed to be different, with 6 movies and selecting 3,
        // the probability of getting the same set in the same order is very low
        // We'll just check that we got valid results
        $this->assertEquals(3, count($firstIds));
        $this->assertEquals(3, count($secondIds));

        // Test orderRandom with more than available documents
        $documents = $database->find('movies', [
            Query::orderRandom(),
            Query::limit(10), // We only have 6 movies
        ]);
        $this->assertLessThanOrEqual(6, count($documents)); // Should return all available documents

        // Test orderRandom with filters
        $documents = $database->find('movies', [
            Query::greaterThan('price', 10),
            Query::orderRandom(),
            Query::limit(2),
        ]);
        $this->assertLessThanOrEqual(2, count($documents));
        foreach ($documents as $document) {
            $this->assertGreaterThan(10, $document['price']);
        }

        // Test orderRandom without explicit limit (should use default)
        $documents = $database->find('movies', [
            Query::orderRandom(),
        ]);
        $this->assertGreaterThan(0, count($documents));
        $this->assertLessThanOrEqual(25, count($documents)); // Default limit is 25
    }

    public function testFindNotBetween(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        // Test notBetween with price range - should return documents outside the range
        $documents = $database->find('movies', [
            Query::notBetween('price', 25.94, 25.99),
        ]);
        $this->assertEquals(4, count($documents)); // All movies except the 2 in the price range

        // Test notBetween with range that includes no documents - should return all documents
        $documents = $database->find('movies', [
            Query::notBetween('price', 30, 35),
        ]);
        $this->assertEquals(6, count($documents));

        // Test notBetween with date range
        $documents = $database->find('movies', [
            Query::notBetween('$createdAt', '1975-12-06', '2050-12-06'),
        ]);
        $this->assertEquals(0, count($documents)); // No movies outside this wide date range

        // Test notBetween with narrower date range
        $documents = $database->find('movies', [
            Query::notBetween('$createdAt', '2000-01-01', '2001-01-01'),
        ]);
        $this->assertEquals(6, count($documents)); // All movies should be outside this narrow range

        // Test notBetween with updated date range
        $documents = $database->find('movies', [
            Query::notBetween('$updatedAt', '2000-01-01T00:00:00.000+00:00', '2001-01-01T00:00:00.000+00:00'),
        ]);
        $this->assertEquals(6, count($documents)); // All movies should be outside this narrow range

        // Test notBetween with year range (integer values)
        $documents = $database->find('movies', [
            Query::notBetween('year', 2005, 2007),
        ]);
        $this->assertLessThanOrEqual(6, count($documents)); // Movies outside 2005-2007 range

        // Test notBetween with reversed range (start > end) - should still work
        $documents = $database->find('movies', [
            Query::notBetween('price', 25.99, 25.94), // Note: reversed order
        ]);
        $this->assertGreaterThanOrEqual(4, count($documents)); // Should handle reversed range gracefully

        // Test notBetween with same start and end values
        $documents = $database->find('movies', [
            Query::notBetween('year', 2006, 2006),
        ]);
        $this->assertGreaterThanOrEqual(5, count($documents)); // All movies except those from exactly 2006

        // Test notBetween combined with other filters
        $documents = $database->find('movies', [
            Query::notBetween('price', 25.94, 25.99),
            Query::orderDesc('year'),
            Query::limit(2)
        ]);
        $this->assertEquals(2, count($documents)); // Limited results, ordered, excluding price range

        // Test notBetween with extreme ranges
        $documents = $database->find('movies', [
            Query::notBetween('year', -1000, 1000), // Very wide range
        ]);
        $this->assertLessThanOrEqual(6, count($documents)); // Movies outside this range

        // Test notBetween with float precision
        $documents = $database->find('movies', [
            Query::notBetween('price', 25.945, 25.955), // Very narrow range
        ]);
        $this->assertGreaterThanOrEqual(4, count($documents)); // Most movies should be outside this narrow range
    }

    public function testFindSelect(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->find('movies', [
            Query::select(['name', 'year'])
        ]);

        foreach ($documents as $document) {
            $this->assertArrayHasKey('name', $document);
            $this->assertArrayHasKey('year', $document);
            $this->assertArrayNotHasKey('director', $document);
            $this->assertArrayNotHasKey('price', $document);
            $this->assertArrayNotHasKey('active', $document);
            $this->assertArrayHasKey('$id', $document);
            $this->assertArrayHasKey('$sequence', $document);
            $this->assertArrayHasKey('$collection', $document);
            $this->assertArrayHasKey('$createdAt', $document);
            $this->assertArrayHasKey('$updatedAt', $document);
            $this->assertArrayHasKey('$permissions', $document);
        }

        $documents = $database->find('movies', [
            Query::select(['name', 'year', '$id'])
        ]);

        foreach ($documents as $document) {
            $this->assertArrayHasKey('name', $document);
            $this->assertArrayHasKey('year', $document);
            $this->assertArrayNotHasKey('director', $document);
            $this->assertArrayNotHasKey('price', $document);
            $this->assertArrayNotHasKey('active', $document);
            $this->assertArrayHasKey('$id', $document);
            $this->assertArrayHasKey('$sequence', $document);
            $this->assertArrayHasKey('$collection', $document);
            $this->assertArrayHasKey('$createdAt', $document);
            $this->assertArrayHasKey('$updatedAt', $document);
            $this->assertArrayHasKey('$permissions', $document);
        }

        $documents = $database->find('movies', [
            Query::select(['name', 'year', '$sequence'])
        ]);

        foreach ($documents as $document) {
            $this->assertArrayHasKey('name', $document);
            $this->assertArrayHasKey('year', $document);
            $this->assertArrayNotHasKey('director', $document);
            $this->assertArrayNotHasKey('price', $document);
            $this->assertArrayNotHasKey('active', $document);
            $this->assertArrayHasKey('$id', $document);
            $this->assertArrayHasKey('$sequence', $document);
            $this->assertArrayHasKey('$collection', $document);
            $this->assertArrayHasKey('$createdAt', $document);
            $this->assertArrayHasKey('$updatedAt', $document);
            $this->assertArrayHasKey('$permissions', $document);
        }

        $documents = $database->find('movies', [
            Query::select(['name', 'year', '$collection'])
        ]);

        foreach ($documents as $document) {
            $this->assertArrayHasKey('name', $document);
            $this->assertArrayHasKey('year', $document);
            $this->assertArrayNotHasKey('director', $document);
            $this->assertArrayNotHasKey('price', $document);
            $this->assertArrayNotHasKey('active', $document);
            $this->assertArrayHasKey('$id', $document);
            $this->assertArrayHasKey('$sequence', $document);
            $this->assertArrayHasKey('$collection', $document);
            $this->assertArrayHasKey('$createdAt', $document);
            $this->assertArrayHasKey('$updatedAt', $document);
            $this->assertArrayHasKey('$permissions', $document);
        }

        $documents = $database->find('movies', [
            Query::select(['name', 'year', '$createdAt'])
        ]);

        foreach ($documents as $document) {
            $this->assertArrayHasKey('name', $document);
            $this->assertArrayHasKey('year', $document);
            $this->assertArrayNotHasKey('director', $document);
            $this->assertArrayNotHasKey('price', $document);
            $this->assertArrayNotHasKey('active', $document);
            $this->assertArrayHasKey('$id', $document);
            $this->assertArrayHasKey('$sequence', $document);
            $this->assertArrayHasKey('$collection', $document);
            $this->assertArrayHasKey('$createdAt', $document);
            $this->assertArrayHasKey('$updatedAt', $document);
            $this->assertArrayHasKey('$permissions', $document);
        }

        $documents = $database->find('movies', [
            Query::select(['name', 'year', '$updatedAt'])
        ]);

        foreach ($documents as $document) {
            $this->assertArrayHasKey('name', $document);
            $this->assertArrayHasKey('year', $document);
            $this->assertArrayNotHasKey('director', $document);
            $this->assertArrayNotHasKey('price', $document);
            $this->assertArrayNotHasKey('active', $document);
            $this->assertArrayHasKey('$id', $document);
            $this->assertArrayHasKey('$sequence', $document);
            $this->assertArrayHasKey('$collection', $document);
            $this->assertArrayHasKey('$createdAt', $document);
            $this->assertArrayHasKey('$updatedAt', $document);
            $this->assertArrayHasKey('$permissions', $document);
        }

        $documents = $database->find('movies', [
            Query::select(['name', 'year', '$permissions'])
        ]);

        foreach ($documents as $document) {
            $this->assertArrayHasKey('name', $document);
            $this->assertArrayHasKey('year', $document);
            $this->assertArrayNotHasKey('director', $document);
            $this->assertArrayNotHasKey('price', $document);
            $this->assertArrayNotHasKey('active', $document);
            $this->assertArrayHasKey('$id', $document);
            $this->assertArrayHasKey('$sequence', $document);
            $this->assertArrayHasKey('$collection', $document);
            $this->assertArrayHasKey('$createdAt', $document);
            $this->assertArrayHasKey('$updatedAt', $document);
            $this->assertArrayHasKey('$permissions', $document);
        }
    }

    /** @depends testFind */
    public function testForeach(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        /**
         * Test, foreach generator on empty collection
         */
        $database->createCollection('moviesEmpty');
        $documents = [];
        foreach ($database->iterate('moviesEmpty', queries: [Query::limit(2)]) as $document) {
            $documents[] = $document;
        }
        $this->assertEquals(0, \count($documents));
        $this->assertTrue($database->deleteCollection('moviesEmpty'));

        /**
         * Test, foreach generator
         */
        $documents = [];
        foreach ($database->iterate('movies', queries: [Query::limit(2)]) as $document) {
            $documents[] = $document;
        }
        $this->assertEquals(6, count($documents));

        /**
         * Test, foreach goes through all the documents
         */
        $documents = [];
        $database->foreach('movies', queries: [Query::limit(2)], callback: function ($document) use (&$documents) {
            $documents[] = $document;
        });
        $this->assertEquals(6, count($documents));

        /**
         * Test, foreach with initial cursor
         */

        $first = $documents[0];
        $documents = [];
        $database->foreach('movies', queries: [Query::limit(2), Query::cursorAfter($first)], callback: function ($document) use (&$documents) {
            $documents[] = $document;
        });
        $this->assertEquals(5, count($documents));

        /**
         * Test, foreach with initial offset
         */

        $documents = [];
        $database->foreach('movies', queries: [Query::limit(2), Query::offset(2)], callback: function ($document) use (&$documents) {
            $documents[] = $document;
        });
        $this->assertEquals(4, count($documents));

        /**
         * Test, cursor before throws error
         */
        try {
            $database->foreach('movies', queries: [Query::cursorBefore($documents[0]), Query::offset(2)], callback: function ($document) use (&$documents) {
                $documents[] = $document;
            });

        } catch (Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
            $this->assertEquals('Cursor ' . Database::CURSOR_BEFORE . ' not supported in this method.', $e->getMessage());
        }

    }

    /**
     * @depends testFind
     */
    public function testCount(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $count = $database->count('movies');
        $this->assertEquals(6, $count);
        $count = $database->count('movies', [Query::equal('year', [2019])]);

        $this->assertEquals(2, $count);
        $count = $database->count('movies', [Query::equal('with-dash', ['Works'])]);
        $this->assertEquals(2, $count);
        $count = $database->count('movies', [Query::equal('with-dash', ['Works2', 'Works3'])]);
        $this->assertEquals(4, $count);

        $this->getDatabase()->getAuthorization()->removeRole('user:x');
        $count = $database->count('movies');
        $this->assertEquals(5, $count);

        $this->getDatabase()->getAuthorization()->disable();
        $count = $database->count('movies');
        $this->assertEquals(6, $count);
        $this->getDatabase()->getAuthorization()->reset();

        $this->getDatabase()->getAuthorization()->disable();
        $count = $database->count('movies', [], 3);
        $this->assertEquals(3, $count);
        $this->getDatabase()->getAuthorization()->reset();

        /**
         * Test that OR queries are handled correctly
         */
        $this->getDatabase()->getAuthorization()->disable();
        $count = $database->count('movies', [
            Query::equal('director', ['TBD', 'Joe Johnston']),
            Query::equal('year', [2025]),
        ]);
        $this->assertEquals(1, $count);
        $this->getDatabase()->getAuthorization()->reset();
    }

    /**
     * @depends testFind
     */
    public function testSum(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->getDatabase()->getAuthorization()->addRole('user:x');

        $sum = $database->sum('movies', 'year', [Query::equal('year', [2019]),]);
        $this->assertEquals(2019 + 2019, $sum);
        $sum = $database->sum('movies', 'year');
        $this->assertEquals(2013 + 2019 + 2011 + 2019 + 2025 + 2026, $sum);
        $sum = $database->sum('movies', 'price', [Query::equal('year', [2019]),]);
        $this->assertEquals(round(39.50 + 25.99, 2), round($sum, 2));
        $sum = $database->sum('movies', 'price', [Query::equal('year', [2019]),]);
        $this->assertEquals(round(39.50 + 25.99, 2), round($sum, 2));

        $sum = $database->sum('movies', 'year', [Query::equal('year', [2019])], 1);
        $this->assertEquals(2019, $sum);

        $this->getDatabase()->getAuthorization()->removeRole('user:x');

        $sum = $database->sum('movies', 'year', [Query::equal('year', [2019]),]);
        $this->assertEquals(2019 + 2019, $sum);
        $sum = $database->sum('movies', 'year');
        $this->assertEquals(2013 + 2019 + 2011 + 2019 + 2025, $sum);
        $sum = $database->sum('movies', 'price', [Query::equal('year', [2019]),]);
        $this->assertEquals(round(39.50 + 25.99, 2), round($sum, 2));
        $sum = $database->sum('movies', 'price', [Query::equal('year', [2019]),]);
        $this->assertEquals(round(39.50 + 25.99, 2), round($sum, 2));
    }

    public function testIntegersBeyondInt32(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection(__FUNCTION__, attributes: [
            new Document([
                '$id' => 'amount',
                'type' => Database::VAR_INTEGER,
                'size' => 8,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => 'amounts',
                'type' => Database::VAR_INTEGER,
                'size' => 8,
                'required' => true,
                'signed' => true,
                'array' => true,
                'filters' => [],
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ], documentSecurity: false);

        // Small values encode as int32, large ones as int64. Mongo hands the
        // latter back wrapped, so both widths have to appear in one row.
        $database->createDocument(__FUNCTION__, new Document([
            '$id' => 'row1',
            'amount' => 2000000000,
            'amounts' => [-3408048000, -42, 3408048000, Database::MAX_BIG_INT],
        ]));
        $database->createDocument(__FUNCTION__, new Document([
            '$id' => 'row2',
            'amount' => 2000000000,
            'amounts' => [-42],
        ]));

        foreach (['getDocument' => $database->getDocument(__FUNCTION__, 'row1'), 'find' => $database->find(__FUNCTION__, [Query::equal('$id', ['row1'])])[0]] as $path => $document) {
            $this->assertIsInt($document->getAttribute('amount'), $path . ' returned a non-int scalar');

            $amounts = $document->getAttribute('amounts');
            foreach ($amounts as $index => $amount) {
                $this->assertIsInt($amount, $path . ' returned a non-int at amounts[' . $index . ']');
            }

            $this->assertSame([-3408048000, -42, 3408048000, Database::MAX_BIG_INT], $amounts);

            // An Int64 wrapper survives assertSame above but serialises as
            // {"$numberLong":"..."}, which is what reaches an API client.
            $this->assertSame(
                '{"amount":2000000000,"amounts":[-3408048000,-42,3408048000,' . Database::MAX_BIG_INT . ']}',
                \json_encode([
                    'amount' => $document->getAttribute('amount'),
                    'amounts' => $amounts,
                ]),
                $path . ' did not serialise as plain JSON numbers'
            );
        }

        // sum() declares float|int, so a total past int32 is a return type
        // violation unless the adapter hands back a native integer.
        $sum = $database->sum(__FUNCTION__, 'amount');
        $this->assertIsInt($sum);
        $this->assertSame(4000000000, $sum);
    }

    public function testEncodeDecode(): void
    {
        $collection = new Document([
            '$collection' => ID::custom(Database::METADATA),
            '$id' => ID::custom('users'),
            'name' => 'Users',
            'attributes' => [
                [
                    '$id' => ID::custom('name'),
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 256,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => [],
                ],
                [
                    '$id' => ID::custom('email'),
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 1024,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => [],
                ],
                [
                    '$id' => ID::custom('status'),
                    'type' => Database::VAR_INTEGER,
                    'format' => '',
                    'size' => 0,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => [],
                ],
                [
                    '$id' => ID::custom('password'),
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 16384,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => [],
                ],
                [
                    '$id' => ID::custom('passwordUpdate'),
                    'type' => Database::VAR_DATETIME,
                    'format' => '',
                    'size' => 0,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => ['datetime'],
                ],
                [
                    '$id' => ID::custom('registration'),
                    'type' => Database::VAR_DATETIME,
                    'format' => '',
                    'size' => 0,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => ['datetime'],
                ],
                [
                    '$id' => ID::custom('emailVerification'),
                    'type' => Database::VAR_BOOLEAN,
                    'format' => '',
                    'size' => 0,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => [],
                ],
                [
                    '$id' => ID::custom('reset'),
                    'type' => Database::VAR_BOOLEAN,
                    'format' => '',
                    'size' => 0,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => [],
                ],
                [
                    '$id' => ID::custom('prefs'),
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 16384,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => ['json']
                ],
                [
                    '$id' => ID::custom('sessions'),
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 16384,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => ['json'],
                ],
                [
                    '$id' => ID::custom('tokens'),
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 16384,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => ['json'],
                ],
                [
                    '$id' => ID::custom('memberships'),
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 16384,
                    'signed' => true,
                    'required' => false,
                    'array' => false,
                    'filters' => ['json'],
                ],
                [
                    '$id' => ID::custom('roles'),
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 128,
                    'signed' => true,
                    'required' => false,
                    'array' => true,
                    'filters' => [],
                ],
                [
                    '$id' => ID::custom('tags'),
                    'type' => Database::VAR_STRING,
                    'format' => '',
                    'size' => 128,
                    'signed' => true,
                    'required' => false,
                    'array' => true,
                    'filters' => ['json'],
                ],
            ],
            'indexes' => [
                [
                    '$id' => ID::custom('_key_email'),
                    'type' => Database::INDEX_UNIQUE,
                    'attributes' => ['email'],
                    'lengths' => [1024],
                    'orders' => [Database::ORDER_ASC],
                ]
            ],
        ]);

        $document = new Document([
            '$id' => ID::custom('608fdbe51361a'),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::user('608fdbe51361a')),
                Permission::update(Role::user('608fdbe51361a')),
                Permission::delete(Role::user('608fdbe51361a')),
            ],
            'email' => 'test@example.com',
            'emailVerification' => false,
            'status' => 1,
            'password' => 'randomhash',
            'passwordUpdate' => '2000-06-12 14:12:55',
            'registration' => '1975-06-12 14:12:55+01:00',
            'reset' => false,
            'name' => 'My Name',
            'prefs' => new \stdClass(),
            'sessions' => [],
            'tokens' => [],
            'memberships' => [],
            'roles' => [
                'admin',
                'developer',
                'tester',
            ],
            'tags' => [
                ['$id' => '1', 'label' => 'x'],
                ['$id' => '2', 'label' => 'y'],
                ['$id' => '3', 'label' => 'z'],
            ],
        ]);

        /** @var Database $database */
        $database = $this->getDatabase();

        $result = $database->encode($collection, $document);

        $this->assertEquals('608fdbe51361a', $result->getAttribute('$id'));
        $this->assertContains('read("any")', $result->getAttribute('$permissions'));
        $this->assertContains('read("any")', $result->getPermissions());
        $this->assertContains('any', $result->getRead());
        $this->assertContains(Permission::create(Role::user(ID::custom('608fdbe51361a'))), $result->getPermissions());
        $this->assertContains('user:608fdbe51361a', $result->getCreate());
        $this->assertContains('user:608fdbe51361a', $result->getWrite());
        $this->assertEquals('test@example.com', $result->getAttribute('email'));
        $this->assertEquals(false, $result->getAttribute('emailVerification'));
        $this->assertEquals(1, $result->getAttribute('status'));
        $this->assertEquals('randomhash', $result->getAttribute('password'));
        $this->assertEquals('2000-06-12 14:12:55.000', $result->getAttribute('passwordUpdate'));
        $this->assertEquals('1975-06-12 13:12:55.000', $result->getAttribute('registration'));
        $this->assertEquals(false, $result->getAttribute('reset'));
        $this->assertEquals('My Name', $result->getAttribute('name'));
        $this->assertEquals('{}', $result->getAttribute('prefs'));
        $this->assertEquals('[]', $result->getAttribute('sessions'));
        $this->assertEquals('[]', $result->getAttribute('tokens'));
        $this->assertEquals('[]', $result->getAttribute('memberships'));
        $this->assertEquals(['admin', 'developer', 'tester',], $result->getAttribute('roles'));
        $this->assertEquals(['{"$id":"1","label":"x"}', '{"$id":"2","label":"y"}', '{"$id":"3","label":"z"}',], $result->getAttribute('tags'));

        $result = $database->decode($collection, $document);

        $this->assertEquals('608fdbe51361a', $result->getAttribute('$id'));
        $this->assertContains('read("any")', $result->getAttribute('$permissions'));
        $this->assertContains('read("any")', $result->getPermissions());
        $this->assertContains('any', $result->getRead());
        $this->assertContains(Permission::create(Role::user('608fdbe51361a')), $result->getPermissions());
        $this->assertContains('user:608fdbe51361a', $result->getCreate());
        $this->assertContains('user:608fdbe51361a', $result->getWrite());
        $this->assertEquals('test@example.com', $result->getAttribute('email'));
        $this->assertEquals(false, $result->getAttribute('emailVerification'));
        $this->assertEquals(1, $result->getAttribute('status'));
        $this->assertEquals('randomhash', $result->getAttribute('password'));
        $this->assertEquals('2000-06-12T14:12:55.000+00:00', $result->getAttribute('passwordUpdate'));
        $this->assertEquals('1975-06-12T13:12:55.000+00:00', $result->getAttribute('registration'));
        $this->assertEquals(false, $result->getAttribute('reset'));
        $this->assertEquals('My Name', $result->getAttribute('name'));
        $this->assertEquals([], $result->getAttribute('prefs'));
        $this->assertEquals([], $result->getAttribute('sessions'));
        $this->assertEquals([], $result->getAttribute('tokens'));
        $this->assertEquals([], $result->getAttribute('memberships'));
        $this->assertEquals(['admin', 'developer', 'tester',], $result->getAttribute('roles'));
        $this->assertEquals([
            new Document(['$id' => '1', 'label' => 'x']),
            new Document(['$id' => '2', 'label' => 'y']),
            new Document(['$id' => '3', 'label' => 'z']),
        ], $result->getAttribute('tags'));
    }
    /**
     * @depends testGetDocument
     */
    public function testUpdateDocument(Document $document): Document
    {
        $document
            ->setAttribute('string', 'text📝 updated')
            ->setAttribute('integer_signed', -6)
            ->setAttribute('integer_unsigned', 6)
            ->setAttribute('float_signed', -5.56)
            ->setAttribute('float_unsigned', 5.56)
            ->setAttribute('boolean', false)
            ->setAttribute('colors', 'red', Document::SET_TYPE_APPEND)
            ->setAttribute('with-dash', 'Works');

        $new = $this->getDatabase()->updateDocument($document->getCollection(), $document->getId(), $document);

        $this->assertNotEmpty($new->getId());
        $this->assertIsString($new->getAttribute('string'));
        $this->assertEquals('text📝 updated', $new->getAttribute('string'));
        $this->assertIsInt($new->getAttribute('integer_signed'));
        $this->assertEquals(-6, $new->getAttribute('integer_signed'));
        $this->assertIsInt($new->getAttribute('integer_unsigned'));
        $this->assertEquals(6, $new->getAttribute('integer_unsigned'));
        $this->assertIsFloat($new->getAttribute('float_signed'));
        $this->assertEquals(-5.56, $new->getAttribute('float_signed'));
        $this->assertIsFloat($new->getAttribute('float_unsigned'));
        $this->assertEquals(5.56, $new->getAttribute('float_unsigned'));
        $this->assertIsBool($new->getAttribute('boolean'));
        $this->assertEquals(false, $new->getAttribute('boolean'));
        $this->assertIsArray($new->getAttribute('colors'));
        $this->assertEquals(['pink', 'green', 'blue', 'red'], $new->getAttribute('colors'));
        $this->assertEquals('Works', $new->getAttribute('with-dash'));

        $oldPermissions = $document->getPermissions();

        $new
            ->setAttribute('$permissions', Permission::read(Role::guests()), Document::SET_TYPE_APPEND)
            ->setAttribute('$permissions', Permission::create(Role::guests()), Document::SET_TYPE_APPEND)
            ->setAttribute('$permissions', Permission::update(Role::guests()), Document::SET_TYPE_APPEND)
            ->setAttribute('$permissions', Permission::delete(Role::guests()), Document::SET_TYPE_APPEND);

        $this->getDatabase()->updateDocument($new->getCollection(), $new->getId(), $new);

        $new = $this->getDatabase()->getDocument($new->getCollection(), $new->getId());

        $this->assertContains('guests', $new->getRead());
        $this->assertContains('guests', $new->getWrite());
        $this->assertContains('guests', $new->getCreate());
        $this->assertContains('guests', $new->getUpdate());
        $this->assertContains('guests', $new->getDelete());

        $new->setAttribute('$permissions', $oldPermissions);

        $this->getDatabase()->updateDocument($new->getCollection(), $new->getId(), $new);

        $new = $this->getDatabase()->getDocument($new->getCollection(), $new->getId());

        $this->assertNotContains('guests', $new->getRead());
        $this->assertNotContains('guests', $new->getWrite());
        $this->assertNotContains('guests', $new->getCreate());
        $this->assertNotContains('guests', $new->getUpdate());
        $this->assertNotContains('guests', $new->getDelete());

        // Test change document ID
        $id = $new->getId();
        $newId = 'new-id';
        $new->setAttribute('$id', $newId);
        $new = $this->getDatabase()->updateDocument($new->getCollection(), $id, $new);
        $this->assertEquals($newId, $new->getId());

        // Reset ID
        $new->setAttribute('$id', $id);
        $new = $this->getDatabase()->updateDocument($new->getCollection(), $newId, $new);
        $this->assertEquals($id, $new->getId());

        return $document;
    }


    /**
     * @depends testUpdateDocument
     */
    public function testUpdateDocumentConflict(Document $document): void
    {
        $document->setAttribute('integer_signed', 7);
        $result = $this->getDatabase()->withRequestTimestamp(new \DateTime(), function () use ($document) {
            return $this->getDatabase()->updateDocument($document->getCollection(), $document->getId(), $document);
        });
        $this->assertEquals(7, $result->getAttribute('integer_signed'));

        $oneHourAgo = (new \DateTime())->sub(new \DateInterval('PT1H'));
        $document->setAttribute('integer_signed', 8);
        try {
            $this->getDatabase()->withRequestTimestamp($oneHourAgo, function () use ($document) {
                return $this->getDatabase()->updateDocument($document->getCollection(), $document->getId(), $document);
            });
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertTrue($e instanceof ConflictException);
            $this->assertEquals('Document was updated after the request timestamp', $e->getMessage());
        }
    }

    /**
     * @depends testUpdateDocument
     */
    public function testDeleteDocumentConflict(Document $document): void
    {
        $oneHourAgo = (new \DateTime())->sub(new \DateInterval('PT1H'));
        $this->expectException(ConflictException::class);
        $this->getDatabase()->withRequestTimestamp($oneHourAgo, function () use ($document) {
            return $this->getDatabase()->deleteDocument($document->getCollection(), $document->getId());
        });
    }

    /**
     * @depends testGetDocument
     */
    public function testUpdateDocumentDuplicatePermissions(Document $document): Document
    {
        $new = $this->getDatabase()->updateDocument($document->getCollection(), $document->getId(), $document);

        $new
            ->setAttribute('$permissions', Permission::read(Role::guests()), Document::SET_TYPE_APPEND)
            ->setAttribute('$permissions', Permission::read(Role::guests()), Document::SET_TYPE_APPEND)
            ->setAttribute('$permissions', Permission::create(Role::guests()), Document::SET_TYPE_APPEND)
            ->setAttribute('$permissions', Permission::create(Role::guests()), Document::SET_TYPE_APPEND);

        $this->getDatabase()->updateDocument($new->getCollection(), $new->getId(), $new);

        $new = $this->getDatabase()->getDocument($new->getCollection(), $new->getId());

        $this->assertContains('guests', $new->getRead());
        $this->assertContains('guests', $new->getCreate());

        return $document;
    }

    /**
     * @depends testUpdateDocument
     */
    public function testDeleteDocument(Document $document): void
    {
        $result = $this->getDatabase()->deleteDocument($document->getCollection(), $document->getId());
        $document = $this->getDatabase()->getDocument($document->getCollection(), $document->getId());

        $this->assertEquals(true, $result);
        $this->assertEquals(true, $document->isEmpty());
    }

    public function testUpdateDocuments(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'testUpdateDocuments';
        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        $database->createCollection($collection, attributes: [
            new Document([
                '$id' => ID::custom('string'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 100,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('integer'),
                'type' => Database::VAR_INTEGER,
                'format' => '',
                'size' => 10000,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('boolean'),
                'type' => Database::VAR_BOOLEAN,
                'format' => '',
                'size' => 0,
                'signed' => true,
                'required' => false,
                'default' => false, // not null
                'array' => false,
                'filters' => [],
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ], documentSecurity: false);

        for ($i = 0; $i < 10; $i++) {
            $database->createDocument($collection, new Document([
                '$id' => 'doc' . $i,
                'string' => 'text📝 ' . $i,
                'integer' => $i,
                'boolean' => true
            ]));
        }

        // Test Update half of the documents
        $results = [];
        $count = $database->updateDocuments($collection, new Document([
            'string' => 'text📝 updated',
        ]), [
            Query::greaterThanEqual('integer', 5),
        ], onNext: function ($doc) use (&$results) {
            $results[] = $doc;
        });

        $this->assertEquals(5, $count);

        foreach ($results as $document) {
            $this->assertEquals('text📝 updated', $document->getAttribute('string'));
            $this->assertEquals(true, $document->getAttribute('boolean'));
        }

        $updatedDocuments = $database->find($collection, [
            Query::greaterThanEqual('integer', 5),
        ]);

        $this->assertCount(5, $updatedDocuments);

        foreach ($updatedDocuments as $document) {
            $this->assertEquals('text📝 updated', $document->getAttribute('string'));
            $this->assertGreaterThanOrEqual(5, $document->getAttribute('integer'));
            $this->assertEquals(true, $document->getAttribute('boolean'));
        }

        $controlDocuments = $database->find($collection, [
            Query::lessThan('integer', 5),
        ]);

        $this->assertEquals(count($controlDocuments), 5);

        foreach ($controlDocuments as $document) {
            $this->assertNotEquals('text📝 updated', $document->getAttribute('string'));
        }

        // Test Update all documents
        $this->assertEquals(10, $database->updateDocuments($collection, new Document([
            'string' => 'text📝 updated all',
        ])));

        $updatedDocuments = $database->find($collection);

        $this->assertEquals(count($updatedDocuments), 10);

        foreach ($updatedDocuments as $document) {
            $this->assertEquals('text📝 updated all', $document->getAttribute('string'));
        }

        // TEST: Can't delete documents in the past
        $oneHourAgo = (new \DateTime())->sub(new \DateInterval('PT1H'));

        try {
            $this->getDatabase()->withRequestTimestamp($oneHourAgo, function () use ($collection, $database) {
                $database->updateDocuments($collection, new Document([
                    'string' => 'text📝 updated all',
                ]));
            });
            $this->fail('Failed to throw exception');
        } catch (ConflictException $e) {
            $this->assertEquals('Document was updated after the request timestamp', $e->getMessage());
        }

        // Check collection level permissions
        $database->updateCollection($collection, permissions: [
            Permission::read(Role::user('asd')),
            Permission::create(Role::user('asd')),
            Permission::update(Role::user('asd')),
            Permission::delete(Role::user('asd')),
        ], documentSecurity: false);

        try {
            $database->updateDocuments($collection, new Document([
                'string' => 'text📝 updated all',
            ]));
            $this->fail('Failed to throw exception');
        } catch (AuthorizationException $e) {
            $this->assertStringStartsWith('Missing "update" permission for role "user:asd".', $e->getMessage());
        }

        // Check document level permissions
        $database->updateCollection($collection, permissions: [], documentSecurity: true);

        $this->getDatabase()->getAuthorization()->skip(function () use ($collection, $database) {
            $database->updateDocument($collection, 'doc0', new Document([
                'string' => 'text📝 updated all',
                '$permissions' => [
                    Permission::read(Role::user('asd')),
                    Permission::create(Role::user('asd')),
                    Permission::update(Role::user('asd')),
                    Permission::delete(Role::user('asd')),
                ],
            ]));
        });

        $this->getDatabase()->getAuthorization()->addRole(Role::user('asd')->toString());

        $database->updateDocuments($collection, new Document([
            'string' => 'permission text',
        ]));

        $documents = $database->find($collection, [
            Query::equal('string', ['permission text']),
        ]);

        $this->assertCount(1, $documents);

        $this->getDatabase()->getAuthorization()->skip(function () use ($collection, $database) {
            $unmodifiedDocuments = $database->find($collection, [
                Query::equal('string', ['text📝 updated all']),
            ]);

            $this->assertCount(9, $unmodifiedDocuments);
        });

        $this->getDatabase()->getAuthorization()->skip(function () use ($collection, $database) {
            $database->updateDocuments($collection, new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]));
        });

        // Test we can update more documents than batchSize
        $this->assertEquals(10, $database->updateDocuments($collection, new Document([
            'string' => 'batchSize Test'
        ]), batchSize: 2));

        $documents = $database->find($collection);

        foreach ($documents as $document) {
            $this->assertEquals('batchSize Test', $document->getAttribute('string'));
        }

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());
    }

    public function testUpdateDocumentsWithCallbackSupport(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'update_callback';
        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        $database->createCollection($collection, attributes: [
            new Document([
                '$id' => ID::custom('string'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 100,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('integer'),
                'type' => Database::VAR_INTEGER,
                'format' => '',
                'size' => 10000,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ], documentSecurity: false);

        for ($i = 0; $i < 10; $i++) {
            $database->createDocument($collection, new Document([
                '$id' => 'doc' . $i,
                'string' => 'text📝 ' . $i,
                'integer' => $i
            ]));
        }
        // Test onNext is throwing the error without the onError
        // a non existent document to test the error thrown
        try {
            $results = [];
            $count = $database->updateDocuments($collection, new Document([
                'string' => 'text📝 updated',
            ]), [
                Query::greaterThanEqual('integer', 100),
            ], onNext: function ($doc) use (&$results) {
                $results[] = $doc;
                throw new Exception("Error thrown to test that update doesn't stop and error is caught");
            });
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertEquals("Error thrown to test that update doesn't stop and error is caught", $e->getMessage());
        }

        // Test Update half of the documents
        $results = [];
        $count = $database->updateDocuments($collection, new Document([
            'string' => 'text📝 updated',
        ]), [
            Query::greaterThanEqual('integer', 5),
        ], onNext: function ($doc) use (&$results) {
            $results[] = $doc;
            throw new Exception("Error thrown to test that update doesn't stop and error is caught");
        }, onError:function ($e) {
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertEquals("Error thrown to test that update doesn't stop and error is caught", $e->getMessage());
        });

        $this->assertEquals(5, $count);

        foreach ($results as $document) {
            $this->assertEquals('text📝 updated', $document->getAttribute('string'));
        }

        $updatedDocuments = $database->find($collection, [
            Query::greaterThanEqual('integer', 5),
        ]);

        $this->assertCount(5, $updatedDocuments);
    }

    /**
     * @depends testCreateDocument
     */
    public function testReadPermissionsSuccess(Document $document): Document
    {
        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->createDocument('documents', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'string' => 'text📝',
            'integer_signed' => -Database::MAX_INT,
            'integer_unsigned' => Database::MAX_INT,
            'bigint_signed' => -Database::MAX_BIG_INT,
            'bigint_unsigned' => Database::MAX_BIG_INT,
            'float_signed' => -5.55,
            'float_unsigned' => 5.55,
            'boolean' => true,
            'colors' => ['pink', 'green', 'blue'],
        ]));

        $this->assertEquals(false, $document->isEmpty());

        $this->getDatabase()->getAuthorization()->cleanRoles();

        $document = $database->getDocument($document->getCollection(), $document->getId());
        $this->assertEquals(true, $document->isEmpty());

        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        return $document;
    }

    /**
     * @depends testCreateDocument
     */
    public function testWritePermissionsSuccess(Document $document): void
    {
        $this->getDatabase()->getAuthorization()->cleanRoles();

        /** @var Database $database */
        $database = $this->getDatabase();

        $this->expectException(AuthorizationException::class);
        $database->createDocument('documents', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'string' => 'text📝',
            'integer_signed' => -Database::MAX_INT,
            'integer_unsigned' => Database::MAX_INT,
            'bigint_signed' => -Database::MAX_BIG_INT,
            'bigint_unsigned' => Database::MAX_BIG_INT,
            'float_signed' => -5.55,
            'float_unsigned' => 5.55,
            'boolean' => true,
            'colors' => ['pink', 'green', 'blue'],
        ]));
    }

    /**
     * @depends testCreateDocument
     */
    public function testWritePermissionsUpdateFailure(Document $document): Document
    {
        $this->expectException(AuthorizationException::class);

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->createDocument('documents', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'string' => 'text📝',
            'integer_signed' => -Database::MAX_INT,
            'integer_unsigned' => Database::MAX_INT,
            'bigint_signed' => -Database::MAX_BIG_INT,
            'bigint_unsigned' => Database::MAX_BIG_INT,
            'float_signed' => -5.55,
            'float_unsigned' => 5.55,
            'boolean' => true,
            'colors' => ['pink', 'green', 'blue'],
        ]));

        $this->getDatabase()->getAuthorization()->cleanRoles();

        $document = $database->updateDocument('documents', $document->getId(), new Document([
            '$id' => ID::custom($document->getId()),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'string' => 'text📝',
            'integer_signed' => 6,
            'bigint_signed' => -Database::MAX_BIG_INT,
            'float_signed' => -Database::MAX_DOUBLE,
            'float_unsigned' => Database::MAX_DOUBLE,
            'boolean' => true,
            'colors' => ['pink', 'green', 'blue'],
        ]));

        return $document;
    }

    /**
     * @depends testFind
     */
    public function testUniqueIndexDuplicate(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->assertEquals(true, $database->createIndex('movies', 'uniqueIndex', Database::INDEX_UNIQUE, ['name'], [128], [Database::ORDER_ASC]));

        try {
            $database->createDocument('movies', new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::read(Role::user('1')),
                    Permission::read(Role::user('2')),
                    Permission::create(Role::any()),
                    Permission::create(Role::user('1x')),
                    Permission::create(Role::user('2x')),
                    Permission::update(Role::any()),
                    Permission::update(Role::user('1x')),
                    Permission::update(Role::user('2x')),
                    Permission::delete(Role::any()),
                    Permission::delete(Role::user('1x')),
                    Permission::delete(Role::user('2x')),
                ],
                'name' => 'Frozen',
                'director' => 'Chris Buck & Jennifer Lee',
                'year' => 2013,
                'price' => 39.50,
                'active' => true,
                'genres' => ['animation', 'kids'],
                'with-dash' => 'Works4'
            ]));

            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
            $this->assertInstanceOf(UniqueException::class, $e);
        }
    }

    /**
     * Test that duplicate exceptions differentiate between
     * document ID duplicates and unique index violations.
     */
    public function testDuplicateExceptionMessages(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUniqueIndex()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('duplicateMessages');
        $database->createAttribute('duplicateMessages', 'email', Database::VAR_STRING, 128, true);
        $database->createIndex('duplicateMessages', 'emailUnique', Database::INDEX_UNIQUE, ['email'], [128]);

        // Create first document
        $database->createDocument('duplicateMessages', new Document([
            '$id' => 'dup_msg_1',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'email' => 'test@example.com',
        ]));

        // Test 1: Duplicate document ID should say "Document already exists"
        try {
            $database->createDocument('duplicateMessages', new Document([
                '$id' => 'dup_msg_1',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'email' => 'different@example.com',
            ]));
            $this->fail('Expected DuplicateException for duplicate document ID');
        } catch (DuplicateException $e) {
            $this->assertNotInstanceOf(UniqueException::class, $e);
            $this->assertStringContainsString('Document already exists', $e->getMessage());
        }

        // Test 2: Unique index violation should use UniqueException
        try {
            $database->createDocument('duplicateMessages', new Document([
                '$id' => 'dup_msg_2',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'email' => 'test@example.com',
            ]));
            $this->fail('Expected DuplicateException for unique index violation');
        } catch (DuplicateException $e) {
            $this->assertInstanceOf(UniqueException::class, $e);
            $this->assertStringContainsString('Unique index violation', $e->getMessage());
        }

        // Test 3: A conflicting value containing "_uid" must not be mistaken
        // for a document identifier conflict
        $database->createDocument('duplicateMessages', new Document([
            '$id' => 'dup_msg_3',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'email' => 'prefix_uid_suffix@example.com',
        ]));

        try {
            $database->createDocument('duplicateMessages', new Document([
                '$id' => 'dup_msg_4',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'email' => 'prefix_uid_suffix@example.com',
            ]));
            $this->fail('Expected DuplicateException for unique index violation');
        } catch (DuplicateException $e) {
            $this->assertInstanceOf(UniqueException::class, $e);
            $this->assertStringContainsString('Unique index violation', $e->getMessage());
        }

        $database->deleteCollection('duplicateMessages');
    }

    /**
     * @depends testUniqueIndexDuplicate
     */
    public function testUniqueIndexDuplicateUpdate(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());
        // create document then update to conflict with index
        $document = $database->createDocument('movies', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::read(Role::user('1')),
                Permission::read(Role::user('2')),
                Permission::create(Role::any()),
                Permission::create(Role::user('1x')),
                Permission::create(Role::user('2x')),
                Permission::update(Role::any()),
                Permission::update(Role::user('1x')),
                Permission::update(Role::user('2x')),
                Permission::delete(Role::any()),
                Permission::delete(Role::user('1x')),
                Permission::delete(Role::user('2x')),
            ],
            'name' => 'Frozen 5',
            'director' => 'Chris Buck & Jennifer Lee',
            'year' => 2013,
            'price' => 39.50,
            'active' => true,
            'genres' => ['animation', 'kids'],
            'with-dash' => 'Works4'
        ]));

        try {
            $database->updateDocument('movies', $document->getId(), $document->setAttribute('name', 'Frozen'));

            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
            $this->assertInstanceOf(UniqueException::class, $e);
        }
    }

    public function propagateBulkDocuments(string $collection, int $amount = 10, bool $documentSecurity = false): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        for ($i = 0; $i < $amount; $i++) {
            $database->createDocument($collection, new Document(
                array_merge([
                    '$id' => 'doc' . $i,
                    'text' => 'value' . $i,
                    'integer' => $i
                ], $documentSecurity ? [
                    '$permissions' => [
                        Permission::create(Role::any()),
                        Permission::read(Role::any()),
                    ],
                ] : [])
            ));
        }
    }

    public function testDeleteBulkDocuments(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(
            'bulk_delete',
            attributes: [
                new Document([
                    '$id' => 'text',
                    'type' => Database::VAR_STRING,
                    'size' => 100,
                    'required' => true,
                ]),
                new Document([
                    '$id' => 'integer',
                    'type' => Database::VAR_INTEGER,
                    'size' => 10,
                    'required' => true,
                ])
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any())
            ],
            documentSecurity: false
        );

        $this->propagateBulkDocuments('bulk_delete');

        $docs = $database->find('bulk_delete');
        $this->assertCount(10, $docs);

        /**
         * Test Short select query, test pagination as well, Add order to select
         */
        $selects = ['$sequence', '$id', '$collection', '$permissions', '$updatedAt'];

        $count = $database->deleteDocuments(
            collection: 'bulk_delete',
            queries: [
                Query::select([...$selects, '$createdAt']),
                Query::cursorAfter($docs[6]),
                Query::greaterThan('$createdAt', '2000-01-01'),
                Query::orderAsc('$createdAt'),
                Query::orderAsc(),
                Query::limit(2),
            ],
            batchSize: 1
        );

        $this->assertEquals(2, $count);

        // TEST: Bulk Delete All Documents
        $this->assertEquals(8, $database->deleteDocuments('bulk_delete'));

        $docs = $database->find('bulk_delete');
        $this->assertCount(0, $docs);

        // TEST: Bulk delete documents with queries.
        $this->propagateBulkDocuments('bulk_delete');

        $results = [];
        $count = $database->deleteDocuments('bulk_delete', [
            Query::greaterThanEqual('integer', 5)
        ], onNext: function ($doc) use (&$results) {
            $results[] = $doc;
        });

        $this->assertEquals(5, $count);

        foreach ($results as $document) {
            $this->assertGreaterThanOrEqual(5, $document->getAttribute('integer'));
        }

        $docs = $database->find('bulk_delete');
        $this->assertEquals(5, \count($docs));

        // TEST (FAIL): Can't delete documents in the past
        $oneHourAgo = (new \DateTime())->sub(new \DateInterval('PT1H'));

        try {
            $this->getDatabase()->withRequestTimestamp($oneHourAgo, function () {
                return $this->getDatabase()->deleteDocuments('bulk_delete');
            });
            $this->fail('Failed to throw exception');
        } catch (ConflictException $e) {
            $this->assertEquals('Document was updated after the request timestamp', $e->getMessage());
        }

        // TEST (FAIL): Bulk delete all documents with invalid collection permission
        $database->updateCollection('bulk_delete', [], false);
        try {
            $database->deleteDocuments('bulk_delete');
            $this->fail('Bulk deleted documents with invalid collection permission');
        } catch (\Utopia\Database\Exception\Authorization) {
        }

        $database->updateCollection('bulk_delete', [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::delete(Role::any())
        ], false);

        $this->assertEquals(5, $database->deleteDocuments('bulk_delete'));
        $this->assertEquals(0, \count($this->getDatabase()->find('bulk_delete')));

        // TEST: Make sure we can't delete documents we don't have permissions for
        $database->updateCollection('bulk_delete', [
            Permission::create(Role::any()),
        ], true);
        $this->propagateBulkDocuments('bulk_delete', documentSecurity: true);

        $this->assertEquals(0, $database->deleteDocuments('bulk_delete'));

        $documents = $this->getDatabase()->getAuthorization()->skip(function () use ($database) {
            return $database->find('bulk_delete');
        });

        $this->assertEquals(10, \count($documents));

        $database->updateCollection('bulk_delete', [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::delete(Role::any())
        ], false);

        $database->deleteDocuments('bulk_delete');

        $this->assertEquals(0, \count($this->getDatabase()->find('bulk_delete')));

        // Teardown
        $database->deleteCollection('bulk_delete');
    }

    public function testDeleteBulkDocumentsQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(
            'bulk_delete_queries',
            attributes: [
                new Document([
                    '$id' => 'text',
                    'type' => Database::VAR_STRING,
                    'size' => 100,
                    'required' => true,
                ]),
                new Document([
                    '$id' => 'integer',
                    'type' => Database::VAR_INTEGER,
                    'size' => 10,
                    'required' => true,
                ])
            ],
            documentSecurity: false,
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any())
            ]
        );

        // Test limit
        $this->propagateBulkDocuments('bulk_delete_queries');

        $this->assertEquals(5, $database->deleteDocuments('bulk_delete_queries', [Query::limit(5)]));
        $this->assertEquals(5, \count($database->find('bulk_delete_queries')));

        $this->assertEquals(5, $database->deleteDocuments('bulk_delete_queries', [Query::limit(5)]));
        $this->assertEquals(0, \count($database->find('bulk_delete_queries')));

        // Test Limit more than batchSize
        $this->propagateBulkDocuments('bulk_delete_queries', Database::DELETE_BATCH_SIZE * 2);
        $this->assertEquals(Database::DELETE_BATCH_SIZE * 2, \count($database->find('bulk_delete_queries', [Query::limit(Database::DELETE_BATCH_SIZE * 2)])));
        $this->assertEquals(Database::DELETE_BATCH_SIZE + 2, $database->deleteDocuments('bulk_delete_queries', [Query::limit(Database::DELETE_BATCH_SIZE + 2)]));
        $this->assertEquals(Database::DELETE_BATCH_SIZE - 2, \count($database->find('bulk_delete_queries', [Query::limit(Database::DELETE_BATCH_SIZE * 2)])));
        $this->assertEquals(Database::DELETE_BATCH_SIZE - 2, $this->getDatabase()->deleteDocuments('bulk_delete_queries'));

        // Test Offset
        $this->propagateBulkDocuments('bulk_delete_queries', 100);
        $this->assertEquals(50, $database->deleteDocuments('bulk_delete_queries', [Query::offset(50)]));

        $docs = $database->find('bulk_delete_queries', [Query::limit(100)]);
        $this->assertEquals(50, \count($docs));

        $lastDoc = \end($docs);
        $this->assertNotEmpty($lastDoc);
        $this->assertEquals('doc49', $lastDoc->getId());
        $this->assertEquals(50, $database->deleteDocuments('bulk_delete_queries'));

        $database->deleteCollection('bulk_delete_queries');
    }

    public function testDeleteBulkDocumentsWithCallbackSupport(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(
            'bulk_delete_with_callback',
            attributes: [
                new Document([
                    '$id' => 'text',
                    'type' => Database::VAR_STRING,
                    'size' => 100,
                    'required' => true,
                ]),
                new Document([
                    '$id' => 'integer',
                    'type' => Database::VAR_INTEGER,
                    'size' => 10,
                    'required' => true,
                ])
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::delete(Role::any())
            ],
            documentSecurity: false
        );

        $this->propagateBulkDocuments('bulk_delete_with_callback');

        $docs = $database->find('bulk_delete_with_callback');
        $this->assertCount(10, $docs);

        /**
         * Test Short select query, test pagination as well, Add order to select
         */
        $selects = ['$sequence', '$id', '$collection', '$permissions', '$updatedAt'];

        try {
            // a non existent document to test the error thrown
            $database->deleteDocuments(
                collection: 'bulk_delete_with_callback',
                queries: [
                    Query::select([...$selects, '$createdAt']),
                    Query::lessThan('$createdAt', '1800-01-01'),
                    Query::orderAsc('$createdAt'),
                    Query::orderAsc(),
                    Query::limit(1),
                ],
                batchSize: 1,
                onNext: function () {
                    throw new Exception("Error thrown to test that deletion doesn't stop and error is caught");
                }
            );
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertEquals("Error thrown to test that deletion doesn't stop and error is caught", $e->getMessage());
        }

        $docs = $database->find('bulk_delete_with_callback');
        $this->assertCount(10, $docs);

        $count = $database->deleteDocuments(
            collection: 'bulk_delete_with_callback',
            queries: [
                Query::select([...$selects, '$createdAt']),
                Query::cursorAfter($docs[6]),
                Query::greaterThan('$createdAt', '2000-01-01'),
                Query::orderAsc('$createdAt'),
                Query::orderAsc(),
                Query::limit(2),
            ],
            batchSize: 1,
            onNext: function () {
                // simulating error throwing but should not stop deletion
                throw new Exception("Error thrown to test that deletion doesn't stop and error is caught");
            },
            onError:function ($e) {
                $this->assertInstanceOf(Exception::class, $e);
                $this->assertEquals("Error thrown to test that deletion doesn't stop and error is caught", $e->getMessage());
            }
        );

        $this->assertEquals(2, $count);

        // TEST: Bulk Delete All Documents without passing callbacks
        $this->assertEquals(8, $database->deleteDocuments('bulk_delete_with_callback'));

        $docs = $database->find('bulk_delete_with_callback');
        $this->assertCount(0, $docs);

        // TEST: Bulk delete documents with queries with callbacks
        $this->propagateBulkDocuments('bulk_delete_with_callback');

        $results = [];
        $count = $database->deleteDocuments('bulk_delete_with_callback', [
            Query::greaterThanEqual('integer', 5)
        ], onNext: function ($doc) use (&$results) {
            $results[] = $doc;
            throw new Exception("Error thrown to test that deletion doesn't stop and error is caught");
        }, onError:function ($e) {
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertEquals("Error thrown to test that deletion doesn't stop and error is caught", $e->getMessage());
        });

        $this->assertEquals(5, $count);

        foreach ($results as $document) {
            $this->assertGreaterThanOrEqual(5, $document->getAttribute('integer'));
        }

        $docs = $database->find('bulk_delete_with_callback');
        $this->assertEquals(5, \count($docs));

        // Teardown
        $database->deleteCollection('bulk_delete_with_callback');
    }

    public function testUpdateDocumentsQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'testUpdateDocumentsQueries';

        $database->createCollection($collection, attributes: [
            new Document([
                '$id' => ID::custom('text'),
                'type' => Database::VAR_STRING,
                'size' => 64,
                'required' => true,
            ]),
            new Document([
                '$id' => ID::custom('integer'),
                'type' => Database::VAR_INTEGER,
                'size' => 64,
                'required' => true,
            ]),
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ], documentSecurity: true);

        // Test limit
        $this->propagateBulkDocuments($collection, 100);

        $this->assertEquals(10, $database->updateDocuments($collection, new Document([
            'text' => 'text📝 updated',
        ]), [Query::limit(10)]));

        $this->assertEquals(10, \count($database->find($collection, [Query::equal('text', ['text📝 updated'])])));
        $this->assertEquals(100, $database->deleteDocuments($collection));
        $this->assertEquals(0, \count($database->find($collection)));

        // Test Offset
        $this->propagateBulkDocuments($collection, 100);
        $this->assertEquals(50, $database->updateDocuments($collection, new Document([
            'text' => 'text📝 updated',
        ]), [
            Query::offset(50),
        ]));

        $docs = $database->find($collection, [Query::equal('text', ['text📝 updated']), Query::limit(100)]);
        $this->assertCount(50, $docs);

        $lastDoc = end($docs);
        $this->assertNotEmpty($lastDoc);
        $this->assertEquals('doc99', $lastDoc->getId());

        $this->assertEquals(100, $database->deleteDocuments($collection));
    }

    /**
     * @depends testCreateDocument
     */
    public function testFulltextIndexWithInteger(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->expectException(Exception::class);
            if (!$this->getDatabase()->getAdapter()->getSupportForFulltextIndex()) {
                $this->expectExceptionMessage('Fulltext index is not supported');
            } else {
                $this->expectExceptionMessage('Attribute "integer_signed" cannot be part of a fulltext index, must be of type string');
            }

            $database->createIndex('documents', 'fulltext_integer', Database::INDEX_FULLTEXT, ['string','integer_signed']);
        } else {
            $this->expectNotToPerformAssertions();
            return;
        }
    }

    public function testEnableDisableValidation(): void
    {
        $database = $this->getDatabase();

        $database->createCollection('validation', permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);

        $database->createAttribute(
            'validation',
            'name',
            Database::VAR_STRING,
            10,
            false
        );

        $database->createDocument('validation', new Document([
            '$id' => 'docwithmorethan36charsasitsidentifier',
            'name' => 'value1',
        ]));

        try {
            $database->find('validation', queries: [
                Query::equal('$id', ['docwithmorethan36charsasitsidentifier']),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }

        $database->disableValidation();

        $database->find('validation', queries: [
            Query::equal('$id', ['docwithmorethan36charsasitsidentifier']),
        ]);

        $database->enableValidation();

        try {
            $database->find('validation', queries: [
                Query::equal('$id', ['docwithmorethan36charsasitsidentifier']),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }

        $database->skipValidation(function () use ($database) {
            $database->find('validation', queries: [
                Query::equal('$id', ['docwithmorethan36charsasitsidentifier']),
            ]);
        });

        $database->enableValidation();
    }

    /**
     * @depends testGetDocument
     */
    public function testExceptionDuplicate(Document $document): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $document->setAttribute('$id', 'duplicated');
        $document->removeAttribute('$sequence');

        $database->createDocument($document->getCollection(), $document);
        $document->removeAttribute('$sequence');

        try {
            $database->createDocument($document->getCollection(), $document);
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }
    }

    /**
     * @depends testGetDocument
     */
    public function testExceptionCaseInsensitiveDuplicate(Document $document): Document
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $document->setAttribute('$id', 'caseSensitive');
        $document->removeAttribute('$sequence');

        $database->createDocument($document->getCollection(), $document);

        $document->setAttribute('$id', 'CaseSensitive');
        $document->removeAttribute('$sequence');

        try {
            $database->createDocument($document->getCollection(), $document);
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }

        return $document;
    }

    public function testEmptyTenant(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getSharedTables()) {
            $documents = $database->find(
                'documents',
                [Query::select(['*'])] // Mongo bug with Integer UID
            );

            $document = $documents[0];
            $doc = $database->getDocument($document->getCollection(), $document->getId());
            $this->assertEquals($document->getTenant(), $doc->getTenant());
            return;
        }

        $documents = $database->find(
            'documents',
            [Query::notEqual('$id', '56000')] // Mongo bug with Integer UID
        );

        $document = $documents[0];
        $this->assertArrayHasKey('$id', $document);
        $this->assertArrayNotHasKey('$tenant', $document);

        $document = $database->getDocument('documents', $document->getId());
        $this->assertArrayHasKey('$id', $document);
        $this->assertArrayNotHasKey('$tenant', $document);

        $document = $database->updateDocument('documents', $document->getId(), $document);
        $this->assertArrayHasKey('$id', $document);
        $this->assertArrayNotHasKey('$tenant', $document);
    }

    public function testEmptyOperatorValues(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        try {
            $database->findOne('documents', [
                Query::equal('string', []),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertEquals('Invalid query: Equal queries require at least one value.', $e->getMessage());
        }

        try {
            $database->findOne('documents', [
                Query::contains('string', []),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertEquals('Invalid query: Contains queries require at least one value.', $e->getMessage());
        }
    }

    public function testDateTimeDocument(): void
    {
        /**
         * @var Database $database
         */
        $database = $this->getDatabase();
        $collection = 'create_modify_dates';
        $database->createCollection($collection);
        $this->assertEquals(true, $database->createAttribute($collection, 'string', Database::VAR_STRING, 128, false));
        $this->assertEquals(true, $database->createAttribute($collection, 'datetime', Database::VAR_DATETIME, 0, false, null, true, false, null, [], ['datetime']));

        $date = '2000-01-01T10:00:00.000+00:00';
        // test - default behaviour of external datetime attribute not changed
        $doc = $database->createDocument($collection, new Document([
            '$id' => 'doc1',
            '$permissions' => [Permission::read(Role::any()),Permission::write(Role::any()),Permission::update(Role::any())],
            'datetime' => ''
        ]));
        $this->assertNotEmpty($doc->getAttribute('datetime'));
        $this->assertNotEmpty($doc->getAttribute('$createdAt'));
        $this->assertNotEmpty($doc->getAttribute('$updatedAt'));

        $doc = $database->getDocument($collection, 'doc1');
        $this->assertNotEmpty($doc->getAttribute('datetime'));
        $this->assertNotEmpty($doc->getAttribute('$createdAt'));
        $this->assertNotEmpty($doc->getAttribute('$updatedAt'));

        $database->setPreserveDates(true);
        // test - modifying $createdAt and $updatedAt
        $doc = $database->createDocument($collection, new Document([
            '$id' => 'doc2',
            '$permissions' => [Permission::read(Role::any()),Permission::write(Role::any()),Permission::update(Role::any())],
            '$createdAt' => $date
        ]));

        $this->assertEquals($doc->getAttribute('$createdAt'), $date);
        $this->assertNotEmpty($doc->getAttribute('$updatedAt'));
        $this->assertNotEquals($doc->getAttribute('$updatedAt'), $date);

        $doc = $database->getDocument($collection, 'doc2');

        $this->assertEquals($doc->getAttribute('$createdAt'), $date);
        $this->assertNotEmpty($doc->getAttribute('$updatedAt'));
        $this->assertNotEquals($doc->getAttribute('$updatedAt'), $date);

        $database->setPreserveDates(false);
        $database->deleteCollection($collection);
    }

    public function testDateTimeArrayDocument(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collection = 'datetime_array_doc';
        $database->createCollection($collection);
        $this->assertEquals(true, $database->createAttribute($collection, 'dates', Database::VAR_DATETIME, 0, false, null, false, true, null, [], ['datetime']));

        $d1 = '2000-01-01T10:00:00.000+00:00';
        $d2 = '2001-02-03T05:06:07.000+00:00';

        $database->createDocument($collection, new Document([
            '$id' => 'doc1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'dates' => [$d1, $d2],
        ]));

        // Regression: reading a datetime (object) array used to throw in Mongo with
        // "Cannot use object of type MongoDB\BSON\UTCDateTime as array" because the
        // Document constructor tried to array-access non-array elements.
        $doc = $database->getDocument($collection, 'doc1');
        $dates = $doc->getAttribute('dates');
        $this->assertIsArray($dates);
        $this->assertCount(2, $dates);
        $this->assertEquals($d1, $dates[0]);
        $this->assertEquals($d2, $dates[1]);

        // Same values must round-trip through find()
        $found = $database->find($collection, [Query::equal('$id', ['doc1'])]);
        $this->assertCount(1, $found);
        $this->assertEquals([$d1, $d2], $found[0]->getAttribute('dates'));

        $database->deleteCollection($collection);
    }

    public function testInvalidCreatedAndUpdatedAtThrowStructureException(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'invalid_date_attributes';

        $database->createCollection($collection);
        $this->assertEquals(true, $database->createAttribute($collection, 'string', Database::VAR_STRING, 128, false));

        $database->setPreserveDates(true);

        try {
            // Outside allowed year range (Structure uses DatetimeValidator min/max, e.g. 0000–9999).
            $invalidDate = '10000-01-01T00:00:00.000+00:00';

            try {
                $database->createDocument($collection, new Document([
                    '$id' => 'doc1',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                    ],
                    '$createdAt' => $invalidDate,
                ]));
                $this->fail('Expected StructureException for invalid $createdAt');
            } catch (Throwable $e) {
                $this->assertInstanceOf(StructureException::class, $e);
            }

            $database->createDocument($collection, new Document([
                '$id' => 'doc2',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                'string' => 'x',
            ]));

            try {
                $database->updateDocument($collection, 'doc2', new Document([
                    '$updatedAt' => $invalidDate,
                ]));
                $this->fail('Expected StructureException for invalid $updatedAt');
            } catch (Throwable $e) {
                $this->assertInstanceOf(StructureException::class, $e);
            }
        } finally {
            $database->setPreserveDates(false);
            $database->deleteCollection($collection);
        }
    }

    public function testSingleDocumentDateOperations(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $collection = 'normal_date_operations';
        $database->createCollection($collection);
        $this->assertEquals(true, $database->createAttribute($collection, 'string', Database::VAR_STRING, 128, false));

        $database->setPreserveDates(true);

        $createDate = '2000-01-01T10:00:00.000+00:00';
        $updateDate = '2000-02-01T15:30:00.000+00:00';
        $date1 = '2000-01-01T10:00:00.000+00:00';
        $date2 = '2000-02-01T15:30:00.000+00:00';
        $date3 = '2000-03-01T20:45:00.000+00:00';
        // Test 1: Create with custom createdAt, then update with custom updatedAt
        $doc = $database->createDocument($collection, new Document([
            '$id' => 'doc1',
            '$permissions' => [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())],
            'string' => 'initial',
            '$createdAt' => $createDate
        ]));

        $this->assertEquals($createDate, $doc->getAttribute('$createdAt'));
        $this->assertNotEquals($createDate, $doc->getAttribute('$updatedAt'));

        // Update with custom updatedAt
        $doc->setAttribute('string', 'updated');
        $doc->setAttribute('$updatedAt', $updateDate);
        $updatedDoc = $database->updateDocument($collection, 'doc1', $doc);

        $this->assertEquals($createDate, $updatedDoc->getAttribute('$createdAt'));
        $this->assertEquals($updateDate, $updatedDoc->getAttribute('$updatedAt'));

        // Test 2: Create with both custom dates
        $doc2 = $database->createDocument($collection, new Document([
            '$id' => 'doc2',
            '$permissions' => [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())],
            'string' => 'both_dates',
            '$createdAt' => $createDate,
            '$updatedAt' => $updateDate
        ]));

        $this->assertEquals($createDate, $doc2->getAttribute('$createdAt'));
        $this->assertEquals($updateDate, $doc2->getAttribute('$updatedAt'));

        // Test 3: Create without dates, then update with custom dates
        $doc3 = $database->createDocument($collection, new Document([
            '$id' => 'doc3',
            '$permissions' => [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())],
            'string' => 'no_dates'
        ]));


        $doc3->setAttribute('string', 'updated_no_dates');
        $doc3->setAttribute('$createdAt', $createDate);
        $doc3->setAttribute('$updatedAt', $updateDate);
        $updatedDoc3 = $database->updateDocument($collection, 'doc3', $doc3);

        $this->assertEquals($createDate, $updatedDoc3->getAttribute('$createdAt'));
        $this->assertEquals($updateDate, $updatedDoc3->getAttribute('$updatedAt'));

        // Test 4: Update only createdAt
        $doc4 = $database->createDocument($collection, new Document([
            '$id' => 'doc4',
            '$permissions' => [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())],
            'string' => 'initial'
        ]));

        $originalCreatedAt4 = $doc4->getAttribute('$createdAt');
        $originalUpdatedAt4 = $doc4->getAttribute('$updatedAt');

        sleep(1); // Ensure $updatedAt differs when adapter timestamp precision is seconds

        $doc4->setAttribute('$updatedAt', null);
        $doc4->setAttribute('$createdAt', null);
        $updatedDoc4 = $database->updateDocument($collection, 'doc4', document: $doc4);

        $this->assertEquals($originalCreatedAt4, $updatedDoc4->getAttribute('$createdAt'));
        $this->assertNotEquals($originalUpdatedAt4, $updatedDoc4->getAttribute('$updatedAt'));

        // Test 5: Update only updatedAt
        $updatedDoc4->setAttribute('$updatedAt', $updateDate);
        $updatedDoc4->setAttribute('$createdAt', $createDate);
        $finalDoc4 = $database->updateDocument($collection, 'doc4', $updatedDoc4);

        $this->assertEquals($createDate, $finalDoc4->getAttribute('$createdAt'));
        $this->assertEquals($updateDate, $finalDoc4->getAttribute('$updatedAt'));

        // Test 6: Create with updatedAt, update with createdAt
        $doc5 = $database->createDocument($collection, new Document([
            '$id' => 'doc5',
            '$permissions' => [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())],
            'string' => 'doc5',
            '$updatedAt' => $date2
        ]));

        $this->assertNotEquals($date2, $doc5->getAttribute('$createdAt'));
        $this->assertEquals($date2, $doc5->getAttribute('$updatedAt'));

        $doc5->setAttribute('string', 'doc5_updated');
        $doc5->setAttribute('$createdAt', $date1);
        $updatedDoc5 = $database->updateDocument($collection, 'doc5', $doc5);

        $this->assertEquals($date1, $updatedDoc5->getAttribute('$createdAt'));
        $this->assertEquals($date2, $updatedDoc5->getAttribute('$updatedAt'));

        // Test 7: Create with both dates, update with different dates
        $doc6 = $database->createDocument($collection, new Document([
            '$id' => 'doc6',
            '$permissions' => [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())],
            'string' => 'doc6',
            '$createdAt' => $date1,
            '$updatedAt' => $date2
        ]));

        $this->assertEquals($date1, $doc6->getAttribute('$createdAt'));
        $this->assertEquals($date2, $doc6->getAttribute('$updatedAt'));

        $doc6->setAttribute('string', 'doc6_updated');
        $doc6->setAttribute('$createdAt', $date3);
        $doc6->setAttribute('$updatedAt', $date3);
        $updatedDoc6 = $database->updateDocument($collection, 'doc6', $doc6);

        $this->assertEquals($date3, $updatedDoc6->getAttribute('$createdAt'));
        $this->assertEquals($date3, $updatedDoc6->getAttribute('$updatedAt'));

        // Test 8: Preserve dates disabled
        $database->setPreserveDates(false);

        $customDate = '2000-01-01T10:00:00.000+00:00';

        $doc7 = $database->createDocument($collection, new Document([
            '$id' => 'doc7',
            '$permissions' => [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())],
            'string' => 'doc7',
            '$createdAt' => $customDate,
            '$updatedAt' => $customDate
        ]));

        $this->assertNotEquals($customDate, $doc7->getAttribute('$createdAt'));
        $this->assertNotEquals($customDate, $doc7->getAttribute('$updatedAt'));

        // Update with custom dates should also be ignored
        $doc7->setAttribute('string', 'updated');
        $doc7->setAttribute('$createdAt', $customDate);
        $doc7->setAttribute('$updatedAt', $customDate);
        $updatedDoc7 = $database->updateDocument($collection, 'doc7', $doc7);

        $this->assertNotEquals($customDate, $updatedDoc7->getAttribute('$createdAt'));
        $this->assertNotEquals($customDate, $updatedDoc7->getAttribute('$updatedAt'));

        // Test checking updatedAt updates even old document exists
        $database->setPreserveDates(true);
        $doc11 = $database->createDocument($collection, new Document([
            '$id' => 'doc11',
            '$permissions' => [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())],
            'string' => 'no_dates',
            '$createdAt' => $customDate
        ]));

        $newUpdatedAt = $doc11->getUpdatedAt();

        \usleep(2000); // Ensure $updatedAt differs when adapter timestamp precision is milliseconds

        $newDoc11 = new Document([
            'string' => 'no_dates_update',
        ]);
        $updatedDoc7 = $database->updateDocument($collection, 'doc11', $newDoc11);
        $this->assertNotEquals($newUpdatedAt, $updatedDoc7->getAttribute('$updatedAt'));

        $database->setPreserveDates(false);
        $database->deleteCollection($collection);
    }

    public function testBulkDocumentDateOperations(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $collection = 'bulk_date_operations';
        $database->createCollection($collection);
        $this->assertEquals(true, $database->createAttribute($collection, 'string', Database::VAR_STRING, 128, false));

        $database->setPreserveDates(true);

        $createDate = '2000-01-01T10:00:00.000+00:00';
        $updateDate = '2000-02-01T15:30:00.000+00:00';
        $permissions = [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())];

        // Test 1: Bulk create with different date configurations
        $documents = [
            new Document([
                '$id' => 'doc1',
                '$permissions' => $permissions,
                'string' => 'doc1',
                '$createdAt' => $createDate
            ]),
            new Document([
                '$id' => 'doc2',
                '$permissions' => $permissions,
                'string' => 'doc2',
                '$updatedAt' => $updateDate
            ]),
            new Document([
                '$id' => 'doc3',
                '$permissions' => $permissions,
                'string' => 'doc3',
                '$createdAt' => $createDate,
                '$updatedAt' => $updateDate
            ]),
            new Document([
                '$id' => 'doc4',
                '$permissions' => $permissions,
                'string' => 'doc4'
            ]),
            new Document([
                '$id' => 'doc5',
                '$permissions' => $permissions,
                'string' => 'doc5',
                '$createdAt' => null
            ]),
            new Document([
                '$id' => 'doc6',
                '$permissions' => $permissions,
                'string' => 'doc6',
                '$updatedAt' => null
            ])
        ];

        $database->createDocuments($collection, $documents);

        // Verify initial state
        foreach (['doc1', 'doc3'] as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertEquals($createDate, $doc->getAttribute('$createdAt'), "createdAt mismatch for $id");
        }

        foreach (['doc2', 'doc3'] as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertEquals($updateDate, $doc->getAttribute('$updatedAt'), "updatedAt mismatch for $id");
        }

        foreach (['doc4', 'doc5', 'doc6'] as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertNotEmpty($doc->getAttribute('$createdAt'), "createdAt missing for $id");
            $this->assertNotEmpty($doc->getAttribute('$updatedAt'), "updatedAt missing for $id");
        }

        // Test 2: Bulk update with custom dates
        $updateDoc = new Document([
            'string' => 'updated',
            '$createdAt' => $createDate,
            '$updatedAt' => $updateDate
        ]);
        $ids = [];
        foreach ($documents as $doc) {
            $ids[] = $doc->getId();
        }
        $count = $database->updateDocuments($collection, $updateDoc, [
            Query::equal('$id', $ids)
        ]);
        $this->assertEquals(6, $count);

        foreach (['doc1', 'doc3'] as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertEquals($createDate, $doc->getAttribute('$createdAt'), "createdAt mismatch for $id");
            $this->assertEquals($updateDate, $doc->getAttribute('$updatedAt'), "updatedAt mismatch for $id");
            $this->assertEquals('updated', $doc->getAttribute('string'), "string mismatch for $id");
        }

        foreach (['doc2', 'doc4','doc5','doc6'] as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertEquals($updateDate, $doc->getAttribute('$updatedAt'), "updatedAt mismatch for $id");
            $this->assertEquals('updated', $doc->getAttribute('string'), "string mismatch for $id");
        }

        // Test 3: Bulk update with preserve dates disabled
        $database->setPreserveDates(false);

        $customDate = 'should be ignored anyways so no error';
        $updateDocDisabled = new Document([
            'string' => 'disabled_update',
            '$createdAt' => $customDate,
            '$updatedAt' => $customDate
        ]);

        $countDisabled = $database->updateDocuments($collection, $updateDocDisabled);
        $this->assertEquals(6, $countDisabled);

        // Test 4: Bulk update with preserve dates re-enabled
        $database->setPreserveDates(true);

        $newDate = '2000-03-01T20:45:00.000+00:00';
        $updateDocEnabled = new Document([
            'string' => 'enabled_update',
            '$createdAt' => $newDate,
            '$updatedAt' => $newDate
        ]);

        $countEnabled = $database->updateDocuments($collection, $updateDocEnabled);
        $this->assertEquals(6, $countEnabled);

        $database->setPreserveDates(false);
        $database->deleteCollection($collection);
    }

    public function testUpsertDateOperations(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'upsert_date_operations';
        $database->createCollection($collection);
        $this->assertEquals(true, $database->createAttribute($collection, 'string', Database::VAR_STRING, 128, false));

        $database->setPreserveDates(true);

        $createDate = '2000-01-01T10:00:00.000+00:00';
        $updateDate = '2000-02-01T15:30:00.000+00:00';
        $date1 = '2000-01-01T10:00:00.000+00:00';
        $date2 = '2000-02-01T15:30:00.000+00:00';
        $date3 = '2000-03-01T20:45:00.000+00:00';
        $permissions = [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())];

        // Test 1: Upsert new document with custom createdAt
        $upsertResults = [];
        $database->upsertDocuments($collection, [
            new Document([
                '$id' => 'upsert1',
                '$permissions' => $permissions,
                'string' => 'upsert1_initial',
                '$createdAt' => $createDate
            ])
        ], onNext: function ($doc) use (&$upsertResults) {
            $upsertResults[] = $doc;
        });
        $upsertDoc1 = $upsertResults[0];

        $this->assertEquals($createDate, $upsertDoc1->getAttribute('$createdAt'));
        $this->assertNotEquals($createDate, $upsertDoc1->getAttribute('$updatedAt'));

        // Test 2: Upsert existing document with custom updatedAt
        $upsertDoc1->setAttribute('string', 'upsert1_updated');
        $upsertDoc1->setAttribute('$updatedAt', $updateDate);
        $updatedUpsertResults = [];
        $database->upsertDocuments($collection, [$upsertDoc1], onNext: function ($doc) use (&$updatedUpsertResults) {
            $updatedUpsertResults[] = $doc;
        });
        $updatedUpsertDoc1 = $updatedUpsertResults[0];

        $this->assertEquals($createDate, $updatedUpsertDoc1->getAttribute('$createdAt'));
        $this->assertEquals($updateDate, $updatedUpsertDoc1->getAttribute('$updatedAt'));

        // Test 3: Upsert new document with both custom dates
        $upsertResults2 = [];
        $database->upsertDocuments($collection, [
            new Document([
                '$id' => 'upsert2',
                '$permissions' => $permissions,
                'string' => 'upsert2_both_dates',
                '$createdAt' => $createDate,
                '$updatedAt' => $updateDate
            ])
        ], onNext: function ($doc) use (&$upsertResults2) {
            $upsertResults2[] = $doc;
        });
        $upsertDoc2 = $upsertResults2[0];

        $this->assertEquals($createDate, $upsertDoc2->getAttribute('$createdAt'));
        $this->assertEquals($updateDate, $upsertDoc2->getAttribute('$updatedAt'));

        // Test 4: Upsert existing document with different dates
        $upsertDoc2->setAttribute('string', 'upsert2_updated');
        $upsertDoc2->setAttribute('$createdAt', $date3);
        $upsertDoc2->setAttribute('$updatedAt', $date3);
        $updatedUpsertResults2 = [];
        $database->upsertDocuments($collection, [$upsertDoc2], onNext: function ($doc) use (&$updatedUpsertResults2) {
            $updatedUpsertResults2[] = $doc;
        });
        $updatedUpsertDoc2 = $updatedUpsertResults2[0];

        $this->assertEquals($date3, $updatedUpsertDoc2->getAttribute('$createdAt'));
        $this->assertEquals($date3, $updatedUpsertDoc2->getAttribute('$updatedAt'));

        // Test 5: Upsert with preserve dates disabled
        $database->setPreserveDates(false);

        $customDate = '2000-01-01T10:00:00.000+00:00';
        $upsertResults3 = [];
        $database->upsertDocuments($collection, [
            new Document([
                '$id' => 'upsert3',
                '$permissions' => $permissions,
                'string' => 'upsert3_disabled',
                '$createdAt' => $customDate,
                '$updatedAt' => $customDate
            ])
        ], onNext: function ($doc) use (&$upsertResults3) {
            $upsertResults3[] = $doc;
        });
        $upsertDoc3 = $upsertResults3[0];

        $this->assertNotEquals($customDate, $upsertDoc3->getAttribute('$createdAt'));
        $this->assertNotEquals($customDate, $upsertDoc3->getAttribute('$updatedAt'));

        // Update with custom dates should also be ignored
        $upsertDoc3->setAttribute('string', 'upsert3_updated');
        $upsertDoc3->setAttribute('$createdAt', $customDate);
        $upsertDoc3->setAttribute('$updatedAt', $customDate);
        $updatedUpsertResults3 = [];
        $database->upsertDocuments($collection, [$upsertDoc3], onNext: function ($doc) use (&$updatedUpsertResults3) {
            $updatedUpsertResults3[] = $doc;
        });
        $updatedUpsertDoc3 = $updatedUpsertResults3[0];

        $this->assertNotEquals($customDate, $updatedUpsertDoc3->getAttribute('$createdAt'));
        $this->assertNotEquals($customDate, $updatedUpsertDoc3->getAttribute('$updatedAt'));

        // Test 6: Bulk upsert operations with custom dates
        $database->setPreserveDates(true);

        // Test 7: Bulk upsert with different date configurations
        $upsertDocuments = [
            new Document([
                '$id' => 'bulk_upsert1',
                '$permissions' => $permissions,
                'string' => 'bulk_upsert1_initial',
                '$createdAt' => $createDate
            ]),
            new Document([
                '$id' => 'bulk_upsert2',
                '$permissions' => $permissions,
                'string' => 'bulk_upsert2_initial',
                '$updatedAt' => $updateDate
            ]),
            new Document([
                '$id' => 'bulk_upsert3',
                '$permissions' => $permissions,
                'string' => 'bulk_upsert3_initial',
                '$createdAt' => $createDate,
                '$updatedAt' => $updateDate
            ]),
            new Document([
                '$id' => 'bulk_upsert4',
                '$permissions' => $permissions,
                'string' => 'bulk_upsert4_initial'
            ])
        ];

        $bulkUpsertResults = [];
        $database->upsertDocuments($collection, $upsertDocuments, onNext: function ($doc) use (&$bulkUpsertResults) {
            $bulkUpsertResults[] = $doc;
        });

        // Test 8: Verify initial bulk upsert state
        foreach (['bulk_upsert1', 'bulk_upsert3'] as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertEquals($createDate, $doc->getAttribute('$createdAt'), "createdAt mismatch for $id");
        }

        foreach (['bulk_upsert2', 'bulk_upsert3'] as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertEquals($updateDate, $doc->getAttribute('$updatedAt'), "updatedAt mismatch for $id");
        }

        foreach (['bulk_upsert4'] as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertNotEmpty($doc->getAttribute('$createdAt'), "createdAt missing for $id");
            $this->assertNotEmpty($doc->getAttribute('$updatedAt'), "updatedAt missing for $id");
        }

        // Test 9: Bulk upsert update with custom dates using updateDocuments
        $newDate = '2000-04-01T12:00:00.000+00:00';
        $updateUpsertDoc = new Document([
            'string' => 'bulk_upsert_updated',
            '$createdAt' => $newDate,
            '$updatedAt' => $newDate
        ]);

        $upsertIds = [];
        foreach ($upsertDocuments as $doc) {
            $upsertIds[] = $doc->getId();
        }

        $database->updateDocuments($collection, $updateUpsertDoc, [
            Query::equal('$id', $upsertIds)
        ]);

        foreach ($upsertIds as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertEquals($newDate, $doc->getAttribute('$createdAt'), "createdAt mismatch for $id");
            $this->assertEquals($newDate, $doc->getAttribute('$updatedAt'), "updatedAt mismatch for $id");
            $this->assertEquals('bulk_upsert_updated', $doc->getAttribute('string'), "string mismatch for $id");
        }

        // Test 10: checking by passing null to each
        $updateUpsertDoc = new Document([
            'string' => 'bulk_upsert_updated',
            '$createdAt' => null,
            '$updatedAt' => null
        ]);

        $upsertIds = [];
        foreach ($upsertDocuments as $doc) {
            $upsertIds[] = $doc->getId();
        }

        $database->updateDocuments($collection, $updateUpsertDoc, [
            Query::equal('$id', $upsertIds)
        ]);

        foreach ($upsertIds as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertNotEmpty($doc->getAttribute('$createdAt'), "createdAt mismatch for $id");
            $this->assertNotEmpty($doc->getAttribute('$updatedAt'), "updatedAt mismatch for $id");
        }

        // Test 11: Bulk upsert operations with upsertDocuments
        $upsertUpdateDocuments = [];
        foreach ($upsertDocuments as $doc) {
            $updatedDoc = clone $doc;
            $updatedDoc->setAttribute('string', 'bulk_upsert_updated_via_upsert');
            $updatedDoc->setAttribute('$createdAt', $newDate);
            $updatedDoc->setAttribute('$updatedAt', $newDate);
            $upsertUpdateDocuments[] = $updatedDoc;
        }

        $upsertUpdateResults = [];
        $countUpsertUpdate = $database->upsertDocuments($collection, $upsertUpdateDocuments, onNext: function ($doc) use (&$upsertUpdateResults) {
            $upsertUpdateResults[] = $doc;
        });
        $this->assertEquals(4, $countUpsertUpdate);

        foreach ($upsertUpdateResults as $doc) {
            $this->assertEquals($newDate, $doc->getAttribute('$createdAt'), "createdAt mismatch for upsert update");
            $this->assertEquals($newDate, $doc->getAttribute('$updatedAt'), "updatedAt mismatch for upsert update");
            $this->assertEquals('bulk_upsert_updated_via_upsert', $doc->getAttribute('string'), "string mismatch for upsert update");
        }

        // Test 12: Bulk upsert with preserve dates disabled
        $database->setPreserveDates(false);

        $customDate = 'should be ignored anyways so no error';
        $upsertDisabledDocuments = [];
        foreach ($upsertDocuments as $doc) {
            $disabledDoc = clone $doc;
            $disabledDoc->setAttribute('string', 'bulk_upsert_disabled');
            $disabledDoc->setAttribute('$createdAt', $customDate);
            $disabledDoc->setAttribute('$updatedAt', $customDate);
            $upsertDisabledDocuments[] = $disabledDoc;
        }

        $upsertDisabledResults = [];
        $countUpsertDisabled = $database->upsertDocuments($collection, $upsertDisabledDocuments, onNext: function ($doc) use (&$upsertDisabledResults) {
            $upsertDisabledResults[] = $doc;
        });
        $this->assertEquals(4, $countUpsertDisabled);

        foreach ($upsertDisabledResults as $doc) {
            $this->assertNotEquals($customDate, $doc->getAttribute('$createdAt'), "createdAt should not be custom date when disabled");
            $this->assertNotEquals($customDate, $doc->getAttribute('$updatedAt'), "updatedAt should not be custom date when disabled");
            $this->assertEquals('bulk_upsert_disabled', $doc->getAttribute('string'), "string mismatch for disabled upsert");
        }

        $database->setPreserveDates(false);
        $database->deleteCollection($collection);
    }

    public function testUpdateDocumentsCount(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForUpserts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = "update_count";
        $database->createCollection($collectionName);

        $database->createAttribute($collectionName, 'key', Database::VAR_STRING, 60, false);
        $database->createAttribute($collectionName, 'value', Database::VAR_STRING, 60, false);

        $permissions = [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())];

        $docs =  [
            new Document([
                '$id' => 'bulk_upsert1',
                '$permissions' => $permissions,
                'key' => 'bulk_upsert1_initial',
            ]),
            new Document([
                '$id' => 'bulk_upsert2',
                '$permissions' => $permissions,
                'key' => 'bulk_upsert2_initial',
            ]),
            new Document([
                '$id' => 'bulk_upsert3',
                '$permissions' => $permissions,
                'key' => 'bulk_upsert3_initial',
            ]),
            new Document([
                '$id' => 'bulk_upsert4',
                '$permissions' => $permissions,
                'key' => 'bulk_upsert4_initial'
            ])
        ];
        $upsertUpdateResults = [];
        $count = $database->upsertDocuments($collectionName, $docs, onNext: function ($doc) use (&$upsertUpdateResults) {
            $upsertUpdateResults[] = $doc;
        });
        $this->assertCount(4, $upsertUpdateResults);
        $this->assertEquals(4, $count);

        $updates = new Document(['value' => 'test']);
        $newDocs = [];
        $count = $database->updateDocuments($collectionName, $updates, onNext:function ($doc) use (&$newDocs) {
            $newDocs[] = $doc;
        });

        $this->assertCount(4, $newDocs);
        $this->assertEquals(4, $count);

        $database->deleteCollection($collectionName);
    }

    public function testCreateUpdateDocumentsMismatch(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        // with different set of attributes
        $colName = "docs_with_diff";
        $database->createCollection($colName);
        $database->createAttribute($colName, 'key', Database::VAR_STRING, 50, true);
        $database->createAttribute($colName, 'value', Database::VAR_STRING, 50, false, 'value');
        $permissions = [Permission::read(Role::any()), Permission::write(Role::any()),Permission::update(Role::any())];
        $docs =  [
            new Document([
                '$id' => 'doc1',
                'key' => 'doc1',
            ]),
            new Document([
                '$id' => 'doc2',
                'key' => 'doc2',
                'value' => 'test',
            ]),
            new Document([
                '$id' => 'doc3',
                '$permissions' => $permissions,
                'key' => 'doc3'
            ]),
        ];
        $this->assertEquals(3, $database->createDocuments($colName, $docs));
        // we should get only one document as read permission provided to the last document only
        $addedDocs = $database->find($colName);
        $this->assertCount(1, $addedDocs);
        $doc = $addedDocs[0];
        $this->assertEquals('doc3', $doc->getId());
        $this->assertNotEmpty($doc->getPermissions());
        $this->assertCount(3, $doc->getPermissions());

        $database->createDocument($colName, new Document([
            '$id' => 'doc4',
            '$permissions' => $permissions,
            'key' => 'doc4'
        ]));

        $this->assertEquals(2, $database->updateDocuments($colName, new Document(['key' => 'new doc'])));
        $doc = $database->getDocument($colName, 'doc4');
        $this->assertEquals('doc4', $doc->getId());
        $this->assertEquals('value', $doc->getAttribute('value'));

        $addedDocs = $database->find($colName);
        $this->assertCount(2, $addedDocs);
        foreach ($addedDocs as $doc) {
            $this->assertNotEmpty($doc->getPermissions());
            $this->assertCount(3, $doc->getPermissions());
            $this->assertEquals('value', $doc->getAttribute('value'));
        }
        $database->deleteCollection($colName);
    }

    public function testBypassStructureWithSupportForAttributes(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();
        // for schemaless the validation will be automatically skipped
        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionId = 'successive_update_single';

        $database->createCollection($collectionId);
        $database->createAttribute($collectionId, 'attrA', Database::VAR_STRING, 50, true);
        $database->createAttribute($collectionId, 'attrB', Database::VAR_STRING, 50, true);

        // bypass required
        $database->disableValidation();

        $permissions = [Permission::read(Role::any()), Permission::write(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())];
        $docs = $database->createDocuments($collectionId, [
            new Document(['attrA' => null,'attrB' => 'B','$permissions' => $permissions])
        ]);

        $docs = $database->find($collectionId);
        foreach ($docs as $doc) {
            $this->assertArrayHasKey('attrA', $doc->getAttributes());
            $this->assertNull($doc->getAttribute('attrA'));
            $this->assertEquals('B', $doc->getAttribute('attrB'));
        }
        // reset
        $database->enableValidation();

        try {
            $database->createDocuments($collectionId, [
                new Document(['attrA' => null,'attrB' => 'B','$permissions' => $permissions])
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(StructureException::class, $e);
        }

        $database->deleteCollection($collectionId);
    }

    public function testValidationGuardsWithNullRequired(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Base collection and attributes
        $collection = 'validation_guard_all';
        $database->createCollection($collection, permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ], documentSecurity: true);
        $database->createAttribute($collection, 'name', Database::VAR_STRING, 32, true);
        $database->createAttribute($collection, 'age', Database::VAR_INTEGER, 0, true);
        $database->createAttribute($collection, 'value', Database::VAR_INTEGER, 0, false);

        // 1) createDocument with null required should fail when validation enabled, pass when disabled
        try {
            $database->createDocument($collection, new Document([
                '$permissions' => [Permission::read(Role::any()), Permission::create(Role::any())],
                'name' => null,
                'age' => null,
            ]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertInstanceOf(StructureException::class, $e);
        }

        $database->disableValidation();
        $doc = $database->createDocument($collection, new Document([
            '$id' => 'created-null',
            '$permissions' => [Permission::read(Role::any()), Permission::create(Role::any()), Permission::update(Role::any())],
            'name' => null,
            'age' => null,
        ]));
        $this->assertEquals('created-null', $doc->getId());
        $database->enableValidation();

        // Seed a valid document for updates
        $valid = $database->createDocument($collection, new Document([
            '$id' => 'valid',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'ok',
            'age' => 10,
        ]));
        $this->assertEquals('valid', $valid->getId());

        // 2) updateDocument set required to null should fail when validation enabled, pass when disabled
        try {
            $database->updateDocument($collection, 'valid', new Document([
                'age' => null,
            ]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertInstanceOf(StructureException::class, $e);
        }

        $database->disableValidation();
        $updated = $database->updateDocument($collection, 'valid', new Document([
            'age' => null,
        ]));
        $this->assertNull($updated->getAttribute('age'));
        $database->enableValidation();

        // Seed a few valid docs for bulk update
        for ($i = 0; $i < 2; $i++) {
            $database->createDocument($collection, new Document([
                '$id' => 'b' . $i,
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'name' => 'ok',
                'age' => 1,
            ]));
        }

        // 3) updateDocuments setting required to null should fail when validation enabled, pass when disabled
        if ($database->getAdapter()->getSupportForBatchOperations()) {
            try {
                $database->updateDocuments($collection, new Document([
                    'name' => null,
                ]));
                $this->fail('Failed to throw exception');
            } catch (Throwable $e) {
                $this->assertInstanceOf(StructureException::class, $e);
            }

            $database->disableValidation();
            $count = $database->updateDocuments($collection, new Document([
                'name' => null,
            ]));
            $this->assertGreaterThanOrEqual(3, $count); // at least the seeded docs are updated
            $database->enableValidation();
        }

        // 4) upsertDocumentsWithIncrease with null required should fail when validation enabled, pass when disabled
        if ($database->getAdapter()->getSupportForUpserts()) {
            try {
                $database->upsertDocumentsWithIncrease(
                    collection: $collection,
                    attribute: 'value',
                    documents: [new Document([
                        '$id' => 'u1',
                        'name' => null, // required null
                        'value' => 1,
                    ])]
                );
                $this->fail('Failed to throw exception');
            } catch (Throwable $e) {
                $this->assertInstanceOf(StructureException::class, $e);
            }

            $database->disableValidation();
            $ucount = $database->upsertDocumentsWithIncrease(
                collection: $collection,
                attribute: 'value',
                documents: [new Document([
                    '$id' => 'u1',
                    'name' => null,
                    'value' => 1,
                ])]
            );
            $this->assertEquals(1, $ucount);
            $database->enableValidation();
        }

        // Cleanup
        $database->deleteCollection($collection);
    }

    /**
     * SQL adapters store columns under filter(attributeId). After getDocument + decode, and after
     * updateDocument (return value + refetch), the document must expose only schema ids (e.g.
     * pb.e_DSS.FIRMWARE_VERSION), never the filtered alias.
     */
    public function testDottedAttributeKeyGetDocumentExposesOnlySchemaKeys(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Keep id short: MySQL/MariaDB table names are limited to 64 characters (namespace + collection).
        $collectionId = 'dotkey_' . ID::unique();
        $attrId = 'pb.e_DSS.FIRMWARE_VERSION';
        $filteredStorageKey = $database->getAdapter()->filter($attrId);

        $database->createCollection($collectionId);
        $this->assertTrue($database->createAttribute($collectionId, $attrId, Database::VAR_STRING, 128, false));

        // Optional attribute omitted: DB column is NULL — decode must not leave the SQL column name as a key.
        $database->createDocument($collectionId, new Document([
            '$id' => 'dev1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]));

        $doc = $database->getDocument($collectionId, 'dev1');
        $this->assertSame('dev1', $doc->getId());
        $this->assertNull($doc->getAttribute($attrId));
        $this->assertArrayNotHasKey($filteredStorageKey, $doc->getAttributes());
        $userKeys = array_keys($doc->getAttributes());
        sort($userKeys);
        $this->assertSame([$attrId], $userKeys);

        $updated = $database->updateDocument($collectionId, 'dev1', new Document([
            $attrId => '1.0.0',
        ]));
        $this->assertSame('1.0.0', $updated->getAttribute($attrId));
        $this->assertArrayNotHasKey($filteredStorageKey, $updated->getAttributes());
        $userKeys = array_keys($updated->getAttributes());
        sort($userKeys);
        $this->assertSame([$attrId], $userKeys);

        $doc = $database->getDocument($collectionId, 'dev1');
        $this->assertSame('1.0.0', $doc->getAttribute($attrId));
        $this->assertArrayNotHasKey($filteredStorageKey, $doc->getAttributes());
        $userKeys = array_keys($doc->getAttributes());
        sort($userKeys);
        $this->assertSame([$attrId], $userKeys);

        $updated = $database->updateDocument($collectionId, 'dev1', new Document([
            $attrId => '2.0.0',
        ]));
        $this->assertSame('2.0.0', $updated->getAttribute($attrId));
        $this->assertArrayNotHasKey($filteredStorageKey, $updated->getAttributes());
        $userKeys = array_keys($updated->getAttributes());
        sort($userKeys);
        $this->assertSame([$attrId], $userKeys);

        $doc = $database->getDocument($collectionId, 'dev1');
        $this->assertSame('2.0.0', $doc->getAttribute($attrId));
        $this->assertArrayNotHasKey($filteredStorageKey, $doc->getAttributes());
        $userKeys = array_keys($doc->getAttributes());
        sort($userKeys);
        $this->assertSame([$attrId], $userKeys);

        $database->deleteCollection($collectionId);
    }

    public function testUpsertWithJSONFilters(): void
    {
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create collection with JSON filter attribute
        $collection = ID::unique();
        $database->createCollection($collection, permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ]);

        $database->createAttribute($collection, 'name', Database::VAR_STRING, 128, true);
        $database->createAttribute($collection, 'metadata', Database::VAR_STRING, 4000, true, filters: ['json']);

        $permissions = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        // Test 1: Insertion (createDocument) with JSON filter
        $docId1 = 'json-doc-1';
        $initialMetadata = [
            'version' => '1.0.0',
            'tags' => ['php', 'database'],
            'config' => [
                'debug' => false,
                'timeout' => 30
            ]
        ];

        $document1 = $database->createDocument($collection, new Document([
            '$id' => $docId1,
            'name' => 'Initial Document',
            'metadata' => $initialMetadata,
            '$permissions' => $permissions,
        ]));

        $this->assertEquals($docId1, $document1->getId());
        $this->assertEquals('Initial Document', $document1->getAttribute('name'));
        $this->assertIsArray($document1->getAttribute('metadata'));
        $this->assertEquals('1.0.0', $document1->getAttribute('metadata')['version']);
        $this->assertEquals(['php', 'database'], $document1->getAttribute('metadata')['tags']);

        // Test 2: Update (updateDocument) with modified JSON filter
        $updatedMetadata = [
            'version' => '2.0.0',
            'tags' => ['php', 'database', 'json'],
            'config' => [
                'debug' => true,
                'timeout' => 60,
                'cache' => true
            ],
            'updated' => true
        ];

        $document1->setAttribute('name', 'Updated Document');
        $document1->setAttribute('metadata', $updatedMetadata);

        $updatedDoc = $database->updateDocument($collection, $docId1, $document1);

        $this->assertEquals($docId1, $updatedDoc->getId());
        $this->assertEquals('Updated Document', $updatedDoc->getAttribute('name'));
        $this->assertIsArray($updatedDoc->getAttribute('metadata'));
        $this->assertEquals('2.0.0', $updatedDoc->getAttribute('metadata')['version']);
        $this->assertEquals(['php', 'database', 'json'], $updatedDoc->getAttribute('metadata')['tags']);
        $this->assertTrue($updatedDoc->getAttribute('metadata')['config']['debug']);
        $this->assertTrue($updatedDoc->getAttribute('metadata')['updated']);

        // Test 3: Upsert - Create new document (upsertDocument)
        $docId2 = 'json-doc-2';
        $newMetadata = [
            'version' => '1.5.0',
            'tags' => ['javascript', 'node'],
            'config' => [
                'debug' => false,
                'timeout' => 45
            ]
        ];

        $document2 = new Document([
            '$id' => $docId2,
            'name' => 'New Upsert Document',
            'metadata' => $newMetadata,
            '$permissions' => $permissions,
        ]);

        $upsertedDoc = $database->upsertDocument($collection, $document2);

        $this->assertEquals($docId2, $upsertedDoc->getId());
        $this->assertEquals('New Upsert Document', $upsertedDoc->getAttribute('name'));
        $this->assertIsArray($upsertedDoc->getAttribute('metadata'));
        $this->assertEquals('1.5.0', $upsertedDoc->getAttribute('metadata')['version']);

        // Test 4: Upsert - Update existing document (upsertDocument)
        $document2->setAttribute('name', 'Updated Upsert Document');
        $document2->setAttribute('metadata', [
            'version' => '2.5.0',
            'tags' => ['javascript', 'node', 'typescript'],
            'config' => [
                'debug' => true,
                'timeout' => 90
            ],
            'migrated' => true
        ]);

        $upsertedDoc2 = $database->upsertDocument($collection, $document2);

        $this->assertEquals($docId2, $upsertedDoc2->getId());
        $this->assertEquals('Updated Upsert Document', $upsertedDoc2->getAttribute('name'));
        $this->assertIsArray($upsertedDoc2->getAttribute('metadata'));
        $this->assertEquals('2.5.0', $upsertedDoc2->getAttribute('metadata')['version']);
        $this->assertEquals(['javascript', 'node', 'typescript'], $upsertedDoc2->getAttribute('metadata')['tags']);
        $this->assertTrue($upsertedDoc2->getAttribute('metadata')['migrated']);

        // Test 5: Upsert - Bulk upsertDocuments (create and update)
        $docId3 = 'json-doc-3';
        $docId4 = 'json-doc-4';

        $bulkDocuments = [
            new Document([
                '$id' => $docId3,
                'name' => 'Bulk Upsert 1',
                'metadata' => [
                    'version' => '3.0.0',
                    'tags' => ['python', 'flask'],
                    'config' => ['debug' => false]
                ],
                '$permissions' => $permissions,
            ]),
            new Document([
                '$id' => $docId4,
                'name' => 'Bulk Upsert 2',
                'metadata' => [
                    'version' => '3.1.0',
                    'tags' => ['go', 'golang'],
                    'config' => ['debug' => true]
                ],
                '$permissions' => $permissions,
            ]),
            // Update existing document
            new Document([
                '$id' => $docId1,
                'name' => 'Bulk Updated Document',
                'metadata' => [
                    'version' => '3.0.0',
                    'tags' => ['php', 'database', 'bulk'],
                    'config' => [
                        'debug' => false,
                        'timeout' => 120
                    ],
                    'bulkUpdated' => true
                ],
                '$permissions' => $permissions,
            ]),
        ];

        $count = $database->upsertDocuments($collection, $bulkDocuments);
        $this->assertEquals(3, $count);

        // Verify bulk upsert results
        $bulkDoc1 = $database->getDocument($collection, $docId3);
        $this->assertEquals('Bulk Upsert 1', $bulkDoc1->getAttribute('name'));
        $this->assertEquals('3.0.0', $bulkDoc1->getAttribute('metadata')['version']);

        $bulkDoc2 = $database->getDocument($collection, $docId4);
        $this->assertEquals('Bulk Upsert 2', $bulkDoc2->getAttribute('name'));
        $this->assertEquals('3.1.0', $bulkDoc2->getAttribute('metadata')['version']);

        $bulkDoc3 = $database->getDocument($collection, $docId1);
        $this->assertEquals('Bulk Updated Document', $bulkDoc3->getAttribute('name'));
        $this->assertEquals('3.0.0', $bulkDoc3->getAttribute('metadata')['version']);
        $this->assertTrue($bulkDoc3->getAttribute('metadata')['bulkUpdated']);

        // Cleanup
        $database->deleteCollection($collection);
    }

    public function testFindRegex(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Skip test if regex is not supported
        if (!$database->getAdapter()->getSupportForRegex()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Determine regex support type
        $supportsPCRE = $database->getAdapter()->getSupportForPCRERegex();
        $supportsPOSIX = $database->getAdapter()->getSupportForPOSIXRegex();

        // Determine word boundary pattern based on support
        $wordBoundaryPattern = null;
        $wordBoundaryPatternPHP = null;
        if ($supportsPCRE) {
            $wordBoundaryPattern = '\\b'; // PCRE uses \b
            $wordBoundaryPatternPHP = '\\b'; // PHP preg_match uses \b
        } elseif ($supportsPOSIX) {
            $wordBoundaryPattern = '\\y'; // POSIX uses \y
            $wordBoundaryPatternPHP = '\\b'; // PHP preg_match still uses \b for verification
        }

        $database->createCollection('moviesRegex', permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ]);

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->assertEquals(true, $database->createAttribute('moviesRegex', 'name', Database::VAR_STRING, 128, true));
            $this->assertEquals(true, $database->createAttribute('moviesRegex', 'director', Database::VAR_STRING, 128, true));
            $this->assertEquals(true, $database->createAttribute('moviesRegex', 'year', Database::VAR_INTEGER, 0, true));
        }

        if ($database->getAdapter()->getSupportForTrigramIndex()) {
            $database->createIndex('moviesRegex', 'trigram_name', Database::INDEX_TRIGRAM, ['name']);
            $database->createIndex('moviesRegex', 'trigram_director', Database::INDEX_TRIGRAM, ['director']);
        }

        // Create test documents
        $database->createDocuments('moviesRegex', [
            new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Frozen',
                'director' => 'Chris Buck & Jennifer Lee',
                'year' => 2013,
            ]),
            new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Frozen II',
                'director' => 'Chris Buck & Jennifer Lee',
                'year' => 2019,
            ]),
            new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Captain America: The First Avenger',
                'director' => 'Joe Johnston',
                'year' => 2011,
            ]),
            new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Captain Marvel',
                'director' => 'Anna Boden & Ryan Fleck',
                'year' => 2019,
            ]),
            new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Work in Progress',
                'director' => 'TBD',
                'year' => 2025,
            ]),
            new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Work in Progress 2',
                'director' => 'TBD',
                'year' => 2026,
            ]),
        ]);

        // Helper function to verify regex query completeness
        $verifyRegexQuery = function (string $attribute, string $regexPattern, array $queryResults) use ($database) {
            // Convert database regex pattern to PHP regex format.
            // POSIX-style word boundary (\y) is not supported by PHP PCRE, so map it to \b.
            $normalizedPattern = str_replace('\y', '\b', $regexPattern);
            $phpPattern = '/' . str_replace('/', '\/', $normalizedPattern) . '/';

            // Get all documents to manually verify
            $allDocuments = $database->find('moviesRegex');

            // Manually filter documents that match the pattern
            $expectedMatches = [];
            foreach ($allDocuments as $doc) {
                $value = $doc->getAttribute($attribute);
                if (preg_match($phpPattern, $value)) {
                    $expectedMatches[] = $doc->getId();
                }
            }

            // Get IDs from query results
            $actualMatches = array_map(fn ($doc) => $doc->getId(), $queryResults);

            // Verify no extra documents are returned
            foreach ($queryResults as $doc) {
                $value = $doc->getAttribute($attribute);
                $this->assertTrue(
                    (bool) preg_match($phpPattern, $value),
                    "Document '{$doc->getId()}' with {$attribute}='{$value}' should match pattern '{$regexPattern}'"
                );
            }

            // Verify all expected documents are returned (no missing)
            sort($expectedMatches);
            sort($actualMatches);
            $this->assertEquals(
                $expectedMatches,
                $actualMatches,
                "Query should return exactly the documents matching pattern '{$regexPattern}' on attribute '{$attribute}'"
            );
        };

        // Test basic regex pattern - match movies starting with 'Captain'
        // Note: Pattern format may vary by adapter (MongoDB uses regex strings, SQL uses REGEXP)
        $pattern = '/^Captain/';
        $documents = $database->find('moviesRegex', [
            Query::regex('name', '^Captain'),
        ]);

        // Verify completeness: all matching documents returned, no extra documents
        $verifyRegexQuery('name', '^Captain', $documents);

        // Verify expected documents are included
        $names = array_map(fn ($doc) => $doc->getAttribute('name'), $documents);
        $this->assertTrue(in_array('Captain America: The First Avenger', $names));
        $this->assertTrue(in_array('Captain Marvel', $names));

        // Test regex pattern - match movies containing 'Frozen'
        $pattern = '/Frozen/';
        $documents = $database->find('moviesRegex', [
            Query::regex('name', 'Frozen'),
        ]);

        // Verify completeness: all matching documents returned, no extra documents
        $verifyRegexQuery('name', 'Frozen', $documents);

        // Test regex pattern - match exact title 'Frozen'
        $exactFrozenDocuments = $database->find('moviesRegex', [
            Query::regex('name', '^Frozen$'),
        ]);
        $verifyRegexQuery('name', '^Frozen$', $exactFrozenDocuments);
        $this->assertCount(1, $exactFrozenDocuments, 'Exact ^Frozen$ regex should return only one document');
        // Verify expected documents are included
        $names = array_map(fn ($doc) => $doc->getAttribute('name'), $documents);
        $this->assertTrue(in_array('Frozen', $names));
        $this->assertTrue(in_array('Frozen II', $names));

        // Test regex pattern - match movies ending with 'Marvel'
        $pattern = '/Marvel$/';
        $documents = $database->find('moviesRegex', [
            Query::regex('name', 'Marvel$'),
        ]);

        // Verify completeness: all matching documents returned, no extra documents
        $verifyRegexQuery('name', 'Marvel$', $documents);

        $this->assertEquals(1, count($documents)); // Only Captain Marvel
        $this->assertEquals('Captain Marvel', $documents[0]->getAttribute('name'));

        // Test regex pattern - match movies with 'Work' in the name
        $pattern = '/.*Work.*/';
        $documents = $database->find('moviesRegex', [
            Query::regex('name', '.*Work.*'),
        ]);

        // Verify completeness: all matching documents returned, no extra documents
        $verifyRegexQuery('name', '.*Work.*', $documents);

        // Verify expected documents are included
        $names = array_map(fn ($doc) => $doc->getAttribute('name'), $documents);
        $this->assertTrue(in_array('Work in Progress', $names));
        $this->assertTrue(in_array('Work in Progress 2', $names));

        // Test regex pattern - match movies with 'Buck' in director
        $pattern = '/.*Buck.*/';
        $documents = $database->find('moviesRegex', [
            Query::regex('director', '.*Buck.*'),
        ]);

        // Verify completeness: all matching documents returned, no extra documents
        $verifyRegexQuery('director', '.*Buck.*', $documents);

        // Verify expected documents are included
        $names = array_map(fn ($doc) => $doc->getAttribute('name'), $documents);
        $this->assertTrue(in_array('Frozen', $names));
        $this->assertTrue(in_array('Frozen II', $names));

        // Test regex with case pattern - adapters may be case-sensitive or case-insensitive
        // MySQL/MariaDB REGEXP is case-insensitive by default, MongoDB is case-sensitive
        $patternCaseSensitive = '/captain/';
        $patternCaseInsensitive = '/captain/i';
        $documents = $database->find('moviesRegex', [
            Query::regex('name', 'captain'), // lowercase
        ]);

        // Verify all returned documents match the pattern (case-insensitive check for verification)
        foreach ($documents as $doc) {
            $name = $doc->getAttribute('name');
            // Verify that returned documents contain 'captain' (case-insensitive check)
            $this->assertTrue(
                (bool) preg_match($patternCaseInsensitive, $name),
                "Document '{$name}' should match pattern 'captain' (case-insensitive check)"
            );
        }

        // Verify completeness: Check what the database actually returns
        // Some adapters (MongoDB) are case-sensitive, others (MySQL/MariaDB) are case-insensitive
        // We'll determine expected matches based on case-sensitive matching (pure regex behavior)
        // If the adapter is case-insensitive, it will return more documents, which is fine
        $allDocuments = $database->find('moviesRegex');
        $expectedMatchesCaseSensitive = [];
        $expectedMatchesCaseInsensitive = [];
        foreach ($allDocuments as $doc) {
            $name = $doc->getAttribute('name');
            if (preg_match($patternCaseSensitive, $name)) {
                $expectedMatchesCaseSensitive[] = $doc->getId();
            }
            if (preg_match($patternCaseInsensitive, $name)) {
                $expectedMatchesCaseInsensitive[] = $doc->getId();
            }
        }

        $actualMatches = array_map(fn ($doc) => $doc->getId(), $documents);
        sort($actualMatches);

        // The database might be case-sensitive (MongoDB) or case-insensitive (MySQL/MariaDB)
        // Check which one matches the actual results
        sort($expectedMatchesCaseSensitive);
        sort($expectedMatchesCaseInsensitive);

        // Verify that actual results match either case-sensitive or case-insensitive expectations
        $matchesCaseSensitive = ($expectedMatchesCaseSensitive === $actualMatches);
        $matchesCaseInsensitive = ($expectedMatchesCaseInsensitive === $actualMatches);

        $this->assertTrue(
            $matchesCaseSensitive || $matchesCaseInsensitive,
            "Query results should match either case-sensitive (" . count($expectedMatchesCaseSensitive) . " docs) or case-insensitive (" . count($expectedMatchesCaseInsensitive) . " docs) expectations. Got " . count($actualMatches) . " documents."
        );

        // Test regex with case-insensitive pattern (if adapter supports it via flags)
        // Test with uppercase to verify case sensitivity
        $pattern = '/Captain/';
        $documents = $database->find('moviesRegex', [
            Query::regex('name', 'Captain'), // uppercase
        ]);

        // Verify all returned documents match the pattern
        foreach ($documents as $doc) {
            $name = $doc->getAttribute('name');
            $this->assertTrue(
                (bool) preg_match($pattern, $name),
                "Document '{$name}' should match pattern 'Captain'"
            );
        }

        // Verify completeness
        $allDocuments = $database->find('moviesRegex');
        $expectedMatches = [];
        foreach ($allDocuments as $doc) {
            $name = $doc->getAttribute('name');
            if (preg_match($pattern, $name)) {
                $expectedMatches[] = $doc->getId();
            }
        }
        $actualMatches = array_map(fn ($doc) => $doc->getId(), $documents);
        sort($expectedMatches);
        sort($actualMatches);
        $this->assertEquals(
            $expectedMatches,
            $actualMatches,
            "Query should return exactly the documents matching pattern 'Captain'"
        );

        // Test regex combined with other queries
        $pattern = '/^Captain/';
        $documents = $database->find('moviesRegex', [
            Query::regex('name', '^Captain'),
            Query::greaterThan('year', 2010),
        ]);

        // Verify all returned documents match both conditions
        foreach ($documents as $doc) {
            $name = $doc->getAttribute('name');
            $year = $doc->getAttribute('year');
            $this->assertTrue(
                (bool) preg_match($pattern, $name),
                "Document '{$name}' should match pattern '{$pattern}'"
            );
            $this->assertGreaterThan(2010, $year, "Document '{$name}' should have year > 2010");
        }

        // Verify completeness: manually check all documents that match both conditions
        $allDocuments = $database->find('moviesRegex');
        $expectedMatches = [];
        foreach ($allDocuments as $doc) {
            $name = $doc->getAttribute('name');
            $year = $doc->getAttribute('year');
            if (preg_match($pattern, $name) && $year > 2010) {
                $expectedMatches[] = $doc->getId();
            }
        }
        $actualMatches = array_map(fn ($doc) => $doc->getId(), $documents);
        sort($expectedMatches);
        sort($actualMatches);
        $this->assertEquals(
            $expectedMatches,
            $actualMatches,
            "Query should return exactly the documents matching both regex '^Captain' and year > 2010"
        );

        // Test regex with limit
        $pattern = '/.*/';
        $documents = $database->find('moviesRegex', [
            Query::regex('name', '.*'), // Match all
            Query::limit(3),
        ]);

        $this->assertEquals(3, count($documents));

        // Verify all returned documents match the pattern (should match all)
        foreach ($documents as $doc) {
            $name = $doc->getAttribute('name');
            $this->assertTrue(
                (bool) preg_match($pattern, $name),
                "Document '{$name}' should match pattern '{$pattern}'"
            );
        }

        // Note: With limit, we can't verify completeness, but we can verify all returned match

        // Test regex with non-matching pattern
        $pattern = '/^NonExistentPattern$/';
        $documents = $database->find('moviesRegex', [
            Query::regex('name', '^NonExistentPattern$'),
        ]);

        $this->assertEquals(0, count($documents));

        // Verify no documents match (double-check by getting all and filtering)
        $allDocuments = $database->find('moviesRegex');
        $matchingCount = 0;
        foreach ($allDocuments as $doc) {
            $name = $doc->getAttribute('name');
            if (preg_match($pattern, $name)) {
                $matchingCount++;
            }
        }
        $this->assertEquals(0, $matchingCount, "No documents should match pattern '{$pattern}'");

        // Verify completeness: no documents should be returned
        $this->assertEquals([], array_map(fn ($doc) => $doc->getId(), $documents));

        // Test regex with special characters (should be escaped or handled properly)
        $pattern = '/.*:.*/';
        $documents = $database->find('moviesRegex', [
            Query::regex('name', '.*:.*'), // Match movies with colon
        ]);

        // Verify completeness: all matching documents returned, no extra documents
        $verifyRegexQuery('name', '.*:.*', $documents);

        // Verify expected document is included
        $names = array_map(fn ($doc) => $doc->getAttribute('name'), $documents);
        $this->assertTrue(in_array('Captain America: The First Avenger', $names));

        // ReDOS safety: ensure pathological patterns respond quickly and do not hang
        $catastrophicPattern = '(a+)+$';
        $start = microtime(true);
        $redosDocs = $database->find('moviesRegex', [
            Query::regex('name', $catastrophicPattern),
        ]);
        $elapsed = microtime(true) - $start;
        $this->assertLessThan(1.0, $elapsed, 'Regex evaluation should not be slow or vulnerable to ReDOS');
        $verifyRegexQuery('name', $catastrophicPattern, $redosDocs);
        $this->assertCount(0, $redosDocs, 'Pathological regex should not match any movie titles');

        // Test regex search pattern - match movies with word boundaries
        // Only test if word boundaries are supported (PCRE or POSIX)
        if ($wordBoundaryPattern !== null) {
            $dbPattern = $wordBoundaryPattern . 'Work' . $wordBoundaryPattern;
            $phpPattern = '/' . $wordBoundaryPatternPHP . 'Work' . $wordBoundaryPatternPHP . '/';
            $documents = $database->find('moviesRegex', [
                Query::regex('name', $dbPattern),
            ]);

            // Verify all returned documents match the pattern
            foreach ($documents as $doc) {
                $name = $doc->getAttribute('name');
                $this->assertTrue(
                    (bool) preg_match($phpPattern, $name),
                    "Document '{$name}' should match pattern '{$dbPattern}'"
                );
            }

            // Verify completeness: manually check all documents
            $allDocuments = $database->find('moviesRegex');
            $expectedMatches = [];
            foreach ($allDocuments as $doc) {
                $name = $doc->getAttribute('name');
                if (preg_match($phpPattern, $name)) {
                    $expectedMatches[] = $doc->getId();
                }
            }
            $actualMatches = array_map(fn ($doc) => $doc->getId(), $documents);
            sort($expectedMatches);
            sort($actualMatches);
            $this->assertEquals(
                $expectedMatches,
                $actualMatches,
                "Query should return exactly the documents matching pattern '{$dbPattern}'"
            );
        }

        // Test regex search with multiple patterns - match movies containing 'Captain' or 'Frozen'
        $pattern1 = '/Captain/';
        $pattern2 = '/Frozen/';
        $documents = $database->find('moviesRegex', [
            Query::or([
                Query::regex('name', 'Captain'),
                Query::regex('name', 'Frozen'),
            ]),
        ]);

        // Verify all returned documents match at least one pattern
        foreach ($documents as $doc) {
            $name = $doc->getAttribute('name');
            $matchesPattern1 = (bool) preg_match($pattern1, $name);
            $matchesPattern2 = (bool) preg_match($pattern2, $name);
            $this->assertTrue(
                $matchesPattern1 || $matchesPattern2,
                "Document '{$name}' should match either pattern 'Captain' or 'Frozen'"
            );
        }

        // Verify completeness: manually check all documents
        $allDocuments = $database->find('moviesRegex');
        $expectedMatches = [];
        foreach ($allDocuments as $doc) {
            $name = $doc->getAttribute('name');
            if (preg_match($pattern1, $name) || preg_match($pattern2, $name)) {
                $expectedMatches[] = $doc->getId();
            }
        }
        $actualMatches = array_map(fn ($doc) => $doc->getId(), $documents);
        sort($expectedMatches);
        sort($actualMatches);
        $this->assertEquals(
            $expectedMatches,
            $actualMatches,
            "Query should return exactly the documents matching pattern 'Captain' OR 'Frozen'"
        );
        $database->deleteCollection('moviesRegex');
    }
    public function testRegexInjection(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        // Skip test if regex is not supported
        if (!$database->getAdapter()->getSupportForRegex()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'injectionTest';
        $database->createCollection($collectionName, permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ]);

        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->assertEquals(true, $database->createAttribute($collectionName, 'text', Database::VAR_STRING, 1000, true));
        }

        // Create test documents - one that should match, one that shouldn't
        $database->createDocument($collectionName, new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'text' => 'target',
        ]));

        $database->createDocument($collectionName, new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'text' => 'other',
        ]));

        // SQL injection attempts - these should NOT return the "other" document
        $sqlInjectionPatterns = [
            "target') OR '1'='1",           // SQL injection attempt
            "target' OR 1=1--",            // SQL injection with comment
            "target' OR 'x'='x",           // SQL injection attempt
            "target' UNION SELECT *--",     // SQL UNION injection
        ];

        // MongoDB injection attempts - these should NOT return the "other" document
        $mongoInjectionPatterns = [
            'target" || "1"=="1',          // MongoDB injection attempt
            'target" || true',              // MongoDB boolean injection
            'target"} || {"text": "other"}', // MongoDB operator injection
        ];

        $allInjectionPatterns = array_merge($sqlInjectionPatterns, $mongoInjectionPatterns);

        foreach ($allInjectionPatterns as $pattern) {
            try {
                $results = $database->find($collectionName, [
                    Query::regex('text', $pattern),
                ]);

                // Critical check: if injection succeeded, we might get the "other" document
                // which should NOT match a pattern starting with "target"
                $foundOther = false;
                foreach ($results as $doc) {
                    $text = $doc->getAttribute('text');
                    if ($text === 'other') {
                        $foundOther = true;

                        // Verify that "other" doesn't actually match the pattern as a regex
                        $matches = @preg_match('/' . str_replace('/', '\/', $pattern) . '/', $text);
                        if ($matches === 0 || $matches === false) {
                            // "other" doesn't match the pattern but was returned
                            // This indicates potential injection vulnerability
                            $this->fail(
                                "Potential injection detected: Pattern '{$pattern}' returned document 'other' " .
                                "which doesn't match the pattern. This suggests SQL/MongoDB injection may have succeeded."
                            );
                        }
                    }
                }

                // Additional verification: check that all returned documents actually match the pattern
                foreach ($results as $doc) {
                    $text = $doc->getAttribute('text');
                    $matches = @preg_match('/' . str_replace('/', '\/', $pattern) . '/', $text);

                    // If pattern is invalid, skip validation
                    if ($matches === false) {
                        continue;
                    }

                    // If document doesn't match but was returned, it's suspicious
                    if ($matches === 0) {
                        $this->fail(
                            "Potential injection: Document '{$text}' was returned for pattern '{$pattern}' " .
                            "but doesn't match the regex pattern."
                        );
                    }
                }

            } catch (\Exception $e) {
                // Exceptions are acceptable - they indicate the injection was blocked or caused an error
                // This is actually good - it means the system rejected the malicious pattern
                $this->assertInstanceOf(\Exception::class, $e);
            }
        }

        // Test that legitimate regex patterns still work correctly
        $legitimatePatterns = [
            'target',      // Should match "target"
            '^target',     // Should match "target" (anchored)
            'other',       // Should match "other"
        ];

        foreach ($legitimatePatterns as $pattern) {
            try {
                $results = $database->find($collectionName, [
                    Query::regex('text', $pattern),
                ]);

                $this->assertIsArray($results);

                // Verify each result actually matches
                foreach ($results as $doc) {
                    $text = $doc->getAttribute('text');
                    $matches = @preg_match('/' . str_replace('/', '\/', $pattern) . '/', $text);
                    if ($matches !== false) {
                        $this->assertEquals(
                            1,
                            $matches,
                            "Document '{$text}' should match pattern '{$pattern}'"
                        );
                    }
                }
            } catch (\Exception $e) {
                $this->fail("Legitimate pattern '{$pattern}' should not throw exception: " . $e->getMessage());
            }
        }

        // Cleanup
        $database->deleteCollection($collectionName);
    }

    /**
     * Test ReDoS (Regular Expression Denial of Service) with timeout protection
     * This test verifies that ReDoS patterns either timeout properly or complete quickly,
     * preventing denial of service attacks.
     */
    //    public function testRegexRedos(): void
    //    {
    //        /** @var Database $database */
    //        $database = static::getDatabase();
    //
    //        // Skip test if regex is not supported
    //        if (!$database->getAdapter()->getSupportForRegex()) {
    //            $this->expectNotToPerformAssertions();
    //            return;
    //        }
    //
    //        $collectionName = 'redosTimeoutTest';
    //        $database->createCollection($collectionName, permissions: [
    //            Permission::create(Role::any()),
    //            Permission::read(Role::any()),
    //            Permission::update(Role::any()),
    //            Permission::delete(Role::any()),
    //        ]);
    //
    //        if ($database->getAdapter()->getSupportForAttributes()) {
    //            $this->assertEquals(true, $database->createAttribute($collectionName, 'text', Database::VAR_STRING, 1000, true));
    //        }
    //
    //        // Create documents with strings designed to trigger ReDoS
    //        // These strings have many 'a's but end with 'c' instead of 'b'
    //        // This causes catastrophic backtracking with patterns like (a+)+b
    //        $redosStrings = [];
    //        for ($i = 15; $i <= 35; $i += 5) {
    //            $redosStrings[] = str_repeat('a', $i) . 'c';
    //        }
    //
    //        // Also add some normal strings
    //        $normalStrings = [
    //            'normal text',
    //            'another string',
    //            'test123',
    //            'valid data',
    //        ];
    //
    //        $documents = [];
    //        foreach ($redosStrings as $text) {
    //            $documents[] = new Document([
    //                '$permissions' => [
    //                    Permission::read(Role::any()),
    //                    Permission::create(Role::any()),
    //                    Permission::update(Role::any()),
    //                    Permission::delete(Role::any()),
    //                ],
    //                'text' => $text,
    //            ]);
    //        }
    //
    //        foreach ($normalStrings as $text) {
    //            $documents[] = new Document([
    //                '$permissions' => [
    //                    Permission::read(Role::any()),
    //                    Permission::create(Role::any()),
    //                    Permission::update(Role::any()),
    //                    Permission::delete(Role::any()),
    //                ],
    //                'text' => $text,
    //            ]);
    //        }
    //
    //        $database->createDocuments($collectionName, $documents);
    //
    //        // ReDoS patterns that cause exponential backtracking
    //        $redosPatterns = [
    //            '(a+)+b',      // Classic ReDoS: nested quantifiers
    //            '(a|a)*b',     // Alternation with quantifier
    //            '(a+)+$',      // Anchored pattern
    //            '(a*)*b',      // Nested star quantifiers
    //            '(a+)+b+',     // Multiple nested quantifiers
    //            '(.+)+b',      // Generic nested quantifiers
    //            '(.*)+b',      // Generic nested quantifiers
    //        ];
    //
    //        $supportsTimeout = $database->getAdapter()->getSupportForTimeouts();
    //
    //        if ($supportsTimeout) {
    //            $database->setTimeout(2000);
    //        }
    //
    //        foreach ($redosPatterns as $pattern) {
    //            $startTime = microtime(true);
    //
    //            try {
    //                $results = $database->find($collectionName, [
    //                    Query::regex('text', $pattern),
    //                ]);
    //                $elapsed = microtime(true) - $startTime;
    //                // If timeout is supported, the query should either:
    //                // 1. Complete quickly (< 3 seconds) if ReDoS is mitigated
    //                // 2. Throw TimeoutException if it takes too long
    //                if ($supportsTimeout) {
    //                    // If we got here without timeout, it should have completed quickly
    //                    $this->assertLessThan(
    //                        3.0,
    //                        $elapsed,
    //                        "Regex pattern '{$pattern}' should complete quickly or timeout. Took {$elapsed}s"
    //                    );
    //                } else {
    //                    // Without timeout support, we just check it doesn't hang forever
    //                    // Set a reasonable upper bound (15 seconds) for systems without timeout
    //                    $this->assertLessThan(
    //                        15.0,
    //                        $elapsed,
    //                        "Regex pattern '{$pattern}' should not cause excessive delay. Took {$elapsed}s"
    //                    );
    //                }
    //
    //                // Verify results: none of our ReDoS strings should match these patterns
    //                // (they all end with 'c', not 'b')
    //                foreach ($results as $doc) {
    //                    $text = $doc->getAttribute('text');
    //                    // If it matched, verify it's actually a valid match
    //                    $matches = @preg_match('/' . str_replace('/', '\/', $pattern) . '/', $text);
    //                    if ($matches !== false) {
    //                        $this->assertEquals(
    //                            1,
    //                            $matches,
    //                            "Document with text '{$text}' should actually match pattern '{$pattern}'"
    //                        );
    //                    }
    //                }
    //
    //            } catch (TimeoutException $e) {
    //                // Timeout is expected for ReDoS patterns if not properly mitigated
    //                $elapsed = microtime(true) - $startTime;
    //                $this->assertInstanceOf(
    //                    TimeoutException::class,
    //                    $e,
    //                    "Regex pattern '{$pattern}' should timeout if it causes ReDoS. Elapsed: {$elapsed}s"
    //                );
    //
    //                // Timeout should happen within reasonable time (not immediately, but not too late)
    //                // Fast timeouts are actually good - they mean the system is protecting itself quickly
    //                $this->assertGreaterThan(
    //                    0.05,
    //                    $elapsed,
    //                    "Timeout should occur after some minimal processing time"
    //                );
    //
    //                // Timeout should happen before the timeout limit (with some buffer)
    //                if ($supportsTimeout) {
    //                    $this->assertLessThan(
    //                        5.0,
    //                        $elapsed,
    //                        "Timeout should occur within reasonable time (before 5 seconds)"
    //                    );
    //                }
    //
    //            } catch (\Exception $e) {
    //                // Check if this is a query interruption/timeout from MySQL (error 1317)
    //                // MySQL sometimes throws "Query execution was interrupted" instead of TimeoutException
    //                $message = $e->getMessage();
    //                $isQueryInterrupted = false;
    //
    //                // Check message for interruption keywords
    //                if (strpos($message, 'Query execution was interrupted') !== false ||
    //                    strpos($message, 'interrupted') !== false) {
    //                    $isQueryInterrupted = true;
    //                }
    //
    //                // Check if it's a PDOException with error code 1317
    //                if ($e instanceof PDOException) {
    //                    $errorInfo = $e->errorInfo ?? [];
    //                    // Error 1317 is "Query execution was interrupted"
    //                    if (isset($errorInfo[1]) && $errorInfo[1] === 1317) {
    //                        $isQueryInterrupted = true;
    //                    }
    //                    // Also check SQLSTATE 70100
    //                    if ($e->getCode() === '70100') {
    //                        $isQueryInterrupted = true;
    //                    }
    //                }
    //
    //                if ($isQueryInterrupted) {
    //                    // This is effectively a timeout - MySQL interrupted the query
    //                    $elapsed = microtime(true) - $startTime;
    //                    $this->assertGreaterThan(
    //                        0.05,
    //                        $elapsed,
    //                        "Query interruption should occur after some minimal processing time"
    //                    );
    //                    // This is acceptable - the query was interrupted due to timeout
    //                    continue;
    //                }
    //
    //                // Other exceptions are unexpected
    //                $this->fail("Unexpected exception for pattern '{$pattern}': " . get_class($e) . " - " . $e->getMessage());
    //            }
    //        }
    //
    //        // Test with a pattern that should match quickly (not ReDoS)
    //        $safePattern = 'normal';
    //        $startTime = microtime(true);
    //        $results = $database->find($collectionName, [
    //            Query::regex('text', $safePattern),
    //        ]);
    //        $elapsed = microtime(true) - $startTime;
    //
    //        // Safe patterns should complete very quickly
    //        $this->assertLessThan(1.0, $elapsed, 'Safe regex pattern should complete quickly');
    //        $this->assertGreaterThan(0, count($results), 'Safe pattern should match some documents');
    //
    //        // Verify safe pattern results are correct
    //        foreach ($results as $doc) {
    //            $text = $doc->getAttribute('text');
    //            $this->assertStringContainsString('normal', $text, "Document '{$text}' should contain 'normal'");
    //        }
    //
    //        // Cleanup
    //        if ($supportsTimeout) {
    //            $database->clearTimeout();
    //        }
    //        $database->deleteCollection($collectionName);
    //    }

    public function testCreateDocumentsIgnoreDuplicates(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection(__FUNCTION__);
        $database->createAttribute(__FUNCTION__, 'name', Database::VAR_STRING, 128, true);

        // Insert initial documents
        $database->createDocuments(__FUNCTION__, [
            new Document([
                '$id' => 'doc1',
                'name' => 'Original A',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                ],
            ]),
            new Document([
                '$id' => 'doc2',
                'name' => 'Original B',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                ],
            ]),
        ]);

        // Without ignore, duplicates should throw
        try {
            $database->createDocuments(__FUNCTION__, [
                new Document([
                    '$id' => 'doc1',
                    'name' => 'Duplicate A',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::create(Role::any()),
                    ],
                ]),
            ]);
            $this->fail('Expected DuplicateException');
        } catch (DuplicateException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // With skipDuplicates, duplicates should be silently skipped
        $emittedIds = [];
        $collection = __FUNCTION__;
        $count = $database->skipDuplicates(function () use ($database, $collection, &$emittedIds) {
            return $database->createDocuments($collection, [
                new Document([
                    '$id' => 'doc1',
                    'name' => 'Duplicate A',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::create(Role::any()),
                    ],
                ]),
                new Document([
                    '$id' => 'doc3',
                    'name' => 'New C',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::create(Role::any()),
                    ],
                ]),
            ], onNext: function (Document $doc) use (&$emittedIds) {
                $emittedIds[] = $doc->getId();
            });
        });

        $this->assertSame(2, $count);
        $this->assertCount(2, $emittedIds);
        \sort($emittedIds);
        $this->assertSame(['doc1', 'doc3'], $emittedIds);

        $doc1 = $database->getDocument(__FUNCTION__, 'doc1');
        $this->assertSame('Original A', $doc1->getAttribute('name'));

        $doc3 = $database->getDocument(__FUNCTION__, 'doc3');
        $this->assertSame('New C', $doc3->getAttribute('name'));

        // Total should be 3 (doc1, doc2, doc3)
        $all = $database->find(__FUNCTION__);
        $this->assertCount(3, $all);
    }

    public function testCreateDocumentsIgnoreAllDuplicates(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection(__FUNCTION__);
        $database->createAttribute(__FUNCTION__, 'name', Database::VAR_STRING, 128, true);

        // Insert initial document
        $database->createDocuments(__FUNCTION__, [
            new Document([
                '$id' => 'existing',
                'name' => 'Original',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                ],
            ]),
        ]);

        // With skipDuplicates, inserting only duplicates should succeed with no new rows
        $emittedIds = [];
        $collection = __FUNCTION__;
        $count = $database->skipDuplicates(function () use ($database, $collection, &$emittedIds) {
            return $database->createDocuments($collection, [
                new Document([
                    '$id' => 'existing',
                    'name' => 'Duplicate',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::create(Role::any()),
                    ],
                ]),
            ], onNext: function (Document $doc) use (&$emittedIds) {
                $emittedIds[] = $doc->getId();
            });
        });

        $this->assertSame(1, $count);
        $this->assertSame(['existing'], $emittedIds);

        $doc = $database->getDocument(__FUNCTION__, 'existing');
        $this->assertSame('Original', $doc->getAttribute('name'));

        // Still only 1 document
        $all = $database->find(__FUNCTION__);
        $this->assertCount(1, $all);
    }

    public function testCreateDocumentsSkipDuplicatesEmptyBatch(): void
    {
        $database = $this->getDatabase();

        $collection = 'skipDupEmpty';
        $database->createCollection($collection);
        $database->createAttribute($collection, 'name', Database::VAR_STRING, 128, true);

        $count = $database->skipDuplicates(fn () => $database->createDocuments($collection, []));

        $this->assertSame(0, $count);
        $this->assertCount(0, $database->find($collection));
    }

    public function testCreateDocumentsSkipDuplicatesNestedScope(): void
    {
        $database = $this->getDatabase();

        $collection = 'skipDupNested';
        $database->createCollection($collection);
        $database->createAttribute($collection, 'name', Database::VAR_STRING, 128, true);

        $makeDoc = fn (string $id, string $name) => new Document([
            '$id' => $id,
            'name' => $name,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);

        // Seed an existing doc
        $database->createDocuments($collection, [$makeDoc('seed', 'Seed')]);

        // Nested scope — inner scope runs inside outer scope.
        // After inner exits, outer state should still be "skip enabled".
        // After outer exits, state should restore to "skip disabled".
        $countOuter = $database->skipDuplicates(function () use ($database, $collection, $makeDoc) {
            // Inner scope: add dup + new
            $countInner = $database->skipDuplicates(function () use ($database, $collection, $makeDoc) {
                return $database->createDocuments($collection, [
                    $makeDoc('seed', 'Dup'),
                    $makeDoc('innerNew', 'InnerNew'),
                ]);
            });
            $this->assertSame(2, $countInner);

            // Still inside outer scope — skip flag should still be on
            return $database->createDocuments($collection, [
                $makeDoc('seed', 'Dup2'),
                $makeDoc('outerNew', 'OuterNew'),
            ]);
        });
        $this->assertSame(2, $countOuter);

        // After both scopes exit, skip flag is off again — a plain createDocuments
        // call with a duplicate should throw.
        $thrown = null;
        try {
            $database->createDocuments($collection, [$makeDoc('seed', 'ShouldThrow')]);
        } catch (DuplicateException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'Plain createDocuments after nested scopes should throw on duplicate');

        // Final state: seed + innerNew + outerNew
        $all = $database->find($collection);
        $ids = \array_map(fn (Document $d) => $d->getId(), $all);
        \sort($ids);
        $this->assertSame(['innerNew', 'outerNew', 'seed'], $ids);
    }

    public function testCreateDocumentsSkipDuplicatesLargeBatch(): void
    {
        $database = $this->getDatabase();

        $collection = 'skipDupLarge';
        $database->createCollection($collection);
        $database->createAttribute($collection, 'idx', Database::VAR_INTEGER, 0, true);

        // Seed 50 docs
        $seed = [];
        for ($i = 0; $i < 50; $i++) {
            $seed[] = new Document([
                '$id' => 'doc_' . $i,
                'idx' => $i,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                ],
            ]);
        }
        $database->createDocuments($collection, $seed);

        // Now call skipDuplicates with 300 docs: 50 existing (0-49) + 250 new (50-299).
        // 300 > default INSERT_BATCH_SIZE, so this exercises the chunk loop.
        $batch = [];
        for ($i = 0; $i < 300; $i++) {
            $batch[] = new Document([
                '$id' => 'doc_' . $i,
                'idx' => $i + 1000, // different value so we can detect if existing got overwritten
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                ],
            ]);
        }

        $emittedIds = [];
        $count = $database->skipDuplicates(function () use ($database, $collection, $batch, &$emittedIds) {
            return $database->createDocuments($collection, $batch, onNext: function (Document $doc) use (&$emittedIds) {
                $emittedIds[] = $doc->getId();
            });
        });

        $this->assertSame(300, $count);
        $this->assertCount(300, $emittedIds);

        $seedDoc = $database->getDocument($collection, 'doc_25');
        $this->assertSame(25, $seedDoc->getAttribute('idx'));

        $newDoc = $database->getDocument($collection, 'doc_100');
        $this->assertSame(1100, $newDoc->getAttribute('idx'));

        $total = $database->count($collection);
        $this->assertSame(300, $total);
    }

    public function testCreateDocumentsSkipDuplicatesSecondCallSkipsAll(): void
    {
        $database = $this->getDatabase();

        $collection = 'skipDupSecond';
        $database->createCollection($collection);
        $database->createAttribute($collection, 'name', Database::VAR_STRING, 128, true);

        $makeBatch = fn (string $name) => \array_map(
            fn (string $id) => new Document([
                '$id' => $id,
                'name' => $name,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                ],
            ]),
            ['a', 'b', 'c']
        );

        // First call — all new
        $firstCount = $database->skipDuplicates(
            fn () => $database->createDocuments($collection, $makeBatch('First'))
        );
        $this->assertSame(3, $firstCount);

        $emittedIds = [];
        $secondCount = $database->skipDuplicates(function () use ($database, $collection, $makeBatch, &$emittedIds) {
            return $database->createDocuments($collection, $makeBatch('Second'), onNext: function (Document $doc) use (&$emittedIds) {
                $emittedIds[] = $doc->getId();
            });
        });
        $this->assertSame(3, $secondCount);
        \sort($emittedIds);
        $this->assertSame(['a', 'b', 'c'], $emittedIds);

        // All three should retain the First values
        foreach (['a', 'b', 'c'] as $id) {
            $doc = $database->getDocument($collection, $id);
            $this->assertSame('First', $doc->getAttribute('name'), "Doc {$id} should not have been overwritten");
        }
    }

    public function testCreateDocumentsSkipDuplicatesRelationships(): void
    {
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $parent = 'skipDupParent';
        $child = 'skipDupChild';
        $permissions = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        $database->createCollection($parent);
        $database->createCollection($child);
        $database->createAttribute($parent, 'name', Database::VAR_STRING, 128, true);
        $database->createAttribute($child, 'name', Database::VAR_STRING, 128, true);
        $database->createRelationship(
            collection: $parent,
            relatedCollection: $child,
            type: Database::RELATION_ONE_TO_MANY,
            id: 'children',
        );

        $database->createDocument($parent, new Document([
            '$id' => 'existingParent',
            'name' => 'ExistingParent',
            '$permissions' => $permissions,
            'children' => [
                new Document([
                    '$id' => 'existingChild',
                    'name' => 'ExistingChild',
                    '$permissions' => $permissions,
                ]),
            ],
        ]));

        $batch = [
            new Document([
                '$id' => 'existingParent',
                'name' => 'ShouldNotOverwrite',
                '$permissions' => $permissions,
                'children' => [
                    new Document([
                        '$id' => 'existingChild',
                        'name' => 'ExistingChild',
                        '$permissions' => $permissions,
                    ]),
                    new Document([
                        '$id' => 'retryChild',
                        'name' => 'RetryChild',
                        '$permissions' => $permissions,
                    ]),
                ],
            ]),
            new Document([
                '$id' => 'newParent',
                'name' => 'NewParent',
                '$permissions' => $permissions,
                'children' => [
                    new Document([
                        '$id' => 'newChild',
                        'name' => 'NewChild',
                        '$permissions' => $permissions,
                    ]),
                ],
            ]),
        ];

        $database->skipDuplicates(fn () => $database->createDocuments($parent, $batch));

        $existing = $database->getDocument($parent, 'existingParent');
        $this->assertFalse($existing->isEmpty());
        $this->assertSame('ExistingParent', $existing->getAttribute('name'));

        $existingChildren = $existing->getAttribute('children', []);
        $childIds = \array_map(fn (Document $d) => $d->getId(), $existingChildren);
        \sort($childIds);
        $this->assertSame(['existingChild', 'retryChild'], $childIds);

        $new = $database->getDocument($parent, 'newParent');
        $this->assertFalse($new->isEmpty());
        $this->assertSame('NewParent', $new->getAttribute('name'));
        $newChildren = $new->getAttribute('children', []);
        $this->assertCount(1, $newChildren);
        $this->assertSame('newChild', $newChildren[0]->getId());

        $allChildren = $database->find($child);
        $allChildIds = \array_map(fn (Document $d) => $d->getId(), $allChildren);
        \sort($allChildIds);
        $this->assertSame(['existingChild', 'newChild', 'retryChild'], $allChildIds);
    }


    public function testDropUnknownAttributes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $permissions = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        $database->createCollection(__FUNCTION__);
        $this->assertEquals(true, $database->createAttribute(__FUNCTION__, 'known', Database::VAR_STRING, 128, false));

        try {
            $database->createDocument(__FUNCTION__, new Document([
                '$id' => 'strict',
                '$permissions' => $permissions,
                'known' => 'kept',
                'unknown' => 'dropped',
            ]));
            $this->fail('Unknown attribute was accepted while dropping is disabled');
        } catch (StructureException $e) {
            $this->assertEquals('Invalid document structure: Unknown attribute: "unknown"', $e->getMessage());
        }

        $database->setDropUnknownAttributes(true);

        try {
            $collection = $database->getCollection(__FUNCTION__);
            $encoded = $database->encode($collection, new Document([
                '$id' => 'encoded',
                '$collection' => __FUNCTION__,
                'known' => 'kept',
                'unknown' => 'dropped',
            ]));
            $this->assertEquals('kept', $encoded->getAttribute('known'));
            $this->assertNull($encoded->getAttribute('unknown'), 'Unknown attribute survived encode');

            $created = $database->createDocument(__FUNCTION__, new Document([
                '$id' => 'lenient',
                '$permissions' => $permissions,
                'known' => 'kept',
                'unknown' => 'dropped',
            ]));

            $this->assertEquals('kept', $created->getAttribute('known'));
            $this->assertNull($created->getAttribute('unknown'), 'Unknown attribute survived the create');

            $database->purgeCachedDocument(__FUNCTION__, 'lenient');
            $stored = $database->getDocument(__FUNCTION__, 'lenient');
            $this->assertEquals('kept', $stored->getAttribute('known'));
            $this->assertNull($stored->getAttribute('unknown'), 'Unknown attribute reached storage on create');

            $updated = $database->updateDocument(__FUNCTION__, 'lenient', new Document([
                '$id' => 'lenient',
                '$permissions' => $permissions,
                'known' => 'changed',
                'unknown' => 'dropped',
            ]));

            $this->assertEquals('changed', $updated->getAttribute('known'));
            $this->assertNull($updated->getAttribute('unknown'), 'Unknown attribute survived the update');

            $database->purgeCachedDocument(__FUNCTION__, 'lenient');
            $stored = $database->getDocument(__FUNCTION__, 'lenient');
            $this->assertEquals('changed', $stored->getAttribute('known'));
            $this->assertNull($stored->getAttribute('unknown'), 'Unknown attribute reached storage on update');

            \usleep(5000);

            $unchanged = $database->updateDocument(__FUNCTION__, 'lenient', new Document([
                '$id' => 'lenient',
                '$permissions' => $permissions,
                'known' => 'changed',
                'unknown' => 'dropped',
            ]));

            $this->assertEquals(
                $stored->getUpdatedAt(),
                $unchanged->getUpdatedAt(),
                'A write carrying only a dropped attribute counted as a change'
            );
        } finally {
            $database->setDropUnknownAttributes(false);
        }
    }
}
