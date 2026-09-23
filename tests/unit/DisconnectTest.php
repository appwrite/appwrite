<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

final class DisconnectTest extends TestCase
{
    public function testRefuseWithoutAReasonCarriesNoProperties(): void
    {
        $disconnect = Disconnect::refuse(Disconnect::NOT_AUTHORIZED);

        $this->assertSame(Disconnect::NOT_AUTHORIZED, $disconnect->reasonCode);
        $this->assertNull($disconnect->properties);
    }

    public function testRefuseCarriesTheReasonStringAsAProperty(): void
    {
        $disconnect = Disconnect::refuse(Disconnect::NOT_AUTHORIZED, 'Re-auth changed the resolved user');

        $this->assertSame(Disconnect::NOT_AUTHORIZED, $disconnect->reasonCode);
        $this->assertNotNull($disconnect->properties);
        $this->assertSame('Re-auth changed the resolved user', $disconnect->properties->get(Property::REASON_STRING));
    }

    public function testNormalCarriesTheReasonStringWhenGiven(): void
    {
        $this->assertNull(Disconnect::normal()->properties);
        $this->assertSame('Server shutting down', Disconnect::normal('Server shutting down')->properties?->get(Property::REASON_STRING));
    }

    public function testEmptyReasonStringIsTreatedAsAbsent(): void
    {
        // An empty string is not a diagnostic, so it must not add an (empty) Reason String property.
        $this->assertNull(Disconnect::refuse(Disconnect::NOT_AUTHORIZED, '')->properties);
    }

    public function testReasonStringSurvivesEncodingOnTheWire(): void
    {
        $disconnect = Disconnect::refuse(Disconnect::NOT_AUTHORIZED, 'quota exceeded');
        $packet = Packet::parse(V5::disconnect($disconnect->reasonCode, $disconnect->properties));

        $this->assertSame(Packet::DISCONNECT, $packet->type);
        $this->assertSame(Disconnect::NOT_AUTHORIZED, ord($packet->body[0])); // reason code

        [$parsed] = Properties::parse($packet->body, 1);
        $this->assertSame('quota exceeded', $parsed->get(Property::REASON_STRING));
    }
}
