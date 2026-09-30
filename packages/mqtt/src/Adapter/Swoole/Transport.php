<?php

namespace Utopia\Mqtt\Adapter\Swoole;

abstract class Transport
{
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 1883,
    ) {
    }

    abstract public function getSockType(): int;

    /** @return array<string, mixed> */
    abstract public function getSettings(): array;
}
