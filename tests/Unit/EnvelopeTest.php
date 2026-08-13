<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Address;
use Utopia\SMTP\Envelope;
use Utopia\SMTP\Message;

final class EnvelopeTest extends TestCase
{
    public function testCarriesBlindRecipients(): void
    {
        $envelope = Envelope::fromMessage(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Hello',
            text: 'Body',
            cc: [new Address('ada@example.test')],
            bcc: [new Address('eve@example.test')],
        ));

        $this->assertSame('jane@example.test', $envelope->sender);
        $this->assertSame(
            ['john@example.test', 'ada@example.test', 'eve@example.test'],
            $envelope->recipients,
        );
    }

    public function testAnAddressListedTwiceIsSentOnce(): void
    {
        $envelope = Envelope::fromMessage(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Hello',
            text: 'Body',
            bcc: [new Address('john@example.test')],
        ));

        $this->assertSame(['john@example.test'], $envelope->recipients);
    }

    public function testAnEmptySenderIsAllowedForBounces(): void
    {
        $this->assertSame('', (new Envelope('', ['john@example.test']))->sender);
    }

    public function testRejectsAnEnvelopeWithNoRecipients(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Envelope('jane@example.test', []);
    }

    public function testRejectsACommandInjectedThroughAPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Envelope('jane@example.test', ["john@example.test>\r\nRCPT TO:<eve@example.test"]);
    }

    public function testDetectsAnInternationalPath(): void
    {
        $this->assertTrue((new Envelope('jäne@example.test', ['john@example.test']))->isInternational());
        $this->assertFalse((new Envelope('jane@example.test', ['john@example.test']))->isInternational());
    }
}
