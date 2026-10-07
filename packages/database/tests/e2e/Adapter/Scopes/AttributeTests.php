<?php

namespace Tests\E2E\Adapter\Scopes;

use Exception;
use Throwable;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Dependency as DependencyException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Truncate as TruncateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Structure;
use Utopia\Validator\Range;

trait AttributeTests
{
    private function createRandomString(int $length = 10): string
    {
        return \substr(\bin2hex(\random_bytes(\max(1, \intval(($length + 1) / 2)))), 0, $length);
    }

    /**
     * Using phpunit dataProviders to check that all these combinations of types/defaults throw exceptions
     * https://phpunit.de/manual/3.7/en/writing-tests-for-phpunit.html#writing-tests-for-phpunit.data-providers
     *
     * @return array<array<bool|float|int|string>>
     */
    public function invalidDefaultValues(): array
    {
        return [
            [Database::VAR_STRING, 1],
            [Database::VAR_STRING, 1.5],
            [Database::VAR_STRING, false],
            [Database::VAR_INTEGER, "one"],
            [Database::VAR_INTEGER, 1.5],
            [Database::VAR_INTEGER, true],
            [Database::VAR_FLOAT, 1],
            [Database::VAR_FLOAT, "one"],
            [Database::VAR_FLOAT, false],
            [Database::VAR_BOOLEAN, 0],
            [Database::VAR_BOOLEAN, "false"],
            [Database::VAR_BOOLEAN, 0.5],
            [Database::VAR_VARCHAR, 1],
            [Database::VAR_VARCHAR, 1.5],
            [Database::VAR_VARCHAR, false],
            [Database::VAR_TEXT, 1],
            [Database::VAR_TEXT, 1.5],
            [Database::VAR_TEXT, true],
            [Database::VAR_MEDIUMTEXT, 1],
            [Database::VAR_MEDIUMTEXT, 1.5],
            [Database::VAR_MEDIUMTEXT, false],
            [Database::VAR_LONGTEXT, 1],
            [Database::VAR_LONGTEXT, 1.5],
            [Database::VAR_LONGTEXT, true],
        ];
    }

