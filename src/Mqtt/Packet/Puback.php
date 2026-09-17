<?php

namespace Utopia\Mqtt\Packet;

use Utopia\Mqtt\Packet;

class Puback
{
    private function __construct(
        public readonly int $packetId,
        public readonly int $reasonCode = 0,
    ) {
    }

    public static function decode(string $body): self
    {
        [$packetId] = Packet::readInt16($body, 0);
        $reasonCode = isset($body[2]) ? ord($body[2]) : 0;

        return new self($packetId, $reasonCode);
    }
}
