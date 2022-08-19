<?php

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries;
use Appwrite\Utopia\Database\Validator\Queries\LimitOffsetQuery;
use Utopia\Database\Query;
use PHPUnit\Framework\TestCase;

class LimitOffsetQueryTest extends TestCase
{
    /**
     * @var Key
     */
    protected $validator = null;

    public function setUp(): void
    {
        $this->validator = new LimitOffsetQuery();
    }

    public function tearDown(): void
    {
    }

    public function testValue(): void
    {
        // Test for Success
        $this->assertEquals($this->validator->isValid(Query::limit(1)), true, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::limit(0)), true, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::limit(100)), true, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::offset(1)), true, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::offset(0)), true, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::offset(5000)), true, $this->validator->getDescription());

        // Test for Failure
        $this->assertEquals($this->validator->isValid(Query::limit(-1)), false, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::limit(101)), false, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::offset(-1)), false, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::offset(5001)), false, $this->validator->getDescription());
    }

    public function testValues(): void
    {

        $validator = new Queries($this->validator, strict: false);
    
        // Test for Success
        $this->assertEquals($validator->isValid(['limit(1)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['limit(0)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['limit(100)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['offset(1)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['offset(0)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['offset(5000)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['limit(25)', 'offset(25)']), true, $validator->getDescription());

        // Test for Failure
        $this->assertEquals($validator->isValid(['limit(-1)']), false, $validator->getDescription());
        $this->assertEquals($validator->isValid(['limit(101)']), false, $validator->getDescription());
        $this->assertEquals($validator->isValid(['offset(-1)']), false, $validator->getDescription());
        $this->assertEquals($validator->isValid(['offset(5001)']), false, $validator->getDescription());
    }
}
