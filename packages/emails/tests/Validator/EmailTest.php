<?php

namespace Utopia\Tests\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Validator\Email;

class EmailTest extends TestCase
{
    public function test_validemail(): void
    {
        $validator = new Email;

        $this->assertSame(true, $validator->isValid('test@example.com'));
        $this->assertSame(true, $validator->isValid('user.name+tag@example.com'));
        $this->assertSame(true, $validator->isValid('user-name@example-domain.com'));
        $this->assertSame(true, $validator->isValid('user_name@example.com'));
        $this->assertSame(true, $validator->isValid('user123@example123.com'));
        $this->assertSame(true, $validator->isValid('user.name.last@example.com'));
        $this->assertSame(true, $validator->isValid('user@mail.example.com'));
        $this->assertSame(true, $validator->isValid('user@mail.sub.example.com'));
    }

    public function test_invalidemail(): void
    {
        $validator = new Email;

        $this->assertSame(false, $validator->isValid(''));
        $this->assertSame(false, $validator->isValid('invalid-email'));
        $this->assertSame(false, $validator->isValid('user@example@com'));
        $this->assertSame(false, $validator->isValid('@example.com'));
        $this->assertSame(false, $validator->isValid('user@'));
        $this->assertSame(false, $validator->isValid('user..name@example.com'));
        $this->assertSame(false, $validator->isValid('.user@example.com'));
        $this->assertSame(false, $validator->isValid('user.@example.com'));
        $this->assertSame(false, $validator->isValid('user@example..com'));
        // filter_var allows consecutive hyphens, so this will be valid
        $this->assertSame(true, $validator->isValid('user@example--com.com'));
        $this->assertSame(false, $validator->isValid('user@.example.com'));
        $this->assertSame(false, $validator->isValid('user@example.com.'));
        $this->assertSame(false, $validator->isValid('user@-example.com'));
        $this->assertSame(false, $validator->isValid('user@example-.com'));
        $this->assertSame(false, $validator->isValid('user@example'));
        $this->assertSame(false, $validator->isValid('user@example!.com'));
        // filter_var allows exclamation marks in local part, so this will be valid
        $this->assertSame(true, $validator->isValid('user!@example.com'));
    }

    public function test_non_string_input(): void
    {
        $validator = new Email;

        $this->assertSame(false, $validator->isValid(null));
        $this->assertSame(false, $validator->isValid(123));
        $this->assertSame(false, $validator->isValid([]));
        $this->assertSame(false, $validator->isValid(new \stdClass));
        $this->assertSame(false, $validator->isValid(true));
        $this->assertSame(false, $validator->isValid(false));
    }

    public function test_validatordescription(): void
    {
        $validator = new Email;

        $this->assertSame('Value must be a valid email address', $validator->getDescription());
    }

    public function test_validatortype(): void
    {
        $validator = new Email;

        $this->assertSame('string', $validator->getType());
    }

    public function test_validator_is_array(): void
    {
        $validator = new Email;

        $this->assertSame(false, $validator->isArray());
    }

    public function test_allow_empty_disabled(): void
    {
        $validator = new Email(false);

        $this->assertSame(false, $validator->isValid(''));
        $this->assertSame(true, $validator->isValid('test@example.com'));
    }

    public function test_allow_empty_enabled(): void
    {
        $validator = new Email(true);

        $this->assertSame(true, $validator->isValid(''));
        $this->assertSame(true, $validator->isValid('test@example.com'));
        $this->assertSame(false, $validator->isValid('invalid-email'));
    }

    public function test_allow_empty_default_behavior(): void
    {
        $validator = new Email;

        $this->assertSame(false, $validator->isValid(''));
        $this->assertSame(true, $validator->isValid('test@example.com'));
    }
}
