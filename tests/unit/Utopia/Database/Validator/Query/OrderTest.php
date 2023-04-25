<?php
//
//namespace Tests\Unit\Utopia\Database\Validator\Query;
//
//use Appwrite\Utopia\Database\Validator\Query\Base;
//use Appwrite\Utopia\Database\Validator\Query\Order;
//use Utopia\Database\Database;
//use Utopia\Database\Document;
//use Utopia\Database\Query;
//use PHPUnit\Framework\TestCase;
//
//class OrderTest extends TestCase
//{
//    /**
//     * @var Base
//     */
//    protected $validator = null;
//
//    public function setUp(): void
//    {
//        $this->validator = new Order(
//            attributes: [
//                new Document([
//                    'key' => 'attr',
//                    'type' => Database::VAR_STRING,
//                    'array' => false,
//                ]),
//            ],
//        );
//    }
//
//    public function tearDown(): void
//    {
//    }
//
//    public function testValue(): void
//    {
//        // Test for Success
//        $this->assertEquals($this->validator->isValid(Query::orderAsc('attr')), true, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::orderAsc('')), true, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::orderDesc('attr')), true, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::orderDesc('')), true, $this->validator->getDescription());
//
//        // Test for Failure
//        $this->assertEquals($this->validator->isValid(Query::limit(-1)), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::limit(101)), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::offset(-1)), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::offset(5001)), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::equal('attr', ['v'])), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::equal('dne', ['v'])), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::equal('', ['v'])), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::orderDesc('dne')), false, $this->validator->getDescription());
//        $this->assertEquals($this->validator->isValid(Query::orderAsc('dne')), false, $this->validator->getDescription());
//    }
//}
