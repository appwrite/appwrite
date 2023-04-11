<?php

namespace Tests\Unit\Utopia\Database\Validator\Query;

use Appwrite\Utopia\Database\Validator\Query\Base;
use Appwrite\Utopia\Database\Validator\Query\Cursor;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

class CursorTest extends TestCase
{
    /**
     * @var Base
     */
    protected $validator = null;

    public function setUp(): void
    {
        $this->validator = new Cursor();
    }

    public function tearDown(): void
    {
    }

    public function testValue(): void
    {
        // Test for Success
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
}
