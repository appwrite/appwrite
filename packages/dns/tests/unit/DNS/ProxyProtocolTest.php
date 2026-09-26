<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\DNS\ProxyProtocol;

final class ProxyProtocolTest extends TestCase
{
    public function testV1Tcp4(): void
    {
        $raw = "PROXY TCP4 192.168.0.1 192.168.0.11 56324 443\r\n";
        $header = ProxyProtocol::parse($raw . 'payload');

        $this->assertInstanceOf(\Utopia\DNS\ProxyProtocol::class, $header);
        $this->assertSame(\strlen($raw), $header->length);
        $this->assertSame('192.168.0.1', $header->ip);
        $this->assertSame(56324, $header->port);
    }

    public function testV1Tcp6(): void
    {
        $raw = "PROXY TCP6 2001:db8::1 2001:db8::2 4124 53\r\n";
        $header = ProxyProtocol::parse($raw);

        $this->assertInstanceOf(\Utopia\DNS\ProxyProtocol::class, $header);
        $this->assertSame(\strlen($raw), $header->length);
        $this->assertSame('2001:db8::1', $header->ip);
        $this->assertSame(4124, $header->port);
    }

    public function testV1Unknown(): void
    {
        $raw = "PROXY UNKNOWN\r\n";
        $header = ProxyProtocol::parse($raw);

        $this->assertInstanceOf(\Utopia\DNS\ProxyProtocol::class, $header);
        $this->assertSame(\strlen($raw), $header->length);
        $this->assertNull($header->ip);
        $this->assertNull($header->port);
    }

    public function testIncompleteReturnsNull(): void
    {
        $this->assertNotInstanceOf(\Utopia\DNS\ProxyProtocol::class, ProxyProtocol::parse(''));
        $this->assertNotInstanceOf(\Utopia\DNS\ProxyProtocol::class, ProxyProtocol::parse('P'));
        $this->assertNotInstanceOf(\Utopia\DNS\ProxyProtocol::class, ProxyProtocol::parse('PROXY TCP4 192.168.0.1'));
        $this->assertNotInstanceOf(\Utopia\DNS\ProxyProtocol::class, ProxyProtocol::parse("\r\n\r\n"));
        $this->assertNotInstanceOf(\Utopia\DNS\ProxyProtocol::class, ProxyProtocol::parse(ProxyProtocol::SIGNATURE_V2));
        $this->assertNotInstanceOf(\Utopia\DNS\ProxyProtocol::class, ProxyProtocol::parse(ProxyProtocol::SIGNATURE_V2 . "\x21\x11"));
    }

    public function testV2IncompleteAddressesReturnsNull(): void
    {
        // Header announces 12 address bytes but carries none yet
        $buffer = ProxyProtocol::SIGNATURE_V2 . "\x21\x11" . pack('n', 12);
        $this->assertNotInstanceOf(\Utopia\DNS\ProxyProtocol::class, ProxyProtocol::parse($buffer));
    }

    public function testGarbageThrows(): void
    {
        $this->expectException(Exception::class);
        ProxyProtocol::parse('GET / HTTP/1.1');
    }

    public function testV1InvalidAddressThrows(): void
    {
        $this->expectException(Exception::class);
        ProxyProtocol::parse("PROXY TCP4 999.0.0.1 192.168.0.11 56324 443\r\n");
    }

    public function testV1InvalidProtocolThrows(): void
    {
        $this->expectException(Exception::class);
        ProxyProtocol::parse("PROXY TCP9 192.168.0.1 192.168.0.11 56324 443\r\n");
    }

    public function testV1UnterminatedThrows(): void
    {
        $this->expectException(Exception::class);
        ProxyProtocol::parse('PROXY TCP4 ' . str_repeat('x', 107));
    }

    public function testV2Tcp4(): void
    {
        $addresses = inet_pton('10.1.2.3') . inet_pton('10.0.0.1') . pack('n', 4124) . pack('n', 53);
        $buffer = ProxyProtocol::SIGNATURE_V2 . "\x21\x11" . pack('n', \strlen($addresses)) . $addresses;

        $header = ProxyProtocol::parse($buffer . 'payload');

        $this->assertInstanceOf(\Utopia\DNS\ProxyProtocol::class, $header);
        $this->assertSame(\strlen($buffer), $header->length);
        $this->assertSame('10.1.2.3', $header->ip);
        $this->assertSame(4124, $header->port);
    }

    public function testV2Tcp6(): void
    {
        $addresses = inet_pton('2001:db8::1') . inet_pton('2001:db8::2') . pack('n', 4124) . pack('n', 53);
        $buffer = ProxyProtocol::SIGNATURE_V2 . "\x21\x21" . pack('n', \strlen($addresses)) . $addresses;

        $header = ProxyProtocol::parse($buffer);

        $this->assertInstanceOf(\Utopia\DNS\ProxyProtocol::class, $header);
        $this->assertSame('2001:db8::1', $header->ip);
        $this->assertSame(4124, $header->port);
    }

    public function testV2WithTlvExtension(): void
    {
        $addresses = inet_pton('10.1.2.3') . inet_pton('10.0.0.1') . pack('n', 4124) . pack('n', 53);
        $tlv = "\x04\x00\x02ok"; // PP2_TYPE_NOOP
        $buffer = ProxyProtocol::SIGNATURE_V2 . "\x21\x11" . pack('n', \strlen($addresses . $tlv)) . $addresses . $tlv;

        $header = ProxyProtocol::parse($buffer);

        $this->assertInstanceOf(\Utopia\DNS\ProxyProtocol::class, $header);
        $this->assertSame(\strlen($buffer), $header->length);
        $this->assertSame('10.1.2.3', $header->ip);
    }

    public function testV2Local(): void
    {
        $buffer = ProxyProtocol::SIGNATURE_V2 . "\x20\x00" . pack('n', 0);

        $header = ProxyProtocol::parse($buffer);

        $this->assertInstanceOf(\Utopia\DNS\ProxyProtocol::class, $header);
        $this->assertSame(16, $header->length);
        $this->assertNull($header->ip);
        $this->assertNull($header->port);
    }

    public function testV2InvalidVersionThrows(): void
    {
        $this->expectException(Exception::class);
        ProxyProtocol::parse(ProxyProtocol::SIGNATURE_V2 . "\x31\x11" . pack('n', 0));
    }

    public function testV2TruncatedAddressesThrows(): void
    {
        // PROXY command with an INET family but an address block that is too short
        $this->expectException(Exception::class);
        ProxyProtocol::parse(ProxyProtocol::SIGNATURE_V2 . "\x21\x11" . pack('n', 4) . 'abcd');
    }
}
