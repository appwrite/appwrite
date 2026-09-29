<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Protocol\Writer;

final class WriterTest extends TestCase
{
    private Writer $writer;

    protected function setUp(): void
    {
        $this->writer = new Writer();
    }

    public function testConnect(): void
    {
        $result = $this->writer->connect(['verbose' => false, 'lang' => 'php']);
        $this->assertSame("CONNECT {\"verbose\":false,\"lang\":\"php\"}\r\n", $result);
    }

    public function testPub(): void
    {
        $result = $this->writer->pub('foo.bar', 'hello');
        $this->assertSame("PUB foo.bar 5\r\nhello\r\n", $result);
    }

    public function testPubEmpty(): void
    {
        $result = $this->writer->pub('foo', '');
        $this->assertSame("PUB foo 0\r\n\r\n", $result);
    }

    public function testPubWithReply(): void
    {
        $result = $this->writer->pub('foo', 'world', '_INBOX.abc');
        $this->assertSame("PUB foo _INBOX.abc 5\r\nworld\r\n", $result);
    }

    public function testHpub(): void
    {
        $headers = "NATS/1.0\r\nX-Key: value\r\n\r\n";
        $result = $this->writer->hpub('foo', $headers, 'hello');
        $headerLen = \strlen($headers);
        $totalLen = $headerLen + 5;
        $this->assertSame("HPUB foo {$headerLen} {$totalLen}\r\n{$headers}hello\r\n", $result);
    }

    public function testHpubWithReply(): void
    {
        $headers = "NATS/1.0\r\n\r\n";
        $result = $this->writer->hpub('foo', $headers, 'data', 'reply');
        $headerLen = \strlen($headers);
        $totalLen = $headerLen + 4;
        $this->assertSame("HPUB foo reply {$headerLen} {$totalLen}\r\n{$headers}data\r\n", $result);
    }

    public function testSub(): void
    {
        $result = $this->writer->sub('foo.>', '1');
        $this->assertSame("SUB foo.> 1\r\n", $result);
    }

    public function testSubWithQueue(): void
    {
        $result = $this->writer->sub('foo', '5', 'workers');
        $this->assertSame("SUB foo workers 5\r\n", $result);
    }

    public function testUnsub(): void
    {
        $result = $this->writer->unsub('3');
        $this->assertSame("UNSUB 3\r\n", $result);
    }

    public function testUnsubWithMax(): void
    {
        $result = $this->writer->unsub('3', 10);
        $this->assertSame("UNSUB 3 10\r\n", $result);
    }

    public function testPing(): void
    {
        $this->assertSame("PING\r\n", $this->writer->ping());
    }

    public function testPong(): void
    {
        $this->assertSame("PONG\r\n", $this->writer->pong());
    }
}
