<?php

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PasswordPolicy;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function testDefaultPolicy(): void
    {
        $validator = new PasswordPolicy();

        $this->assertFalse($validator->isValid('1234567'));
        $this->assertTrue($validator->isValid('password'));
    }

    public function testConfiguredPolicy(): void
    {
        $validator = new PasswordPolicy([
            'minLength' => 12,
            'requireUppercase' => true,
            'requireLowercase' => true,
            'requireNumber' => true,
            'requireSpecialChar' => true,
        ]);

        $this->assertFalse($validator->isValid('Password1!'));
        $this->assertFalse($validator->isValid('password123!'));
        $this->assertFalse($validator->isValid('PASSWORD123!'));
        $this->assertFalse($validator->isValid('PasswordOnly!'));
        $this->assertFalse($validator->isValid('Password1234'));
        $this->assertTrue($validator->isValid('Password123!'));
        $this->assertTrue($validator->isValid('Password123€'));
    }

    public function testAllowEmpty(): void
    {
        $validator = new PasswordPolicy([
            'minLength' => 12,
            'requireUppercase' => true,
            'requireLowercase' => true,
            'requireNumber' => true,
            'requireSpecialChar' => true,
        ], true);

        $this->assertTrue($validator->isValid(''));
    }
}
