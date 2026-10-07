<?php

namespace Tests\Unit\Validator\Query;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Offset;

class OffsetTest extends TestCase
{
    public function testValueSuccess(): void
    {
        $validator = new Offset(5000);

        $this->assertTrue($validator->isValid(Query::offset(1)));
        $this->assertTrue($validator->isValid(Query::offset(0)));
        $this->assertTrue($validator->isValid(Query::offset(5000)));
    }

    public function testValueFailure(): void
    {
        $validator = new Offset(5000);

        $this->assertFalse($validator->isValid(Query::offset(-1)));
        $this->assertEquals('Invalid offset: Value must be a valid range between 0 and 5,000', $validator->getDescription());
        $this->assertFalse($validator->isValid(Query::offset(5001)));
        $this->assertFalse($validator->isValid(Query::equal('attr', ['v'])));
        $this->assertFalse($validator->isValid(Query::orderAsc('attr')));
        $this->assertFalse($validator->isValid(Query::orderDesc('attr')));
        $this->assertFalse($validator->isValid(Query::limit(100)));
    }
}
