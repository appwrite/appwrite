<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS\Message;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Record;

final class RecordTest extends TestCase
{
    public function testEncodeARecordMatchesBytes(): void
    {
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_A,
            class: Record::CLASS_IN,
            ttl: 300,
            rdata: '93.184.216.34',
        );

        // Raw RR: example.com. 300 IN A 93.184.216.34
        $expected = "\x07example\x03com\x00"
            . "\x00\x01"
            . "\x00\x01"
            . "\x00\x00\x01\x2C"
            . "\x00\x04"
            . "\x5D\xB8\xD8\x22";

        $this->assertSame($expected, $record->encode());
    }

    public function testDecodeARecordParsesFields(): void
    {
        // Raw RR: example.com. 300 IN A 93.184.216.34
        $data = "\x07example\x03com\x00"
            . "\x00\x01"
            . "\x00\x01"
            . "\x00\x00\x01\x2C"
            . "\x00\x04"
            . "\x5D\xB8\xD8\x22";

        $offset = 0;
        $record = Record::decode($data, $offset);

        $this->assertSame('example.com', $record->name);
        $this->assertSame(Record::TYPE_A, $record->type);
        $this->assertSame(Record::CLASS_IN, $record->class);
        $this->assertSame(300, $record->ttl);
        $this->assertSame('93.184.216.34', $record->rdata);
        $this->assertNull($record->priority);
        $this->assertNull($record->weight);
        $this->assertNull($record->port);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testEncodeMxRecordMatchesBytes(): void
    {
        $record = new Record(
            name: 'mail.example.com',
            type: Record::TYPE_MX,
            class: Record::CLASS_IN,
            ttl: 3600,
            rdata: 'mail.exchange.example.com',
            priority: 10,
        );

        // Raw RR: mail.example.com. 3600 IN MX 10 mail.exchange.example.com.
        $expected = "\x04mail\x07example\x03com\x00"
            . "\x00\x0F"
            . "\x00\x01"
            . "\x00\x00\x0E\x10"
            . "\x00\x1D"
            . "\x00\x0A"
            . "\x04mail\x08exchange\x07example\x03com\x00";

        $this->assertSame($expected, $record->encode());
    }

    public function testDecodeMxRecordParsesFields(): void
    {
        // Raw RR: mail.example.com. 3600 IN MX 10 mail.exchange.example.com.
        $data = "\x04mail\x07example\x03com\x00"
            . "\x00\x0F"
            . "\x00\x01"
            . "\x00\x00\x0E\x10"
            . "\x00\x1D"
            . "\x00\x0A"
            . "\x04mail\x08exchange\x07example\x03com\x00";

        $offset = 0;
        $record = Record::decode($data, $offset);

        $this->assertSame('mail.example.com', $record->name);
        $this->assertSame(Record::TYPE_MX, $record->type);
        $this->assertSame(Record::CLASS_IN, $record->class);
        $this->assertSame(3600, $record->ttl);
        $this->assertSame(10, $record->priority);
        $this->assertSame('mail.exchange.example.com', $record->rdata);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testEncodeSrvRecordMatchesBytes(): void
    {
        $record = new Record(
            name: '_sip._tcp.example.com',
            type: Record::TYPE_SRV,
            class: Record::CLASS_IN,
            ttl: 7200,
            rdata: 'sip.example.com',
            priority: 5,
            weight: 10,
            port: 5060,
        );

        // Raw RR: _sip._tcp.example.com. 7200 IN SRV 5 10 5060 sip.example.com.
        $expected = "\x04_sip\x04_tcp\x07example\x03com\x00"
            . "\x00\x21"
            . "\x00\x01"
            . "\x00\x00\x1C\x20"
            . "\x00\x17"
            . "\x00\x05\x00\x0A\x13\xC4"
            . "\x03sip\x07example\x03com\x00";

        $this->assertSame($expected, $record->encode());
    }

    public function testDecodeSrvRecordParsesFields(): void
    {
        // Raw RR: _sip._tcp.example.com. 7200 IN SRV 5 10 5060 sip.example.com.
        $data = "\x04_sip\x04_tcp\x07example\x03com\x00"
            . "\x00\x21"
            . "\x00\x01"
            . "\x00\x00\x1C\x20"
            . "\x00\x17"
            . "\x00\x05\x00\x0A\x13\xC4"
            . "\x03sip\x07example\x03com\x00";

        $offset = 0;
        $record = Record::decode($data, $offset);

        $this->assertSame('_sip._tcp.example.com', $record->name);
        $this->assertSame(Record::TYPE_SRV, $record->type);
        $this->assertSame(Record::CLASS_IN, $record->class);
        $this->assertSame(7200, $record->ttl);
        $this->assertSame(5, $record->priority);
        $this->assertSame(10, $record->weight);
        $this->assertSame(5060, $record->port);
        $this->assertSame('sip.example.com', $record->rdata);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testEncodeMxRecordWithZeroPriorityMatchesBytes(): void
    {
        // Zero is the highest priority a mail exchange can be given, and it is a value the sender
        // chose rather than one it omitted. It has to reach the wire as two zero octets.
        $record = new Record(
            name: 'mail.example.com',
            type: Record::TYPE_MX,
            class: Record::CLASS_IN,
            ttl: 3600,
            rdata: 'mail.exchange.example.com',
            priority: 0,
        );

        // Raw RR: mail.example.com. 3600 IN MX 0 mail.exchange.example.com.
        $expected = "\x04mail\x07example\x03com\x00"
            . "\x00\x0F"
            . "\x00\x01"
            . "\x00\x00\x0E\x10"
            . "\x00\x1D"
            . "\x00\x00"
            . "\x04mail\x08exchange\x07example\x03com\x00";

        $this->assertSame($expected, $record->encode());
    }

    public function testDecodeMxRecordWithZeroPriorityParsesFields(): void
    {
        // Raw RR: mail.example.com. 3600 IN MX 0 mail.exchange.example.com.
        $data = "\x04mail\x07example\x03com\x00"
            . "\x00\x0F"
            . "\x00\x01"
            . "\x00\x00\x0E\x10"
            . "\x00\x1D"
            . "\x00\x00"
            . "\x04mail\x08exchange\x07example\x03com\x00";

        $offset = 0;
        $record = Record::decode($data, $offset);

        // A zero read off the wire stays a zero, rather than becoming the null that means "this
        // record type carries no priority".
        $this->assertSame(0, $record->priority);
        $this->assertSame('mail.exchange.example.com', $record->rdata);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testEncodeSrvRecordWithZeroNumericsMatchesBytes(): void
    {
        // RFC 2782 gives all three fields a meaning at zero: highest priority, no share of the
        // weighted draw, and the port that marks the service unavailable on this target.
        $record = new Record(
            name: '_sip._tcp.example.com',
            type: Record::TYPE_SRV,
            class: Record::CLASS_IN,
            ttl: 7200,
            rdata: 'sip.example.com',
            priority: 0,
            weight: 0,
            port: 0,
        );

        // Raw RR: _sip._tcp.example.com. 7200 IN SRV 0 0 0 sip.example.com.
        $expected = "\x04_sip\x04_tcp\x07example\x03com\x00"
            . "\x00\x21"
            . "\x00\x01"
            . "\x00\x00\x1C\x20"
            . "\x00\x17"
            . "\x00\x00\x00\x00\x00\x00"
            . "\x03sip\x07example\x03com\x00";

        $this->assertSame($expected, $record->encode());
    }

    public function testDecodeSrvRecordWithZeroNumericsParsesFields(): void
    {
        // Raw RR: _sip._tcp.example.com. 7200 IN SRV 0 0 0 sip.example.com.
        $data = "\x04_sip\x04_tcp\x07example\x03com\x00"
            . "\x00\x21"
            . "\x00\x01"
            . "\x00\x00\x1C\x20"
            . "\x00\x17"
            . "\x00\x00\x00\x00\x00\x00"
            . "\x03sip\x07example\x03com\x00";

        $offset = 0;
        $record = Record::decode($data, $offset);

        $this->assertSame(0, $record->priority);
        $this->assertSame(0, $record->weight);
        $this->assertSame(0, $record->port);
        $this->assertSame('sip.example.com', $record->rdata);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testEncodeTxtRecordMatchesBytes(): void
    {
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_TXT,
            class: Record::CLASS_IN,
            ttl: 600,
            rdata: 'hello',
        );

        // Raw RR: example.com. 600 IN TXT "hello"
        $expected = "\x07example\x03com\x00"
            . "\x00\x10"
            . "\x00\x01"
            . "\x00\x00\x02\x58"
            . "\x00\x06"
            . "\x05hello";

        $this->assertSame($expected, $record->encode());
    }

    public function testDecodeTxtRecordParsesFields(): void
    {
        // Raw RR: example.com. 600 IN TXT "hello"
        $data = "\x07example\x03com\x00"
            . "\x00\x10"
            . "\x00\x01"
            . "\x00\x00\x02\x58"
            . "\x00\x06"
            . "\x05hello";

        $offset = 0;
        $record = Record::decode($data, $offset);

        $this->assertSame('example.com', $record->name);
        $this->assertSame(Record::TYPE_TXT, $record->type);
        $this->assertSame(Record::CLASS_IN, $record->class);
        $this->assertSame(600, $record->ttl);
        $this->assertSame('hello', $record->rdata);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testDecodeCnameRecordParsesNameRdata(): void
    {
        // Raw RR: www.example.com. 4000 IN CNAME cdn.example.com.
        $data = "\x03www\x07example\x03com\x00"
            . "\x00\x05"
            . "\x00\x01"
            . "\x00\x00\x0F\xA0"
            . "\x00\x11"
            . "\x03cdn\x07example\x03com\x00";

        $offset = 0;
        $record = Record::decode($data, $offset);

        $this->assertSame('www.example.com', $record->name);
        $this->assertSame(Record::TYPE_CNAME, $record->type);
        $this->assertSame(Record::CLASS_IN, $record->class);
        $this->assertSame(4000, $record->ttl);
        $this->assertSame('cdn.example.com', $record->rdata);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testDecodeUnknownRecordKeepsHexData(): void
    {
        // Raw RR: example.com. 60 IN TYPE65400 RDATA=0x0aff
        $data = "\x07example\x03com\x00"
            . "\xFE\xF8"
            . "\x00\x01"
            . "\x00\x00\x00\x3C"
            . "\x00\x02"
            . "\x0A\xFF";

        $offset = 0;
        $record = Record::decode($data, $offset);

        $this->assertSame('example.com', $record->name);
        $this->assertSame(0xFEF8, $record->type);
        $this->assertSame(Record::CLASS_IN, $record->class);
        $this->assertSame(60, $record->ttl);
        $this->assertSame('0aff', $record->rdata);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testDecodeSoaRecordParsesFields(): void
    {
        // Raw RR: example.com. 3600 IN SOA ns1.example.com. admin.example.com. 2024102701 7200 3600 1209600 86400
        $data = "\x07example\x03com\x00"
            . "\x00\x06"  // TYPE_SOA
            . "\x00\x01"  // CLASS_IN
            . "\x00\x00\x0E\x10"  // TTL: 3600
            . "\x00\x38"  // RDLENGTH: 56 bytes (17 + 19 + 20)
            // MNAME: ns1.example.com (17 bytes)
            . "\x03ns1\x07example\x03com\x00"
            // RNAME: admin.example.com (19 bytes)
            . "\x05admin\x07example\x03com\x00"
            // Serial: 2024102701 = 0x78a55b2d
            . "\x78\xa5\x5b\x2d"
            // Refresh: 7200
            . "\x00\x00\x1C\x20"
            // Retry: 3600
            . "\x00\x00\x0E\x10"
            // Expire: 1209600
            . "\x00\x12\x75\x00"
            // Minimum: 86400
            . "\x00\x01\x51\x80";

        $offset = 0;
        $record = Record::decode($data, $offset);

        $this->assertSame('example.com', $record->name);
        $this->assertSame(Record::TYPE_SOA, $record->type);
        $this->assertSame(Record::CLASS_IN, $record->class);
        $this->assertSame(3600, $record->ttl);
        $this->assertSame(
            'ns1.example.com admin.example.com 2024102701 7200 3600 1209600 86400',
            $record->rdata,
        );
        $this->assertSame(\strlen($data), $offset);
    }

    public function testEncodeSoaRecordMatchesBytes(): void
    {
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_SOA,
            class: Record::CLASS_IN,
            ttl: 3600,
            rdata: 'ns1.example.com admin.example.com 2024102701 7200 3600 1209600 86400',
        );

        $expected = "\x07example\x03com\x00"
            . "\x00\x06"
            . "\x00\x01"
            . "\x00\x00\x0E\x10"
            . "\x00\x38"  // RDLENGTH: 56 bytes
            . "\x03ns1\x07example\x03com\x00"
            . "\x05admin\x07example\x03com\x00"
            . "\x78\xa5\x5b\x2d"
            . "\x00\x00\x1C\x20"
            . "\x00\x00\x0E\x10"
            . "\x00\x12\x75\x00"
            . "\x00\x01\x51\x80";

        $this->assertSame($expected, $record->encode());
    }

    public function testEncodeSoaRecordAcceptsEmailRname(): void
    {
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_SOA,
            class: Record::CLASS_IN,
            ttl: 3600,
            rdata: 'ns1.example.com hostmaster@example.com 2024102701 7200 3600 1209600 86400',
        );

        $encoded = $record->encode();

        $this->assertStringContainsString("\x0Ahostmaster\x07example\x03com\x00", $encoded);
    }

    public function testEncodeSoaRecordEscapesDotsInEmailRnameLocalPart(): void
    {
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_SOA,
            class: Record::CLASS_IN,
            ttl: 3600,
            rdata: 'ns1.example.com first.last@example.com 2024102701 7200 3600 1209600 86400',
        );

        $encoded = $record->encode();

        $this->assertStringContainsString("\x0Afirst.last\x07example\x03com\x00", $encoded);
    }

    public function testDecodeTxtRecordWithMultipleChunks(): void
    {
        // TXT with two chunks: "hello" (5 bytes) + "world" (5 bytes)
        $data = "\x07example\x03com\x00"
            . "\x00\x10"  // TYPE_TXT
            . "\x00\x01"  // CLASS_IN
            . "\x00\x00\x02\x58"  // TTL: 600
            . "\x00\x0C"  // RDLENGTH: 12 bytes (1+5+1+5)
            . "\x05hello"
            . "\x05world";

        $offset = 0;
        $record = Record::decode($data, $offset);

        $this->assertSame('example.com', $record->name);
        $this->assertSame(Record::TYPE_TXT, $record->type);
        $this->assertSame(Record::CLASS_IN, $record->class);
        $this->assertSame(600, $record->ttl);
        $this->assertSame('helloworld', $record->rdata);
        $this->assertSame(\strlen($data), $offset);
    }

    public function testDecodeTxtRecordWithThreeChunks(): void
    {
        // TXT with three chunks: 1+3 + 1+3 + 1+3 = 12 bytes
        $data = "\x07example\x03com\x00"
            . "\x00\x10"
            . "\x00\x01"
            . "\x00\x00\x02\x58"
            . "\x00\x0C"  // RDLENGTH: 12 bytes (not 15)
            . "\x03foo"
            . "\x03bar"
            . "\x03baz";

        $offset = 0;
        $record = Record::decode($data, $offset);

        $this->assertSame('foobarbaz', $record->rdata);
    }

    public function testDecodeSoaRecordRoundTrip(): void
    {
        // Original SOA RR for round-trip comparison: example.com. 3600 IN SOA ns1.example.com. admin.example.com. 2024102701 7200 3600 1209600 86400
        $original = "\x07example\x03com\x00"
            . "\x00\x06"
            . "\x00\x01"
            . "\x00\x00\x0E\x10"
            . "\x00\x38"  // RDLENGTH: 56 bytes
            . "\x03ns1\x07example\x03com\x00"
            . "\x05admin\x07example\x03com\x00"
            . "\x78\xa5\x5b\x2d"
            . "\x00\x00\x1C\x20"
            . "\x00\x00\x0E\x10"
            . "\x00\x12\x75\x00"
            . "\x00\x01\x51\x80";

        $offset = 0;
        $record = Record::decode($original, $offset);
        $encoded = $record->encode();

        $this->assertSame($original, $encoded);
    }

    public function testEncodeTxtRecordWithMultipleChunks(): void
    {
        // Test that a TXT record with rdata exactly 256 bytes gets split into 2 chunks
        // (255 bytes + 1 byte)
        $exactly256Bytes = str_repeat('a', 256);
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_TXT,
            class: Record::CLASS_IN,
            ttl: 600,
            rdata: $exactly256Bytes,
        );

        $encoded = $record->encode();

        // Extract RDATA portion to verify chunking
        $nameLen = \strlen("\x07example\x03com\x00");
        $headerLen = $nameLen + 2 + 2 + 4 + 2; // name + type + class + ttl + rdlength
        $rdataEncoded = substr($encoded, $headerLen);

        // Should have 2 chunks: 255 bytes + 1 byte = 256 bytes total
        // First chunk: chr(255) + 255 bytes = 256 bytes
        // Second chunk: chr(1) + 1 byte = 2 bytes
        // Total RDATA: 256 + 2 = 258 bytes
        $this->assertSame(258, \strlen($rdataEncoded)); // Total RDATA length
        $this->assertSame(255, \ord($rdataEncoded[0])); // First chunk is 255 bytes
        $this->assertSame(1, \ord($rdataEncoded[256])); // Second chunk is 1 byte

        // Verify round-trip
        $offset = 0;
        $decoded = Record::decode($encoded, $offset);
        $this->assertSame($exactly256Bytes, $decoded->rdata);
    }

    public function testEncodeTxtRecordWithLongString(): void
    {
        // Create a TXT record with rdata > 255 bytes to test chunking
        $longString = str_repeat('a', 300); // 300 bytes
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_TXT,
            class: Record::CLASS_IN,
            ttl: 600,
            rdata: $longString,
        );

        $encoded = $record->encode();
        $offset = 0;
        $decoded = Record::decode($encoded, $offset);

        // Verify round-trip: decoded rdata should match original
        $this->assertSame($longString, $decoded->rdata);
        $this->assertSame(Record::TYPE_TXT, $decoded->type);
        $this->assertSame(600, $decoded->ttl);

        // Verify the encoded data has multiple chunks
        // Extract RDATA portion (skip header: name + type + class + ttl + rdlength)
        $nameLen = \strlen("\x07example\x03com\x00");
        $headerLen = $nameLen + 2 + 2 + 4 + 2; // name + type + class + ttl + rdlength
        $rdataEncoded = substr($encoded, $headerLen);

        // Should have 2 chunks: 255 bytes + 45 bytes = 300 bytes total
        // First chunk: chr(255) + 255 bytes = 256 bytes
        // Second chunk: chr(45) + 45 bytes = 46 bytes
        // Total RDATA: 256 + 46 = 302 bytes
        $this->assertSame(302, \strlen($rdataEncoded)); // Total RDATA length
        $this->assertSame(255, \ord($rdataEncoded[0])); // First chunk is 255 bytes
        $this->assertSame(45, \ord($rdataEncoded[256])); // Second chunk is 45 bytes
    }

    public function testEncodeTxtRecordRoundTripWithMultipleChunks(): void
    {
        // Test round-trip encoding/decoding of multi-chunk TXT record
        $original = "\x07example\x03com\x00"
            . "\x00\x10"  // TYPE_TXT
            . "\x00\x01"  // CLASS_IN
            . "\x00\x00\x02\x58"  // TTL: 600
            . "\x00\x0C"  // RDLENGTH: 12 bytes (1+3+1+3+1+3)
            . "\x03foo"
            . "\x03bar"
            . "\x03baz";

        $offset = 0;
        $record = Record::decode($original, $offset);
        $encoded = $record->encode();

        // Decode again to verify
        $offset2 = 0;
        $record2 = Record::decode($encoded, $offset2);

        $this->assertSame('foobarbaz', $record2->rdata);
        $this->assertSame(Record::TYPE_TXT, $record2->type);
        $this->assertSame(600, $record2->ttl);
    }

    public function testEncodeTxtRecordWithEmptyRdata(): void
    {
        // Test that empty TXT rdata is encoded as a single zero-length character-string
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_TXT,
            class: Record::CLASS_IN,
            ttl: 600,
            rdata: '',
        );

        $encoded = $record->encode();

        // Extract RDATA portion
        $nameLen = \strlen("\x07example\x03com\x00");
        $headerLen = $nameLen + 2 + 2 + 4 + 2; // name + type + class + ttl + rdlength
        $rdataEncoded = substr($encoded, $headerLen);

        // Should be a single zero-length character-string: chr(0)
        $this->assertSame(1, \strlen($rdataEncoded)); // RDLENGTH should be 1
        $this->assertSame(0, \ord($rdataEncoded[0])); // First (and only) chunk is 0 bytes

        // Verify round-trip: decode should work and return empty string
        $offset = 0;
        $decoded = Record::decode($encoded, $offset);
        $this->assertSame('', $decoded->rdata);
        $this->assertSame(Record::TYPE_TXT, $decoded->type);
        $this->assertSame(600, $decoded->ttl);
    }

    public function testValidateRdataRejectsHostnameForARecord(): void
    {
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_A,
            class: Record::CLASS_IN,
            ttl: 300,
            rdata: 'ns2.appwrite.zone',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid IPv4 address: ns2.appwrite.zone');

        $record->validateRdata();
    }

    public function testValidateRdataAcceptsHostnameForNsRecord(): void
    {
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_NS,
            class: Record::CLASS_IN,
            ttl: 300,
            rdata: 'ns2.appwrite.zone',
        );

        $record->validateRdata();

        $this->addToAssertionCount(1);
    }

    public function testConstructorTrimsWhitespaceFromName(): void
    {
        $record = new Record(
            name: '  example.com  ',
            type: Record::TYPE_A,
            class: Record::CLASS_IN,
            ttl: 300,
            rdata: '93.184.216.34',
        );

        $this->assertSame('example.com', $record->name);
    }

    public function testConstructorTrimsTabsAndNewlinesFromName(): void
    {
        $record = new Record(
            name: "\t\nexample.com\r\n",
            type: Record::TYPE_A,
            class: Record::CLASS_IN,
            ttl: 300,
            rdata: '93.184.216.34',
        );

        $this->assertSame('example.com', $record->name);
    }

    public function testWithNameTrimsWhitespace(): void
    {
        $record = new Record(
            name: 'example.com',
            type: Record::TYPE_A,
            class: Record::CLASS_IN,
            ttl: 300,
            rdata: '93.184.216.34',
        );

        $renamed = $record->withName('  other.com  ');
        $this->assertSame('other.com', $renamed->name);
    }
}
