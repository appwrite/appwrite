<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Redis\Client;
use Utopia\Cache\Adapter\Redis\ConnectionError;
use Utopia\Cache\Adapter\Redis\ConnectionException;
use Utopia\Cache\Adapter\Redis\RedisError;

final class RedisClientTest extends TestCase
{
    public function testEncodeBuildsRespArrayOfBulkStrings(): void
    {
        $this->assertSame(
            "*1\r\n\$4\r\nPING\r\n",
            Client::encode(['PING']),
        );

        $this->assertSame(
            "*3\r\n\$3\r\nSET\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n",
            Client::encode(['SET', 'foo', 'bar']),
        );
    }

    public function testEncodeCoercesIntegersToStrings(): void
    {
        $this->assertSame(
            "*2\r\n\$6\r\nSELECT\r\n\$1\r\n3\r\n",
            Client::encode(['SELECT', 3]),
        );
    }

    public function testEncodePreservesBinaryPayloadByByteLength(): void
    {
        $payload = "café\n\0bytes";
        $encoded = Client::encode(['SET', 'k', $payload]);

        $this->assertSame(
            "*3\r\n\$3\r\nSET\r\n\$1\r\nk\r\n\$" . \strlen($payload) . "\r\n" . $payload . "\r\n",
            $encoded,
        );
    }

    public function testParseSimpleString(): void
    {
        $offset = 0;
        $this->assertSame('OK', Client::parse("+OK\r\n", $offset));
        $this->assertSame(5, $offset);
    }

    public function testParseInteger(): void
    {
        $offset = 0;
        $this->assertSame(42, Client::parse(":42\r\n", $offset));
        $this->assertSame(5, $offset);
    }

    public function testParseNegativeInteger(): void
    {
        $offset = 0;
        $this->assertSame(-7, Client::parse(":-7\r\n", $offset));
    }

    public function testParseBulkString(): void
    {
        $offset = 0;
        $this->assertSame('hello', Client::parse("\$5\r\nhello\r\n", $offset));
        $this->assertSame(11, $offset);
    }

    public function testParseBulkStringWithEmbeddedCrlf(): void
    {
        $offset = 0;
        $payload = "line1\r\nline2";
        $this->assertSame(
            $payload,
            Client::parse('$' . \strlen($payload) . "\r\n" . $payload . "\r\n", $offset),
        );
    }

    public function testParseEmptyBulkString(): void
    {
        $offset = 0;
        $this->assertSame('', Client::parse("\$0\r\n\r\n", $offset));
        $this->assertSame(6, $offset);
    }

    public function testParseNullBulkString(): void
    {
        $offset = 0;
        $this->assertNull(Client::parse("\$-1\r\n", $offset));
        $this->assertSame(5, $offset);
    }

    public function testParseArrayOfMixedTypes(): void
    {
        $offset = 0;
        $buffer = "*3\r\n\$3\r\nfoo\r\n:42\r\n+OK\r\n";
        $this->assertSame(['foo', 42, 'OK'], Client::parse($buffer, $offset));
        $this->assertSame(\strlen($buffer), $offset);
    }

    public function testParseEmptyArray(): void
    {
        $offset = 0;
        $this->assertSame([], Client::parse("*0\r\n", $offset));
    }

    public function testParseNullArray(): void
    {
        $offset = 0;
        $this->assertNull(Client::parse("*-1\r\n", $offset));
    }

    public function testParseNestedArrays(): void
    {
        $offset = 0;
        $buffer = "*2\r\n*2\r\n:1\r\n:2\r\n*1\r\n\$1\r\nx\r\n";
        $this->assertSame([[1, 2], ['x']], Client::parse($buffer, $offset));
        $this->assertSame(\strlen($buffer), $offset);
    }

    public function testParseRedisErrorIsWrappedNotThrown(): void
    {
        $offset = 0;
        $value = Client::parse("-WRONGTYPE wrong kind\r\n", $offset);

        $this->assertInstanceOf(RedisError::class, $value);
        $this->assertSame('WRONGTYPE wrong kind', $value->exception->getMessage());
    }

