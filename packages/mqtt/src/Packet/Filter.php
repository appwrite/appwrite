<?php

namespace Utopia\Mqtt\Packet;

class Filter
{
    public function __construct(
        public readonly string $topic,
        public readonly int $qos,
    ) {
    }
}
