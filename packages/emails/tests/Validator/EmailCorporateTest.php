<?php

namespace Utopia\Tests\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Emails\Validator\EmailCorporate;

class EmailCorporateTest extends TestCase
{
    public function test_valid_corporate_email(): void
    {
        $validator = new EmailCorporate;

        $this->assertSame(true, $validator->isValid('test@company.com'));
        $this->assertSame(true, $validator->isValid('user@business.org'));
        $this->assertSame(true, $validator->isValid('user@enterprise.net'));
        $this->assertSame(true, $validator->isValid('user@corporation.co.uk'));
        $this->assertSame(true, $validator->isValid('user@organization.org'));
        $this->assertSame(true, $validator->isValid('user@firm.com'));
        $this->assertSame(true, $validator->isValid('user@office.net'));
        $this->assertSame(true, $validator->isValid('user@work.org'));
    }

    public function test_invalid_free_email(): void
    {
        $validator = new EmailCorporate;

        $this->assertSame(false, $validator->isValid('user@gmail.com'));
        $this->assertSame(false, $validator->isValid('user@yahoo.com'));
        $this->assertSame(false, $validator->isValid('user@hotmail.com'));
        $this->assertSame(false, $validator->isValid('user@outlook.com'));
        $this->assertSame(false, $validator->isValid('user@live.com'));
        $this->assertSame(false, $validator->isValid('user@aol.com'));
        $this->assertSame(false, $validator->isValid('user@icloud.com'));
        $this->assertSame(false, $validator->isValid('user@protonmail.com'));
        $this->assertSame(false, $validator->isValid('user@zoho.com'));
        $this->assertSame(false, $validator->isValid('user@yandex.com'));
        $this->assertSame(false, $validator->isValid('user@mail.com'));
        $this->assertSame(false, $validator->isValid('user@gmx.com'));
        $this->assertSame(false, $validator->isValid('user@web.de'));
        $this->assertSame(false, $validator->isValid('user@tutanota.com'));
        $this->assertSame(false, $validator->isValid('user@fastmail.com'));
        $this->assertSame(false, $validator->isValid('user@hey.com'));
    }

    public function test_invalid_disposable_email(): void
    {
        $validator = new EmailCorporate;

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
        // company.org is corporate
        $this->assertSame(true, $validator->isValid('user@company.org'));
        $this->assertSame(true, $validator->isValid('user@business.org'));
        $this->assertSame(true, $validator->isValid('user@enterprise.net'));
    }

    public function test_invalid_email_format(): void
    {
        $validator = new EmailCorporate;

        $this->assertSame(false, $validator->isValid(''));
        $this->assertSame(false, $validator->isValid('invalid-email'));
        $this->assertSame(false, $validator->isValid('user@example@com'));
        $this->assertSame(false, $validator->isValid('@example.com'));
        $this->assertSame(false, $validator->isValid('user@'));
    }

    public function test_non_string_input(): void
    {
        $validator = new EmailCorporate;

        $this->assertSame(false, $validator->isValid(null));
        $this->assertSame(false, $validator->isValid(123));
        $this->assertSame(false, $validator->isValid([]));
        $this->assertSame(false, $validator->isValid(new \stdClass));
        $this->assertSame(false, $validator->isValid(true));
        $this->assertSame(false, $validator->isValid(false));
    }

    public function test_validatordescription(): void
    {
        $validator = new EmailCorporate;

        $this->assertSame('Value must be a valid email address from a corporate domain', $validator->getDescription());
    }

    public function test_validatortype(): void
    {
        $validator = new EmailCorporate;

        $this->assertSame('string', $validator->getType());
    }

    public function test_validator_is_array(): void
    {
        $validator = new EmailCorporate;

        $this->assertSame(false, $validator->isArray());
    }
}
