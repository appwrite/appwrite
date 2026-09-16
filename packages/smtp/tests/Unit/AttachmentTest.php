<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Address;
use Utopia\SMTP\Attachment;
use Utopia\SMTP\Message;

final class AttachmentTest extends TestCase
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

    private function file(string $contents, string $suffix = '.bin'): string
    {
        $path = sys_get_temp_dir() . '/utopia-smtp-' . bin2hex(random_bytes(8)) . $suffix;
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    private function message(Attachment $attachment): string
    {
        return (string) new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Attached',
            text: 'Body',
            attachments: [$attachment],
        );
    }

    /**
     * Pull the base64 body of the last part back out of the rendered message.
     */
    private function decode(string $rendered): string
    {
        if (preg_match('/boundary="([^"]+)"/', $rendered, $matches) !== 1) {
            $this->fail('the message declares no boundary');
        }

        foreach (explode("--{$matches[1]}", $rendered) as $part) {
            if (! str_contains($part, 'Content-Transfer-Encoding: base64')) {
                continue;
            }

            $sections = explode("\r\n\r\n", $part, 2);
            $this->assertCount(2, $sections, 'the part has no body');

            $decoded = base64_decode(trim($sections[1]), true);

            $this->assertIsString($decoded, 'the part is not valid base64');

            return $decoded;
        }

        $this->fail('the message has no base64 part');
    }

    public function testCarriesContentHeldInMemory(): void
    {
        $rendered = $this->message(Attachment::fromString('the file body', 'notes.txt', 'text/plain'));

        $this->assertSame('the file body', $this->decode($rendered));
    }

    public function testReadsAFileFromDisk(): void
    {
        $path = $this->file('read from disk');

        $this->assertSame('read from disk', $this->decode($this->message(Attachment::fromPath($path))));
    }

    /**
     * The file is read in blocks and each is encoded on its own. Base64 only
     * concatenates cleanly on three byte boundaries, so a block size that is
     * not a multiple of three corrupts everything after the first block — and
     * a file smaller than one block never shows it.
     */
    public function testReadsAFileSpanningManyBlocks(): void
    {
        $contents = random_bytes(64_000);
        $path = $this->file($contents);

        $this->assertSame($contents, $this->decode($this->message(Attachment::fromPath($path))));
    }

    public function testAnEmptyFileIsStillAPart(): void
    {
        $path = $this->file('');

        $this->assertSame('', $this->decode($this->message(Attachment::fromPath($path))));
    }

    public function testEncodedLinesStayWithinTheLimit(): void
    {
        $path = $this->file(random_bytes(10_000));
        $rendered = $this->message(Attachment::fromPath($path));

        foreach (explode("\r\n", $rendered) as $line) {
            // RFC 2045 section 6.8 caps an encoded line at 76 characters.
            $this->assertLessThanOrEqual(76, \strlen($line));
        }
    }

    public function testNameDefaultsToTheFileName(): void
    {
        $path = $this->file('x', '.pdf');

        $this->assertSame(basename($path), Attachment::fromPath($path)->name);
    }

    public function testNameAndTypeCanBeOverridden(): void
    {
        $attachment = Attachment::fromPath($this->file('x'), 'report.pdf', 'application/pdf');

        $this->assertSame('report.pdf', $attachment->name);
        $this->assertSame('application/pdf', $attachment->type);
    }

    public function testInlineKeepsEverythingButTheDisposition(): void
    {
        $attachment = Attachment::fromString('x', 'logo.png', 'image/png')->inline('logo');

        $this->assertSame('logo', $attachment->cid);
        $this->assertSame('logo.png', $attachment->name);
        $this->assertSame('image/png', $attachment->type);
    }

    public function testRefusesAFileItCannotRead(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Attachment::fromPath('/nonexistent/utopia-smtp/missing.txt');
    }

    public function testRefusesAHeaderInjectedThroughTheName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Attachment::fromString('x', "notes.txt\r\nContent-Type: text/evil");
    }

    public function testRefusesAHeaderInjectedThroughTheContentId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Attachment::fromString('x', 'logo.png')->inline("logo\r\nX-Evil: yes");
    }
}
