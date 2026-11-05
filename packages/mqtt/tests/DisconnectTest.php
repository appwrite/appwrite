<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

final class DisconnectTest extends TestCase
{
    public function testRefuseWithoutAReasonEncodesJustTheCode(): void
    {
        $wire = $this->onWire(Disconnect::refuse(Disconnect::NOT_AUTHORIZED));

        $this->assertSame(Disconnect::NOT_AUTHORIZED, $wire['code']);
        $this->assertNull($wire['reason'], 'no Reason String reaches the client');
    }

    public function testRefuseEncodesTheReasonString(): void
    {
        $wire = $this->onWire(Disconnect::refuse(Disconnect::NOT_AUTHORIZED, 'Re-auth changed the resolved user'));

        $this->assertSame(Disconnect::NOT_AUTHORIZED, $wire['code']);
        $this->assertSame('Re-auth changed the resolved user', $wire['reason']);
    }

    public function testNormalEncodesReasonCodeZeroWithAnOptionalString(): void
    {
        $this->assertNull($this->onWire(Disconnect::normal())['reason']);

        $wire = $this->onWire(Disconnect::normal('Server shutting down'));
        $this->assertSame(Disconnect::NORMAL, $wire['code']);
        $this->assertSame('Server shutting down', $wire['reason']);
    }

    public function testEmptyReasonStringIsNotEmittedOnTheWire(): void
    {
        // An empty string is not a diagnostic, so no (blank) Reason String must appear on the wire.
        $this->assertNull($this->onWire(Disconnect::refuse(Disconnect::NOT_AUTHORIZED, ''))['reason']);
    }

    /**
     * Encode a Disconnect the way the server does for a 5.0 client, then parse it back — so the
     * assertions are on the bytes a client actually receives, not on the object's internal shape.
     *
     * @return array{code: int, reason: string|null}
     */
    private function onWire(Disconnect $disconnect): array
    {
        $packet = Packet::parse(V5::disconnect($disconnect->reasonCode, $disconnect->properties));
        $this->assertSame(Packet::DISCONNECT, $packet->type);

        [$properties] = Properties::parse($packet->body, 1); // past the reason code byte

        return [
            'code' => ord($packet->body[0]),
            'reason' => $properties->get(Property::REASON_STRING),
        ];
    }
}
