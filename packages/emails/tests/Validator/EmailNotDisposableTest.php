<?php

namespace Utopia\Tests\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Validator\EmailNotDisposable;

class EmailNotDisposableTest extends TestCase
{
    public function test_valid_non_disposable_email(): void
    {
        $validator = new EmailNotDisposable;

        $this->assertSame(true, $validator->isValid('test@company.org'));
        $this->assertSame(true, $validator->isValid('user@gmail.com'));
        $this->assertSame(true, $validator->isValid('user@yahoo.com'));
        $this->assertSame(true, $validator->isValid('user@company.com'));
        $this->assertSame(true, $validator->isValid('user@business.org'));
    }

    public function test_invalid_disposable_email(): void
    {
        $validator = new EmailNotDisposable;

        $this->assertSame(false, $validator->isValid('user@10minutemail.com'));
        $this->assertSame(false, $validator->isValid('user@tempmail.org'));
        $this->assertSame(false, $validator->isValid('user@guerrillamail.com'));
        $this->assertSame(false, $validator->isValid('user@mailinator.com'));
        $this->assertSame(false, $validator->isValid('user@yopmail.com'));
        $this->assertSame(false, $validator->isValid('user@temp-mail.org'));
        $this->assertSame(false, $validator->isValid('user@throwaway.email'));
        $this->assertSame(false, $validator->isValid('user@getnada.com'));
        $this->assertSame(false, $validator->isValid('user@maildrop.cc'));
        $this->assertSame(false, $validator->isValid('user@sharklasers.com'));
        $this->assertSame(false, $validator->isValid('user@test.com'));
        // company.org is not disposable
        $this->assertSame(true, $validator->isValid('user@company.org'));
        $this->assertSame(true, $validator->isValid('user@business.org'));
        $this->assertSame(true, $validator->isValid('user@enterprise.net'));
    }

    public function test_invalid_email_format(): void
    {
        $validator = new EmailNotDisposable;

        $this->assertSame(false, $validator->isValid(''));
        $this->assertSame(false, $validator->isValid('invalid-email'));
        $this->assertSame(false, $validator->isValid('user@example@com'));
        $this->assertSame(false, $validator->isValid('@example.com'));
        $this->assertSame(false, $validator->isValid('user@'));
    }

    public function test_non_string_input(): void
    {
        $validator = new EmailNotDisposable;

        $this->assertSame(false, $validator->isValid(null));
        $this->assertSame(false, $validator->isValid(123));
        $this->assertSame(false, $validator->isValid([]));
        $this->assertSame(false, $validator->isValid(new \stdClass));
        $this->assertSame(false, $validator->isValid(true));
        $this->assertSame(false, $validator->isValid(false));
    }

    public function test_validatordescription(): void
    {
        $validator = new EmailNotDisposable;

        $this->assertSame('Value must be a valid email address that is not from a disposable email service', $validator->getDescription());
    }

    public function test_validatortype(): void
    {
        $validator = new EmailNotDisposable;

        $this->assertSame('string', $validator->getType());
    }

    public function test_validator_is_array(): void
    {
        $validator = new EmailNotDisposable;

        $this->assertSame(false, $validator->isArray());
    }
}
