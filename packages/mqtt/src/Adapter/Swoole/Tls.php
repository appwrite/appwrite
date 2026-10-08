<?php

namespace Utopia\Mqtt\Adapter\Swoole;

class Tls extends Transport
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $cert,
        private readonly string $key,
    ) {
        parent::__construct($transport->host, $transport->port);
    }

    public function getSockType(): int
    {
        return $this->transport->getSockType() | SWOOLE_SSL;
    }

    public function getSettings(): array
    {
        return $this->transport->getSettings() + [
            'ssl_cert_file' => $this->cert,
            'ssl_key_file' => $this->key,
        ];
    }

    public function isWebSocket(): bool
    {
        return $this->transport->isWebSocket();
    }
}
