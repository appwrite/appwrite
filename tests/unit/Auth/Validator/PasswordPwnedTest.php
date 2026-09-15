<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Pwned;
use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;

final class PasswordPwnedTest extends TestCase
{
    private const PASSWORD = 'Password123!';

    public function testEnabledByDefaultWithoutSessionChecks(): void
    {
        $validator = new PasswordPwned(new FakePwned());

        $this->assertTrue($validator->isEnabled());
        $this->assertFalse($validator->checksSessions());
        $this->assertFalse($validator->blocksUsers());
    }

    public function testPolicyFlags(): void
    {
        $validator = new PasswordPwned(new FakePwned(), ['enabled' => true, 'sessions' => true, 'users' => true]);

        $this->assertTrue($validator->isEnabled());
        $this->assertTrue($validator->checksSessions());
        $this->assertTrue($validator->blocksUsers());
    }

    public function testSessionChecksNeedAnEnabledPolicy(): void
    {
        $validator = new PasswordPwned(new FakePwned(), ['enabled' => false, 'sessions' => true, 'users' => true]);

        $this->assertFalse($validator->checksSessions());
    }

    public function testDisabledPolicyNeverAsksTheAdapter(): void
    {
        $adapter = new FakePwned(pwned: true);
        $validator = new PasswordPwned($adapter, ['enabled' => false]);

        $this->assertNull($validator->check(self::PASSWORD));
        $this->assertTrue($validator->isValid(self::PASSWORD));
        $this->assertSame(0, $adapter->calls);
    }

    public function testCheckReportsWhatTheAdapterFound(): void
    {
        $this->assertTrue((new PasswordPwned(new FakePwned(pwned: true)))->check(self::PASSWORD));
        $this->assertFalse((new PasswordPwned(new FakePwned(pwned: false)))->check(self::PASSWORD));
    }

    public function testBreachedPasswordIsInvalid(): void
    {
        $this->assertFalse((new PasswordPwned(new FakePwned(pwned: true)))->isValid(self::PASSWORD));
        $this->assertTrue((new PasswordPwned(new FakePwned(pwned: false)))->isValid(self::PASSWORD));
    }

    public function testAdapterFailureSurfaces(): void
    {
        $adapter = new FakePwned(failure: new Exception(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE));
        $validator = new PasswordPwned($adapter);

        $this->expectException(Exception::class);

        $validator->isValid(self::PASSWORD);
    }

    public function testBasePasswordRulesStillApply(): void
    {
        $adapter = new FakePwned(pwned: false);
        $validator = new PasswordPwned($adapter);

        $this->assertFalse($validator->isValid('short'));
        $this->assertFalse($validator->isValid(\str_repeat('p', 257)));
        $this->assertFalse($validator->isValid(''));
        $this->assertSame(0, $adapter->calls);
    }

    public function testAllowEmptySkipsTheLookup(): void
    {
        $adapter = new FakePwned(pwned: true);
        $validator = new PasswordPwned($adapter, [], allowEmpty: true);

        $this->assertTrue($validator->isValid(''));
        $this->assertSame(0, $adapter->calls);
    }
}

final class FakePwned extends Pwned
{
    public int $calls = 0;

    public function __construct(private bool $pwned = false, private ?\Throwable $failure = null)
    {
        parent::__construct('https://breaches.test');
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function isPwned(string $password): bool
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->pwned;
    }
}
