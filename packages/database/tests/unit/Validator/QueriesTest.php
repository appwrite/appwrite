<?php

namespace Tests\Unit\Validator;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Filter;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Query\Order;

class QueriesTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testEmptyQueries(): void
    {
        $validator = new Queries();

        $this->assertEquals(true, $validator->isValid([]));
    }

    public function testInvalidMethod(): void
    {
        $validator = new Queries();
        $this->assertEquals(false, $validator->isValid([Query::equal('attr', ["value"])]));

        $validator = new Queries([new Limit()]);
        $this->assertEquals(false, $validator->isValid([Query::equal('attr', ["value"])]));
    }

    public function testInvalidValue(): void
    {
        $validator = new Queries([new Limit()]);
        $this->assertEquals(false, $validator->isValid([Query::limit(-1)]));
    }

    /**
     * @throws Exception
     */
    public function testValid(): void
    {
        $attributes = [
            new Document([
                '$id' => 'name',
                'key' => 'name',
                'type' => Database::VAR_STRING,
                'array' => false,
            ]),
            new Document([
                '$id' => 'meta',
                'key' => 'meta',
                'type' => Database::VAR_OBJECT,
                'array' => false,
            ]),
        ];

        $validator = new Queries(
            [
                new Cursor(),
                new Filter($attributes, Database::VAR_INTEGER),
                new Limit(),
                new Offset(),
                new Order($attributes)
            ]
        );

        $this->assertEquals(true, $validator->isValid([Query::cursorAfter(new Document(['$id' => 'asdf']))]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::equal('name', ['value'])]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::limit(10)]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::offset(10)]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::orderAsc('name')]), $validator->getDescription());

        // Object attribute query: allowed shape
        $this->assertTrue(
            $validator->isValid([
                Query::equal('meta', [
                    ['a' => [1, 2]],
                    ['b' => [212]],
                ]),
            ]),
            $validator->getDescription()
        );

        // Object attribute query: disallowed nested multiple keys in same level
        $this->assertFalse(
            $validator->isValid([
                Query::equal('meta', [
                    ['a' => [1, 'b' => [212]]],
                ]),
            ])
        );

        // Object attribute query: disallowed complex multi-key nested structure
        $this->assertTrue(
            $validator->isValid([
                Query::contains('meta', [
                    [
                        'role' => [
                            'name' => ['test1', 'test2'],
                            'ex' => ['new' => 'test1'],
                        ],
                    ],
                ]),
            ])
        );
    }
}
