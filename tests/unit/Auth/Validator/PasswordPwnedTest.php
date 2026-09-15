<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PasswordPwned\Mock;
use PHPUnit\Framework\TestCase;

/**
 * The policy decisions every breach validator shares, exercised through the one
 * subclass that needs no service behind it.
 */
final class PasswordPwnedTest extends TestCase
{
    private const BREACHED = Mock::BREACHED[0];

    public function testEnabledByDefaultWithoutSessionChecks(): void
    {
        $validator = new Mock();

        $this->assertTrue($validator->isEnabled());
        $this->assertFalse($validator->checksSessions());
        $this->assertFalse($validator->blocksUsers());
    }

    public function testPolicyFlags(): void
    {
        $validator = new Mock(['enabled' => true, 'sessions' => true, 'users' => true]);

        $this->assertTrue($validator->isEnabled());
        $this->assertTrue($validator->checksSessions());
        $this->assertTrue($validator->blocksUsers());
    }

    public function testSessionChecksNeedAnEnabledPolicy(): void
    {
        $validator = new Mock(['enabled' => false, 'sessions' => true, 'users' => true]);

        $this->assertFalse($validator->checksSessions());
    }

    public function testBreachedPasswordIsInvalid(): void
    {
        $validator = new Mock();

        $this->assertFalse($validator->isValid(self::BREACHED));
        $this->assertTrue($validator->isValid('a-password-nobody-leaked'));
    }

    public function testDisabledPolicyAcceptsEverything(): void
    {
        $validator = new Mock(['enabled' => false]);

        $this->assertTrue($validator->isValid(self::BREACHED));
    }

    public function testBasePasswordRulesStillApply(): void
    {
        $validator = new Mock();

        $this->assertFalse($validator->isValid('short'));
        $this->assertFalse($validator->isValid(\str_repeat('p', 257)));
        $this->assertFalse($validator->isValid(''));
    }

    public function testAllowEmptyAcceptsAnEmptyPassword(): void
    {
        $this->assertTrue((new Mock([], allowEmpty: true))->isValid(''));
        $this->assertFalse((new Mock([], allowEmpty: true))->isValid(self::BREACHED));
    }
}
