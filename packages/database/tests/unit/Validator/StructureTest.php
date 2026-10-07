<?php

namespace Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Format;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Operator;
use Utopia\Database\Validator\Structure;

class StructureTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    protected array $collection = [
        '$id' => Database::METADATA,
        '$collection' => Database::METADATA,
        'name' => 'collections',
        'attributes' => [
            [
                '$id' => 'title',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 256,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'description',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 1000000,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'rating',
                'type' => Database::VAR_INTEGER,
                'format' => '',
                'size' => 5,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'reviews',
                'type' => Database::VAR_INTEGER,
                'format' => '',
                'size' => 5,
                'required' => false,
                'signed' => true,
                'array' => true,
                'filters' => [],
            ],
            [
                '$id' => 'price',
                'type' => Database::VAR_FLOAT,
                'format' => '',
                'size' => 5,
                'required' => true,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'published',
                'type' => Database::VAR_BOOLEAN,
                'format' => '',
                'size' => 5,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'tags',
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 55,
                'required' => false,
                'signed' => true,
                'array' => true,
                'filters' => [],
            ],
            [
                '$id' => 'id',
                'type' => Database::VAR_ID,
                'format' => '',
                'size' => 0,
                'required' => false,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'varchar_field',
                'type' => Database::VAR_VARCHAR,
                'format' => '',
                'size' => 255,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'text_field',
                'type' => Database::VAR_TEXT,
                'format' => '',
                'size' => 65535,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'mediumtext_field',
                'type' => Database::VAR_MEDIUMTEXT,
                'format' => '',
                'size' => 16777215,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'longtext_field',
                'type' => Database::VAR_LONGTEXT,
                'format' => '',
                'size' => 4294967295,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
        ],
        'indexes' => [],
    ];

    public function setUp(): void
    {
        Structure::addFormat('email', function ($attribute) {
            $size = $attribute['size'] ?? 0;
            return new Format($size);
        }, Database::VAR_STRING);

        // Cannot encode format when defining constants
        // So add feedback attribute on startup
        $this->collection['attributes'][] = [
            '$id' => ID::custom('feedback'),
            'type' => Database::VAR_STRING,
            'format' => 'email',
            'size' => 55,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ];
    }

    public function tearDown(): void
    {
    }

    public function testDocumentInstance(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid('string'));
        $this->assertEquals(false, $validator->isValid(null));
        $this->assertEquals(false, $validator->isValid(false));
        $this->assertEquals(false, $validator->isValid(1));

        $this->assertEquals('Invalid document structure: Value must be an instance of Document', $validator->getDescription());
    }

    public function testCollectionAttribute(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document()));

        $this->assertEquals('Invalid document structure: Missing collection attribute $collection', $validator->getDescription());
    }

    public function testCollection(): void
    {
        $validator = new Structure(
            new Document(),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Collection not found', $validator->getDescription());
    }

    public function testRequiredKeys(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Missing required attribute "title"', $validator->getDescription());
    }

    public function testRequiredKeyStoredAsNull(): void
    {
        $document = [
            '$collection' => ID::custom('posts'),
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ];

        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER,
            currentDocument: new Document(['title' => null])
        );

        $this->assertTrue($validator->isValid(new Document($document)), $validator->getDescription());
        $this->assertTrue($validator->isValid(new Document([...$document, 'title' => null])), $validator->getDescription());

        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER,
            currentDocument: new Document(['title' => 'My Title'])
        );

        $this->assertFalse($validator->isValid(new Document([...$document, 'title' => null])));
        $this->assertEquals('Invalid document structure: Missing required attribute "title"', $validator->getDescription());
    }

    public function testNullValues(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'My Title',
            'description' => null,
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
            'id' => '1000',
        ])));

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'My Title',
            'description' => null,
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', null, 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));
    }

    public function testUnknownKeys(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'titlex' => 'Unknown Attribute',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Unknown attribute: "titlex"', $validator->getDescription());
    }

    public function testIntegerAsString(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => '5',
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "rating" has invalid type. Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647', $validator->getDescription());
    }

    public function testValidDocument(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));
    }

    public function testStringValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 5,
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "title" has invalid type. Value must be a valid string and no longer than 256 chars', $validator->getDescription());
    }

    public function testTextByteSafeValidationTooBig(): void
    {
        // A legacy `text` attribute whose declared size (1MB) exceeds the real
        // 65,535-byte capacity of a TEXT column. Such attributes exist in older
        // databases created before VAR_TEXT was capped, so the limit must come
        // from the column type, not from the (untrustworthy) declared size.
        $collection = new Document([
            '$id' => ID::custom('posts'),
            '$collection' => Database::METADATA,
            'name' => 'posts',
            'attributes' => [
                [
                    '$id' => 'text',
                    'type' => Database::VAR_TEXT,
                    'format' => '',
                    'size' => 1048576,
                    'required' => false,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ],
            ],
            'indexes' => [],
        ]);

        $validator = new Structure($collection, Database::VAR_INTEGER);

        $base = [
            '$collection' => ID::custom('posts'),
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
        ];

        // A TEXT column is limited to 65,535 bytes. Validation measures the
        // value's actual byte length, so a value over that capacity is rejected
        // even though the declared $size (1MB) would allow the character count.
        $tooBig = \str_repeat('a', 65536);
        $this->assertEquals(false, $validator->isValid(new Document($base + ['text' => $tooBig])));
        $this->assertEquals('Invalid document structure: Attribute "text" has invalid type. Value must be a valid string no longer than 65535 bytes', $validator->getDescription());
    }

    public function testTextByteSafeValidationMultibyte(): void
    {
        $collection = new Document([
            '$id' => ID::custom('posts'),
            '$collection' => Database::METADATA,
            'name' => 'posts',
            'attributes' => [
                [
                    '$id' => 'text',
                    'type' => Database::VAR_TEXT,
                    'format' => '',
                    'size' => 1048576,
                    'required' => false,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ],
            ],
            'indexes' => [],
        ]);

        $validator = new Structure($collection, Database::VAR_INTEGER);

        $base = [
            '$collection' => ID::custom('posts'),
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
        ];

        // Multi-byte content over the byte capacity is rejected the same way
        // (20,000 emoji = 80,000 bytes in utf8mb4).
        $multibyte = \str_repeat('📝', 20000);
        $this->assertEquals(false, $validator->isValid(new Document($base + ['text' => $multibyte])));
    }

    public function testTextByteSafeValidationValid(): void
    {
        $collection = new Document([
            '$id' => ID::custom('posts'),
            '$collection' => Database::METADATA,
            'name' => 'posts',
            'attributes' => [
                [
                    '$id' => 'text',
                    'type' => Database::VAR_TEXT,
                    'format' => '',
                    'size' => 1048576,
                    'required' => false,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ],
            ],
            'indexes' => [],
        ]);

        $validator = new Structure($collection, Database::VAR_INTEGER);

        $base = [
            '$collection' => ID::custom('posts'),
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
        ];

        // A value that fills the column's full byte capacity is accepted.
        $ok = \str_repeat('a', 65535);
        $this->assertEquals(true, $validator->isValid(new Document($base + ['text' => $ok])));
    }

    public function testArrayOfStringsValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => [1, 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "tags[\'0\']" has invalid type. Value must be a valid string and no longer than 55 chars', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => [true],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "tags[\'0\']" has invalid type. Value must be a valid string and no longer than 55 chars', $validator->getDescription());

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => [],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['too-long-tag-name-to-make-sure-the-length-validator-inside-string-attribute-type-fails-properly'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "tags[\'0\']" has invalid type. Value must be a valid string and no longer than 55 chars', $validator->getDescription());
    }

    /**
     * @throws Exception
     */
    public function testArrayAsObjectValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['name' => 'dog'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));
    }

    public function testArrayOfObjectsValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => [['name' => 'dog']],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));
    }

    public function testIntegerValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => true,
            'price' => 1.99,
            'published' => false,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "rating" has invalid type. Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => '',
            'price' => 1.99,
            'published' => false,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "rating" has invalid type. Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647', $validator->getDescription());
    }

    public function testArrayOfIntegersValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'reviews' => [3, 4, 4, 5],
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'reviews' => [],
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'reviews' => null,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'reviews' => ['', 4, 4, 5],
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "reviews[\'0\']" has invalid type. Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647', $validator->getDescription());
    }

    public function testFloatValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => '2.5',
            'published' => false,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "price" has invalid type. Value must be a valid float', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => '',
            'published' => false,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "price" has invalid type. Value must be a valid float', $validator->getDescription());
    }

    public function testBooleanValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => 1,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "published" has invalid type. Value must be a valid boolean', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => '',
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "published" has invalid type. Value must be a valid boolean', $validator->getDescription());
    }

    public function testFormatValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team_appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "feedback" has invalid format. Value must be a valid email address', $validator->getDescription());
    }

    public function testIntegerMaxRange(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => PHP_INT_MAX,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "rating" has invalid type. Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647', $validator->getDescription());
    }

    public function testDoubleUnsigned(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => -1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertStringContainsString('Invalid document structure: Attribute "price" has invalid type. Value must be a valid range between 0 and ', $validator->getDescription());
    }

    public function testDoubleMaxRange(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'string',
            'description' => 'Demo description',
            'rating' => 1,
            'price' => INF,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));
    }

    public function testId(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $sqlId = '1000';
        $mongoId = '0198fffb-d664-710a-9765-f922b3e81e3d';

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'My Title',
            'description' => null,
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
            'id' => $sqlId,
        ])));

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'My Title',
            'description' => null,
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
            'id' => $mongoId,
        ])));

        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_UUID7
        );

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'My Title',
            'description' => null,
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
            'id' => $mongoId,
        ])));

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'My Title',
            'description' => null,
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
            'id' => $mongoId,
        ])));
    }

    public function testOperatorsSkippedDuringValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        // Operators should be skipped during structure validation
        $this->assertTrue($validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'My Title',
            'description' => 'Demo description',
            'rating' => Operator::increment(1), // Operator on required field
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])), $validator->getDescription());
    }

    public function testMultipleOperatorsSkippedDuringValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        // Multiple operators should all be skipped
        $this->assertTrue($validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => Operator::stringConcat(' - Updated'),
            'description' => 'Demo description',
            'rating' => Operator::increment(1),
            'price' => Operator::multiply(2),
            'published' => Operator::toggle(),
            'tags' => Operator::arrayAppend(['new']),
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])), $validator->getDescription());
    }

    public function testMissingRequiredFieldWithoutOperator(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        // Missing required field (not replaced by operator) should still fail
        $this->assertFalse($validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'My Title',
            'description' => 'Demo description',
            // 'rating' is missing entirely - should fail
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Missing required attribute "rating"', $validator->getDescription());
    }

    public function testVarcharValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'varchar_field' => 'Short varchar text',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'varchar_field' => 123,
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "varchar_field" has invalid type. Value must be a valid string and no longer than 255 chars', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'varchar_field' => \str_repeat('a', 256),
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "varchar_field" has invalid type. Value must be a valid string and no longer than 255 chars', $validator->getDescription());
    }

    public function testTextValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'text_field' => \str_repeat('a', 65535), // fills the TEXT column's full byte capacity
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        // 'text_field' is optional (required => false). Structure short-circuits
        // on `$required === false && is_null($value)` and `continue`s before
        // building or running any validators, so the null never reaches the
        // ByteLength check (which would otherwise reject it, since
        // is_string(null) is false). Hence a null optional text value passes.
        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'text_field' => null,
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'text_field' => 123,
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "text_field" has invalid type. Value must be a valid string no longer than 65535 bytes', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'text_field' => \str_repeat('a', 65536),
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "text_field" has invalid type. Value must be a valid string no longer than 65535 bytes', $validator->getDescription());
    }

    public function testMediumtextValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'mediumtext_field' => \str_repeat('a', 100000),
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'mediumtext_field' => 123,
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "mediumtext_field" has invalid type. Value must be a valid string no longer than 16777215 bytes', $validator->getDescription());
    }

    public function testMediumtextSizedValidation(): void
    {
        // A mediumtext attribute with a declared size of 100. The declared size
        // is enforced as a byte limit (ByteLength), independent of the column's
        // physical 16MB ceiling.
        $collection = new Document([
            '$id' => ID::custom('posts'),
            '$collection' => Database::METADATA,
            'name' => 'posts',
            'attributes' => [
                [
                    '$id' => 'mediumtext',
                    'type' => Database::VAR_MEDIUMTEXT,
                    'format' => '',
                    'size' => 100,
                    'required' => false,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ],
            ],
            'indexes' => [],
        ]);

        $validator = new Structure($collection, Database::VAR_INTEGER);

        $base = [
            '$collection' => ID::custom('posts'),
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
        ];

        // A value of exactly 100 bytes fits the declared size.
        $exact = \str_repeat('a', 100);
        $this->assertEquals(true, $validator->isValid(new Document($base + ['mediumtext' => $exact])));

        // 101 bytes exceeds the declared size of 100 bytes and is rejected.
        $tooBig = \str_repeat('a', 101);
        $this->assertEquals(false, $validator->isValid(new Document($base + ['mediumtext' => $tooBig])));
        $this->assertEquals('Invalid document structure: Attribute "mediumtext" has invalid type. Value must be a valid string no longer than 100 bytes', $validator->getDescription());

        // Each '📝' is 4 bytes in utf8mb4, so 25 chars = 100 bytes fits exactly,
        // while 26 chars = 104 bytes exceeds the declared 100-byte size.
        $exactMultibyte = \str_repeat('📝', 25);
        $this->assertEquals(true, $validator->isValid(new Document($base + ['mediumtext' => $exactMultibyte])));

        $tooBigMultibyte = \str_repeat('📝', 26);
        $this->assertEquals(false, $validator->isValid(new Document($base + ['mediumtext' => $tooBigMultibyte])));
        $this->assertEquals('Invalid document structure: Attribute "mediumtext" has invalid type. Value must be a valid string no longer than 100 bytes', $validator->getDescription());
    }

    public function testLongtextValidation(): void
    {
        $validator = new Structure(
            new Document($this->collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'longtext_field' => \str_repeat('a', 1000000),
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'title' => 'Demo Title',
            'description' => 'Demo description',
            'rating' => 5,
            'price' => 1.99,
            'published' => true,
            'tags' => ['dog', 'cat', 'mouse'],
            'feedback' => 'team@appwrite.io',
            'longtext_field' => 123,
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "longtext_field" has invalid type. Value must be a valid string no longer than 4294967295 bytes', $validator->getDescription());
    }

    public function testStringTypeArrayValidation(): void
    {
        $collection = [
            '$id' => Database::METADATA,
            '$collection' => Database::METADATA,
            'name' => 'collections',
            'attributes' => [
                [
                    '$id' => 'varchar_array',
                    'type' => Database::VAR_VARCHAR,
                    'format' => '',
                    'size' => 128,
                    'required' => false,
                    'signed' => true,
                    'array' => true,
                    'filters' => [],
                ],
                [
                    '$id' => 'text_array',
                    'type' => Database::VAR_TEXT,
                    'format' => '',
                    'size' => 65535,
                    'required' => false,
                    'signed' => true,
                    'array' => true,
                    'filters' => [],
                ],
            ],
            'indexes' => [],
        ];

        $validator = new Structure(
            new Document($collection),
            Database::VAR_INTEGER
        );

        $this->assertEquals(true, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'varchar_array' => ['test1', 'test2', 'test3'],
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'varchar_array' => [123, 'test2', 'test3'],
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "varchar_array[\'0\']" has invalid type. Value must be a valid string and no longer than 128 chars', $validator->getDescription());

        $this->assertEquals(false, $validator->isValid(new Document([
            '$collection' => ID::custom('posts'),
            'varchar_array' => [\str_repeat('a', 129), 'test2'],
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00'
        ])));

        $this->assertEquals('Invalid document structure: Attribute "varchar_array[\'0\']" has invalid type. Value must be a valid string and no longer than 128 chars', $validator->getDescription());
    }

    public function testBigIntSignedAcceptsNumericStringAndNormalizesToInt(): void
    {
        $collection = [
            '$id' => Database::METADATA,
            '$collection' => Database::METADATA,
            'name' => 'collections',
            'attributes' => [
                [
                    '$id' => 'bigint_signed',
                    'type' => Database::VAR_BIGINT,
                    'format' => '',
                    'size' => 0,
                    'required' => true,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ],
            ],
            'indexes' => [],
        ];

        $validator = new Structure(
            new Document($collection),
            Database::VAR_INTEGER
        );

        $doc = new Document([
            '$collection' => ID::custom('posts'),
            'bigint_signed' => (string)PHP_INT_MAX,
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
        ]);

        $this->assertTrue($validator->isValid($doc));
        $this->assertIsInt($doc->getAttribute('bigint_signed'));
        $this->assertEquals(PHP_INT_MAX, $doc->getAttribute('bigint_signed'));
    }

    public function testBigIntUnsignedAcceptsLargeNumericStringAsString(): void
    {
        $collection = [
            '$id' => Database::METADATA,
            '$collection' => Database::METADATA,
            'name' => 'collections',
            'attributes' => [
                [
                    '$id' => 'bigint_unsigned',
                    'type' => Database::VAR_BIGINT,
                    'format' => '',
                    'size' => 0,
                    'required' => true,
                    'signed' => false,
                    'array' => false,
                    'filters' => [],
                ],
            ],
            'indexes' => [],
        ];

        $validator = new Structure(
            new Document($collection),
            Database::VAR_INTEGER
        );

        $unsignedMax = '18446744073709551615';

        $doc = new Document([
            '$collection' => ID::custom('posts'),
            'bigint_unsigned' => $unsignedMax,
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
        ]);

        $this->assertTrue($validator->isValid($doc));
        $this->assertIsString($doc->getAttribute('bigint_unsigned'));
        $this->assertEquals($unsignedMax, $doc->getAttribute('bigint_unsigned'));
    }

    public function testBigIntUnsignedRejectsNegativeNumericString(): void
    {
        $collection = [
            '$id' => Database::METADATA,
            '$collection' => Database::METADATA,
            'name' => 'collections',
            'attributes' => [
                [
                    '$id' => 'bigint_unsigned',
                    'type' => Database::VAR_BIGINT,
                    'format' => '',
                    'size' => 0,
                    'required' => true,
                    'signed' => false,
                    'array' => false,
                    'filters' => [],
                ],
            ],
            'indexes' => [],
        ];

        $validator = new Structure(
            new Document($collection),
            Database::VAR_INTEGER
        );

        $doc = new Document([
            '$collection' => ID::custom('posts'),
            'bigint_unsigned' => '-1',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
        ]);

        $this->assertFalse($validator->isValid($doc));
        $this->assertEquals(
            'Invalid document structure: Attribute "bigint_unsigned" has invalid type. Value must be a valid unsigned 64-bit integer between 0 and 18,446,744,073,709,551,615',
            $validator->getDescription()
        );
    }

}
