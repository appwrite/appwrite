<?php

namespace Tests\Unit\Validator;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Queries\Documents;

class DocumentsQueriesTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    protected array $collection = [];

    /**
     * @throws Exception
     */
    public function setUp(): void
    {
        $this->collection = [
            '$id' => Database::METADATA,
            '$collection' => Database::METADATA,
            'name' => 'movies',
            'attributes' => [
                new Document([
                    '$id' => 'title',
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'size' => 256,
                    'required' => true,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'description',
                    'key' => 'description',
                    'type' => Database::VAR_STRING,
                    'size' => 1000000,
                    'required' => true,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'rating',
                    'key' => 'rating',
                    'type' => Database::VAR_INTEGER,
                    'size' => 5,
                    'required' => true,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'price',
                    'key' => 'price',
                    'type' => Database::VAR_FLOAT,
                    'size' => 5,
                    'required' => true,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'is_bool',
                    'key' => 'is_bool',
                    'type' => Database::VAR_BOOLEAN,
                    'size' => 0,
                    'required' => false,
                    'signed' => false,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => 'id',
                    'key' => 'id',
                    'type' => Database::VAR_ID,
                    'size' => 0,
                    'required' => false,
                    'signed' => false,
                    'array' => false,
                    'filters' => [],
                ])
            ],
            'indexes' => [
                new Document([
                    '$id' => ID::custom('testindex2'),
                    'type' => 'key',
                    'attributes' => [
                        'title',
                        'description',
                        'price'
                    ],
                    'orders' => [
                        'ASC',
                        'DESC'
                    ],
                ]),
                new Document([
                    '$id' => ID::custom('testindex3'),
                    'type' => 'fulltext',
                    'attributes' => [
                        'title'
                    ],
                    'orders' => []
                ]),
            ],
        ];
    }

    public function tearDown(): void
    {
    }

    /**
     * @throws Exception
     */
    public function testValidQueries(): void
    {
        $validator = new Documents(
            $this->collection['attributes'],
            $this->collection['indexes'],
            Database::VAR_INTEGER
        );

        $queries = [
            Query::notEqual('id', '1000000'),
            Query::equal('description', ['Best movie ever']),
            Query::equal('description', ['']),
            Query::equal('is_bool', [false]),
            Query::lessThanEqual('price', 6.50),
            Query::lessThan('price', 6.50),
            Query::greaterThan('rating', 4),
            Query::greaterThan('rating', 0),
            Query::greaterThanEqual('rating', 6),
            Query::between('price', 1.50, 6.50),
            Query::search('title', 'SEO'),
            Query::startsWith('title', 'Good'),
            Query::endsWith('title', 'Night'),
            Query::isNull('title'),
            Query::isNotNull('title'),
            Query::cursorAfter(new Document(['$id' => 'a'])),
            Query::cursorBefore(new Document(['$id' => 'b'])),
            Query::orderAsc('title'),
            Query::limit(10),
            Query::offset(10),
            Query::orderDesc(),
        ];

        $this->assertEquals(true, $validator->isValid($queries));
    }

    /**
     * @throws Exception
     */
    public function testInvalidQueries(): void
    {
        $validator = new Documents(
            $this->collection['attributes'],
            $this->collection['indexes'],
            Database::VAR_INTEGER
        );

        $queries = ['{"method":"notEqual","attribute":"title","values":["Iron Man","Ant Man"]}'];
        $this->assertEquals(false, $validator->isValid($queries));
        $this->assertEquals('Invalid query: NotEqual queries require exactly one value.', $validator->getDescription());

        $queries = [Query::search('description', 'iron')];
        $this->assertEquals(false, $validator->isValid($queries));
        $this->assertEquals('Searching by attribute "description" requires a fulltext index.', $validator->getDescription());

        $queries = [Query::equal('not_found', [4])];
        $this->assertEquals(false, $validator->isValid($queries));
        $this->assertEquals('Invalid query: Attribute not found in schema: not_found', $validator->getDescription());

        $queries = [Query::limit(-1)];
        $this->assertEquals(false, $validator->isValid($queries));
        $this->assertEquals('Invalid query: Invalid limit: Value must be a valid range between 1 and ' . number_format(PHP_INT_MAX), $validator->getDescription());

        $queries = [Query::equal('title', [])]; // empty array
        $this->assertEquals(false, $validator->isValid($queries));
        $this->assertEquals('Invalid query: Equal queries require at least one value.', $validator->getDescription());


    }
}
