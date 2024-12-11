<?php

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Base;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Query;

class CollectionTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testEmptyQueries(): void
    {
        $validator = new Base('users', []);

        $this->assertEquals($validator->isValid([]), true);
    }

    public function testValid(): void
    {
        $validator = new Base('users', ['name', 'search']);
        $this->assertEquals(true, $validator->isValid([Query::cursorAfter(new Document(['$id' => 'asdf']))]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::equal('name', ['value'])]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::limit(10)]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::offset(10)]), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid([Query::orderAsc('name')]), $validator->getDescription());
    }

    public function testMissingIndex(): void
    {
        $validator = new Base('users', ['name']);
        $this->assertEquals(false, $validator->isValid([Query::equal('dne', ['value'])]), $validator->getDescription());
        $this->assertEquals(false, $validator->isValid([Query::orderAsc('dne')]), $validator->getDescription());
    }
}
