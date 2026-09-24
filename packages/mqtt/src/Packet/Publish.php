<?php

namespace Utopia\Mqtt\Packet;

use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Properties;

class Publish
{
    private function __construct(
        public readonly string $topic,
        public readonly string $payload,
        public readonly int $qos,
        public readonly bool $dup,
        public readonly bool $retain,
        public readonly int $packetId,
        public readonly ?Properties $properties = null,
    ) {
    }

    public static function decode(Packet $packet, int $protocol): self
    {
        $body = $packet->body;
        [$topic, $offset] = Packet::readString($body, 0);

        $packetId = 0;
        if ($packet->qos() > 0) {
            [$packetId, $offset] = Packet::readInt16($body, $offset);
        }

        $properties = null;
        if ($protocol >= 5) {
            [$properties, $offset] = Properties::parse($body, $offset);
        }

        return new self(
            $topic,
            substr($body, $offset),
            $packet->qos(),
            $packet->dup(),
            ($packet->flags & 0x01) === 0x01,
            $packetId,
            $properties,
        );
    }

    /** @return array<string, string> */
    public function userProperties(): array
    {
        return $this->properties?->user() ?? [];
    }
}
