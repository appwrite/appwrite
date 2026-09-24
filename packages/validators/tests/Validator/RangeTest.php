<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class RangeTest extends TestCase
{
    public function testCanValidateIntegerRange(): void
    {
        $range = new Range(0, 5, \Utopia\Validator::TYPE_INTEGER);

        // Assertions for integer
        $this->assertTrue($range->isValid(0));
        $this->assertTrue($range->isValid(1));
        $this->assertTrue($range->isValid(4));
        $this->assertTrue($range->isValid(5));
        $this->assertTrue($range->isValid('5'));
        $this->assertFalse($range->isValid('1.5'));
        $this->assertFalse($range->isValid(6));
        $this->assertFalse($range->isValid(-1));
        $this->assertSame(0, $range->getMin());
        $this->assertSame(5, $range->getMax());
        $this->assertFalse($range->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_INTEGER, $range->getFormat());
        $this->assertSame(\Utopia\Validator::TYPE_INTEGER, $range->getType());
    }

    public function testCanValidateFloatRange(): void
    {
        $range = new Range(0, 1, \Utopia\Validator::TYPE_FLOAT);

        $this->assertTrue($range->isValid(0.0));
        $this->assertTrue($range->isValid(1.0));
        $this->assertTrue($range->isValid(0.5));
        $this->assertTrue($range->isValid('0.5'));
        $this->assertTrue($range->isValid('0.6'));
        $this->assertFalse($range->isValid(4));
        $this->assertFalse($range->isValid(1.5));
        $this->assertFalse($range->isValid(-1));
        $this->assertSame(0, $range->getMin());
        $this->assertSame(1, $range->getMax());
        $this->assertFalse($range->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_FLOAT, $range->getFormat());
        $this->assertSame(\Utopia\Validator::TYPE_FLOAT, $range->getType(), \Utopia\Validator::TYPE_FLOAT);
    }

    public function canValidateInfinityRange(): void
    {
        $integer = new Range(5, INF, \Utopia\Validator::TYPE_INTEGER);
        $float = new Range(-INF, 45.6, \Utopia\Validator::TYPE_FLOAT);

        $this->assertTrue($integer->isValid(25));
        $this->assertFalse($integer->isValid(3));
        $this->assertTrue($integer->isValid(INF));
        $this->assertTrue($float->isValid(32.1));
        $this->assertFalse($float->isValid(97.6));
        $this->assertTrue($float->isValid(-INF));
    }
}
