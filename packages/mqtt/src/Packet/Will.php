<?php

namespace Utopia\Mqtt\Packet;

class Will
{
    public function __construct(
        public readonly string $topic,
        public readonly string $payload,
        public readonly int $qos,
        public readonly bool $retain,
    ) {
    }
}
