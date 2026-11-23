<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator\PasswordPwned;

use Appwrite\Auth\Validator\PasswordPwned\Mock;
use Appwrite\Auth\Validator\PasswordPwned\None;
use PHPUnit\Framework\TestCase;

final class NoneTest extends TestCase
{
    public function testEveryPasswordIsAccepted(): void
    {
        $validator = new None();

        // Even the ones every other validator would reject
        foreach ([...Mock::BREACHED, 'password', '123456789', 'qwertyuiop'] as $password) {
            $this->assertTrue($validator->isValid($password), $password);
        }
    }

    public function testBasePasswordRulesStillApply(): void
    {
        $validator = new None();

        $this->assertFalse($validator->isValid('short'));
        $this->assertFalse($validator->isValid(\str_repeat('p', 257)));
        $this->assertFalse($validator->isValid(''));
    }

    public function testAllowEmptyAcceptsAnEmptyPassword(): void
    {
        $this->assertTrue((new None(allowEmpty: true))->isValid(''));
    }
}
