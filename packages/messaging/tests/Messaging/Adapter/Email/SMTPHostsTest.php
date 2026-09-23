<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\SMTP\Encryption;

/**
 * Reading the host string, which decides where a message is even attempted.
 * No network: this is about what the string means.
 */
final class SMTPHostsTest extends TestCase
{
    /**
     * @return list<array{string, int, Encryption}>
     */
    private function hosts(SMTP $adapter): array
    {
        $hosts = new \ReflectionMethod(SMTP::class, 'hosts')->invoke($adapter);

        $this->assertIsArray($hosts);

        /** @var list<array{string, int, Encryption}> $hosts */
        return $hosts;
    }

    public function testOneHostWithTheDefaultPort(): void
    {
        $this->assertSame(
            [['smtp.example.com', 25, Encryption::None]],
            $this->hosts(new SMTP(host: 'smtp.example.com')),
        );
    }

    public function testAPortAfterTheHost(): void
    {
        $this->assertSame(
            [['smtp.example.com', 587, Encryption::None]],
            $this->hosts(new SMTP(host: 'smtp.example.com:587')),
        );
    }

    public function testEachEntryCarriesItsOwnPortAndEncryption(): void
    {
        $this->assertSame(
            [
                ['smtp1.example.com', 587, Encryption::StartTls],
                ['smtp2.example.com', 465, Encryption::Implicit],
                ['smtp3.example.com', 25, Encryption::None],
            ],
            $this->hosts(new SMTP(host: 'tls://smtp1.example.com:587;ssl://smtp2.example.com:465;smtp3.example.com')),
        );
    }

    public function testAnAddressLiteralKeepsItsColons(): void
    {
        // Splitting on the last colon would read this as the host ":" on port
        // 1, which is what PHPMailer's own pattern does.
        $this->assertSame(
            [['::1', 25, Encryption::None]],
            $this->hosts(new SMTP(host: '::1')),
        );
    }

    public function testABracketedAddressLiteralMayCarryAPort(): void
    {
        $this->assertSame(
            [['[::1]', 587, Encryption::None]],
            $this->hosts(new SMTP(host: '[::1]:587')),
        );
    }

    public function testABracketedAddressLiteralWithoutAPort(): void
    {
        $this->assertSame(
            [['[::1]', 25, Encryption::None]],
            $this->hosts(new SMTP(host: '[::1]')),
        );
    }

    public function testEmptyEntriesAreSkipped(): void
    {
        $this->assertSame(
            [['smtp.example.com', 25, Encryption::None]],
            $this->hosts(new SMTP(host: 'smtp.example.com;;')),
        );
    }

    public function testTheAutoTlsFlagDecidesWhenNoPrefixSaysSo(): void
    {
        $this->assertSame(
            [['smtp.example.com', 25, Encryption::Opportunistic]],
            $this->hosts(new SMTP(host: 'smtp.example.com', smtpAutoTLS: true)),
        );
    }
}