    public function testCreateDeleteAttribute(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('attributes');

        $this->assertEquals(true, $database->createAttribute('attributes', 'string1', Database::VAR_STRING, 128, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'string2', Database::VAR_STRING, 16382 + 1, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'string3', Database::VAR_STRING, 65535 + 1, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'string4', Database::VAR_STRING, 16777215 + 1, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'integer', Database::VAR_INTEGER, 0, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'bigint', Database::VAR_INTEGER, 8, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'float', Database::VAR_FLOAT, 0, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'boolean', Database::VAR_BOOLEAN, 0, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'id', Database::VAR_ID, 0, true));

        // New string types
        $this->assertEquals(true, $database->createAttribute('attributes', 'varchar1', Database::VAR_VARCHAR, 255, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'varchar2', Database::VAR_VARCHAR, 128, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'text1', Database::VAR_TEXT, 65535, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'mediumtext1', Database::VAR_MEDIUMTEXT, 16777215, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'longtext1', Database::VAR_LONGTEXT, 4294967295, true));

        $this->assertEquals(true, $database->createIndex('attributes', 'id_index', Database::INDEX_KEY, ['id']));
        $this->assertEquals(true, $database->createIndex('attributes', 'string1_index', Database::INDEX_KEY, ['string1']));
        $this->assertEquals(true, $database->createIndex('attributes', 'string2_index', Database::INDEX_KEY, ['string2'], [255]));
        $this->assertEquals(true, $database->createIndex('attributes', 'multi_index', Database::INDEX_KEY, ['string1', 'string2', 'string3'], [128, 128, 128]));
        $this->assertEquals(true, $database->createIndex('attributes', 'varchar1_index', Database::INDEX_KEY, ['varchar1']));
        $this->assertEquals(true, $database->createIndex('attributes', 'varchar2_index', Database::INDEX_KEY, ['varchar2']));
        $this->assertEquals(true, $database->createIndex('attributes', 'text1_index', Database::INDEX_KEY, ['text1'], [255]));

        $collection = $database->getCollection('attributes');
        $this->assertCount(14, $collection->getAttribute('attributes'));
        $this->assertCount(7, $collection->getAttribute('indexes'));

        // Array
        $this->assertEquals(true, $database->createAttribute('attributes', 'string_list', Database::VAR_STRING, 128, true, null, true, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'integer_list', Database::VAR_INTEGER, 0, true, null, true, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'float_list', Database::VAR_FLOAT, 0, true, null, true, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'boolean_list', Database::VAR_BOOLEAN, 0, true, null, true, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'varchar_list', Database::VAR_VARCHAR, 128, true, null, true, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'text_list', Database::VAR_TEXT, 65535, true, null, true, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'mediumtext_list', Database::VAR_MEDIUMTEXT, 16777215, true, null, true, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'longtext_list', Database::VAR_LONGTEXT, 4294967295, true, null, true, true));

        $collection = $database->getCollection('attributes');
        $this->assertCount(22, $collection->getAttribute('attributes'));

        // Default values
        $this->assertEquals(true, $database->createAttribute('attributes', 'string_default', Database::VAR_STRING, 256, false, 'test'));
        $this->assertEquals(true, $database->createAttribute('attributes', 'integer_default', Database::VAR_INTEGER, 0, false, 1));
        $this->assertEquals(true, $database->createAttribute('attributes', 'float_default', Database::VAR_FLOAT, 0, false, 1.5));
        $this->assertEquals(true, $database->createAttribute('attributes', 'boolean_default', Database::VAR_BOOLEAN, 0, false, false));
        $this->assertEquals(true, $database->createAttribute('attributes', 'datetime_default', Database::VAR_DATETIME, 0, false, '2000-06-12T14:12:55.000+00:00', true, false, null, [], ['datetime']));
        $this->assertEquals(true, $database->createAttribute('attributes', 'varchar_default', Database::VAR_VARCHAR, 255, false, 'varchar default'));
        $this->assertEquals(true, $database->createAttribute('attributes', 'text_default', Database::VAR_TEXT, 65535, false, 'text default'));
        $this->assertEquals(true, $database->createAttribute('attributes', 'mediumtext_default', Database::VAR_MEDIUMTEXT, 16777215, false, 'mediumtext default'));
        $this->assertEquals(true, $database->createAttribute('attributes', 'longtext_default', Database::VAR_LONGTEXT, 4294967295, false, 'longtext default'));

        $collection = $database->getCollection('attributes');
        $this->assertCount(31, $collection->getAttribute('attributes'));

        // Delete
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'string1'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'string2'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'string3'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'string4'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'integer'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'bigint'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'float'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'boolean'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'id'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'varchar1'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'varchar2'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'text1'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'mediumtext1'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'longtext1'));

        $collection = $database->getCollection('attributes');
        $this->assertCount(17, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        // Delete Array
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'string_list'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'integer_list'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'float_list'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'boolean_list'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'varchar_list'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'text_list'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'mediumtext_list'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'longtext_list'));

        $collection = $database->getCollection('attributes');
        $this->assertCount(9, $collection->getAttribute('attributes'));

        // Delete default
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'string_default'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'integer_default'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'float_default'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'boolean_default'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'datetime_default'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'varchar_default'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'text_default'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'mediumtext_default'));
        $this->assertEquals(true, $database->deleteAttribute('attributes', 'longtext_default'));

        $collection = $database->getCollection('attributes');
        $this->assertCount(0, $collection->getAttribute('attributes'));

        // Test for custom chars in ID
        $this->assertEquals(true, $database->createAttribute('attributes', 'as_5dasdasdas', Database::VAR_BOOLEAN, 0, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'as5dasdasdas_', Database::VAR_BOOLEAN, 0, true));
        $this->assertEquals(true, $database->createAttribute('attributes', '.as5dasdasdas', Database::VAR_BOOLEAN, 0, true));
        $this->assertEquals(true, $database->createAttribute('attributes', '-as5dasdasdas', Database::VAR_BOOLEAN, 0, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'as-5dasdasdas', Database::VAR_BOOLEAN, 0, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'as5dasdasdas-', Database::VAR_BOOLEAN, 0, true));
        $this->assertEquals(true, $database->createAttribute('attributes', 'socialAccountForYoutubeSubscribersss', Database::VAR_BOOLEAN, 0, true));
        $this->assertEquals(true, $database->createAttribute('attributes', '5f058a89258075f058a89258075f058t9214', Database::VAR_BOOLEAN, 0, true));

        // Test non-shared tables duplicates throw duplicate
        $database->createAttribute('attributes', 'duplicate', Database::VAR_STRING, 128, true);
        try {
            $database->createAttribute('attributes', 'duplicate', Database::VAR_STRING, 128, true);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }

        // Test delete attribute when column does not exist
        $this->assertEquals(true, $database->createAttribute('attributes', 'string1', Database::VAR_STRING, 128, true));
        sleep(1);

        $this->assertEquals(true, $this->deleteColumn('attributes', 'string1'));

        $collection = $database->getCollection('attributes');
        $attributes = $collection->getAttribute('attributes');
        $attribute = end($attributes);
        $this->assertEquals('string1', $attribute->getId());

        $this->assertEquals(true, $database->deleteAttribute('attributes', 'string1'));

        $collection = $database->getCollection('attributes');
        $attributes = $collection->getAttribute('attributes');
        $attribute = end($attributes);
        $this->assertNotEquals('string1', $attribute->getId());

        $collection = $database->getCollection('attributes');
    }
    /**
     * @depends      testCreateDeleteAttribute
     * @dataProvider invalidDefaultValues
     */
    public function testInvalidDefaultValues(string $type, mixed $default): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->expectException(\Exception::class);
        $this->assertEquals(false, $database->createAttribute('attributes', 'bad_default', $type, 256, true, $default));
    }
    /**
    * @depends testInvalidDefaultValues
    */
    public function testAttributeCaseInsensitivity(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->assertEquals(true, $database->createAttribute('attributes', 'caseSensitive', Database::VAR_STRING, 128, true));
        $this->expectException(DuplicateException::class);
        $this->assertEquals(true, $database->createAttribute('attributes', 'CaseSensitive', Database::VAR_STRING, 128, true));
    }

    public function testAttributeKeyWithSymbols(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('attributesWithKeys');

        $this->assertEquals(true, $database->createAttribute('attributesWithKeys', 'key_with.sym$bols', Database::VAR_STRING, 128, true));

        $document = $database->createDocument('attributesWithKeys', new Document([
            'key_with.sym$bols' => 'value',
            '$permissions' => [
                Permission::read(Role::any()),
            ]
        ]));

        $this->assertEquals('value', $document->getAttribute('key_with.sym$bols'));

        $document = $database->getDocument('attributesWithKeys', $document->getId());

        $this->assertEquals('value', $document->getAttribute('key_with.sym$bols'));
    }

    public function testAttributeNamesWithDots(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('dots.parent');

        $this->assertTrue($database->createAttribute(
            collection: 'dots.parent',
            id: 'dots.name',
            type: Database::VAR_STRING,
            size: 255,
            required: false
        ));

        $document = $database->find('dots.parent', [
            Query::select(['dots.name']),
        ]);
        $this->assertEmpty($document);

        $database->createCollection('dots');

        $this->assertTrue($database->createAttribute(
            collection: 'dots',
            id: 'name',
            type: Database::VAR_STRING,
            size: 255,
            required: false
        ));

        $database->createRelationship(
            collection: 'dots.parent',
            relatedCollection: 'dots',
            type: Database::RELATION_ONE_TO_ONE
        );

        $database->createDocument('dots.parent', new Document([
            '$id' => ID::custom('father'),
            'dots.name' => 'Bill clinton',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'dots' => [
                '$id' => ID::custom('child'),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]
        ]));

        $documents = $database->find('dots.parent', [
            Query::select(['*']),
        ]);

        $this->assertEquals('Bill clinton', $documents[0]['dots.name']);
    }


    public function testUpdateAttributeDefault(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $flowers = $database->createCollection('flowers');
        $database->createAttribute('flowers', 'name', Database::VAR_STRING, 128, true);
        $database->createAttribute('flowers', 'inStock', Database::VAR_INTEGER, 0, false);
        $database->createAttribute('flowers', 'date', Database::VAR_STRING, 128, false);

        $database->createDocument('flowers', new Document([
            '$id' => 'flowerWithDate',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Violet',
            'inStock' => 51,
            'date' => '2000-06-12 14:12:55.000'
        ]));

        $doc = $database->createDocument('flowers', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Lily'
        ]));

        $this->assertNull($doc->getAttribute('inStock'));

        $database->updateAttributeDefault('flowers', 'inStock', 100);

        $doc = $database->createDocument('flowers', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Iris'
        ]));

        $this->assertIsNumeric($doc->getAttribute('inStock'));
        $this->assertEquals(100, $doc->getAttribute('inStock'));

        $database->updateAttributeDefault('flowers', 'inStock', null);
    }


    public function testRenameAttribute(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $colors = $database->createCollection('colors');
        $database->createAttribute('colors', 'name', Database::VAR_STRING, 128, true);
        $database->createAttribute('colors', 'hex', Database::VAR_STRING, 128, true);

        $database->createIndex('colors', 'index1', Database::INDEX_KEY, ['name'], [128], [Database::ORDER_ASC]);

        $database->createDocument('colors', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'black',
            'hex' => '#000000'
        ]));

        $attribute = $database->renameAttribute('colors', 'name', 'verbose');

        $this->assertTrue($attribute);

        $colors = $database->getCollection('colors');
        $this->assertEquals('hex', $colors->getAttribute('attributes')[1]['$id']);
        $this->assertEquals('verbose', $colors->getAttribute('attributes')[0]['$id']);
        $this->assertCount(2, $colors->getAttribute('attributes'));

        // Attribute in index is renamed automatically on adapter-level. What we need to check is if metadata is properly updated
        $this->assertEquals('verbose', $colors->getAttribute('indexes')[0]->getAttribute("attributes")[0]);
        $this->assertCount(1, $colors->getAttribute('indexes'));

        // Document should be there if adapter migrated properly
        $document = $database->findOne('colors');
        $this->assertFalse($document->isEmpty());
        $this->assertEquals('black', $document->getAttribute('verbose'));
        $this->assertEquals('#000000', $document->getAttribute('hex'));
        $this->assertEquals(null, $document->getAttribute('name'));
    }


    /**
     * @depends testUpdateAttributeDefault
     */
    public function testUpdateAttributeRequired(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->updateAttributeRequired('flowers', 'inStock', true);

        $this->expectExceptionMessage('Invalid document structure: Missing required attribute "inStock"');

        $doc = $database->createDocument('flowers', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Lily With Missing Stocks'
        ]));
    }

    /**
     * @depends testUpdateAttributeDefault
     */
    public function testUpdateAttributeFilter(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createAttribute('flowers', 'cartModel', Database::VAR_STRING, 2000, false);

        $doc = $database->createDocument('flowers', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Lily With CartData',
            'inStock' => 50,
            'cartModel' => '{"color":"string","size":"number"}'
        ]));

        $this->assertIsString($doc->getAttribute('cartModel'));
        $this->assertEquals('{"color":"string","size":"number"}', $doc->getAttribute('cartModel'));

        $database->updateAttributeFilters('flowers', 'cartModel', ['json']);

        $doc = $database->getDocument('flowers', $doc->getId());
        $this->assertIsArray($doc->getAttribute('cartModel'));
        $this->assertCount(2, $doc->getAttribute('cartModel'));
        $this->assertEquals('string', $doc->getAttribute('cartModel')['color']);
        $this->assertEquals('number', $doc->getAttribute('cartModel')['size']);
    }

    /**
     * @depends testUpdateAttributeDefault
     */
    public function testUpdateAttributeFormat(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createAttribute('flowers', 'price', Database::VAR_INTEGER, 0, false);

        $doc = $database->createDocument('flowers', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            '$id' => ID::custom('LiliPriced'),
            'name' => 'Lily Priced',
            'inStock' => 50,
            'cartModel' => '{}',
            'price' => 500
        ]));

        $this->assertIsNumeric($doc->getAttribute('price'));
        $this->assertEquals(500, $doc->getAttribute('price'));

        Structure::addFormat('priceRange', function ($attribute) {
            $min = $attribute['formatOptions']['min'];
            $max = $attribute['formatOptions']['max'];

            return new Range($min, $max);
        }, Database::VAR_INTEGER);

        $database->updateAttributeFormat('flowers', 'price', 'priceRange');
        $database->updateAttributeFormatOptions('flowers', 'price', ['min' => 1, 'max' => 10000]);

        $this->expectExceptionMessage('Invalid document structure: Attribute "price" has invalid format. Value must be a valid range between 1 and 10,000');

        $doc = $database->createDocument('flowers', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Lily Overpriced',
            'inStock' => 50,
            'cartModel' => '{}',
            'price' => 15000
        ]));
    }

    /**
     * @depends testUpdateAttributeDefault
     * @depends testUpdateAttributeFormat
     */
    public function testUpdateAttributeStructure(): void
    {
        // TODO: When this becomes relevant, add many more tests (from all types to all types, chaging size up&down, switchign between array/non-array...

        Structure::addFormat('priceRangeNew', function ($attribute) {
            $min = $attribute['formatOptions']['min'];
            $max = $attribute['formatOptions']['max'];
            return new Range($min, $max);
        }, Database::VAR_INTEGER);

        /** @var Database $database */
        $database = $this->getDatabase();

        // price attribute
        $collection = $database->getCollection('flowers');
        $attribute = $collection->getAttribute('attributes')[4];
        $this->assertEquals(true, $attribute['signed']);
        $this->assertEquals(0, $attribute['size']);
        $this->assertEquals(null, $attribute['default']);
        $this->assertEquals(false, $attribute['array']);
        $this->assertEquals(false, $attribute['required']);
        $this->assertEquals('priceRange', $attribute['format']);
        $this->assertEquals(['min' => 1, 'max' => 10000], $attribute['formatOptions']);

        $database->updateAttribute('flowers', 'price', default: 100);
        $collection = $database->getCollection('flowers');
        $attribute = $collection->getAttribute('attributes')[4];
        $this->assertEquals('integer', $attribute['type']);
        $this->assertEquals(true, $attribute['signed']);
        $this->assertEquals(0, $attribute['size']);
        $this->assertEquals(100, $attribute['default']);
        $this->assertEquals(false, $attribute['array']);
        $this->assertEquals(false, $attribute['required']);
        $this->assertEquals('priceRange', $attribute['format']);
        $this->assertEquals(['min' => 1, 'max' => 10000], $attribute['formatOptions']);

        $database->updateAttribute('flowers', 'price', format: 'priceRangeNew');
        $collection = $database->getCollection('flowers');
        $attribute = $collection->getAttribute('attributes')[4];
        $this->assertEquals('integer', $attribute['type']);
        $this->assertEquals(true, $attribute['signed']);
        $this->assertEquals(0, $attribute['size']);
        $this->assertEquals(100, $attribute['default']);
        $this->assertEquals(false, $attribute['array']);
        $this->assertEquals(false, $attribute['required']);
        $this->assertEquals('priceRangeNew', $attribute['format']);
        $this->assertEquals(['min' => 1, 'max' => 10000], $attribute['formatOptions']);

        $database->updateAttribute('flowers', 'price', format: '');
        $collection = $database->getCollection('flowers');
        $attribute = $collection->getAttribute('attributes')[4];
        $this->assertEquals('integer', $attribute['type']);
        $this->assertEquals(true, $attribute['signed']);
        $this->assertEquals(0, $attribute['size']);
        $this->assertEquals(100, $attribute['default']);
        $this->assertEquals(false, $attribute['array']);
        $this->assertEquals(false, $attribute['required']);
        $this->assertEquals('', $attribute['format']);
        $this->assertEquals(['min' => 1, 'max' => 10000], $attribute['formatOptions']);

        $database->updateAttribute('flowers', 'price', formatOptions: ['min' => 1, 'max' => 999]);
        $collection = $database->getCollection('flowers');
        $attribute = $collection->getAttribute('attributes')[4];
        $this->assertEquals('integer', $attribute['type']);
        $this->assertEquals(true, $attribute['signed']);
        $this->assertEquals(0, $attribute['size']);
        $this->assertEquals(100, $attribute['default']);
        $this->assertEquals(false, $attribute['array']);
        $this->assertEquals(false, $attribute['required']);
        $this->assertEquals('', $attribute['format']);
        $this->assertEquals(['min' => 1, 'max' => 999], $attribute['formatOptions']);

        $database->updateAttribute('flowers', 'price', formatOptions: []);
        $collection = $database->getCollection('flowers');
        $attribute = $collection->getAttribute('attributes')[4];
        $this->assertEquals('integer', $attribute['type']);
        $this->assertEquals(true, $attribute['signed']);
        $this->assertEquals(0, $attribute['size']);
        $this->assertEquals(100, $attribute['default']);
        $this->assertEquals(false, $attribute['array']);
        $this->assertEquals(false, $attribute['required']);
        $this->assertEquals('', $attribute['format']);
        $this->assertEquals([], $attribute['formatOptions']);

        $database->updateAttribute('flowers', 'price', signed: false);
        $collection = $database->getCollection('flowers');
        $attribute = $collection->getAttribute('attributes')[4];
        $this->assertEquals('integer', $attribute['type']);
        $this->assertEquals(false, $attribute['signed']);
        $this->assertEquals(0, $attribute['size']);
        $this->assertEquals(100, $attribute['default']);
        $this->assertEquals(false, $attribute['array']);
        $this->assertEquals(false, $attribute['required']);
        $this->assertEquals('', $attribute['format']);
        $this->assertEquals([], $attribute['formatOptions']);

        $database->updateAttribute('flowers', 'price', required: true);
        $collection = $database->getCollection('flowers');
        $attribute = $collection->getAttribute('attributes')[4];
        $this->assertEquals('integer', $attribute['type']);
        $this->assertEquals(false, $attribute['signed']);
        $this->assertEquals(0, $attribute['size']);
        $this->assertEquals(null, $attribute['default']);
        $this->assertEquals(false, $attribute['array']);
        $this->assertEquals(true, $attribute['required']);
        $this->assertEquals('', $attribute['format']);
        $this->assertEquals([], $attribute['formatOptions']);

        $database->updateAttribute('flowers', 'price', type: Database::VAR_STRING, size: Database::LENGTH_KEY, format: '');
        $collection = $database->getCollection('flowers');
        $attribute = $collection->getAttribute('attributes')[4];
        $this->assertEquals('string', $attribute['type']);
        $this->assertEquals(false, $attribute['signed']);
        $this->assertEquals(255, $attribute['size']);
        $this->assertEquals(null, $attribute['default']);
        $this->assertEquals(false, $attribute['array']);
        $this->assertEquals(true, $attribute['required']);
        $this->assertEquals('', $attribute['format']);
        $this->assertEquals([], $collection->getAttribute('attributes')[4]['formatOptions']);

        // Date attribute
        $attribute = $collection->getAttribute('attributes')[2];
        $this->assertEquals('date', $attribute['key']);
        $this->assertEquals('string', $attribute['type']);
        $this->assertEquals(null, $attribute['default']);

        $database->updateAttribute('flowers', 'date', type: Database::VAR_DATETIME, size: 0, filters: ['datetime']);
        $collection = $database->getCollection('flowers');
        $attribute = $collection->getAttribute('attributes')[2];
        $this->assertEquals('datetime', $attribute['type']);
        $this->assertEquals(0, $attribute['size']);
        $this->assertEquals(null, $attribute['default']);
        $this->assertEquals(false, $attribute['required']);
        $this->assertEquals(true, $attribute['signed']);
        $this->assertEquals(false, $attribute['array']);
        $this->assertEquals('', $attribute['format']);
        $this->assertEquals([], $attribute['formatOptions']);

        $doc = $database->getDocument('flowers', 'LiliPriced');
        $this->assertIsString($doc->getAttribute('price'));
        $this->assertEquals('500', $doc->getAttribute('price'));

        $doc = $database->getDocument('flowers', 'flowerWithDate');
        $this->assertEquals('2000-06-12T14:12:55.000+00:00', $doc->getAttribute('date'));
    }

    public function testUpdateAttributeRename(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('rename_test');

        $this->assertEquals(true, $database->createAttribute('rename_test', 'rename_me', Database::VAR_STRING, 128, true));

        $doc = $database->createDocument('rename_test', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rename_me' => 'string'
        ]));

        $this->assertEquals('string', $doc->getAttribute('rename_me'));

        // Create an index to check later
        $database->createIndex('rename_test', 'renameIndexes', Database::INDEX_KEY, ['rename_me'], [], [Database::ORDER_DESC, Database::ORDER_DESC]);

        $database->updateAttribute(
            collection: 'rename_test',
            id: 'rename_me',
            newKey: 'renamed',
        );

        $doc = $database->getDocument('rename_test', $doc->getId());

        // Check the attribute was correctly renamed
        $this->assertEquals('string', $doc->getAttribute('renamed'));
        $this->assertArrayNotHasKey('rename_me', $doc);

        // Check we can update the document with the new key
        $doc->setAttribute('renamed', 'string2');
        $database->updateDocument('rename_test', $doc->getId(), $doc);

        $doc = $database->getDocument('rename_test', $doc->getId());
        $this->assertEquals('string2', $doc->getAttribute('renamed'));

        // Check collection
        $collection = $database->getCollection('rename_test');
        $this->assertEquals('renamed', $collection->getAttribute('attributes')[0]['key']);
        $this->assertEquals('renamed', $collection->getAttribute('attributes')[0]['$id']);
        $this->assertEquals('renamed', $collection->getAttribute('indexes')[0]['attributes'][0]);

        $supportsIdenticalIndexes = $database->getAdapter()->getSupportForIdenticalIndexes();

        try {
            // Check empty newKey doesn't cause issues
            $database->updateAttribute(
                collection: 'rename_test',
                id: 'renamed',
                type: Database::VAR_STRING,
            );

            if (!$supportsIdenticalIndexes) {
                $this->fail('Expected exception when getSupportForIdenticalIndexes=false but none was thrown');
            }
        } catch (Throwable $e) {
            if (!$supportsIdenticalIndexes) {
                $this->assertTrue(true, 'Exception thrown as expected when getSupportForIdenticalIndexes=false');
                return; // Exit early if exception was expected
            } else {
                $this->fail('Unexpected exception when getSupportForIdenticalIndexes=true: ' . $e->getMessage());
            }
        }

        $collection = $database->getCollection('rename_test');

        $this->assertEquals('renamed', $collection->getAttribute('attributes')[0]['key']);
        $this->assertEquals('renamed', $collection->getAttribute('attributes')[0]['$id']);
        $this->assertEquals('renamed', $collection->getAttribute('indexes')[0]['attributes'][0]);

        $doc = $database->getDocument('rename_test', $doc->getId());

        $this->assertEquals('string2', $doc->getAttribute('renamed'));
        $this->assertArrayNotHasKey('rename_me', $doc->getAttributes());

        // Check the metadata was correctly updated
        $attribute = $collection->getAttribute('attributes')[0];
        $this->assertEquals('renamed', $attribute['key']);
        $this->assertEquals('renamed', $attribute['$id']);

        // Check the indexes were updated
        $index = $collection->getAttribute('indexes')[0];
        $this->assertEquals('renamed', $index->getAttribute('attributes')[0]);
        $this->assertEquals(1, count($collection->getAttribute('indexes')));

        // Try and create new document with new key
        $doc = $database->createDocument('rename_test', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'renamed' => 'string'
        ]));

        $this->assertEquals('string', $doc->getAttribute('renamed'));

        // Make sure we can't create a new attribute with the old key
        try {
            $doc = $database->createDocument('rename_test', new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'rename_me' => 'string'
            ]));
            $this->fail('Succeeded creating a document with old key after renaming the attribute');
        } catch (\Exception $e) {
            $this->assertInstanceOf(StructureException::class, $e);
        }

        // Check new key filtering
        $database->updateAttribute(
            collection: 'rename_test',
            id: 'renamed',
            newKey: 'renamed-test',
        );

        $doc = $database->getDocument('rename_test', $doc->getId());

        $this->assertEquals('string', $doc->getAttribute('renamed-test'));
        $this->assertArrayNotHasKey('renamed', $doc->getAttributes());
    }


    /**
     * @depends testRenameAttribute
     * @expectedException Exception
     */
    public function textRenameAttributeMissing(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->expectExceptionMessage('Attribute not found');
        $database->renameAttribute('colors', 'name2', 'name3');
    }

    /**
    * @depends testRenameAttribute
    * @expectedException Exception
    */
    public function testRenameAttributeExisting(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->expectExceptionMessage('Attribute name already used');
        $database->renameAttribute('colors', 'verbose', 'hex');
    }

    public function testWidthLimit(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getDocumentSizeLimit() === 0) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = $database->createCollection('width_limit');

        $init = $database->getAdapter()->getAttributeWidth($collection);
        $this->assertEquals(1067, $init);

        $attribute = new Document([
            '$id' => ID::custom('varchar_100'),
            'type' => Database::VAR_STRING,
            'size' => 100,
            'required' => false,
            'default' => null,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);
        $res = $database->getAdapter()->getAttributeWidth($collection->setAttribute('attributes', [$attribute]));
        $this->assertEquals(401, $res - $init); // 100 * 4 + 1 (length)

        $attribute = new Document([
            '$id' => ID::custom('json'),
            'type' => Database::VAR_STRING,
            'size' => 100,
            'required' => false,
            'default' => null,
            'signed' => true,
            'array' => true,
            'filters' => [],
        ]);
        $res = $database->getAdapter()->getAttributeWidth($collection->setAttribute('attributes', [$attribute]));
        $this->assertEquals(20, $res - $init); // Pointer of Json / Longtext (mariaDB)

        $attribute = new Document([
            '$id' => ID::custom('text'),
            'type' => Database::VAR_STRING,
            'size' => 20000,
            'required' => false,
            'default' => null,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);
        $res = $database->getAdapter()->getAttributeWidth($collection->setAttribute('attributes', [$attribute]));
        $this->assertEquals(20, $res - $init);

        $attribute = new Document([
            '$id' => ID::custom('bigint'),
            'type' => Database::VAR_INTEGER,
            'size' => 8,
            'required' => false,
            'default' => null,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);
        $res = $database->getAdapter()->getAttributeWidth($collection->setAttribute('attributes', [$attribute]));
        $this->assertEquals(8, $res - $init);

        $attribute = new Document([
            '$id' => ID::custom('date'),
            'type' => Database::VAR_DATETIME,
            'size' => 8,
            'required' => false,
            'default' => null,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);
        $res = $database->getAdapter()->getAttributeWidth($collection->setAttribute('attributes', [$attribute]));
        $this->assertEquals(7, $res - $init);
    }

    public function testExceptionAttributeLimit(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getLimitForAttributes() === 0) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $limit = $database->getAdapter()->getLimitForAttributes() - $database->getAdapter()->getCountOfDefaultAttributes();

        $attributes = [];

        for ($i = 0; $i <= $limit; $i++) {
            $attributes[] = new Document([
                '$id' => ID::custom("attr_{$i}"),
                'type' => Database::VAR_INTEGER,
                'size' => 0,
                'required' => false,
                'default' => null,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]);
        }

        try {
            $database->createCollection('attributes_limit', $attributes);
            $this->fail('Failed to throw exception');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(LimitException::class, $e);
            $this->assertEquals('Attribute limit of 1017 exceeded. Cannot create collection.', $e->getMessage());
        }

        /**
         * Remove last attribute
         */

        array_pop($attributes);

        $collection = $database->createCollection('attributes_limit', $attributes);

        $attribute = new Document([
            '$id' => ID::custom('breaking'),
            'type' => Database::VAR_STRING,
            'size' => 100,
            'required' => true,
            'default' => null,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);

        try {
            $database->checkAttribute($collection, $attribute);
            $this->fail('Failed to throw exception');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(LimitException::class, $e);
            $this->assertStringContainsString('Column limit reached. Cannot create new attribute.', $e->getMessage());
            $this->assertStringContainsString('Remove some attributes to free up space.', $e->getMessage());
        }

        try {
            $database->createAttribute($collection->getId(), 'breaking', Database::VAR_STRING, 100, true);
            $this->fail('Failed to throw exception');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(LimitException::class, $e);
            $this->assertStringContainsString('Column limit reached. Cannot create new attribute.', $e->getMessage());
            $this->assertStringContainsString('Remove some attributes to free up space.', $e->getMessage());
        }
    }

    public function testExceptionWidthLimit(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if ($database->getAdapter()->getDocumentSizeLimit() === 0) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $attributes = [];

        $attributes[] = new Document([
            '$id' => ID::custom('varchar_16000'),
            'type' => Database::VAR_STRING,
            'size' => 16000,
            'required' => true,
            'default' => null,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);

        $attributes[] = new Document([
            '$id' => ID::custom('varchar_200'),
            'type' => Database::VAR_STRING,
            'size' => 200,
            'required' => true,
            'default' => null,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);

        try {
            $database->createCollection("attributes_row_size", $attributes);
            $this->fail('Failed to throw exception');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(LimitException::class, $e);
            $this->assertEquals('Document size limit of 65535 exceeded. Cannot create collection.', $e->getMessage());
        }

        /**
         * Remove last attribute
         */

        array_pop($attributes);

        $collection = $database->createCollection("attributes_row_size", $attributes);

        $attribute = new Document([
            '$id' => ID::custom('breaking'),
            'type' => Database::VAR_STRING,
            'size' => 200,
            'required' => true,
            'default' => null,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ]);

        try {
            $database->checkAttribute($collection, $attribute);
            $this->fail('Failed to throw exception');
        } catch (\Exception $e) {
            $this->assertInstanceOf(LimitException::class, $e);
            $this->assertStringContainsString('Row width limit reached. Cannot create new attribute.', $e->getMessage());
            $this->assertStringContainsString('bytes but the maximum is 65535 bytes', $e->getMessage());
            $this->assertStringContainsString('Reduce the size of existing attributes or remove some attributes to free up space.', $e->getMessage());
        }

        try {
            $database->createAttribute($collection->getId(), 'breaking', Database::VAR_STRING, 200, true);
            $this->fail('Failed to throw exception');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(LimitException::class, $e);
            $this->assertStringContainsString('Row width limit reached. Cannot create new attribute.', $e->getMessage());
            $this->assertStringContainsString('bytes but the maximum is 65535 bytes', $e->getMessage());
            $this->assertStringContainsString('Reduce the size of existing attributes or remove some attributes to free up space.', $e->getMessage());
        }
    }

    public function testUpdateAttributeSize(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributeResizing()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('resize_test');

        $this->assertEquals(true, $database->createAttribute('resize_test', 'resize_me', Database::VAR_STRING, 128, true));
        $document = $database->createDocument('resize_test', new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resize_me' => $this->createRandomString(128)
        ]));

        // Go up in size

        // 0-16381 to 16382-65535
        $document = $this->updateStringAttributeSize(16382, $document);

        // 16382-65535 to 65536-16777215
        $document = $this->updateStringAttributeSize(65536, $document);

        // 65536-16777216 to PHP_INT_MAX or adapter limit
        $document = $this->updateStringAttributeSize(16777217, $document);

        // Test going down in size with data that is too big (Expect Failure)
        try {
            $database->updateAttribute('resize_test', 'resize_me', Database::VAR_STRING, 128, true);
            $this->fail('Succeeded updating attribute size to smaller size with data that is too big');
        } catch (TruncateException $e) {
        }

        // Test going down in size when data isn't too big.
        $database->updateDocument('resize_test', $document->getId(), $document->setAttribute('resize_me', $this->createRandomString(128)));
        $database->updateAttribute('resize_test', 'resize_me', Database::VAR_STRING, 128, true);

        // VARCHAR -> VARCHAR Truncation Test
        $database->updateAttribute('resize_test', 'resize_me', Database::VAR_STRING, 1000, true);
        $database->updateDocument('resize_test', $document->getId(), $document->setAttribute('resize_me', $this->createRandomString(1000)));

        try {
            $database->updateAttribute('resize_test', 'resize_me', Database::VAR_STRING, 128, true);
            $this->fail('Succeeded updating attribute size to smaller size with data that is too big');
        } catch (TruncateException $e) {
        }

        if ($database->getAdapter()->getMaxIndexLength() > 0) {
            $length = intval($database->getAdapter()->getMaxIndexLength() / 2);

            $this->assertEquals(true, $database->createAttribute('resize_test', 'attr1', Database::VAR_STRING, $length, true));
            $this->assertEquals(true, $database->createAttribute('resize_test', 'attr2', Database::VAR_STRING, $length, true));

            /**
             * No index length provided, we are able to validate
             */
            $database->createIndex('resize_test', 'index1', Database::INDEX_KEY, ['attr1', 'attr2']);

            try {
                $database->updateAttribute('resize_test', 'attr1', Database::VAR_STRING, 5000);
                $this->fail('Failed to throw exception');
            } catch (Throwable $e) {
                $this->assertEquals('Index length is longer than the maximum: '.$database->getAdapter()->getMaxIndexLength(), $e->getMessage());
            }

            $database->deleteIndex('resize_test', 'index1');

            /**
             * Index lengths are provided, We are able to validate
             * Index $length === attr1, $length === attr2, so $length is removed, so we are able to validate
             */
            $database->createIndex('resize_test', 'index1', Database::INDEX_KEY, ['attr1', 'attr2'], [$length, $length]);

            $collection = $database->getCollection('resize_test');
            $indexes = $collection->getAttribute('indexes', []);
            $this->assertEquals(null, $indexes[0]['lengths'][0]);
            $this->assertEquals(null, $indexes[0]['lengths'][1]);

            try {
                $database->updateAttribute('resize_test', 'attr1', Database::VAR_STRING, 5000);
                $this->fail('Failed to throw exception');
            } catch (Throwable $e) {
                $this->assertEquals('Index length is longer than the maximum: '.$database->getAdapter()->getMaxIndexLength(), $e->getMessage());
            }

            $database->deleteIndex('resize_test', 'index1');

            /**
             * Index lengths are provided
             * We are able to increase size because index length remains 50
             */
            $database->createIndex('resize_test', 'index1', Database::INDEX_KEY, ['attr1', 'attr2'], [50, 50]);

            $collection = $database->getCollection('resize_test');
            $indexes = $collection->getAttribute('indexes', []);
            $this->assertEquals(50, $indexes[0]['lengths'][0]);
            $this->assertEquals(50, $indexes[0]['lengths'][1]);

            $database->updateAttribute('resize_test', 'attr1', Database::VAR_STRING, 5000);
        }
    }

    public function testEncryptAttributes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        // Add custom encrypt filter
        $database->addFilter(
            'encrypt',
            function (mixed $value) {
                return json_encode([
                    'data' => base64_encode($value),
                    'method' => 'base64',
                    'version' => 'v1',
                ]);
            },
            function (mixed $value) {
                if (is_null($value)) {
                    return;
                }
                $value = json_decode($value, true);
                return base64_decode($value['data']);
            }
        );

        $col = $database->createCollection(__FUNCTION__);
        $this->assertNotNull($col->getId());

        $database->createAttribute($col->getId(), 'title', Database::VAR_STRING, 255, true);
        $database->createAttribute($col->getId(), 'encrypt', Database::VAR_STRING, 128, true, filters: ['encrypt']);

        $database->createDocument($col->getId(), new Document([
            'title' => 'Sample Title',
            'encrypt' => 'secret',
        ]));
        // query against encrypt
        try {
            $queries = [Query::equal('encrypt', ['test'])];
            $doc = $database->find($col->getId(), $queries);
            $this->fail('Queried against encrypt field. Failed to throw exeception.');
        } catch (Throwable $e) {
            $this->assertTrue($e instanceof QueryException);
        }

        try {
            $queries = [Query::equal('title', ['test'])];
            $database->find($col->getId(), $queries);
        } catch (Throwable) {
            $this->fail('Should not have thrown error');
        }
    }

    /**
     * A filter can build its value by querying rather than transforming the stored one — that is
     * what the subQuery filters in Appwrite do, listing a child collection per document. Reading a
     * document without selecting such an attribute must not run it: the value is dropped anyway,
     * and it is the filter, not the value, that costs the query.
     */
    public function testFilterNotAppliedWhenAttributeNotSelected(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $calls = 0;

        $database->addFilter(
            'subQueryProbe',
            fn (mixed $value) => null, // stores nothing, like a subQuery filter
            function (mixed $value) use (&$calls) {
                $calls++;
                return ['fanned', 'out'];
            }
        );

        $database->createCollection('filterSelect');
        $database->createAttribute('filterSelect', 'plain', Database::VAR_STRING, 128, false);
        $database->createAttribute('filterSelect', 'kids', Database::VAR_STRING, 128, false, filters: ['subQueryProbe']);

        $database->createDocument('filterSelect', new Document([
            '$id' => 'doc1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'plain' => 'x',
        ]));

        $calls = 0;
        $document = $database->getDocument('filterSelect', 'doc1');
        $this->assertEquals(1, $calls);
        $this->assertEquals(['fanned', 'out'], $document->getAttribute('kids'));

        $calls = 0;
        $document = $database->getDocument('filterSelect', 'doc1', [Query::select(['$id', 'plain'])]);
        $this->assertEquals(0, $calls);
        $this->assertNull($document->getAttribute('kids'));
        $this->assertEquals('x', $document->getAttribute('plain'));

        // Selecting it explicitly, and selecting everything, both still decode it.
        $calls = 0;
        $document = $database->getDocument('filterSelect', 'doc1', [Query::select(['$id', 'kids'])]);
        $this->assertEquals(1, $calls);
        $this->assertEquals(['fanned', 'out'], $document->getAttribute('kids'));

        $calls = 0;
        $document = $database->getDocument('filterSelect', 'doc1', [Query::select(['*'])]);
        $this->assertEquals(1, $calls);
        $this->assertEquals(['fanned', 'out'], $document->getAttribute('kids'));

        // find() decodes through the same path, once per document returned.
        $calls = 0;
        $documents = $database->find('filterSelect', [Query::select(['$id', 'plain'])]);
        $this->assertCount(1, $documents);
        $this->assertEquals(0, $calls);
        $this->assertNull($documents[0]->getAttribute('kids'));

        $calls = 0;
        $documents = $database->find('filterSelect');
        $this->assertCount(1, $documents);
        $this->assertEquals(1, $calls);
        $this->assertEquals(['fanned', 'out'], $documents[0]->getAttribute('kids'));
    }

    public function updateStringAttributeSize(int $size, Document $document): Document
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->updateAttribute('resize_test', 'resize_me', Database::VAR_STRING, $size, true);

        $document = $document->setAttribute('resize_me', $this->createRandomString($size));

        $database->updateDocument('resize_test', $document->getId(), $document);
        $checkDoc = $database->getDocument('resize_test', $document->getId());

        $this->assertEquals($document->getAttribute('resize_me'), $checkDoc->getAttribute('resize_me'));
        $this->assertEquals($size, strlen($checkDoc->getAttribute('resize_me')));

        return $checkDoc;
    }

    /**
     * @depends testAttributeCaseInsensitivity
     */
    public function testIndexCaseInsensitivity(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->assertEquals(true, $database->createIndex('attributes', 'key_caseSensitive', Database::INDEX_KEY, ['caseSensitive'], [128]));

        try {
            $this->assertEquals(true, $database->createIndex('attributes', 'key_CaseSensitive', Database::INDEX_KEY, ['caseSensitive'], [128]));
        } catch (Throwable $e) {
            self::assertTrue($e instanceof DuplicateException);
        }
    }

    /**
     * Ensure the collection is removed after use
     *
     * @depends testIndexCaseInsensitivity
     */
    public function testCleanupAttributeTests(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->deleteCollection('attributes');
        $this->assertEquals(1, 1);
    }

    /**
     * @throws AuthorizationException
     * @throws DuplicateException
     * @throws ConflictException
     * @throws LimitException
     * @throws StructureException
     */
    public function testArrayAttribute(): void
    {
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $collection = 'json';
        $permissions = [Permission::read(Role::any())];

        $database->createCollection($collection, permissions: [
            Permission::create(Role::any()),
        ]);

        $this->assertEquals(true, $database->createAttribute(
            $collection,
            'booleans',
            Database::VAR_BOOLEAN,
            size: 0,
            required: true,
            array: true
        ));

        $this->assertEquals(true, $database->createAttribute(
            $collection,
            'names',
            Database::VAR_STRING,
            size: 255, // Does this mean each Element max is 255? We need to check this on Structure validation?
            required: false,
            array: true
        ));

        $this->assertEquals(true, $database->createAttribute(
            $collection,
            'cards',
            Database::VAR_STRING,
            size: 5000,
            required: false,
            array: true
        ));

        $this->assertEquals(true, $database->createAttribute(
            $collection,
            'numbers',
            Database::VAR_INTEGER,
            size: 0,
            required: false,
            array: true
        ));

        $this->assertEquals(true, $database->createAttribute(
            $collection,
            'age',
            Database::VAR_INTEGER,
            size: 0,
            required: false,
            signed: false
        ));

        $this->assertEquals(true, $database->createAttribute(
            $collection,
            'tv_show',
            Database::VAR_STRING,
            size: $database->getAdapter()->getMaxIndexLength() - 68,
            required: false,
            signed: false,
        ));

        $this->assertEquals(true, $database->createAttribute(
            $collection,
            'short',
            Database::VAR_STRING,
            size: 5,
            required: false,
            signed: false,
            array: true
        ));

        $this->assertEquals(true, $database->createAttribute(
            $collection,
            'pref',
            Database::VAR_STRING,
            size: 16384,
            required: false,
            signed: false,
            filters: ['json'],
        ));

        try {
            $database->createDocument($collection, new Document([]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertEquals('Invalid document structure: Missing required attribute "booleans"', $e->getMessage());
            }
        }

        $database->updateAttribute($collection, 'booleans', required: false);

        $doc = $database->getCollection($collection);
        $attribute = $doc->getAttribute('attributes')[0];
        $this->assertEquals('boolean', $attribute['type']);
        $this->assertEquals(true, $attribute['signed']);
        $this->assertEquals(0, $attribute['size']);
        $this->assertEquals(null, $attribute['default']);
        $this->assertEquals(true, $attribute['array']);
        $this->assertEquals(false, $attribute['required']);

        try {
            $database->createDocument($collection, new Document([
                'short' => ['More than 5 size'],
            ]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertEquals('Invalid document structure: Attribute "short[\'0\']" has invalid type. Value must be a valid string and no longer than 5 chars', $e->getMessage());
            }
        }

        try {
            $database->createDocument($collection, new Document([
                'names' => ['Joe', 100],
            ]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertEquals('Invalid document structure: Attribute "names[\'1\']" has invalid type. Value must be a valid string and no longer than 255 chars', $e->getMessage());
            }
        }

        try {
            $database->createDocument($collection, new Document([
                'age' => 1.5,
            ]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertEquals('Invalid document structure: Attribute "age" has invalid type. Value must be a valid unsigned 32-bit integer between 0 and 4,294,967,295', $e->getMessage());
            }
        }

        try {
            $database->createDocument($collection, new Document([
                'age' => -100,
            ]));
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertEquals('Invalid document structure: Attribute "age" has invalid type. Value must be a valid unsigned 32-bit integer between 0 and 4,294,967,295', $e->getMessage());
            }
        }

        $database->createDocument($collection, new Document([
            '$id' => 'id1',
            '$permissions' => $permissions,
            'booleans' => [false],
            'names' => ['Joe', 'Antony', '100'],
            'numbers' => [0, 100, 1000, -1],
            'age' => 41,
            'tv_show' => 'Everybody Loves Raymond',
            'pref' => [
                'fname' => 'Joe',
                'lname' => 'Baiden',
                'age' => 80,
                'male' => true,
            ],
        ]));

        $document = $database->getDocument($collection, 'id1');

        $this->assertEquals(false, $document->getAttribute('booleans')[0]);
        $this->assertEquals('Antony', $document->getAttribute('names')[1]);
        $this->assertEquals(100, $document->getAttribute('numbers')[1]);

        if ($database->getAdapter()->getSupportForIndexArray()) {
            /**
             * Functional index dependency cannot be dropped or rename
             */
            $database->createIndex($collection, 'idx_cards', Database::INDEX_KEY, ['cards'], [100]);
        }

        if ($database->getAdapter()->getSupportForCastIndexArray()) {
            /**
             * Delete attribute
             */
            try {
                $database->deleteAttribute($collection, 'cards');
                $this->fail('Failed to throw exception');
            } catch (Throwable $e) {
                $this->assertInstanceOf(DependencyException::class, $e);
                $this->assertEquals("Attribute can't be deleted or renamed because it is used in an index", $e->getMessage());
            }

            /**
             * Rename attribute
             */
            try {
                $database->renameAttribute($collection, 'cards', 'cards_new');
                $this->fail('Failed to throw exception');
            } catch (Throwable $e) {
                $this->assertInstanceOf(DependencyException::class, $e);
                $this->assertEquals("Attribute can't be deleted or renamed because it is used in an index", $e->getMessage());
            }

            /**
             * Update attribute
             */
            try {
                $database->updateAttribute($collection, id:'cards', newKey: 'cards_new');
                $this->fail('Failed to throw exception');
            } catch (Throwable $e) {
                $this->assertInstanceOf(DependencyException::class, $e);
                $this->assertEquals("Attribute can't be deleted or renamed because it is used in an index", $e->getMessage());
            }

        } else {
            $this->assertTrue($database->renameAttribute($collection, 'cards', 'cards_new'));
            $this->assertTrue($database->deleteAttribute($collection, 'cards_new'));
        }

        if ($database->getAdapter()->getSupportForIndexArray()) {
            try {
                $database->createIndex($collection, 'indx', Database::INDEX_FULLTEXT, ['names']);
                if ($database->getAdapter()->getSupportForAttributes()) {
                    $this->fail('Failed to throw exception');
                }
            } catch (Throwable $e) {
                if ($database->getAdapter()->getSupportForFulltextIndex()) {
                    $this->assertEquals('"Fulltext" index is forbidden on array attributes', $e->getMessage());
                } else {
                    $this->assertEquals('Fulltext index is not supported', $e->getMessage());
                }
            }

            try {
                $database->createIndex($collection, 'indx', Database::INDEX_KEY, ['numbers', 'names'], [100,100]);
                if ($database->getAdapter()->getSupportForAttributes()) {
                    $this->fail('Failed to throw exception');
                }
            } catch (Throwable $e) {
                if ($database->getAdapter()->getSupportForAttributes()) {
                    $this->assertEquals('An index may only contain one array attribute', $e->getMessage());
                } else {
                    $this->assertEquals('Index already exists', $e->getMessage());
                }
            }
        }

        $this->assertEquals(true, $database->createAttribute(
            $collection,
            'long_size',
            Database::VAR_STRING,
            size: 2000,
            required: false,
            array: true
        ));

        if ($database->getAdapter()->getSupportForIndexArray()) {
            if ($database->getAdapter()->getSupportForAttributes() && $database->getAdapter()->getMaxIndexLength() > 0) {
                // If getMaxIndexLength() > 0 We clear length for array attributes
                $database->createIndex($collection, 'indx1', Database::INDEX_KEY, ['long_size'], [], []);
                $database->deleteIndex($collection, 'indx1');
                $database->createIndex($collection, 'indx2', Database::INDEX_KEY, ['long_size'], [1000], []);

                try {
                    $database->createIndex($collection, 'indx_numbers', Database::INDEX_KEY, ['tv_show', 'numbers'], [], []); // [700, 255]
                    $this->fail('Failed to throw exception');
                } catch (Throwable $e) {
                    $this->assertEquals('Index length is longer than the maximum: ' . $database->getAdapter()->getMaxIndexLength(), $e->getMessage());
                }
            }

            try {
                if ($database->getAdapter()->getSupportForAttributes()) {
                    $database->createIndex($collection, 'indx4', Database::INDEX_KEY, ['age', 'names'], [10, 255], []);
                    $this->fail('Failed to throw exception');
                }
            } catch (Throwable $e) {
                $this->assertEquals('Cannot set a length on "integer" attributes', $e->getMessage());
            }

            $this->assertTrue($database->createIndex($collection, 'indx6', Database::INDEX_KEY, ['age', 'names'], [null, 999], []));
            $this->assertTrue($database->createIndex($collection, 'indx7', Database::INDEX_KEY, ['age', 'booleans'], [0, 999], []));
        }

        if ($this->getDatabase()->getAdapter()->getSupportForQueryContains()) {
            try {
                $database->find($collection, [
                    Query::equal('names', ['Joe']),
                ]);
                $this->fail('Failed to throw exception');
            } catch (Throwable $e) {
                $this->assertEquals('Invalid query: Cannot query equal on attribute "names" because it is an array.', $e->getMessage());
            }

            try {
                $database->find($collection, [
                    Query::contains('age', [10])
                ]);
                $this->fail('Failed to throw exception');
            } catch (Throwable $e) {
                $this->assertEquals('Invalid query: Cannot query contains on attribute "age" because it is not an array, string, or object.', $e->getMessage());
            }

            $documents = $database->find($collection, [
                Query::isNull('long_size')
            ]);
            $this->assertCount(1, $documents);

            $documents = $database->find($collection, [
                Query::contains('tv_show', ['love'])
            ]);
            $this->assertCount(1, $documents);

            $documents = $database->find($collection, [
                Query::contains('names', ['Jake', 'Joe'])
            ]);
            $this->assertCount(1, $documents);

            $documents = $database->find($collection, [
                Query::contains('numbers', [-1, 0, 999])
            ]);
            $this->assertCount(1, $documents);

            $documents = $database->find($collection, [
                Query::contains('booleans', [false, true])
            ]);
            $this->assertCount(1, $documents);

            // Regular like query on primitive json string data
            $documents = $database->find($collection, [
                Query::contains('pref', ['Joe'])
            ]);
            $this->assertCount(1, $documents);

            // containsAny tests — should behave identically to contains

            $documents = $database->find($collection, [
                Query::containsAny('tv_show', ['love'])
            ]);
            $this->assertCount(1, $documents);

            $documents = $database->find($collection, [
                Query::containsAny('names', ['Jake', 'Joe'])
            ]);
            $this->assertCount(1, $documents);

            $documents = $database->find($collection, [
                Query::containsAny('numbers', [-1, 0, 999])
            ]);
            $this->assertCount(1, $documents);

            $documents = $database->find($collection, [
                Query::containsAny('booleans', [false, true])
            ]);
            $this->assertCount(1, $documents);

            $documents = $database->find($collection, [
                Query::containsAny('pref', ['Joe'])
            ]);
            $this->assertCount(1, $documents);

            // containsAny with no matching values
            $documents = $database->find($collection, [
                Query::containsAny('names', ['Jake', 'Unknown'])
            ]);
            $this->assertCount(0, $documents);

            // containsAll tests on array attributes

            // All values present in names array
            $documents = $database->find($collection, [
                Query::containsAll('names', ['Joe', 'Antony'])
            ]);
            $this->assertCount(1, $documents);

            // One value missing from names array
            $documents = $database->find($collection, [
                Query::containsAll('names', ['Joe', 'Jake'])
            ]);
            $this->assertCount(0, $documents);

            // All values present in numbers array
            $documents = $database->find($collection, [
                Query::containsAll('numbers', [0, 100, -1])
            ]);
            $this->assertCount(1, $documents);

            // One value missing from numbers array
            $documents = $database->find($collection, [
                Query::containsAll('numbers', [0, 999])
            ]);
            $this->assertCount(0, $documents);

            // Single value containsAll — should match
            $documents = $database->find($collection, [
                Query::containsAll('booleans', [false])
            ]);
            $this->assertCount(1, $documents);

            // Boolean value not present
            $documents = $database->find($collection, [
                Query::containsAll('booleans', [true])
            ]);
            $this->assertCount(0, $documents);
        }
    }

    public function testCreateDatetime(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('datetime');
        if ($database->getAdapter()->getSupportForAttributes()) {
            $this->assertEquals(true, $database->createAttribute('datetime', 'date', Database::VAR_DATETIME, 0, true, null, true, false, null, [], ['datetime']));
            $this->assertEquals(true, $database->createAttribute('datetime', 'date2', Database::VAR_DATETIME, 0, false, null, true, false, null, [], ['datetime']));
        }

        try {
            $database->createDocument('datetime', new Document([
                'date' => ['2020-01-01'], // array
            ]));
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->fail('Failed to throw exception');
            }
        } catch (Exception $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertInstanceOf(StructureException::class, $e);
            }
        }

        $doc = $database->createDocument('datetime', new Document([
            '$id' => ID::custom('id1234'),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'date' => DateTime::now(),
        ]));

        $this->assertEquals(29, strlen($doc->getCreatedAt()));
        $this->assertEquals(29, strlen($doc->getUpdatedAt()));
        $this->assertEquals('+00:00', substr($doc->getCreatedAt(), -6));
        $this->assertEquals('+00:00', substr($doc->getUpdatedAt(), -6));
        $this->assertGreaterThan('2020-08-16T19:30:08.363+00:00', $doc->getCreatedAt());
        $this->assertGreaterThan('2020-08-16T19:30:08.363+00:00', $doc->getUpdatedAt());

        $document = $database->getDocument('datetime', 'id1234');

        $min = $database->getAdapter()->getMinDateTime();
        $max = $database->getAdapter()->getMaxDateTime();
        $dateValidator = new DatetimeValidator($min, $max);
        $this->assertEquals(null, $document->getAttribute('date2'));
        $this->assertEquals(true, $dateValidator->isValid($document->getAttribute('date')));
        $this->assertEquals(false, $dateValidator->isValid($document->getAttribute('date2')));

        $documents = $database->find('datetime', [
            Query::greaterThan('date', '1975-12-06 10:00:00+01:00'),
            Query::lessThan('date', '2030-12-06 10:00:00-01:00'),
        ]);
        $this->assertEquals(1, count($documents));

        $documents = $database->find('datetime', [
            Query::greaterThan('$createdAt', '1975-12-06 11:00:00.000'),
        ]);
        $this->assertCount(1, $documents);

        try {
            $database->createDocument('datetime', new Document([
                '$id' => 'datenew1',
                'date' => "1975-12-06 00:00:61", // 61 seconds is invalid,
            ]));
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->fail('Failed to throw exception');
            }
        } catch (Exception $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertInstanceOf(StructureException::class, $e);
            }
        }

        try {
            $database->createDocument('datetime', new Document([
                'date' => '+055769-02-14T17:56:18.000Z'
            ]));
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->fail('Failed to throw exception');
            }
        } catch (Exception $e) {
            if ($database->getAdapter()->getSupportForAttributes()) {
                $this->assertInstanceOf(StructureException::class, $e);
            }
        }

        $invalidDates = [
            '+055769-02-14T17:56:18.000Z1',
            '1975-12-06 00:00:61',
            '16/01/2024 12:00:00AM'
        ];

        foreach ($invalidDates as $date) {
            try {
                $database->find('datetime', [
                    Query::equal('$createdAt', [$date])
                ]);
                $this->fail('Failed to throw exception');
            } catch (Throwable $e) {
                $this->assertTrue($e instanceof QueryException);
                $this->assertEquals('Invalid query: Query value is invalid for attribute "$createdAt"', $e->getMessage());
            }

            try {
                $database->find('datetime', [
                    Query::equal('date', [$date])
                ]);
                if ($database->getAdapter()->getSupportForAttributes()) {
                    $this->fail('Failed to throw exception');
                }
            } catch (Throwable $e) {
                $this->assertTrue($e instanceof QueryException);
                $this->assertEquals('Invalid query: Query value is invalid for attribute "date"', $e->getMessage());
            }
        }

        $validDates = [
            '2024-12-2509:00:21.891119',
            'Tue Dec 31 2024',
        ];

        foreach ($validDates as $date) {
            $docs = $database->find('datetime', [
                Query::equal('$createdAt', [$date])
            ]);
            $this->assertCount(0, $docs);

            $docs = $database->find('datetime', [
                Query::equal('date', [$date])
            ]);
            $this->assertCount(0, $docs);

            /**
             * Test convertQueries on nested queries
             */
            $docs = $database->find('datetime', [
                Query::or([
                    Query::equal('$createdAt', [$date]),
                    Query::equal('date', [$date])
                ]),
            ]);
            $this->assertCount(0, $docs);
        }
    }

    public function testCreateDatetimeAddingAutoFilter(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('datetime_auto_filter');

        $this->expectException(Exception::class);
        $database->createAttribute('datetime_auto', 'date_auto', Database::VAR_DATETIME, 0, false, filters:['json']);
        $collection = $database->getCollection('datetime_auto_filter');
        $attribute = $collection->getAttribute('attributes')[0];
        $this->assertEquals([Database::VAR_DATETIME,'json'], $attribute['filters']);
        $database->updateAttribute('datetime_auto', 'date_auto', Database::VAR_DATETIME, 0, false, filters:[]);
        $collection = $database->getCollection('datetime_auto_filter');
        $attribute = $collection->getAttribute('attributes')[0];
        $this->assertEquals([Database::VAR_DATETIME,'json'], $attribute['filters']);
        $database->deleteCollection('datetime_auto_filter');
    }
    /**
     * @depends testCreateDeleteAttribute
     * @expectedException Exception
     */
    public function testUnknownFormat(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->expectException(\Exception::class);
        $this->assertEquals(false, $database->createAttribute('attributes', 'bad_format', Database::VAR_STRING, 256, true, null, true, false, 'url'));
    }


    // Bulk attribute creation tests
    public function testCreateAttributesEmpty(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        try {
            $database->createAttributes(__FUNCTION__, []);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }

    public function testCreateAttributesMissingId(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $attributes = [[
            'type' => Database::VAR_STRING,
            'size' => 10,
            'required' => false
        ]];
        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }

    public function testCreateAttributesMissingType(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $attributes = [[
            '$id' => 'foo',
            'size' => 10,
            'required' => false
        ]];
        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }

    public function testCreateAttributesMissingSize(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $attributes = [[
            '$id' => 'foo',
            'type' => Database::VAR_STRING,
            'required' => false
        ]];
        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }

    public function testCreateAttributesMissingRequired(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $attributes = [[
            '$id' => 'foo',
            'type' => Database::VAR_STRING,
            'size' => 10
        ]];
        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }

    public function testCreateAttributesDuplicateMetadata(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);
        $database->createAttribute(__FUNCTION__, 'dup', Database::VAR_STRING, 10, false);

        $attributes = [[
            '$id' => 'dup',
            'type' => Database::VAR_STRING,
            'size' => 10,
            'required' => false
        ]];

        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DuplicateException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }
    }

    public function testCreateAttributesInvalidFilter(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $attributes = [[
            '$id' => 'date',
            'type' => Database::VAR_DATETIME,
            'size' => 0,
            'required' => false,
            'filters' => []
        ]];
        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }

    public function testCreateAttributesInvalidFormat(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $attributes = [[
            '$id' => 'foo',
            'type' => Database::VAR_STRING,
            'size' => 10,
            'required' => false,
            'format' => 'nonexistent'
        ]];

        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }

    public function testCreateAttributesDefaultOnRequired(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $attributes = [[
            '$id' => 'foo',
            'type' => Database::VAR_STRING,
            'size' => 10,
            'required' => true,
            'default' => 'bar'
        ]];

        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }

    public function testCreateAttributesUnknownType(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $attributes = [[
            '$id' => 'foo',
            'type' => 'unknown',
            'size' => 0,
            'required' => false
        ]];

        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }

    public function testCreateAttributesStringSizeLimit(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $max = $database->getAdapter()->getLimitForString();

        $attributes = [[
            '$id' => 'foo',
            'type' => Database::VAR_STRING,
            'size' => $max + 1,
            'required' => false
        ]];

        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }

    public function testCreateAttributesIntegerSizeLimit(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $limit = $database->getAdapter()->getLimitForInt() / 2;

        $attributes = [[
            '$id' => 'foo',
            'type' => Database::VAR_INTEGER,
            'size' => (int)$limit + 1,
            'required' => false
        ]];

        try {
            $database->createAttributes(__FUNCTION__, $attributes);
            $this->fail('Expected DatabaseException not thrown');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(DatabaseException::class, $e);
        }
    }


    public function testCreateAttributesBigIntIgnoresSizeMetadata(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'bigint_ignores_size_limit';
        $database->createCollection($collectionName);

        $attributes = [[
            '$id' => 'foo',
            'type' => Database::VAR_BIGINT,
            'size' => 9999,
            'required' => false
        ]];

        $result = $database->createAttributes($collectionName, $attributes);
        $this->assertTrue($result);

        $collection = $database->getCollection($collectionName);
        $attrs = $collection->getAttribute('attributes');
        $this->assertCount(1, $attrs);
        $this->assertEquals('foo', $attrs[0]['$id']);
        $this->assertEquals(0, $attrs[0]['size']);

        $database->updateAttribute($collectionName, 'foo', size: 1);
        $collection = $database->getCollection($collectionName);
        $attrs = $collection->getAttribute('attributes');
        $this->assertEquals(0, $attrs[0]['size']);
    }

    public function testCreateAttributesBigIntValidationSignedUnsignedAndMetadata(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collectionName = 'bigint_attr_validation';
        $database->createCollection($collectionName);

        $this->assertTrue($database->createAttribute(
            $collectionName,
            'signed_bigint',
            Database::VAR_BIGINT,
            0,
            false,
            signed: true
        ));
        $this->assertTrue($database->createAttribute(
            $collectionName,
            'unsigned_bigint',
            Database::VAR_BIGINT,
            0,
            false,
            signed: false
        ));

        $collection = $database->getCollection($collectionName);
        $attributes = $collection->getAttribute('attributes', []);

        $signedAttribute = null;
        $unsignedAttribute = null;
        foreach ($attributes as $attribute) {
            if (($attribute['$id'] ?? '') === 'signed_bigint') {
                $signedAttribute = $attribute;
            }
            if (($attribute['$id'] ?? '') === 'unsigned_bigint') {
                $unsignedAttribute = $attribute;
            }
        }

        $this->assertNotNull($signedAttribute);
        $this->assertNotNull($unsignedAttribute);
        $this->assertTrue($signedAttribute['signed']);
        $this->assertFalse($unsignedAttribute['signed']);
        $this->assertEquals(0, $signedAttribute['size']);
        $this->assertEquals(0, $unsignedAttribute['size']);

        $largeUnsignedAttribute = [[
            '$id' => 'unsigned_bigint_large',
            'type' => Database::VAR_BIGINT,
            'size' => 0,
            'required' => false,
            'signed' => false,
            'default' => '18446744073709551615'
        ]];
        $this->assertTrue($database->createAttributes($collectionName, $largeUnsignedAttribute));
    }

    public function testCreateAttributesSuccessMultiple(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $attributes = [
            [
                '$id' => 'a',
                'type' => Database::VAR_STRING,
                'size' => 10,
                'required' => false
            ],
            [
                '$id' => 'b',
                'type' => Database::VAR_INTEGER,
                'size' => 0,
                'required' => false
            ],
        ];

        $result = $database->createAttributes(__FUNCTION__, $attributes);
        $this->assertTrue($result);

        $collection = $database->getCollection(__FUNCTION__);
        $attrs = $collection->getAttribute('attributes');
        $this->assertCount(2, $attrs);
        $this->assertEquals('a', $attrs[0]['$id']);
        $this->assertEquals('b', $attrs[1]['$id']);

        $doc = $database->createDocument(__FUNCTION__, new Document([
            'a' => 'foo',
            'b' => 123,
        ]));

        $this->assertEquals('foo', $doc->getAttribute('a'));
        $this->assertEquals(123, $doc->getAttribute('b'));
    }

    public function testCreateAttributesDelete(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchCreateAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection(__FUNCTION__);

        $attributes = [
            [
                '$id' => 'a',
                'type' => Database::VAR_STRING,
                'size' => 10,
                'required' => false
            ],
            [
                '$id' => 'b',
                'type' => Database::VAR_INTEGER,
                'size' => 0,
                'required' => false
            ],
        ];

        $result = $database->createAttributes(__FUNCTION__, $attributes);
        $this->assertTrue($result);

        $collection = $database->getCollection(__FUNCTION__);
        $attrs = $collection->getAttribute('attributes');
        $this->assertCount(2, $attrs);
        $this->assertEquals('a', $attrs[0]['$id']);
        $this->assertEquals('b', $attrs[1]['$id']);

        $database->deleteAttribute(__FUNCTION__, 'a');

        $collection = $database->getCollection(__FUNCTION__);
        $attrs = $collection->getAttribute('attributes');
        $this->assertCount(1, $attrs);
        $this->assertEquals('b', $attrs[0]['$id']);
    }

    /**
     * @depends testCreateDeleteAttribute
     */
    public function testStringTypeAttributes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('stringTypes');

        // Create attributes with different string types
        $this->assertEquals(true, $database->createAttribute('stringTypes', 'varchar_field', Database::VAR_VARCHAR, 255, false, 'default varchar'));
        $this->assertEquals(true, $database->createAttribute('stringTypes', 'text_field', Database::VAR_TEXT, 65535, false));
        $this->assertEquals(true, $database->createAttribute('stringTypes', 'mediumtext_field', Database::VAR_MEDIUMTEXT, 16777215, false));
        $this->assertEquals(true, $database->createAttribute('stringTypes', 'longtext_field', Database::VAR_LONGTEXT, 4294967295, false));

        // Test with array types
        $this->assertEquals(true, $database->createAttribute('stringTypes', 'varchar_array', Database::VAR_VARCHAR, 128, false, null, true, true));
        $this->assertEquals(true, $database->createAttribute('stringTypes', 'text_array', Database::VAR_TEXT, 65535, false, null, true, true));

        $collection = $database->getCollection('stringTypes');
        $this->assertCount(6, $collection->getAttribute('attributes'));

        // Test VARCHAR with valid data
        $doc1 = $database->createDocument('stringTypes', new Document([
            '$id' => ID::custom('doc1'),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'varchar_field' => 'This is a varchar field with 255 max length',
            'text_field' => \str_repeat('a', 1000),
            'mediumtext_field' => \str_repeat('b', 100000),
            'longtext_field' => \str_repeat('c', 1000000),
        ]));

        $this->assertEquals('This is a varchar field with 255 max length', $doc1->getAttribute('varchar_field'));
        $this->assertEquals(\str_repeat('a', 1000), $doc1->getAttribute('text_field'));
        $this->assertEquals(\str_repeat('b', 100000), $doc1->getAttribute('mediumtext_field'));
        $this->assertEquals(\str_repeat('c', 1000000), $doc1->getAttribute('longtext_field'));

        // Test VARCHAR with default value
        $doc2 = $database->createDocument('stringTypes', new Document([
            '$id' => ID::custom('doc2'),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]));

        $this->assertEquals('default varchar', $doc2->getAttribute('varchar_field'));
        $this->assertNull($doc2->getAttribute('text_field'));

        // Test array types
        $doc3 = $database->createDocument('stringTypes', new Document([
            '$id' => ID::custom('doc3'),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'varchar_array' => ['test1', 'test2', 'test3'],
            'text_array' => [\str_repeat('x', 1000), \str_repeat('y', 2000)],
        ]));

        $this->assertEquals(['test1', 'test2', 'test3'], $doc3->getAttribute('varchar_array'));
        $this->assertEquals([\str_repeat('x', 1000), \str_repeat('y', 2000)], $doc3->getAttribute('text_array'));

        // Test VARCHAR size constraint (should fail) - only for adapters that support attributes
        if ($database->getAdapter()->getSupportForAttributes()) {
            try {
                $database->createDocument('stringTypes', new Document([
                    '$id' => ID::custom('doc4'),
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::create(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                    'varchar_field' => \str_repeat('a', 256), // Too long for VARCHAR(255)
                ]));
                $this->fail('Failed to throw exception for VARCHAR size violation');
            } catch (Exception $e) {
                $this->assertInstanceOf(StructureException::class, $e);
            }

            // Test TEXT size constraint (should fail)
            try {
                $database->createDocument('stringTypes', new Document([
                    '$id' => ID::custom('doc5'),
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::create(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                    'text_field' => \str_repeat('a', 65536), // Too long for TEXT(65535)
                ]));
                $this->fail('Failed to throw exception for TEXT size violation');
            } catch (Exception $e) {
                $this->assertInstanceOf(StructureException::class, $e);
            }
        }

        // Test querying by VARCHAR field
        $this->assertEquals(true, $database->createIndex('stringTypes', 'varchar_index', Database::INDEX_KEY, ['varchar_field']));

        $results = $database->find('stringTypes', [
            Query::equal('varchar_field', ['This is a varchar field with 255 max length'])
        ]);
        $this->assertCount(1, $results);
        $this->assertEquals('doc1', $results[0]->getId());

        // Test updating VARCHAR field
        $database->updateDocument('stringTypes', 'doc1', new Document([
            '$id' => 'doc1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'varchar_field' => 'Updated varchar value',
        ]));

        $updatedDoc = $database->getDocument('stringTypes', 'doc1');
        $this->assertEquals('Updated varchar value', $updatedDoc->getAttribute('varchar_field'));
    }
}
