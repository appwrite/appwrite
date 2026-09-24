<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class NumericTest extends TestCase
{
    public function testCanValidateNumerics(): void
    {
        $numeric = new Numeric();

        $this->assertTrue($numeric->isValid('42'));
        $this->assertTrue($numeric->isValid(1337));
        $this->assertTrue($numeric->isValid(0x539));
        $this->assertTrue($numeric->isValid(02471));
        $this->assertTrue($numeric->isValid(1337e0));
        $this->assertTrue($numeric->isValid(9.1));
        $this->assertFalse($numeric->isValid('not numeric'));
        $this->assertFalse($numeric->isValid([]));
        $this->assertFalse($numeric->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_MIXED, $numeric->getType());
    }
}
