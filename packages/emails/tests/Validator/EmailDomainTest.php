<?php

namespace Utopia\Tests\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Validator\EmailDomain;

class EmailDomainTest extends TestCase
{
    public function test_valid_email_domain(): void
    {
        $validator = new EmailDomain;

        $this->assertSame(true, $validator->isValid('test@example.com'));
        $this->assertSame(true, $validator->isValid('user@mail.example.com'));
        $this->assertSame(true, $validator->isValid('user@mail.sub.example.com'));
        $this->assertSame(true, $validator->isValid('user@example-domain.com'));
        $this->assertSame(true, $validator->isValid('user@example123.com'));
    }

    public function test_invalid_email_domain(): void
    {
        $validator = new EmailDomain;

        $this->assertSame(false, $validator->isValid(''));
        $this->assertSame(false, $validator->isValid('invalid-email'));
        $this->assertSame(false, $validator->isValid('user@example..com'));
        // filter_var allows consecutive hyphens, so this will be valid
        $this->assertSame(true, $validator->isValid('user@example--com.com'));
        $this->assertSame(false, $validator->isValid('user@.example.com'));
        $this->assertSame(false, $validator->isValid('user@example.com.'));
        $this->assertSame(false, $validator->isValid('user@-example.com'));
        $this->assertSame(false, $validator->isValid('user@example-.com'));
        $this->assertSame(false, $validator->isValid('user@example'));
        $this->assertSame(false, $validator->isValid('user@example!.com'));
    }

    public function test_non_string_input(): void
    {
        $validator = new EmailDomain;

        $this->assertSame(false, $validator->isValid(null));
        $this->assertSame(false, $validator->isValid(123));
        $this->assertSame(false, $validator->isValid([]));
        $this->assertSame(false, $validator->isValid(new \stdClass));
        $this->assertSame(false, $validator->isValid(true));
        $this->assertSame(false, $validator->isValid(false));
    }

    public function test_validatordescription(): void
    {
        $validator = new EmailDomain;

        $this->assertSame('Value must be a valid email address with a valid domain', $validator->getDescription());
    }

    public function test_validatortype(): void
    {
        $validator = new EmailDomain;

        $this->assertSame('string', $validator->getType());
    }

    public function test_validator_is_array(): void
    {
        $validator = new EmailDomain;

        $this->assertSame(false, $validator->isArray());
    }
}
