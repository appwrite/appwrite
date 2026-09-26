<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS\Message;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\DNS\Exception\Message\DecodingException;
use Utopia\DNS\Message\Domain;

final class DomainTest extends TestCase
{
    public function testEncodeProducesExpectedWireFormat(): void
    {
        $encoded = Domain::encode('www.example.com');

        $this->assertSame("\x03www\x07example\x03com\x00", $encoded);
    }

    public function testEncodeTreatsSingleTrailingDotAsAbsolute(): void
    {
        $this->assertSame(
            Domain::encode('example.com'),
            Domain::encode('example.com.'),
        );
    }

    public function testEncodeAllowsRootViaEmptyString(): void
    {
        $this->assertSame("\x00", Domain::encode(''));
    }

    public function testEncodeAllowsRootViaDot(): void
    {
        $this->assertSame("\x00", Domain::encode('.'));
    }

    public function testDecodeSimpleDomain(): void
    {
        $data = "\x03www\x07example\x03com\x00"; // "www.example.com"
        $offset = 0;

        $decoded = Domain::decode($data, $offset);

        $this->assertSame('www.example.com', $decoded);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testDecodeRootDomain(): void
    {
        $data = "\x00"; // root label
        $offset = 0;

        $decoded = Domain::decode($data, $offset);

        $this->assertSame('', $decoded);
        $this->assertSame(1, $offset);
    }

    public function testDecodeCompressionPointer(): void
    {
        $first = "\x05first\x07example\x03com\x00"; // "first.example.com"
        $pointer = "\xC0\x00"; // pointer back to offset 0
        $data = $first . $pointer;

        $offset = 0;
        $decoded = Domain::decode($data, $offset);
        $this->assertSame('first.example.com', $decoded);
        $this->assertSame(\strlen($first), $offset);

        $decodedPointer = Domain::decode($data, $offset);
        $this->assertSame('first.example.com', $decodedPointer);
        $this->assertSame(\strlen($first) + \strlen($pointer), $offset);
    }

    /**
     * Test that self-referencing compression pointers are rejected.
     *
     * Per RFC 1035 Section 4.1.4, compression pointers must point to
     * earlier positions in the packet. A pointer at offset 0 pointing
     * to offset 0 is invalid (would create infinite loop).
     */
    public function testDecodePointerLoopRaisesException(): void
    {
        $data = "\xC0\x00"; // pointer at offset 0 pointing to offset 0 (self-reference)
        $offset = 0;

        $this->expectException(DecodingException::class);
        $this->expectExceptionMessage('Compression pointer must reference earlier position');

        Domain::decode($data, $offset);
    }

    /**
     * Test that forward-referencing compression pointers are rejected.
     *
     * Per RFC 1035 Section 4.1.4, compression pointers must point backward.
     * Forward references can create loops and are not valid DNS packets.
     */
    public function testDecodeForwardPointerRaisesException(): void
    {
        // Packet: pointer at offset 0 pointing to offset 5, which doesn't exist yet
        $data = "\xC0\x05\x03www\x00";
        $offset = 0;

        $this->expectException(DecodingException::class);
        $this->expectExceptionMessage('Compression pointer must reference earlier position');

        Domain::decode($data, $offset);
    }

    /**
     * Test that pointer cycles are prevented by forward reference validation.
     *
     * With strict backward-pointer validation per RFC 1035, true pointer
     * cycles become impossible. This test verifies that a potential cycle
     * is caught by the forward reference check before it can loop.
     */
    public function testDecodePointerCyclePreventedByForwardCheck(): void
    {
        // Attempting to create a cycle: offset 0 -> offset 4 -> offset 0
        // But offset 0 -> offset 4 is a forward reference, caught immediately
        $data = "\xC0\x04\x00\x00\xC0\x00";
        $offset = 0;

        $this->expectException(DecodingException::class);
        $this->expectExceptionMessage('Compression pointer must reference earlier position');

        Domain::decode($data, $offset);
    }

    /**
     * Test that visiting the same backward pointer twice is detected.
     *
     * Even with strict backward-only pointers, we track visited positions
     * to catch any edge cases that might slip through.
     */
    public function testDecodeRevisitedPointerRaisesException(): void
    {
        // Create a packet where we start mid-stream and encounter a pointer
        // that we've already visited in this decode operation.
        // Structure: [label "a"][pointer to 0][label "b"][null]
        // Then start decoding at offset 2 (the pointer)
        $data = "\x01a\xC0\x00\x01b\x00";
        // offset 0: label "a"
        // offset 2: pointer to offset 0
        // offset 4: label "b"
        // offset 6: null terminator

        // When we decode starting at offset 2:
        // - We see pointer to offset 0
        // - We follow to offset 0, see label "a"
        // - We advance to offset 2, see pointer to offset 0 AGAIN
        // - This is a revisit of pointer target 0
        $offset = 2;

        $this->expectException(DecodingException::class);
        $this->expectExceptionMessage('Compression pointer loop detected');

        Domain::decode($data, $offset);
    }

    public function testDecodeTruncatedPointerRaisesException(): void
    {
        $data = "\xC0"; // pointer missing second byte
        $offset = 0;

        $this->expectException(DecodingException::class);
        $this->expectExceptionMessage('Truncated compression pointer');

        Domain::decode($data, $offset);
    }

    #[DataProvider('invalidDomainProvider')]
    public function testEncodeRejectsInvalidDomains(string $domain, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        Domain::encode($domain);
    }

    /**
     * @return \Iterator<string, array<int, string>>
     */
    public static function invalidDomainProvider(): \Iterator
    {
        $longLabel = str_repeat('a', Domain::MAX_LABEL_LEN + 1);
        $tooManyLabels = implode('.', array_fill(0, Domain::MAX_LABELS + 1, 'a'));
        $maxLabel = str_repeat('a', Domain::MAX_LABEL_LEN);
        $overLengthDomain = implode('.', [$maxLabel, $maxLabel, $maxLabel, $maxLabel]);
        yield 'consecutive dots' => ['www..example.com', 'Domain labels must not be empty'];
        yield 'double trailing dot apex' => ['example..', 'Domain labels must not be empty'];
        yield 'double trailing dot absolute' => ['example.com..', 'Domain labels must not be empty'];
        yield 'at symbol label' => ['@', 'Domain label contains invalid characters'];
        yield 'label too long' => ["$longLabel.com", "Label too long: $longLabel"];
        yield 'too many labels' => [$tooManyLabels, 'Domain has too many labels: ' . (Domain::MAX_LABELS + 1)];
        yield 'encoded length exceeds limit' => [
            $overLengthDomain,
            'Encoded domain exceeds maximum length of ' . Domain::MAX_DOMAIN_NAME_LEN . ' bytes',
        ];
    }
}
