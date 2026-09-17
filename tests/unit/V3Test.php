<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V3;

final class V3Test extends TestCase
{
    public function testConnackIsAckFlagsAndReturnCode(): void
    {
        // 0x20, remaining 2, ack flags 0, return code 0 — no property block.
        $this->assertSame("\x20\x02\x00\x00", V3::connack(V3::RETURN_ACCEPTED));
        $this->assertSame("\x20\x02\x00\x05", V3::connack(V3::RETURN_NOT_AUTHORIZED));
    }

    public function testAckEncoders(): void
    {
        $this->assertSame("\x40\x02\x00\x07", V3::puback("\x00\x07"));
        $this->assertSame("\x90\x03\x00\x01\x01", V3::suback("\x00\x01", chr(Packet::QOS_1)));
        $this->assertSame("\xB0\x02\x00\x01", V3::unsuback("\x00\x01")); // no reason codes in 3.1.1
        $this->assertSame("\xE0\x00", V3::disconnect());
    }

    public function testPublishHasNoPropertyBlock(): void
    {
        $packet = Packet::parse(V3::publish('sport/tennis', 'hello', 1, 7));

        $this->assertSame(Packet::PUBLISH, $packet->type);
        $this->assertSame(1, $packet->qos());

        [$topic, $offset] = Packet::readString($packet->body, 0);
        $this->assertSame('sport/tennis', $topic);
        $this->assertSame("\x00\x07", substr($packet->body, $offset, 2)); // packet id

        // No property block: payload follows the packet id directly.
        $this->assertSame('hello', substr($packet->body, $offset + 2));
    }

    public function testSubackFailureMarker(): void
    {
        $this->assertSame("\x90\x03\x00\x02\x80", V3::suback("\x00\x02", chr(V3::SUBSCRIBE_FAILURE)));
    }

    public function testConnectCarriesCredentials(): void
    {
        $packet = Packet::parse(V3::connect('v3-client', 60, true, 'user', 'secret'));

        $this->assertSame(Packet::CONNECT, $packet->type);

        [$protocolName, $offset] = Packet::readString($packet->body, 0);
        $this->assertSame('MQTT', $protocolName);
        $this->assertSame(V3::PROTOCOL_LEVEL, ord($packet->body[$offset]));
        $this->assertSame(0xC0, ord($packet->body[$offset + 1]) & 0xC0); // username + password flags
        $offset += 4;                                                     // level + flags + keepalive

        [$clientId, $offset] = Packet::readString($packet->body, $offset);
        [$username, $offset] = Packet::readString($packet->body, $offset);
        [$password] = Packet::readString($packet->body, $offset);
        $this->assertSame('v3-client', $clientId);
        $this->assertSame('user', $username);
        $this->assertSame('secret', $password);
    }

    public function testSubscribeAndUnsubscribeAreFlagged(): void
    {
        $subscribe = Packet::parse(V3::subscribe(1, ['news/tech'], 1));
        $this->assertSame(Packet::SUBSCRIBE, $subscribe->type);
        $this->assertSame(0x02, $subscribe->flags);

        [$filter, $offset] = Packet::readString($subscribe->body, 2); // no property block in 3.1.1
        $this->assertSame('news/tech', $filter);
        $this->assertSame(1, ord($subscribe->body[$offset])); // options byte: QoS 1

        $unsubscribe = Packet::parse(V3::unsubscribe(2, ['news/tech']));
        $this->assertSame(Packet::UNSUBSCRIBE, $unsubscribe->type);
        $this->assertSame(0x02, $unsubscribe->flags);
    }
}
