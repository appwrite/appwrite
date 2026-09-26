<?php

namespace Utopia\Mqtt\Adapter\Swoole;

class Tls extends Transport
{
    public function __construct(
        string $host = '0.0.0.0',
        int $port = 8883,
        private readonly string $cert = '',
        private readonly string $key = '',
        private readonly int $maxPacketSize = 64000,
    ) {
        parent::__construct($host, $port);
    }

    public function getSockType(): int
    {
        return SWOOLE_SOCK_TCP | SWOOLE_SSL;
    }

    public function getSettings(): array
    {
        return [
            'open_mqtt_protocol' => true,
            'package_max_length' => $this->maxPacketSize,
            'ssl_cert_file' => $this->cert,
            'ssl_key_file' => $this->key,
        ];
    }
}
