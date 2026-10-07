<?php

namespace Tests\Unit\Validator;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Queries\Document as DocumentQueries;

class DocumentQueriesTest extends TestCase
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
            '$collection' => ID::custom(Database::METADATA),
            '$id' => ID::custom('movies'),
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
                    '$id' => 'price',
                    'key' => 'price',
                    'type' => Database::VAR_FLOAT,
                    'size' => 5,
                    'required' => true,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ])
            ]
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
        $validator = new DocumentQueries($this->collection['attributes']);

        $queries = [
            Query::select(['title']),
        ];

        $this->assertEquals(true, $validator->isValid($queries));

        $queries[] = Query::select(['price.relation']);
        $this->assertEquals(true, $validator->isValid($queries));
    }

    /**
     * @throws Exception
     */
    public function testInvalidQueries(): void
    {
        $validator = new DocumentQueries($this->collection['attributes']);
        $queries = [Query::limit(1)];
        $this->assertEquals(false, $validator->isValid($queries));
    }
}
