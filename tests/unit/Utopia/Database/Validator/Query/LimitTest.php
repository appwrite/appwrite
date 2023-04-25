<?php
//
//namespace Tests\Unit\Utopia\Database\Validator\Query;
//
//use Appwrite\Utopia\Database\Validator\Query\Base;
//use Appwrite\Utopia\Database\Validator\Query\Limit;
//use Utopia\Database\Query;
//use PHPUnit\Framework\TestCase;
//
//class LimitTest extends TestCase
//{
//    /**
//     * @var Base
//     */
//    protected $validator = null;
//
//    public function setUp(): void
//    {
//        $this->validator = new Limit(100);
//    }
//
//    public function tearDown(): void
//    {
//    }
//
//    public function testValue(): void
//    {
//        // Test for Success
//        $this->assertEquals($this->validator->isValid(Query::limit(1)), true, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::limit(0)), true, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::limit(100)), true, $this->validator->getDescription());
//
//        // Test for Failure
//        $this->assertEquals($this->validator->isValid(Query::limit(-1)), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::limit(101)), false, $this->validator->getDescription());
//    }
//}
