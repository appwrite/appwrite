<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator\PasswordPwned;

use Appwrite\Auth\Validator\PasswordPwned\Mock;
use PHPUnit\Framework\TestCase;

final class MockTest extends TestCase
{
    public function testFixturesAreReportedAsBreached(): void
    {
        $validator = new Mock();

        foreach (Mock::BREACHED as $password) {
            $this->assertFalse($validator->isValid($password), $password);
        }
    }

    public function testAnythingElseIsAccepted(): void
    {
        $validator = new Mock();

        $this->assertTrue($validator->isValid('some-other-password'));
    }

    public function testMatchingIsExact(): void
    {
        $validator = new Mock();
        $fixture = Mock::BREACHED[0];

        // A password that merely resembles a fixture is not breached
        $this->assertTrue($validator->isValid(\strtoupper($fixture)));
        $this->assertTrue($validator->isValid($fixture . '!'));
        $this->assertTrue($validator->isValid(\substr($fixture, 1)));
    }
}