    public function testParseReturnsIncompleteWhenBufferEmpty(): void
    {
        $offset = 0;
        $this->assertSame(Client::INCOMPLETE, Client::parse('', $offset));
    }

    public function testParseReturnsIncompleteWhenLineUnterminated(): void
    {
        $offset = 0;
        $this->assertSame(Client::INCOMPLETE, Client::parse('+OK', $offset));
    }

    public function testParseReturnsIncompleteForTruncatedBulkString(): void
    {
        $offset = 0;
        // header says 5 bytes, payload is short
        $this->assertSame(Client::INCOMPLETE, Client::parse("\$5\r\nhel", $offset));
    }

    public function testParseReturnsIncompleteForBulkStringMissingTrailingCrlf(): void
    {
        $offset = 0;
        $this->assertSame(Client::INCOMPLETE, Client::parse("\$5\r\nhello", $offset));
    }

    public function testParseReturnsIncompleteForPartiallyDeliveredArrayElement(): void
    {
        $offset = 0;
        $this->assertSame(Client::INCOMPLETE, Client::parse("*2\r\n:1\r\n:", $offset));
    }

    public function testParseAdvancesOffsetExactlyOneFrame(): void
    {
        $offset = 0;
        $buffer = "+OK\r\n+SECOND\r\n";
        $this->assertSame('OK', Client::parse($buffer, $offset));
        $this->assertSame(5, $offset);
        $this->assertSame('SECOND', Client::parse($buffer, $offset));
        $this->assertSame(\strlen($buffer), $offset);
    }

    public function testParseUnknownTypeThrows(): void
    {
        $this->expectException(\RedisException::class);
        $offset = 0;
        Client::parse("?nope\r\n", $offset);
    }

    public function testEncodeAndParseRoundTripFlattenedToServerEcho(): void
    {
        // The server's HSET reply for a new field is :1 — confirm we can
        // round-trip from encode -> ... -> parse for what we'd read back.
        $offset = 0;
        $this->assertSame(1, Client::parse(":1\r\n", $offset));

        // And that encode produces what a real Redis would expect.
        $this->assertSame(
            "*4\r\n\$4\r\nHSET\r\n\$1\r\nk\r\n\$1\r\nf\r\n\$1\r\nv\r\n",
            Client::encode(['HSET', 'k', 'f', 'v']),
        );
    }

    public function testUnwrapPassesThroughScalars(): void
    {
        $this->assertSame('OK', Client::unwrap('OK'));
        $this->assertSame(42, Client::unwrap(42));
        $this->assertNull(Client::unwrap(null));
        $this->assertSame('', Client::unwrap(''));
    }

    public function testUnwrapPassesThroughArraysOfScalars(): void
    {
        $this->assertSame(['a', 1, null], Client::unwrap(['a', 1, null]));
    }

    public function testUnwrapThrowsRedisErrorAtTopLevel(): void
    {
        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('WRONGTYPE');
        Client::unwrap(new RedisError(new \RedisException('WRONGTYPE')));
    }

    public function testUnwrapThrowsConnectionErrorAtTopLevel(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('connection lost');
        Client::unwrap(new ConnectionError(new ConnectionException('connection lost')));
    }

    public function testUnwrapThrowsRedisErrorNestedInArray(): void
    {
        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('NOAUTH');
        // Element 1 is an error frame; the unwrap must walk into it.
        Client::unwrap(['ok', new RedisError(new \RedisException('NOAUTH'))]);
    }

    public function testUnwrapThrowsErrorNestedInsideNestedArray(): void
    {
        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('deep');
        Client::unwrap([
            ['nested', ['deeper', new RedisError(new \RedisException('deep'))]],
        ]);
    }

    public function testUnwrapReturnsArrayUnchangedWhenNoErrors(): void
    {
        $input = [['a', 1], ['b', null], 'c'];
        $this->assertSame($input, Client::unwrap($input));
    }

    public function testUnwrapHandlesEmptyArray(): void
    {
        $this->assertSame([], Client::unwrap([]));
    }
}
