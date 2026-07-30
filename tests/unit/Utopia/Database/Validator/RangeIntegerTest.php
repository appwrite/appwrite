<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use Appwrite\Utopia\Database\Validator\RangeInteger;
use PHPUnit\Framework\TestCase;

final class RangeIntegerTest extends TestCase
{
    public function testValidIntegers(): void
    {
        $validator = new RangeInteger(0, 100);

        $this->assertTrue($validator->isValid(0));
        $this->assertTrue($validator->isValid(1));
        $this->assertTrue($validator->isValid(50));
        $this->assertTrue($validator->isValid(100));
        $this->assertSame(0, $validator->getMin());
        $this->assertSame(100, $validator->getMax());
    }

    public function testRejectsStringIntegers(): void
    {
        $validator = new RangeInteger(0, 100);

        // Numeric strings must be rejected
        $this->assertFalse($validator->isValid('0'));
        $this->assertFalse($validator->isValid('50'));
        $this->assertFalse($validator->isValid('100'));
        $this->assertFalse($validator->isValid('50000'));
        $this->assertFalse($validator->isValid('1.5'));
    }

    public function testRejectsInvalidTypesAndOutOfRange(): void
    {
        $validator = new RangeInteger(0, 100);

        $this->assertFalse($validator->isValid(-1));
        $this->assertFalse($validator->isValid(101));
        $this->assertFalse($validator->isValid(50.5));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(false));
        $this->assertFalse($validator->isValid(true));
        $this->assertFalse($validator->isValid([]));
    }
}
