<?php

declare(strict_types=1);

namespace Utopia\Validator\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Validator\Integer;

final class IntegerTest extends TestCase
{
    public function testCanValidateStrictly(): void
    {
        $validator = new Integer();
        $this->assertTrue($validator->isValid(23));
        $this->assertFalse($validator->isValid('23'));
        $this->assertFalse($validator->isValid(23.5));
        $this->assertFalse($validator->isValid('23.5'));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(true));
        $this->assertFalse($validator->isValid(false));
        $this->assertFalse($validator->isArray());
        $this->assertSame(\Utopia\Validator\Validator::TYPE_INTEGER, $validator->getType());
    }

    public function testCanValidateLoosely(): void
    {
        $validator = new Integer(true);
        $this->assertTrue($validator->isValid(23));
        $this->assertTrue($validator->isValid('23'));
        $this->assertFalse($validator->isValid(23.5));
        $this->assertFalse($validator->isValid('23.5'));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(true));
        $this->assertFalse($validator->isValid(false));
        $this->assertFalse($validator->isArray());
        $this->assertSame(\Utopia\Validator\Validator::TYPE_INTEGER, $validator->getType());
    }

    public function testBitSizeAndSignedness(): void
    {
        // Default: 32-bit signed
        $validator = new Integer();
        $this->assertSame(32, $validator->getBits());
        $this->assertFalse($validator->isUnsigned());
        $this->assertSame('int32', $validator->getFormat());

        // 8-bit signed: -128 to 127
        $validator8 = new Integer(false, 8);
        $this->assertSame('int8', $validator8->getFormat());
        $this->assertTrue($validator8->isValid(-128));
        $this->assertTrue($validator8->isValid(127));
        $this->assertFalse($validator8->isValid(-129));
        $this->assertFalse($validator8->isValid(128));

        // 8-bit unsigned: 0 to 255
        $validator8u = new Integer(false, 8, true);
        $this->assertSame('uint8', $validator8u->getFormat());
        $this->assertTrue($validator8u->isValid(0));
        $this->assertTrue($validator8u->isValid(255));
        $this->assertFalse($validator8u->isValid(-1));
        $this->assertFalse($validator8u->isValid(256));

        // 16-bit unsigned: 0 to 65535
        $validator16u = new Integer(false, 16, true);
        $this->assertSame('uint16', $validator16u->getFormat());
        $this->assertTrue($validator16u->isValid(65535));
        $this->assertFalse($validator16u->isValid(65536));

        // 32-bit unsigned
        $validator32u = new Integer(false, 32, true);
        $this->assertSame('uint32', $validator32u->getFormat());

        // 64-bit signed
        $validator64 = new Integer(false, 64);
        $this->assertSame('int64', $validator64->getFormat());
    }

    public function testDescriptionStatesTheExactBounds(): void
    {
        $this->assertSame('Value must be a valid signed 64-bit integer between -9,223,372,036,854,775,808 and 9,223,372,036,854,775,807', new Integer(false, 64)->getDescription());
        $this->assertSame('Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647', new Integer()->getDescription());
        $this->assertSame('Value must be a valid unsigned 32-bit integer between 0 and 4,294,967,295', new Integer(false, 32, true)->getDescription());
        $this->assertSame('Value must be a valid signed 8-bit integer between -128 and 127', new Integer(false, 8)->getDescription());
    }

    public function testSixtyFourBitBoundsAreInclusive(): void
    {
        $validator = new Integer(false, 64);
        $this->assertTrue($validator->isValid(\PHP_INT_MAX));
        $this->assertTrue($validator->isValid(\PHP_INT_MIN));

        $loose = new Integer(true, 64);
        $this->assertTrue($loose->isValid('9223372036854775807'));
        $this->assertFalse($loose->isValid('9223372036854775808'));
        $this->assertTrue($loose->isValid('-9223372036854775808'));
        $this->assertFalse($loose->isValid('-9223372036854775809'));
    }

    public function testInvalidBitSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Bits must be 8, 16, 32, or 64');
        new Integer(false, 128);
    }

    public function test64BitUnsignedNotSupported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('64-bit unsigned integers are not supported due to PHP integer limitations');
        new Integer(false, 64, true);
    }
}
