<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Address;
use Utopia\SMTP\Attachment;
use Utopia\SMTP\Message;

final class MessageTest extends TestCase
{
    /**
     * @param  list<Attachment>  $attachments
     * @param  list<Address>  $bcc
     * @param  array<string, string>  $headers
     */
    private function message(
        ?string $text = 'Body',
        ?string $html = null,
        array $attachments = [],
        string $subject = 'Hello',
        array $bcc = [],
        array $headers = [],
    ): Message {
        return new Message(
            from: new Address('jane@example.test', 'Jane Doe'),
            to: [new Address('john@example.test')],
            subject: $subject,
            text: $text,
            html: $html,
            bcc: $bcc,
            attachments: $attachments,
            headers: $headers,
            date: new \DateTimeImmutable('2026-08-13 09:30:00', new \DateTimeZone('UTC')),
            messageId: 'fixed@example.test',
        );
    }

    public function testRendersTheHeaderBlock(): void
    {
        $head = explode("\r\n\r\n", (string) $this->message(), 2)[0];

        $this->assertStringContainsString('Date: Thu, 13 Aug 2026 09:30:00 +0000', $head);
        $this->assertStringContainsString('From: "Jane Doe" <jane@example.test>', $head);
        $this->assertStringContainsString('To: <john@example.test>', $head);
        $this->assertStringContainsString('Subject: Hello', $head);
        $this->assertStringContainsString('Message-ID: <fixed@example.test>', $head);
        $this->assertStringContainsString('MIME-Version: 1.0', $head);
    }

    public function testBlindRecipientsNeverReachTheHeaders(): void
    {
        $rendered = (string) $this->message(bcc: [new Address('eve@example.test')]);

        $this->assertStringNotContainsString('eve@example.test', $rendered);
        $this->assertStringNotContainsString('Bcc', $rendered);
    }

    public function testOmitsEmptyHeaders(): void
    {
        $this->assertStringNotContainsString('Cc:', (string) $this->message());
    }

    public function testCarriesCustomHeaders(): void
    {
        $this->assertStringContainsString(
            'X-Mailer: Utopia',
            (string) $this->message(headers: ['X-Mailer' => 'Utopia']),
        );
    }

    public function testNonAsciiSubjectBecomesAnEncodedWord(): void
    {
        $this->assertStringContainsString(
            'Subject: =?UTF-8?B?SGVsbMO2?=',
            (string) $this->message(subject: 'Hellö'),
        );
    }

    public function testTextAloneIsASinglePart(): void
    {
        $rendered = (string) $this->message();

        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $rendered);
        $this->assertStringNotContainsString('multipart', $rendered);
    }

    public function testMarkupAloneIsASinglePart(): void
    {
        $rendered = (string) $this->message(text: null, html: '<p>Body</p>');

        $this->assertStringContainsString('Content-Type: text/html; charset=utf-8', $rendered);
        $this->assertStringNotContainsString('multipart', $rendered);
    }

    public function testBothBodiesBecomeAnAlternative(): void
    {
        $rendered = (string) $this->message(html: '<p>Body</p>');

        $this->assertStringContainsString('Content-Type: multipart/alternative;', $rendered);
        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $rendered);
        $this->assertStringContainsString('Content-Type: text/html; charset=utf-8', $rendered);
    }

    public function testAnAttachmentWrapsTheBodyInAMixedPart(): void
    {
        $rendered = (string) $this->message(
            attachments: [Attachment::fromString('hello', 'notes.txt', 'text/plain')],
        );

        $this->assertStringContainsString('Content-Type: multipart/mixed;', $rendered);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="notes.txt"', $rendered);
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $rendered);
        $this->assertStringContainsString(base64_encode('hello'), $rendered);
    }

    public function testAnInlineAttachmentWrapsTheBodyInARelatedPart(): void
    {
        $rendered = (string) $this->message(
            html: '<img src="cid:logo">',
            attachments: [Attachment::fromString('binary', 'logo.png', 'image/png')->inline('logo')],
        );

        $this->assertStringContainsString('Content-Type: multipart/related;', $rendered);
        $this->assertStringContainsString('Content-ID: <logo>', $rendered);
        $this->assertStringContainsString('Content-Disposition: inline; filename="logo.png"', $rendered);
        $this->assertStringNotContainsString('multipart/mixed', $rendered);
    }

    public function testBothKindsOfAttachmentNestRelatedInsideMixed(): void
    {
        $rendered = (string) $this->message(
            html: '<img src="cid:logo">',
            attachments: [
                Attachment::fromString('binary', 'logo.png', 'image/png')->inline('logo'),
                Attachment::fromString('hello', 'notes.txt', 'text/plain'),
            ],
        );

        $this->assertLessThan(
            strpos($rendered, 'multipart/related'),
            strpos($rendered, 'multipart/mixed'),
            'mixed has to be the outer wrapper',
        );
    }

    public function testANonAsciiFilenameUsesTheParameterEncodingOfRfc2231(): void
    {
        $this->assertStringContainsString(
            "filename*=UTF-8''r%C3%A9sum%C3%A9.txt",
            (string) $this->message(attachments: [Attachment::fromString('x', 'résumé.txt')]),
        );
    }

    public function testStreamingAndRenderingAgree(): void
    {
        $message = $this->message(html: '<p>Body</p>', attachments: [Attachment::fromString('hello', 'notes.txt')]);

        $this->assertSame((string) $message, implode('', iterator_to_array($message->toIterable(), false)));
    }

    public function testDetectsAnInternationalAddress(): void
    {
        $this->assertFalse($this->message()->isInternational());

        $message = new Message(
            from: new Address('jäne@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Hello',
            text: 'Body',
        );

        $this->assertTrue($message->isInternational());
    }

    public function testRejectsAMessageWithNoRecipients(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Message(new Address('jane@example.test'), [], 'Hello', 'Body');
    }

    public function testRejectsAHeaderInjectedThroughTheSubject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->message(subject: "Hello\r\nBcc: eve@example.test");
    }

    public function testRejectsACustomHeaderTheMessageOwns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->message(headers: ['Bcc' => 'eve@example.test']);
    }

    public function testRejectsAHeaderInjectedThroughACustomHeader(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->message(headers: ['X-Mailer' => "Utopia\r\nBcc: eve@example.test"]);
    }
}
