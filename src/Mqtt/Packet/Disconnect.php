<?php

namespace Utopia\Mqtt\Packet;

use Utopia\Mqtt\Properties;

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
        return new self($body === '' ? self::NORMAL : ord($body[0]));
    }

    public static function refuse(int $reasonCode = self::NOT_AUTHORIZED): self
    {
        return new self($reasonCode);
    }

    public static function normal(): self
    {
        return new self(self::NORMAL);
    }
}
