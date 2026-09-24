<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

final class PropertiesTest extends TestCase
{
    public function testEmptyBlockIsASingleZeroLengthByte(): void
    {
        $this->assertSame("\x00", (new Properties())->encode());
    }

    public function testEncodesAndParsesEveryWireType(): void
    {
        $properties = (new Properties())
            ->add(new Property(Property::MAXIMUM_QOS, 1))                       // byte
            ->add(new Property(Property::RECEIVE_MAXIMUM, 10))                  // int16
            ->add(new Property(Property::SESSION_EXPIRY_INTERVAL, 3600))       // int32
            ->add(new Property(Property::SUBSCRIPTION_IDENTIFIER, 268435455))  // varint (4-byte)
            ->add(new Property(Property::REASON_STRING, 'ok'))                 // string
            ->add(new Property(Property::USER, ['projectId' => 'proj-1']));    // pair

        [$parsed, $offset] = Properties::parse($properties->encode(), 0);

        $this->assertSame(strlen($properties->encode()), $offset);
        $this->assertSame(1, $parsed->get(Property::MAXIMUM_QOS));
        $this->assertSame(10, $parsed->get(Property::RECEIVE_MAXIMUM));
        $this->assertSame(3600, $parsed->get(Property::SESSION_EXPIRY_INTERVAL));
        $this->assertSame(268435455, $parsed->get(Property::SUBSCRIPTION_IDENTIFIER));
        $this->assertSame('ok', $parsed->get(Property::REASON_STRING));
        $this->assertSame(['projectId' => 'proj-1'], $parsed->user());
    }

    public function testUserPropertiesMerge(): void
    {
        $encoded = (new Properties())
            ->add(new Property(Property::USER, ['projectId' => 'proj-1']))
            ->add(new Property(Property::USER, ['subId' => 'orders']))
            ->encode();

        [$parsed] = Properties::parse($encoded, 0);

        $this->assertSame(['projectId' => 'proj-1', 'subId' => 'orders'], $parsed->user());
    }

    public function testAuthMethodAndDataRoundTrip(): void
    {
        $encoded = (new Properties())
            ->add(new Property(Property::AUTHENTICATION_METHOD, 'appwrite-jwt'))
            ->add(new Property(Property::AUTHENTICATION_DATA, 'the-token'))
            ->encode();

        [$parsed] = Properties::parse($encoded, 0);

        $this->assertSame('appwrite-jwt', $parsed->get(Property::AUTHENTICATION_METHOD));
        $this->assertSame('the-token', $parsed->get(Property::AUTHENTICATION_DATA));
    }

    public function testGetReturnsNullWhenAbsent(): void
    {
        [$parsed] = Properties::parse((new Properties())->encode(), 0);

        $this->assertNull($parsed->get(Property::REASON_STRING));
    }

    public function testSkipAdvancesPastTheBlock(): void
    {
        $block = (new Properties())
            ->add(new Property(Property::SESSION_EXPIRY_INTERVAL, 60))
            ->encode();
        $wire = $block . 'PAYLOAD';

        $offset = Properties::skip($wire, 0);

        $this->assertSame('PAYLOAD', substr($wire, $offset));
    }

    public function testUnknownPropertyIdThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Property(0x7F, 'x'))->encode();
    }
}
