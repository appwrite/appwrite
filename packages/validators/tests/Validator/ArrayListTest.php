<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class ArrayListTest extends TestCase
{
    public function testDescription(): void
    {
        $arrayList = new ArrayList(new Integer());
        $this->assertFalse($arrayList->isValid(['text']));
        $this->assertSame('Value must a valid array and Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647', $arrayList->getDescription());

        $arrayList = new ArrayList(new Integer(), 3);
        $this->assertFalse($arrayList->isValid(['a', 'b', 'c', 'd']));
        $this->assertSame('Value must a valid array no longer than 3 items and Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647', $arrayList->getDescription());
    }

    public function testCanValidateTextValues(): void
    {
        $arrayList = new ArrayList(new Text(100));
        $this->assertTrue($arrayList->isArray(), 'true');
        $this->assertTrue($arrayList->isValid([0 => 'string', 1 => 'string']));
        $this->assertTrue($arrayList->isValid(['string', 'string']));
        $this->assertFalse($arrayList->isValid(['string', 'string', 3]));
        $this->assertFalse($arrayList->isValid('string'));
        $this->assertFalse($arrayList->isValid('string'));
        $this->assertSame(\Utopia\Validator::TYPE_STRING, $arrayList->getType());
        $this->assertInstanceOf(Text::class, $arrayList->getValidator());
    }

    public function testCanValidateNumericValues(): void
    {
        $arrayList = new ArrayList(new Numeric());
        $this->assertTrue($arrayList->isValid([1, 2, 3]));
        $this->assertFalse($arrayList->isValid(1));
        $this->assertFalse($arrayList->isValid('string'));
        $this->assertSame(\Utopia\Validator::TYPE_MIXED, $arrayList->getType());
        $this->assertInstanceOf(Numeric::class, $arrayList->getValidator());
    }

    public function testCanValidateNumericValuesWithBoundaries(): void
    {
        $arrayList = new ArrayList(new Numeric(), 2);
        $this->assertTrue($arrayList->isValid([1]));
        $this->assertTrue($arrayList->isValid([1, 2]));
        $this->assertFalse($arrayList->isValid([1, 2, 3]));
        $this->assertSame(\Utopia\Validator::TYPE_MIXED, $arrayList->getType());
        $this->assertInstanceOf(Numeric::class, $arrayList->getValidator());
    }
}
