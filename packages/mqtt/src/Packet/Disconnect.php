<?php

namespace Utopia\Mqtt\Packet;

use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

class Disconnect
{
    public const NORMAL = 0x00;
    public const NOT_AUTHORIZED = 0x87;

    private function __construct(
        public readonly int $reasonCode = self::NORMAL,
        public readonly ?Properties $properties = null,
    ) {
    }

    public static function decode(string $body): self
    {
        if ($body === '') {
            return new self(self::NORMAL);
        }

        [$reasonCode] = Packet::readByte($body, 0);

        return new self($reasonCode);
    }

    /**
     * A server-initiated DISCONNECT with a reason code and, optionally, a human-readable
     * Reason String (MQTT 5.0 property 0x1F). The reason string is diagnostic only — 3.1.1
     * has no properties, so it is dropped there; never let a client depend on it.
     */
    public static function refuse(int $reasonCode = self::NOT_AUTHORIZED, ?string $reason = null): self
    {
        return new self($reasonCode, self::reasonProperties($reason));
    }

    public static function normal(?string $reason = null): self
    {
        return new self(self::NORMAL, self::reasonProperties($reason));
    }

    /** Wrap a non-empty reason string in a property block, or null when there is nothing to say. */
    private static function reasonProperties(?string $reason): ?Properties
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return (new Properties())->add(new Property(Property::REASON_STRING, $reason));
    }
}
