<?php

//
//namespace Tests\Unit\Utopia\Database\Validator\Query;
//
//use Appwrite\Utopia\Database\Validator\Query\Base;
//use Appwrite\Utopia\Database\Validator\Query\Offset;
//use Utopia\Database\Query;
//use PHPUnit\Framework\TestCase;
//
//class OffsetTest extends TestCase
//{
//    /**
//     * @var Base
//     */
//    protected $validator = null;
//
//    public function setUp(): void
//    {
//        $this->validator = new Offset(5000);
//    }
//
//    public function tearDown(): void
//    {
//    }
//
//    public function testValue(): void
//    {
//        // Test for Success
//        $this->assertEquals($this->validator->isValid(Query::offset(1)), true, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::offset(0)), true, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::offset(5000)), true, $this->validator->getDescription());
//
//        // Test for Failure
//        $this->assertEquals($this->validator->isValid(Query::offset(-1)), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::offset(5001)), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::equal('attr', ['v'])), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::orderAsc('attr')), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::orderDesc('attr')), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::limit(100)), false, $this->validator->getDescription());
//    }
//}
