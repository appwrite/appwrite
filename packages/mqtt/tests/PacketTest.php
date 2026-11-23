<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V3;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

final class PacketTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function lengthProvider(): array
    {
        // Boundary values of the MQTT variable-length integer (1..4 bytes).
        return [
            '0'        => [0],
            '127'      => [127],
            '128'      => [128],
            '16383'    => [16383],
            '16384'    => [16384],
            '2097151'  => [2097151],
            '2097152'  => [2097152],
        ];
    }

    #[DataProvider('lengthProvider')]
    public function testVariableLengthRoundTrips(int $value): void
    {
        $encoded = Packet::encodeLength($value);
        [$decoded, $bytes] = Packet::decodeLength($encoded, 0);

        $this->assertSame($value, $decoded);
        $this->assertSame(strlen($encoded), $bytes);
    }

    public function testStringRoundTrips(): void
    {
        $encoded = Packet::encodeString('appwrite/push/user-1');
        [$value, $offset] = Packet::readString($encoded, 0);

        $this->assertSame('appwrite/push/user-1', $value);
        $this->assertSame(strlen($encoded), $offset);
    }

    public function testParseReadsFixedHeaderAndName(): void
    {
        $packet = Packet::parse(Packet::pingresp());

        $this->assertSame(Packet::PINGRESP, $packet->type);
        $this->assertSame('pingresp', $packet->name());
        $this->assertSame('', $packet->body);
    }

    public function testQosIsReadFromFlags(): void
    {
        $packet = Packet::parse(V3::publish('sport/tennis', 'hi', 1, 7));

        $this->assertSame(Packet::PUBLISH, $packet->type);
        $this->assertSame(1, $packet->qos());
    }

    public function testPingPackets(): void
    {
        $this->assertSame("\xC0\x00", Packet::pingreq());
        $this->assertSame("\xD0\x00", Packet::pingresp());
    }

    public function testCleanStartReadsTheFlagBitInBothVersions(): void
    {
        $properties = (new Properties())->add(new Property(Property::USER, ['projectId' => 'p1']));

        $this->assertTrue(Packet::isCleanStart(Packet::parse(V5::connect('c', 60, true, $properties))->body));
        $this->assertFalse(Packet::isCleanStart(Packet::parse(V5::connect('c', 60, false, $properties))->body));
        $this->assertTrue(Packet::isCleanStart(Packet::parse(V3::connect('c', 60, true))->body));
        $this->assertFalse(Packet::isCleanStart(Packet::parse(V3::connect('c', 60, false))->body));
    }

    public function testClientIdIsReadPastTheV5PropertyBlock(): void
    {
        $properties = (new Properties())
            ->add(new Property(Property::AUTHENTICATION_METHOD, 'appwrite-jwt'))
            ->add(new Property(Property::USER, ['projectId' => 'p1']));

        $this->assertSame('device-tv', Packet::getClientId(Packet::parse(V5::connect('device-tv', 60, false, $properties))->body));
    }

    public function testClientIdIsReadDirectlyOnV3(): void
    {
        $this->assertSame('device-tv', Packet::getClientId(Packet::parse(V3::connect('device-tv', 60, true))->body));
    }

    public function testEmptyClientIdReadsAsEmptyString(): void
    {
        $this->assertSame('', Packet::getClientId(Packet::parse(V5::connect('', 60, true))->body));
    }

    public function testReadInt16DecodesTwoBigEndianBytes(): void
    {
        $this->assertSame([0x1234, 2], Packet::readInt16("\x12\x34", 0));
        $this->assertSame([7, 4], Packet::readInt16("\xFF\xFF\x00\x07", 2)); // read at an offset
    }

    public function testDupFlagRoundTripsOnBothVersions(): void
    {
        $this->assertTrue(Packet::parse(V5::publish('t', 'p', 1, 7, dup: true))->dup());
        $this->assertFalse(Packet::parse(V5::publish('t', 'p', 1, 7))->dup());
        $this->assertTrue(Packet::parse(V3::publish('t', 'p', 1, 7, dup: true))->dup());
        $this->assertFalse(Packet::parse(V3::publish('t', 'p', 1, 7))->dup());
    }

    public function testDupIsMaskedOnQos0(): void
    {
        // DUP must be 0 on QoS 0, even when a re-delivery is requested.
        $this->assertFalse(Packet::parse(V5::publish('t', 'p', 0, 0, dup: true))->dup());
        $this->assertFalse(Packet::parse(V3::publish('t', 'p', 0, 0, dup: true))->dup());
    }
}
