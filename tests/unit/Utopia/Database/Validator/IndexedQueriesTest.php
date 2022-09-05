<?php

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\IndexedQueries;
use Appwrite\Utopia\Database\Validator\Query\Cursor;
use Appwrite\Utopia\Database\Validator\Query\Filter;
use Appwrite\Utopia\Database\Validator\Query\Limit;
use Appwrite\Utopia\Database\Validator\Query\Offset;
use Appwrite\Utopia\Database\Validator\Query\Order;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;

class IndexedQueriesTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testEmptyQueries(): void
    {
        $validator = new IndexedQueries();

        $this->assertEquals(true, $validator->isValid([]));
    }

    public function testInvalidQuery(): void
    {
        $validator = new IndexedQueries();

        $this->assertEquals(false, $validator->isValid(["this.is.invalid"]));
    }

    public function testInvalidMethod(): void
    {
        $validator = new IndexedQueries();
        $this->assertEquals(false, $validator->isValid(['equal("attr", "value")']));

        $validator = new IndexedQueries([], [], new Limit());
        $this->assertEquals(false, $validator->isValid(['equal("attr", "value")']));
    }

    public function testInvalidValue(): void
    {
        $validator = new IndexedQueries([], [], new Limit());
        $this->assertEquals(false, $validator->isValid(['limit(-1)']));
    }

    public function testValid(): void
    {
        $attributes = [
            new Document([
                'key' => 'name',
                'type' => Database::VAR_STRING,
                'array' => false,
            ]),
        ];
        $indexes = [
            new Document([
                'status' => 'available',
                'type' => Database::INDEX_KEY,
                'attributes' => ['name'],
            ]),
            new Document([
                'status' => 'available',
                'type' => Database::INDEX_FULLTEXT,
                'attributes' => ['name'],
            ]),
        ];
        $validator = new IndexedQueries(
            $attributes,
            $indexes,
            new Cursor(),
            new Filter($attributes),
            new Limit(),
            new Offset(),
            new Order($attributes),
        );
        $this->assertEquals(true, $validator->isValid(['cursorAfter("asdf")']), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid(['equal("name", "value")']), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid(['limit(10)']), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid(['offset(10)']), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid(['orderAsc("name")']), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid(['search("name", "value")']), $validator->getDescription());
    }

    public function testMissingIndex(): void
    {
        $attributes = [
            new Document([
                'key' => 'name',
                'type' => Database::VAR_STRING,
                'array' => false,
            ]),
        ];
        $indexes = [
            new Document([
                'status' => 'available',
                'type' => Database::INDEX_KEY,
                'attributes' => ['name'],
            ]),
        ];
        $validator = new IndexedQueries(
            $attributes,
            $indexes,
            new Cursor(),
            new Filter($attributes),
            new Limit(),
            new Offset(),
            new Order($attributes),
        );
        $this->assertEquals(false, $validator->isValid(['equal("dne", "value")']), $validator->getDescription());
        $this->assertEquals(false, $validator->isValid(['orderAsc("dne")']), $validator->getDescription());
        $this->assertEquals(false, $validator->isValid(['search("name", "value")']), $validator->getDescription());
    }
}
