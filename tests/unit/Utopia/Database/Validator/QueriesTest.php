<?php

//
//namespace Tests\Unit\Utopia\Database\Validator;
//
//use Utopia\Database\Validator\Queries;
//use Utopia\Database\Validator\Query\Cursor;
//use Utopia\Database\Validator\Query\Filter;
//use Utopia\Database\Validator\Query\Limit;
//use Utopia\Database\Validator\Query\Offset;
//use Utopia\Database\Validator\Query\Order;
//use PHPUnit\Framework\TestCase;
//use Utopia\Database\Database;
//use Utopia\Database\Document;
//
//class QueriesTest extends TestCase
//{
//    public function setUp(): void
//    {
//    }
//
//    public function tearDown(): void
//    {
//    }
//
//    public function testEmptyQueries(): void
//    {
//        $validator = new Queries();
//
//        $this->assertEquals(true, $validator->isValid([]));
//    }
//
//    public function testInvalidQuery(): void
//    {
//        $validator = new Queries();
//
//        $this->assertEquals(false, $validator->isValid(["this.is.invalid"]));
//    }
//
//    public function testInvalidMethod(): void
//    {
//        $validator = new Queries();
//        $this->assertEquals(false, $validator->isValid(['equal("attr", "value")']));
//
//        $validator = new Queries(new Limit());
//        $this->assertEquals(false, $validator->isValid(['equal("attr", "value")']));
//    }
//
//    public function testInvalidValue(): void
//    {
//        $validator = new Queries(new Limit());
//        $this->assertEquals(false, $validator->isValid(['limit(-1)']));
//    }
//
//    public function testValid(): void
//    {
//        $attributes = [
//            new Document([
//                'key' => 'name',
//                'type' => Database::VAR_STRING,
//                'array' => false,
//            ])
//        ];
//        $validator = new Queries(
//            new Cursor(),
//            new Filter($attributes),
//            new Limit(),
//            new Offset(),
//            new Order($attributes),
//        );
//        $this->assertEquals(true, $validator->isValid(['cursorAfter("asdf")']), $validator->getDescription());
//        $this->assertEquals(true, $validator->isValid(['equal("name", "value")']), $validator->getDescription());
//        $this->assertEquals(true, $validator->isValid(['limit(10)']), $validator->getDescription());
//        $this->assertEquals(true, $validator->isValid(['offset(10)']), $validator->getDescription());
//        $this->assertEquals(true, $validator->isValid(['orderAsc("name")']), $validator->getDescription());
//    }
//}
