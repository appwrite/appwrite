<?php

namespace Utopia\Tests\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Validator\EmailLocal;

class EmailLocalTest extends TestCase
{
    public function test_valid_email_local(): void
    {
        $validator = new EmailLocal;

        $this->assertSame(true, $validator->isValid('test@example.com'));
        $this->assertSame(true, $validator->isValid('user.name+tag@example.com'));
        $this->assertSame(true, $validator->isValid('user-name@example.com'));
        $this->assertSame(true, $validator->isValid('user_name@example.com'));
        $this->assertSame(true, $validator->isValid('user123@example.com'));
        $this->assertSame(true, $validator->isValid('user.name.last@example.com'));
    }

    public function test_invalid_email_local(): void
    {
        $validator = new EmailLocal;

        $this->assertSame(false, $validator->isValid(''));
        $this->assertSame(false, $validator->isValid('invalid-email'));
        $this->assertSame(false, $validator->isValid('user..name@example.com'));
        $this->assertSame(false, $validator->isValid('.user@example.com'));
        $this->assertSame(false, $validator->isValid('user.@example.com'));
        $this->assertSame(false, $validator->isValid('user!@example.com'));
    }

    public function test_non_string_input(): void
    {
        $validator = new EmailLocal;

        $this->assertSame(false, $validator->isValid(null));
        $this->assertSame(false, $validator->isValid(123));
        $this->assertSame(false, $validator->isValid([]));
        $this->assertSame(false, $validator->isValid(new \stdClass));
        $this->assertSame(false, $validator->isValid(true));
        $this->assertSame(false, $validator->isValid(false));
    }

    public function test_validatordescription(): void
    {
        $validator = new EmailLocal;

        $this->assertSame('Value must be a valid email address with a valid local part', $validator->getDescription());
    }

    public function test_validatortype(): void
    {
        $validator = new EmailLocal;

        $this->assertSame('string', $validator->getType());
    }

    public function test_validator_is_array(): void
    {
        $validator = new EmailLocal;

        $this->assertSame(false, $validator->isArray());
    }
}
