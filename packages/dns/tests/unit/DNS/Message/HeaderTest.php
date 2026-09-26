<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS\Message;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Exception\Message\DecodingException;
use Utopia\DNS\Message\Header;

final class HeaderTest extends TestCase
{
    public function testEncodeDecodeRoundTrip(): void
    {
        $header = new Header(
            id: 0x1234,
            isResponse: true,
            opcode: 0,
            authoritative: false,
            truncated: true,
            recursionDesired: true,
            recursionAvailable: true,
            responseCode: 0,
            questionCount: 1,
            answerCount: 2,
            authorityCount: 3,
            additionalCount: 4,
        );

        $binary = $header->encode();
        $decoded = Header::decode($binary);

        $this->assertSame(0x1234, $decoded->id);
        $this->assertTrue($decoded->isResponse);
        $this->assertSame(0, $decoded->opcode);
        $this->assertFalse($decoded->authoritative);
        $this->assertTrue($decoded->truncated);
        $this->assertTrue($decoded->recursionDesired);
        $this->assertTrue($decoded->recursionAvailable);
        $this->assertSame(0, $decoded->responseCode);
        $this->assertSame(1, $decoded->questionCount);
        $this->assertSame(2, $decoded->answerCount);
        $this->assertSame(3, $decoded->authorityCount);
        $this->assertSame(4, $decoded->additionalCount);
    }

    public function testDecodeThrowsOnShortData(): void
    {
        $this->expectException(DecodingException::class);
        Header::decode("\x00\x01");
    }

    public function testDecodeHonorsOffset(): void
    {
        // Build header with flags: QR=1, opcode=0, AA=0, TC=1, RD=1, RA=1, RCODE=0
        // = 1000 0011 1000 0000 = 0x8380
        $binaryHeader = pack('nnnnnn', 0x0a0b, 0x8380, 0x0001, 0x0002, 0x0003, 0x0004);
        $payload = "\xff\xff\xff\xff" . $binaryHeader . "\x00\x00";

        $decoded = Header::decode($payload, 4);

        $this->assertSame(0x0a0b, $decoded->id);
        $this->assertTrue($decoded->isResponse);
        $this->assertSame(0, $decoded->opcode);
        $this->assertFalse($decoded->authoritative);
        $this->assertTrue($decoded->truncated);
        $this->assertTrue($decoded->recursionDesired);
        $this->assertTrue($decoded->recursionAvailable);
        $this->assertSame(0, $decoded->responseCode);
        $this->assertSame(1, $decoded->questionCount);
        $this->assertSame(2, $decoded->answerCount);
        $this->assertSame(3, $decoded->authorityCount);
        $this->assertSame(4, $decoded->additionalCount);
    }

    public function testEncodeUsesNetworkByteOrder(): void
    {
        // Flags: QR=0, opcode=0, AA=0, TC=0, RD=1, RA=0, RCODE=3
        // = 0000 0001 0000 0011 = 0x0103
        $header = new Header(
            id: 0x1a2b,
            isResponse: false,
            opcode: 0,
            authoritative: false,
            truncated: false,
            recursionDesired: true,
            recursionAvailable: false,
            responseCode: 3,
            questionCount: 0x0506,
            answerCount: 0x0708,
            authorityCount: 0x090a,
            additionalCount: 0x0b0c,
        );

        $binary = $header->encode();
        $this->assertSame('1a2b010305060708090a0b0c', bin2hex($binary));
    }

    public function testOpcodeValidation(): void
    {
        $this->expectException(DecodingException::class);
        $this->expectExceptionMessage('Opcode must be 0-15');

        new Header(
            id: 1,
            isResponse: false,
            opcode: 16,
            authoritative: false,
            truncated: false,
            recursionDesired: false,
            recursionAvailable: false,
            responseCode: 0,
            questionCount: 0,
            answerCount: 0,
            authorityCount: 0,
            additionalCount: 0,
        );
    }

    public function testResponseCodeValidation(): void
    {
        $this->expectException(DecodingException::class);
        $this->expectExceptionMessage('Response code must be 0-15');

        new Header(
            id: 1,
            isResponse: false,
            opcode: 0,
            authoritative: false,
            truncated: false,
            recursionDesired: false,
            recursionAvailable: false,
            responseCode: 16,
            questionCount: 0,
            answerCount: 0,
            authorityCount: 0,
            additionalCount: 0,
        );
    }

    /**
     * Test that packets with non-zero Z bits are accepted for interoperability.
     *
     * RFC 1035 says Z bits MUST be zero, but many DNS clients/proxies
     * (including Google's infrastructure) set these bits. We intentionally
     * ignore them to maximize compatibility.
     */
    public function testDecodeAcceptsNonZeroZBits(): void
    {
        // Flags with Z bits set (bits 4-6):
        // QR=1, opcode=0, AA=0, TC=0, RD=1, RA=1, Z=0b111, RCODE=0
        // = 1000 0001 1111 0000 = 0x81F0
        $flagsWithAllZBits = 0x81F0;
        $binaryHeader = pack('nnnnnn', 0x1234, $flagsWithAllZBits, 1, 0, 0, 0);

        $decoded = Header::decode($binaryHeader);

        // Verify the header is decoded correctly despite Z bits
        $this->assertSame(0x1234, $decoded->id);
        $this->assertTrue($decoded->isResponse);
        $this->assertSame(0, $decoded->opcode);
        $this->assertFalse($decoded->authoritative);
        $this->assertFalse($decoded->truncated);
        $this->assertTrue($decoded->recursionDesired);
        $this->assertTrue($decoded->recursionAvailable);
        $this->assertSame(0, $decoded->responseCode);
        $this->assertSame(1, $decoded->questionCount);
    }

    /**
     * Test various Z bit patterns are all accepted.
     */
    public function testDecodeAcceptsVariousZBitPatterns(): void
    {
        $zBitPatterns = [
            0x0010, // Z bit 4 only
            0x0020, // Z bit 5 only
            0x0040, // Z bit 6 only
            0x0030, // Z bits 4 and 5
            0x0050, // Z bits 4 and 6
            0x0060, // Z bits 5 and 6
            0x0070, // All Z bits (4, 5, 6)
        ];

        foreach ($zBitPatterns as $zBits) {
            // Base flags: QR=0, opcode=0, AA=0, TC=0, RD=1, RA=0, RCODE=0
            // = 0000 0001 0000 0000 = 0x0100
            $flags = 0x0100 | $zBits;
            $binaryHeader = pack('nnnnnn', 0xABCD, $flags, 1, 0, 0, 0);

            $decoded = Header::decode($binaryHeader);

            $this->assertSame(0xABCD, $decoded->id, 'Failed for Z bits pattern: ' . \sprintf('0x%04X', $zBits));
            $this->assertFalse($decoded->isResponse);
            $this->assertTrue($decoded->recursionDesired);
            $this->assertSame(0, $decoded->responseCode);
        }
    }
}
