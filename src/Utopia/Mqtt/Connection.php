<?php

namespace Utopia\Mqtt;

/**
 * Per-connection state, keyed by the transport's file descriptor. Handlers mutate
 * it across the packet lifecycle: CONNECT records the protocol level and resolved
 * identity, PUBLISH draws outbound packet ids for QoS 1 delivery.
 */
class Connection
{
    /** Protocol level: 4 = MQTT 3.1.1, 5 = MQTT 5.0. */
    public int $protocol = 4;

    /** Project id from the CONNECT User Property. */
    public string $projectId = '';

    /** @var array<string, string> resolved identity (project/user ids) from the authenticator */
    public array $identity = [];

    /** Counted as active (accepted CONNECT), for a balanced gauge. */
    public bool $active = false;

    private int $packetId = 0;

    public function __construct(
        public readonly int $fd,
    ) {
    }

    /** Next outbound packet id, wrapping 1..65535 (0 is not allowed). */
    public function nextPacketId(): int
    {
        $this->packetId = ($this->packetId % 0xFFFF) + 1;

        return $this->packetId;
    }
}
