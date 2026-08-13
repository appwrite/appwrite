<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Address;
use Utopia\SMTP\Exception;

final class AddressTest extends TestCase
{
    public function testBareAddress(): void
    {
        $this->assertSame('<jane@example.test>', (string) new Address('jane@example.test'));
    }

    public function testDisplayNameIsQuoted(): void
    {
        $this->assertSame(
            '"Jane Doe" <jane@example.test>',
            (string) new Address('jane@example.test', 'Jane Doe'),
        );
    }

    public function testQuotesInsideADisplayNameAreEscaped(): void
    {
        $this->assertSame(
            '"Jane \\"JD\\" Doe" <jane@example.test>',
            (string) new Address('jane@example.test', 'Jane "JD" Doe'),
        );
    }

    public function testNonAsciiDisplayNameBecomesAnEncodedWord(): void
    {
        $this->assertSame(
            '=?UTF-8?B?SsOkbmU=?= <jane@example.test>',
            (string) new Address('jane@example.test', 'Jäne'),
        );
    }

    public function testAnAsciiAddressIsNotInternational(): void
    {
        $this->assertFalse((new Address('jane@example.test', 'Jäne'))->isInternational());
    }

    public function testANonAsciiLocalPartIsInternational(): void
    {
        $this->assertTrue((new Address('jäne@example.test'))->isInternational());
    }

    public function testRejectsAnAddressWithNoDomain(): void
    {
        $this->expectException(Exception::class);

        new Address('jane');
    }

    public function testRejectsAnAddressThatIsNotAnAddress(): void
    {
        $this->expectException(Exception::class);

        new Address('jane doe@example.test');
    }

    public function testRejectsALocalPartOverTheLimit(): void
    {
        $this->expectException(Exception::class);

        new Address(str_repeat('a', 65) . '@example.test');
    }

    public function testRejectsAHeaderInjectedThroughTheDisplayName(): void
    {
        $this->expectException(Exception::class);

        new Address('jane@example.test', "Jane\r\nBcc: eve@example.test");
    }

    public function testRejectsACommandInjectedThroughTheAddress(): void
    {
        $this->expectException(Exception::class);

        new Address("jane@example.test>\r\nRCPT TO:<eve@example.test");
    }
}
