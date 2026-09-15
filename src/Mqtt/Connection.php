<?php

namespace Utopia\Mqtt;

/**
 * Per-connection state, keyed by the transport's file descriptor. A broker mutates it
 * across the packet lifecycle: CONNECT records the protocol level, client id and clean-start
 * flag; delivery draws outbound packet ids and tracks each QoS 1 message in flight until its
 * PUBACK. Application-specific data (whatever identity or metadata the broker resolved) is the
 * caller's to stash in `$context`; the library never interprets it.
 */
class Connection
{
    /** Protocol level: 4 = MQTT 3.1.1, 5 = MQTT 5.0. */
    public int $protocol = 4;

    /** Isolation key for this connection (a tenant, a project — whatever the broker scopes by). */
    public string $prefix = '';

    /** The MQTT Client Identifier: the client-supplied CONNECT id, or a server-assigned one (see setClientId). */
    private string $clientId = '';

    /** Clean Start (5.0) / Clean Session (3.1.1): true discards any stored session, so delivery is live-only. */
    public bool $cleanStart = true;

    /**
     * Opaque per-connection data the broker attaches (resolved identity, metadata, …).
     * The library stores it and hands it back; it never reads or interprets it.
     *
     * @var array<string, mixed>
     */
    public array $context = [];

    /** Counted as active (accepted CONNECT), for a balanced gauge. */
    public bool $active = false;

    /** CONNECT keep-alive interval in seconds; 0 disables keep-alive (the client is never reaped). */
    public int $keepAlive = 0;

    /** Absolute unix deadline (fractional seconds) past which a silent client is reaped; 0 while disabled. */
    public float $expiresAt = 0.0;

    /** The keep-alive wheel bucket (second) this connection currently sits in; 0 when not scheduled. */
    public int $wheelSlot = 0;

    private int $packetId = 0;

    /**
     * Outbound QoS 1 deliveries awaiting a PUBACK, keyed by packet id. Each entry keeps the
     * topic and the durable sequence delivered, so the matching PUBACK can advance that topic's
     * cursor. Emptied as acks arrive; the remaining entries are exactly this session's unacked
     * gaps, used to advance the cursor only to the highest *contiguous* ack.
     *
     * @var array<int, array{topic: string, sequence: int}>
     */
    private array $inflight = [];

    /** Wall-clock time (fractional unix seconds) the connection was opened, for its lifetime metric. */
    public readonly float $openedAt;

    public function __construct(
        public readonly int $fd,
    ) {
        $this->openedAt = microtime(true);
    }

    /**
     * Push the keep-alive deadline to $multiplier x the negotiated interval past $now — every
     * inbound packet is liveness. No-op when keep-alive is disabled.
     */
    public function updateExpiresAt(float $now, float $multiplier): void
    {
        if ($this->keepAlive > 0) {
            $this->expiresAt = $now + $this->keepAlive * $multiplier;
        }
    }

    /** Next outbound packet id, wrapping 1..65535 (0 is not allowed). */
    public function nextPacketId(): int
    {
        $this->packetId = ($this->packetId % 0xFFFF) + 1;

        return $this->packetId;
    }

    /** Record an outbound QoS 1 delivery so its PUBACK can be matched back to a topic and sequence. */
    public function track(int $packetId, string $topic, int $sequence): void
    {
        $this->inflight[$packetId] = ['topic' => $topic, 'sequence' => $sequence];
    }

    /**
     * Resolve a PUBACK to the delivery it acknowledges, removing it from the in-flight set.
     * Returns the topic, the acked sequence, and `cursor`: the highest sequence safe to persist
     * — one below the lowest sequence still in flight for the topic, or this ack's sequence once
     * nothing is pending. Advancing to `cursor` (not `sequence`) keeps a non-contiguous ack from
     * skipping an earlier unacked message; the worst case is a harmless tail re-delivery on
     * reconnect, never a lost message. Returns null for an unknown or duplicate ack.
     *
     * Example — messages 5, 6, 7 were delivered and the client acks 5, then 7 (6 is still
     * pending):
     *   ack 5 -> in flight {6, 7}, cursor = 5   (lowest pending is 6, so stop at 5)
     *   ack 7 -> in flight {6},    cursor = 5   (6 is still the gap, don't jump to 7)
     *   ack 6 -> in flight {},     cursor = 6   (nothing pending; 7 may be re-sent later)
     * The cursor never moves past the unacked 6, so on reconnect 6 is replayed, not lost.
     *
     * @return array{topic: string, sequence: int, cursor: int}|null
     */
    public function acknowledge(int $packetId): ?array
    {
        $delivery = $this->inflight[$packetId] ?? null;
        unset($this->inflight[$packetId]);
        if ($delivery === null) {
            return null;
        }

        $topic = $delivery['topic'];
        $pending = [];
        foreach ($this->inflight as $entry) {
            if ($entry['topic'] === $topic) {
                $pending[] = $entry['sequence'];
            }
        }

        $cursor = $pending === [] ? $delivery['sequence'] : (min($pending) - 1);

        return ['topic' => $topic, 'sequence' => $delivery['sequence'], 'cursor' => $cursor];
    }

    /**
     * Set the Client Identifier. A client-supplied id is stored verbatim; an empty id is
     * server-assigned (MQTT 3.1.3.1 / 5), here as a per-connection default. A broker that
     * needs a stable id across reconnects (e.g. to resume a session) should resolve its own
     * and pass it in rather than rely on this fallback.
     */
    public function setClientId(string $clientId): void
    {
        $this->clientId = $clientId !== '' ? $clientId : 'mqtt_' . $this->fd;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }
}
