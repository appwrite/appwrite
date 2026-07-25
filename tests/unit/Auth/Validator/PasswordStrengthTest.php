<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PasswordStrength;
use PHPUnit\Framework\TestCase;

final class PasswordStrengthTest extends TestCase
{
    public function testDefaultPolicy(): void
    {
        $validator = new PasswordStrength();

        $this->assertFalse($validator->isValid('1234567'));
        $this->assertTrue($validator->isValid('password'));
    }

    public function testConfiguredPolicy(): void
    {
        $validator = new PasswordStrength([
            'min' => 12,
            'uppercase' => true,
            'lowercase' => true,
            'number' => true,
            'symbols' => true,
        ]);

        $this->assertFalse($validator->isValid('Password1!'));
        $this->assertFalse($validator->isValid('password123!'));
        $this->assertFalse($validator->isValid('PASSWORD123!'));
        $this->assertFalse($validator->isValid('PasswordOnly!'));
        $this->assertFalse($validator->isValid('Password1234'));
        $this->assertTrue($validator->isValid('Password123!'));
        $this->assertTrue($validator->isValid('Password123€'));
    }

    public function testMinimumCanBeEight(): void
    {
        $validator = new PasswordStrength([
            'min' => 8,
        ]);

        $this->assertFalse($validator->isValid('1234567'));
        $this->assertTrue($validator->isValid('12345678'));
    }

    public function testAllowEmpty(): void
    {
        $validator = new PasswordStrength([
            'min' => 12,
            'uppercase' => true,
            'lowercase' => true,
            'number' => true,
            'symbols' => true,
        ], true);

        $this->assertTrue($validator->isValid(''));
    }
}
