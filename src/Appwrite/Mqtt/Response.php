<?php

namespace Appwrite\Mqtt;

use Utopia\Mqtt\Server;
use Utopia\Telemetry\Counter;

/**
 * Writes MQTT packets back to a single connection. Each packet handler receives this
 * typed handle instead of a raw callback, so the reply surface is an explicit
 * send()/close() contract rather than an untyped closure whose shape every call site
 * has to infer.
 */
class Response
{
    public function __construct(
        private readonly Server $server,
        private readonly int $fd,
        private readonly Counter $bytesSent,
    ) {
    }

    /** Write one encoded MQTT packet back to the connection; an empty packet is a no-op. */
    public function send(string $packet): void
    {
        if ($packet === '') {
            return;
        }

        $this->server->send($this->fd, $packet);
        $this->bytesSent->add(\strlen($packet));
    }

    /** Close the connection. */
    public function close(): void
    {
        $this->server->close($this->fd);
    }
}
