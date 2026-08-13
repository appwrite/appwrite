<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Client;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Envelope;
use Utopia\SMTP\Tests\Unit\Support\FakeTransport;

/**
 * Transparency, per RFC 5321 section 4.5.2. A dot that opens a line ends the
 * message, so every one of them has to be doubled — including the ones that
 * only open a line because of where a chunk boundary happened to fall.
 */
final class StuffingTest extends TestCase
{
    /**
     * @param  string|iterable<string>  $content
     */
    private function data(string|iterable $content): string
    {
        $transport = new FakeTransport([
            '220 mail.example.test',
            '250 mail.example.test',
            '250 Sender ok',
            '250 Recipient ok',
            '354 Go ahead',
            '250 Ok',
        ]);

        $client = new Client($transport, encryption: Encryption::None);
        $client->sendRaw(new Envelope('jane@example.test', ['john@example.test']), $content);

        return substr($transport->written, strpos($transport->written, "DATA\r\n") + 6);
    }

    public function testDoublesADotOpeningTheMessage(): void
    {
        $this->assertSame("..hidden\r\n.\r\n", $this->data('.hidden'));
    }

    public function testDoublesADotOpeningALine(): void
    {
        $this->assertSame("one\r\n..two\r\n.\r\n", $this->data("one\r\n.two"));
    }

    public function testLeavesADotInsideALineAlone(): void
    {
        $this->assertSame("version 1.2\r\n.\r\n", $this->data('version 1.2'));
    }

    public function testDoublesADotSplitFromItsLineEndingByAChunkBoundary(): void
    {
        $this->assertSame("one\r\n..two\r\n.\r\n", $this->data(["one\r\n", '.two']));
    }

    public function testDoublesADotWhenTheChunkBoundaryFallsInsideTheLineEnding(): void
    {
        $this->assertSame("one\r\n..two\r\n.\r\n", $this->data(["one\r", "\n.two"]));
    }

    public function testNormalisesBareLineFeeds(): void
    {
        $this->assertSame("one\r\ntwo\r\n.\r\n", $this->data("one\ntwo"));
    }

    public function testNormalisesALineEndingSplitAcrossChunks(): void
    {
        $this->assertSame("one\r\ntwo\r\n.\r\n", $this->data(["one\r", "\ntwo"]));
    }

    public function testDoesNotAddABlankLineWhenTheContentAlreadyEndsWithOne(): void
    {
        $this->assertSame("body\r\n.\r\n", $this->data("body\r\n"));
    }

    public function testTerminatesAnEmptyMessage(): void
    {
        $this->assertSame(".\r\n", $this->data(''));
    }

    public function testTerminatesAMessageEndingMidLineEnding(): void
    {
        $this->assertSame("body\r\n.\r\n", $this->data(['body', "\r"]));
    }

    public function testSkipsEmptyChunks(): void
    {
        $this->assertSame("onetwo\r\n.\r\n", $this->data(['one', '', 'two']));
    }
}
