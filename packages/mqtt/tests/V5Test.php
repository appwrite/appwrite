<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

final class V5Test extends TestCase
{
    public function testConnackWithEmptyProperties(): void
    {
        // [PUBLISH? no] 0x20, remaining 3, ack flags 0, reason 0, property length 0.
        $this->assertSame("\x20\x03\x00\x00\x00", V5::connack(V5::REASON_SUCCESS));
    }

    public function testAckEncoders(): void
    {
        $this->assertSame("\x40\x02\x00\x07", V5::puback("\x00\x07"));
        $this->assertSame("\x90\x04\x00\x01\x00\x01", V5::suback("\x00\x01", chr(Packet::QOS_1)));
        $this->assertSame("\xB0\x04\x00\x01\x00\x00", V5::unsuback("\x00\x01", 1));
        $this->assertSame("\xE0\x02\x87\x00", V5::disconnect(V5::REASON_NOT_AUTHORIZED));
    }

    public function testPublishCarriesPropertiesBeforePayload(): void
    {
        $properties = (new Properties())->add(new Property(Property::USER, ['k' => 'v']));
        $packet = Packet::parse(V5::publish('sport/tennis', 'hello', 1, 7, $properties));

        $this->assertSame(Packet::PUBLISH, $packet->type);
        $this->assertSame(1, $packet->qos());

        [$topic, $offset] = Packet::readString($packet->body, 0);
        $this->assertSame('sport/tennis', $topic);
        $this->assertSame("\x00\x07", substr($packet->body, $offset, 2)); // packet id

        [$parsed, $offset] = Properties::parse($packet->body, $offset + 2);
        $this->assertSame(['k' => 'v'], $parsed->user());
        $this->assertSame('hello', substr($packet->body, $offset));
    }

    public function testConnackReasonAndPropertiesRoundTrip(): void
    {
        $properties = (new Properties())->add(new Property(Property::REASON_STRING, 'welcome'));
        $packet = Packet::parse(V5::connack(V5::REASON_SUCCESS, $properties));

        $this->assertSame(Packet::CONNACK, $packet->type);
        $this->assertSame(V5::REASON_SUCCESS, ord($packet->body[1])); // reason code (after ack flags)

        [$parsed] = Properties::parse($packet->body, 2);
        $this->assertSame('welcome', $parsed->get(Property::REASON_STRING));
    }

    public function testConnectCarriesAuthPropertiesAndClientId(): void
    {
        $properties = (new Properties())
            ->add(new Property(Property::AUTHENTICATION_METHOD, 'appwrite-jwt'))
            ->add(new Property(Property::USER, ['projectId' => 'p1']));
        $packet = Packet::parse(V5::connect('client-1', 60, true, $properties));

        $this->assertSame(Packet::CONNECT, $packet->type);

        [$protocolName, $offset] = Packet::readString($packet->body, 0);
        $this->assertSame('MQTT', $protocolName);
        $this->assertSame(V5::PROTOCOL_LEVEL, ord($packet->body[$offset]));       // protocol level
        $this->assertSame(0x02, ord($packet->body[$offset + 1]) & 0x02);          // clean start

        [$parsed, $offset] = Properties::parse($packet->body, $offset + 4);       // past level+flags+keepalive
        $this->assertSame('appwrite-jwt', $parsed->get(Property::AUTHENTICATION_METHOD));
        $this->assertSame(['projectId' => 'p1'], $parsed->user());

        [$clientId] = Packet::readString($packet->body, $offset);
        $this->assertSame('client-1', $clientId);
    }

    public function testSubscribeAndUnsubscribeAreFlagged(): void
    {
        $subscribe = Packet::parse(V5::subscribe(1, ['sensors/+/temp'], 1));
        $this->assertSame(Packet::SUBSCRIBE, $subscribe->type);
        $this->assertSame(0x02, $subscribe->flags); // reserved SUBSCRIBE flags

        [$filter, $offset] = Packet::readString($subscribe->body, Properties::skip($subscribe->body, 2));
        $this->assertSame('sensors/+/temp', $filter);
        $this->assertSame(1, ord($subscribe->body[$offset])); // options byte: QoS 1

        $unsubscribe = Packet::parse(V5::unsubscribe(2, ['sensors/+/temp']));
        $this->assertSame(Packet::UNSUBSCRIBE, $unsubscribe->type);
        $this->assertSame(0x02, $unsubscribe->flags);
    }

    public function testAuthCarriesMethodInProperties(): void
    {
        $properties = (new Properties())->add(new Property(Property::AUTHENTICATION_METHOD, 'appwrite-jwt'));
        $packet = Packet::parse(V5::auth(V5::AUTH_SUCCESS, $properties));

        $this->assertSame(Packet::AUTH, $packet->type);
        $this->assertSame(V5::AUTH_SUCCESS, ord($packet->body[0])); // reason code

        [$parsed] = Properties::parse($packet->body, 1);
        $this->assertSame('appwrite-jwt', $parsed->get(Property::AUTHENTICATION_METHOD));
    }
}
