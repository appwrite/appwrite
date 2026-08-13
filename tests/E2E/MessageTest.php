<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\E2E;

use Utopia\SMTP\Address;
use Utopia\SMTP\Attachment;
use Utopia\SMTP\Message;
use Utopia\SMTP\Tests\E2E\Support\Server;

/**
 * What we write, as a real parser reads it back.
 *
 * Rendering can be asserted without a server, but folding, encoded words and
 * the MIME tree are claims about how somebody else will interpret the bytes,
 * and only somebody else can settle those.
 */
final class MessageTest extends Server
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    private function file(string $contents): string
    {
        $path = sys_get_temp_dir() . '/utopia-smtp-' . bin2hex(random_bytes(8)) . '.bin';
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @return array<mixed>
     */
    private function deliver(Message $message): array
    {
        $client = $this->client();
        $client->send($message);
        $client->close();

        return $this->delivered();
    }

    public function testAFoldedSubjectComesBackWhole(): void
    {
        // Long enough to fold and accented enough to need encoded words, so the
        // parser has to undo both to get the original back.
        $subject = 'Rapport trimestriel très détaillé sur les résultats du troisième trimestre';

        $delivered = $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: $subject,
            text: 'Body',
        ));

        $this->assertSame($subject, $this->field($delivered, 'Subject'));
    }

    public function testALongRecipientListSurvivesFolding(): void
    {
        $recipients = [];

        for ($index = 0; $index < 25; ++$index) {
            $recipients[] = new Address("recipient{$index}@example.test", "Recipient Number {$index}");
        }

        $delivered = $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: $recipients,
            subject: 'Crowd',
            text: 'Body',
        ));

        // A To field this long has to be folded to stay inside the line limits.
        // Every address still has to come back, in order, with its name intact.
        $to = $this->listOf($delivered, 'To');
        $this->assertCount(25, $to);
        $this->assertSame('recipient0@example.test', $this->field($this->entry($delivered, 'To'), 'Address'));
        $this->assertSame('Recipient Number 0', $this->field($this->entry($delivered, 'To'), 'Name'));
        $this->assertSame('Recipient Number 24', $this->field($this->entry($delivered, 'To', 24), 'Name'));

        foreach (explode("\r\n", $this->source()) as $line) {
            $this->assertLessThanOrEqual(998, \strlen($line), 'RFC 5322 section 2.1.1');
        }
    }

    public function testANonAsciiDisplayNameDecodes(): void
    {
        $delivered = $this->deliver(new Message(
            from: new Address('jane@example.test', 'Jäne Døe'),
            to: [new Address('john@example.test')],
            subject: 'Accents',
            text: 'Body',
        ));

        $this->assertSame('Jäne Døe', $this->field($this->listOf($delivered, 'From'), 'Name'));
    }

    public function testANonAsciiBodyDecodes(): void
    {
        $body = "Grüße aus München — with an em dash and a ß.\r\nSecond line.";

        $delivered = $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Body',
            text: $body,
        ));

        $this->assertStringContainsString('Grüße aus München', $this->field($delivered, 'Text'));
        $this->assertStringContainsString('Second line.', $this->field($delivered, 'Text'));
    }

    public function testBothBodiesArriveAsAlternatives(): void
    {
        $delivered = $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Alternative',
            text: 'The plain one',
            html: '<p>The marked-up one</p>',
        ));

        $this->assertStringContainsString('The plain one', $this->field($delivered, 'Text'));
        $this->assertStringContainsString('The marked-up one', $this->field($delivered, 'HTML'));
    }

    public function testAnAttachmentArrivesByteForByte(): void
    {
        // Random bytes, so a base64 boundary handled wrongly cannot pass by
        // looking plausible.
        $contents = random_bytes(4096);

        $delivered = $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Binary',
            text: 'Body',
            attachments: [Attachment::fromString($contents, 'payload.bin', 'application/octet-stream')],
        ));

        $attachment = $this->entry($delivered, 'Attachments');
        $this->assertSame('payload.bin', $this->field($attachment, 'FileName'));
        $this->assertSame($contents, $this->part($this->field($attachment, 'PartID')));
    }

    public function testAFileFromDiskArrivesByteForByte(): void
    {
        // Comfortably more than one read block, so the streaming path is what
        // actually produced these bytes.
        $contents = random_bytes(200_000);
        $path = $this->file($contents);

        $delivered = $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Streamed',
            text: 'Body',
            attachments: [Attachment::fromPath($path, 'report.bin', 'application/octet-stream')],
        ));

        $attachment = $this->entry($delivered, 'Attachments');
        $this->assertSame(200_000, $this->number($attachment, 'Size'));
        $this->assertSame($contents, $this->part($this->field($attachment, 'PartID')));
    }

    public function testAnInlineAttachmentIsReportedAsInline(): void
    {
        $image = random_bytes(256);

        $delivered = $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Inline',
            text: 'Body',
            html: '<p><img src="cid:logo"></p>',
            attachments: [Attachment::fromString($image, 'logo.png', 'image/png')->inline('logo')],
        ));

        // The related tree and the Content-ID are what let the markup reach it.
        $inline = $this->entry($delivered, 'Inline');
        $this->assertSame('logo', $this->field($inline, 'ContentID'));
        $this->assertSame('logo.png', $this->field($inline, 'FileName'));
        $this->assertSame($image, $this->part($this->field($inline, 'PartID')));

        $this->assertCount(0, $this->listOf($delivered, 'Attachments'), 'inline is not an attachment');
    }

    public function testBothKindsOfAttachmentArriveTogether(): void
    {
        $delivered = $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Mixed and related',
            text: 'Body',
            html: '<p><img src="cid:logo"></p>',
            attachments: [
                Attachment::fromString('image bytes', 'logo.png', 'image/png')->inline('logo'),
                Attachment::fromString('the notes', 'notes.txt', 'text/plain'),
            ],
        ));

        $this->assertCount(1, $this->listOf($delivered, 'Inline'));
        $this->assertCount(1, $this->listOf($delivered, 'Attachments'));
        $this->assertSame('notes.txt', $this->field($this->entry($delivered, 'Attachments'), 'FileName'));
    }

    public function testANonAsciiFileNameDecodes(): void
    {
        $delivered = $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Named',
            text: 'Body',
            attachments: [Attachment::fromString('x', 'résumé.txt', 'text/plain')],
        ));

        $this->assertSame('résumé.txt', $this->field($this->entry($delivered, 'Attachments'), 'FileName'));
    }

    public function testACustomHeaderArrives(): void
    {
        $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Custom',
            text: 'Body',
            headers: ['X-Mailer' => 'Utopia SMTP'],
        ));

        $this->assertStringContainsString('X-Mailer: Utopia SMTP', $this->source());
    }

    public function testALineTooLongForTheProtocolIsWrapped(): void
    {
        // RFC 5321 section 4.5.3.1.6 caps a text line at 1000 octets. Nothing
        // stops a caller passing more than that on one line, so the transfer
        // encoding has to break it up.
        $this->deliver(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Long line',
            text: str_repeat('abcdefghij', 400),
        ));

        foreach (explode("\r\n", $this->source()) as $line) {
            $this->assertLessThanOrEqual(1000, \strlen($line));
        }
    }
}
