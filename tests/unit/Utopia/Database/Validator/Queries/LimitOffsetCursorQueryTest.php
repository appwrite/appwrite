<?php

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries;
use Appwrite\Utopia\Database\Validator\Queries\LimitOffsetCursorQuery;
use Utopia\Database\Query;
use Utopia\Validator;
use PHPUnit\Framework\TestCase;

class LimitOffsetCursorQueryTest extends TestCase
{
    /**
     * @var Validator
     */
    protected $validator = null;

    public function setUp(): void
    {
        $this->validator = new LimitOffsetCursorQuery();
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
        $this->assertEquals($this->validator->isValid(new Query(Query::TYPE_CURSORAFTER, values: ['asdf'])), true, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(new Query(Query::TYPE_CURSORBEFORE, values: ['asdf'])), true, $this->validator->getDescription());

        // Test for Failure
        $this->assertEquals($this->validator->isValid(Query::limit(-1)), false, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::limit(101)), false, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::offset(-1)), false, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::offset(5001)), false, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::equal('attr', ['v'])), false, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::orderAsc('attr')), false, $this->validator->getDescription());
        $this->assertEquals($this->validator->isValid(Query::orderDesc('attr')), false, $this->validator->getDescription());
    }

    public function testValues(): void
    {

        $validator = new Queries($this->validator);

        // Test for Success
        $this->assertEquals($validator->isValid(['limit(1)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['limit(0)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['limit(100)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['offset(1)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['offset(0)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['offset(5000)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['limit(25)', 'offset(25)']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['cursorAfter("asdf")']), true, $validator->getDescription());
        $this->assertEquals($validator->isValid(['cursorBefore("asdf")']), true, $validator->getDescription());

        // Test for Failure
        $this->assertEquals($validator->isValid(['limit(-1)']), false, $validator->getDescription());
        $this->assertEquals($validator->isValid(['limit(101)']), false, $validator->getDescription());
        $this->assertEquals($validator->isValid(['offset(-1)']), false, $validator->getDescription());
        $this->assertEquals($validator->isValid(['offset(5001)']), false, $validator->getDescription());
        $this->assertEquals($validator->isValid(['equal("attr", "v")']), false, $validator->getDescription());
        $this->assertEquals($validator->isValid(['orderAsc("attr")']), false, $validator->getDescription());
        $this->assertEquals($validator->isValid(['orderDesc("attr")']), false, $validator->getDescription());
    }
}
