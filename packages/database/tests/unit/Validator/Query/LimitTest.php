<?php

namespace Tests\Unit\Validator\Query;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Limit;

class LimitTest extends TestCase
{
    public function testValueSuccess(): void
    {
        $validator = new Limit(100);

        $this->assertTrue($validator->isValid(Query::limit(1)));
        $this->assertTrue($validator->isValid(Query::limit(100)));
    }

    public function testValueFailure(): void
    {
        $validator = new Limit(100);

        $this->assertFalse($validator->isValid(Query::limit(0)));
        $this->assertEquals('Invalid limit: Value must be a valid range between 1 and 100', $validator->getDescription());
        $this->assertFalse($validator->isValid(Query::limit(0)));
        $this->assertFalse($validator->isValid(Query::limit(-1)));
        $this->assertFalse($validator->isValid(Query::limit(101)));
    }
}
