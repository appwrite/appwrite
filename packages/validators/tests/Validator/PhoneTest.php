<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    public function testCanValidatePhone(): void
    {
        $validator = new Phone();

        $this->assertTrue($validator->isValid('+15550102680'));
        $this->assertTrue($validator->isValid('+447911123456'));
        $this->assertTrue($validator->isValid('+919876543210'));
        $this->assertTrue($validator->isValid('+1234567'));
        $this->assertTrue($validator->isValid('+123456789012345'));

        $this->assertFalse($validator->isValid('15550102680'));
        $this->assertFalse($validator->isValid(' 15550102680'));
        $this->assertFalse($validator->isValid('%2B15550102680'));
        $this->assertFalse($validator->isValid('+0123456789'));
        $this->assertFalse($validator->isValid('+123456'));
        $this->assertFalse($validator->isValid('+1234567890123456'));
        $this->assertFalse($validator->isValid('+1 555 010 2680'));
        $this->assertFalse($validator->isValid('+1-555-010-2680'));
        $this->assertFalse($validator->isValid(''));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(['+15550102680']));
        $this->assertFalse($validator->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_STRING, $validator->getType());
    }

    public function testCanAllowEmptyValue(): void
    {
        $validator = new Phone(allowEmpty: true);

        $this->assertTrue($validator->isValid(''));
        $this->assertTrue($validator->isValid('+15550102680'));
        $this->assertFalse($validator->isValid(null));
    }

    public function testCanNormalizePathEncoding(): void
    {
        $this->assertSame('+15550102680', Phone::normalize('%2B15550102680'));
        $this->assertSame('+15550102680', Phone::normalize(' 15550102680'));
        $this->assertSame('  15550102680', Phone::normalize('  15550102680'));
        $this->assertSame('+15550102680', Phone::normalize('15550102680'));
        $this->assertSame('+15550102680', Phone::normalize('+15550102680'));
        $this->assertSame('+0123456789', Phone::normalize('+0123456789'));
        $this->assertSame('0123456789', Phone::normalize('0123456789'));
    }

    public function testCanValidateNormalizedPhone(): void
    {
        $validator = new Phone(normalize: true);

        $this->assertTrue($validator->isValid('%2B15550102680'));
        $this->assertTrue($validator->isValid(' 15550102680'));
        $this->assertTrue($validator->isValid('15550102680'));

        $this->assertFalse($validator->isValid('  15550102680'));
        $this->assertTrue($validator->isValid('+15550102680'));

        $this->assertFalse($validator->isValid('0123456789'));
        $this->assertFalse($validator->isValid('%2B0123456789'));
        $this->assertFalse($validator->isValid('+1 555 010 2680'));
    }
}
