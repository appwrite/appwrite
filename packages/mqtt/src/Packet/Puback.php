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
        [$packetId, $offset] = Packet::readInt16($body, 0);
        $reasonCode = isset($body[$offset]) ? Packet::readByte($body, $offset)[0] : 0;

        return new self($packetId, $reasonCode);
    }
}
