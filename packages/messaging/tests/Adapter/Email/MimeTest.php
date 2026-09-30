<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\Mime;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Messages\Email\Attachment;

/**
 * The bytes handed to SES, which the SMTP adapter also sends.
 *
 * SES takes a raw message and reports nothing back about how it read it, so
 * what this produces is worth stating outright rather than discovering from a
 * bounce.
 */
final class MimeTest extends TestCase
{
    /**
     * @param  list<Attachment>  $attachments
     * @param  array<array<string, string>>  $cc
     * @param  array<string, string>  $headers
     */
    private function email(
        string $content = 'Plain body',
        bool $html = false,
        array $attachments = [],
        array $cc = [],
        string $subject = 'Test Subject',
        array $headers = [],
    ): Email {
        return new Email(
            to: [['email' => 'john@example.test', 'name' => 'John Doe']],
            subject: $subject,
            content: $content,
            fromName: 'Jane Doe',
            fromEmail: 'jane@example.test',
            cc: $cc === [] ? null : $cc,
            attachments: $attachments === [] ? null : $attachments,
            html: $html,
            headers: $headers,
        );
    }

    private function render(Email $email): string
    {
        return (string) Mime::message($email, $email->getTo(), $email->getCC() ?? []);
    }

    public function testCarriesTheAddressesAndSubject(): void
    {
        $rendered = $this->render($this->email(cc: [['email' => 'ada@example.test']]));

        $this->assertStringContainsString('From: "Jane Doe" <jane@example.test>', $rendered);
        $this->assertStringContainsString('To: "John Doe" <john@example.test>', $rendered);
        $this->assertStringContainsString('Cc: <ada@example.test>', $rendered);
        $this->assertStringContainsString('Subject: Test Subject', $rendered);
        $this->assertStringContainsString('MIME-Version: 1.0', $rendered);
    }

    public function testPlainTextIsASinglePart(): void
    {
        $rendered = $this->render($this->email());

        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $rendered);
        $this->assertStringNotContainsString('multipart', $rendered);
    }

    public function testMarkupBringsAPlainAlternativeWithIt(): void
    {
        // A client that will not render markup still has something to read, and
        // the contents of a style block are not it.
        $rendered = $this->render($this->email(
            content: '<style>p{color:red}</style><p>Hello <b>world</b></p>',
            html: true,
        ));

        $this->assertStringContainsString('Content-Type: multipart/alternative;', $rendered);
        $this->assertStringContainsString('Content-Type: text/html; charset=utf-8', $rendered);

        [$plain, $markup] = \array_slice(explode('Content-Type: text/', $rendered), 1);

        $this->assertStringContainsString('Hello world', quoted_printable_decode($plain));
        $this->assertStringNotContainsString('color:red', quoted_printable_decode($plain));
        $this->assertStringContainsString('color:red', quoted_printable_decode($markup));
    }

    public function testAnAttachmentIsWrappedAroundTheBody(): void
    {
        $rendered = $this->render($this->email(
            attachments: [new Attachment(name: 'notes.txt', path: '', type: 'text/plain', content: 'the notes')],
        ));

        $this->assertStringContainsString('Content-Type: multipart/mixed;', $rendered);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="notes.txt"', $rendered);
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $rendered);
        $this->assertStringContainsString(base64_encode('the notes'), $rendered);
    }

    public function testANonAsciiSubjectIsEncoded(): void
    {
        $rendered = $this->render($this->email(subject: 'Quarterly résumé'));

        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Quarterly résumé'), $rendered);
    }

    public function testNoLineIsLongerThanTheStandardAllows(): void
    {
        $rendered = $this->render($this->email(
            content: str_repeat('a long line of text that will need breaking up ', 40),
            attachments: [new Attachment(name: 'notes.txt', path: '', type: 'text/plain', content: random_bytes(4096))],
        ));

        foreach (explode("\r\n", $rendered) as $line) {
            // RFC 5322 section 2.1.1, and RFC 5321 section 4.5.3.1.6 is stricter.
            $this->assertLessThanOrEqual(998, \strlen($line));
        }
    }

    public function testBlindRecipientsNeverReachTheHeaders(): void
    {
        $email = new Email(
            to: [['email' => 'john@example.test']],
            subject: 'Test Subject',
            content: 'Plain body',
            fromName: 'Jane Doe',
            fromEmail: 'jane@example.test',
            bcc: [['email' => 'eve@example.test']],
        );

        $rendered = (string) Mime::message($email, $email->getTo(), [], $email->getBCC() ?? []);

        $this->assertStringNotContainsString('eve@example.test', $rendered);
        $this->assertStringNotContainsString('Bcc', $rendered);
    }

    public function testWeighsAttachmentsBeforeTheyAreEncoded(): void
    {
        $this->assertSame(0, Mime::size($this->email()));
        $this->assertSame(9, Mime::size($this->email(
            attachments: [new Attachment(name: 'notes.txt', path: '', type: 'text/plain', content: 'the notes')],
        )));
    }

    public function testCarriesTheMessageHeaders(): void
    {
        $rendered = $this->render($this->email(headers: [
            'List-Unsubscribe' => '<https://example.test/u?token=abc>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]));

        $this->assertStringContainsString("List-Unsubscribe: <https://example.test/u?token=abc>\r\n", $rendered);
        $this->assertStringContainsString("List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n", $rendered);
    }

    public function testAdapterHeadersWinOverMessageHeadersInAnyCase(): void
    {
        $rendered = (string) Mime::message(
            $this->email(headers: ['x-mailer' => 'Caller']),
            [['email' => 'john@example.test']],
            headers: ['X-Mailer' => 'Adapter'],
        );

        $this->assertStringContainsString("X-Mailer: Adapter\r\n", $rendered);
        $this->assertStringNotContainsString('Caller', $rendered);
    }
}
