<?php

namespace Utopia\Mqtt\Adapter\Swoole;

class Tcp extends Transport
{
    public function __construct(
        string $host = '0.0.0.0',
        int $port = 1883,
        private readonly int $maxPacketSize = 64000,
    ) {
        parent::__construct($host, $port);
    }

    public function getSockType(): int
    {
        return SWOOLE_SOCK_TCP;
    }

    public function getSettings(): array
    {
        return [
            'open_mqtt_protocol' => true,
            'package_max_length' => $this->maxPacketSize,
        ];
    }
}
