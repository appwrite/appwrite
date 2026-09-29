<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Protocol\Parser;
use Utopia\NATS\Protocol\ServerOp;
use Utopia\NATS\Transport\Transport;

final class ParserTest extends TestCase
{
    private function createParser(string $data): Parser
    {
        $transport = new class ($data) implements Transport {
            private int $pos = 0;

            public function __construct(private readonly string $data) {}

            public function connect(string $host, int $port, float $timeout): void {}

            public function write(string $data): int
            {
                return \strlen($data);
            }

            public function read(int $maxBytes, ?float $timeout = null): string
            {
                if ($this->pos >= \strlen($this->data)) {
                    return '';
                }
                $chunk = substr($this->data, $this->pos, $maxBytes);
                $this->pos += \strlen($chunk);
                return $chunk;
            }

            public function readLine(?float $timeout = null): string
            {
                $nlPos = strpos($this->data, "\n", $this->pos);
                if ($nlPos === false) {
                    return '';
                }
                $line = substr($this->data, $this->pos, $nlPos - $this->pos + 1);
                $this->pos = $nlPos + 1;
                return $line;
            }

            public function upgradeTls(array $options): void {}
            public function isConnected(): bool
            {
                return true;
            }
            public function close(): void {}
        };

        return new Parser($transport);
    }

    public function testParseInfo(): void
    {
        $parser = $this->createParser("INFO {\"server_id\":\"test\",\"version\":\"2.10.0\"}\r\n");
        [$op, $data] = $parser->next();
        $this->assertSame(ServerOp::Info, $op);
        $this->assertSame('test', $data['server_id']);
        $this->assertSame('2.10.0', $data['version']);
    }

    public function testParsePing(): void
    {
        $parser = $this->createParser("PING\r\n");
        [$op, $data] = $parser->next();
        $this->assertSame(ServerOp::Ping, $op);
        $this->assertNull($data);
    }

    public function testParsePong(): void
    {
        $parser = $this->createParser("PONG\r\n");
        [$op, $data] = $parser->next();
        $this->assertSame(ServerOp::Pong, $op);
    }

    public function testParseOk(): void
    {
        $parser = $this->createParser("+OK\r\n");
        [$op, $data] = $parser->next();
        $this->assertSame(ServerOp::Ok, $op);
    }

    public function testParseErr(): void
    {
        $parser = $this->createParser("-ERR 'Authorization Violation'\r\n");
        [$op, $data] = $parser->next();
        $this->assertSame(ServerOp::Err, $op);
        $this->assertSame('Authorization Violation', $data);
    }

    public function testParseMsg(): void
    {
        $parser = $this->createParser("MSG foo.bar 1 5\r\nhello\r\n");
        [$op, $data] = $parser->next();
        $this->assertSame(ServerOp::Msg, $op);
        $this->assertSame('foo.bar', $data['subject']);
        $this->assertSame('1', $data['sid']);
        $this->assertNull($data['replyTo']);
        $this->assertSame('hello', $data['payload']);
    }

    public function testParseMsgWithReply(): void
    {
        $parser = $this->createParser("MSG foo.bar 1 _INBOX.xyz 5\r\nhello\r\n");
        [$op, $data] = $parser->next();
        $this->assertSame(ServerOp::Msg, $op);
        $this->assertSame('foo.bar', $data['subject']);
        $this->assertSame('1', $data['sid']);
        $this->assertSame('_INBOX.xyz', $data['replyTo']);
        $this->assertSame('hello', $data['payload']);
    }

    public function testParseMsgEmpty(): void
    {
        $parser = $this->createParser("MSG foo 1 0\r\n\r\n");
        [$op, $data] = $parser->next();
        $this->assertSame(ServerOp::Msg, $op);
        $this->assertSame('', $data['payload']);
    }

    public function testParseHmsg(): void
    {
        $headers = "NATS/1.0\r\nX-Test: value\r\n\r\n";
        $headerLen = \strlen($headers);
        $payload = 'hello';
        $totalLen = $headerLen + \strlen($payload);
        $parser = $this->createParser("HMSG foo.bar 1 {$headerLen} {$totalLen}\r\n{$headers}{$payload}\r\n");
        [$op, $data] = $parser->next();
        $this->assertSame(ServerOp::HMsg, $op);
        $this->assertSame('foo.bar', $data['subject']);
        $this->assertSame($headers, $data['headers']);
        $this->assertSame('hello', $data['payload']);
    }

    public function testParseHmsgWithReply(): void
    {
        $headers = "NATS/1.0\r\n\r\n";
        $headerLen = \strlen($headers);
        $totalLen = $headerLen + 3;
        $parser = $this->createParser("HMSG foo 1 reply {$headerLen} {$totalLen}\r\n{$headers}bar\r\n");
        [$op, $data] = $parser->next();
        $this->assertSame(ServerOp::HMsg, $op);
        $this->assertSame('reply', $data['replyTo']);
        $this->assertSame('bar', $data['payload']);
    }

    public function testParseMultipleOps(): void
    {
        $parser = $this->createParser("PING\r\n+OK\r\nMSG foo 1 3\r\nabc\r\n");

        [$op1,] = $parser->next();
        $this->assertSame(ServerOp::Ping, $op1);

        [$op2,] = $parser->next();
        $this->assertSame(ServerOp::Ok, $op2);

        [$op3, $data3] = $parser->next();
        $this->assertSame(ServerOp::Msg, $op3);
        $this->assertSame('abc', $data3['payload']);
    }

    public function testParseUnknownOpThrows(): void
    {
        $parser = $this->createParser("UNKNOWN command\r\n");
        $this->expectException(ProtocolException::class);
        $parser->next();
    }
}
