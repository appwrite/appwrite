<?php

namespace Tests\Unit\Utopia\Database\Validator\Queries;

use Appwrite\Utopia\Database\Validator\Queries\Base;
use PHPUnit\Framework\TestCase;

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
        $this->assertEquals(true, $validator->isValid(['cursorAfter("asdf")']), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid(['equal("name", "value")']), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid(['limit(10)']), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid(['offset(10)']), $validator->getDescription());
        $this->assertEquals(true, $validator->isValid(['orderAsc("name")']), $validator->getDescription());
    }

    public function testMissingIndex(): void
    {
        $validator = new Base('users', ['name']);
        $this->assertEquals(false, $validator->isValid(['equal("dne", "value")']), $validator->getDescription());
        $this->assertEquals(false, $validator->isValid(['orderAsc("dne")']), $validator->getDescription());
    }
}
