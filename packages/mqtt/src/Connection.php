<?php

namespace Utopia\Mqtt;

use Utopia\Mqtt\Packet\Specs\V3;
use Utopia\Mqtt\Packet\Specs\V5;

/**
 * Per-connection state, keyed by the transport's file descriptor. A broker mutates it
 * across the packet lifecycle: CONNECT records the protocol level, client id and clean-start
 * flag; delivery draws outbound packet ids and tracks each QoS 1 message in flight until its
 * PUBACK. Application-specific data (whatever identity or metadata the broker resolved) is the
 * caller's to stash in `$identity`; the library never interprets it.
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
     * The identity (or any metadata) the broker resolved for this connection. Opaque to the
     * library — it stores it and hands it back, never reading or interpreting it.
     *
     * @var array<string, mixed>
     */
    public array $identity = [];

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
     * Outbound QoS 1 deliveries awaiting a PUBACK, keyed by packet id, so a PUBACK resolves back
     * to the topic and durable sequence it acknowledges.
     *
     * @var array<int, array{topic: string, sequence: int}>
     */
    private array $inflight = [];

    /** @var array<string, int> topic => highest contiguously-acknowledged sequence (its cursor) */
    private array $cursors = [];

    /** @var array<string, array<int, true>> topic => acked sequences sitting above the cursor, waiting on a gap */
    private array $acked = [];

    /** Wall-clock time (fractional unix seconds) the connection was opened, for its lifetime metric. */
    public readonly float $openedAt;

    public function __construct(
        public readonly int $fd,
        private readonly ?Adapter $adapter = null,
    ) {
        $this->openedAt = microtime(true);
    }

    public function publish(string $topic, string $payload, int $qos = 0, bool $dup = false, ?int $sequence = null): void
    {
        if ($this->adapter === null) {
            return;
        }

        $packetId = $qos > 0 ? $this->nextPacketId() : 0;
        $packet = $this->protocol >= V5::PROTOCOL_LEVEL
            ? V5::publish($topic, $payload, $qos, $packetId, null, $dup)
            : V3::publish($topic, $payload, $qos, $packetId, $dup);

        $this->adapter->send($this->fd, $packet);

        if ($qos === Packet::QOS_1 && $sequence !== null) {
            $this->track($packetId, $topic, $sequence);
        }
    }

    public function puback(int $packetId): void
    {
        if ($this->adapter === null) {
            return;
        }

        $id = \pack('n', $packetId);
        $this->adapter->send($this->fd, $this->protocol >= V5::PROTOCOL_LEVEL ? V5::puback($id) : V3::puback($id));
    }

    /**
     * Close this connection, first sending a DISCONNECT to the client. A non-zero reason code
     * (and, on 5.0, an optional human-readable Reason String) is only carried on the wire for
     * 5.0 clients; 3.1.1 has no server-initiated DISCONNECT, so the socket is just closed. The
     * close always happens — the caller does not close separately.
     */
    public function disconnect(int $reason = 0, ?string $reasonString = null): void
    {
        if ($this->adapter === null) {
            return;
        }

        try {
            if ($reason !== 0 && $this->protocol >= V5::PROTOCOL_LEVEL) {
                $properties = null;
                if ($reasonString !== null && $reasonString !== '') {
                    $properties = (new Properties())->add(new Property(Property::REASON_STRING, $reasonString));
                }
                $this->adapter->send($this->fd, V5::disconnect($reason, $properties));
            }
        } finally {
            // Closing is the point of this method: a failed courtesy DISCONNECT must not leak the socket.
            $this->adapter->close($this->fd);
        }
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

    /**
     * Anchor a topic's cursor at the sequence the broker is resuming from — its persisted cursor,
     * so replayed and live deliveries are acknowledged against a known-safe baseline. Call this
     * before delivering a topic; it is the order-independent way to seed the cursor. Without it,
     * track() falls back to anchoring at the first delivery, which is safe only when a topic's
     * deliveries are tracked in non-decreasing sequence order.
     */
    public function resume(string $topic, int $cursor): void
    {
        $this->cursors[$topic] = $cursor;
    }

    /**
     * Record an outbound QoS 1 delivery so its PUBACK can be matched back to a topic and sequence.
     * When the topic has not been seeded with resume(), the first delivery anchors its cursor one
     * below its sequence — correct only when deliveries for the topic are tracked in order.
     */
    public function track(int $packetId, string $topic, int $sequence): void
    {
        $this->inflight[$packetId] = ['topic' => $topic, 'sequence' => $sequence];

        if (!isset($this->cursors[$topic])) {
            $this->cursors[$topic] = $sequence - 1;
        }
    }

    /**
     * Resolve a PUBACK to the delivery it acknowledges, advancing the topic's cursor across the
     * now-contiguous run of acknowledged sequences. `cursor` is the highest sequence safe to
     * persist for replay: it moves up only while the next sequence has also been acked, so an
     * unacked or never-delivered gap is never skipped — a non-contiguous ack simply waits for the
     * gap to fill, the worst case being a harmless tail re-delivery on reconnect, never a lost
     * message. Returns null for an unknown or duplicate ack.
     *
     * Example — 5, 6, 7 delivered; the client acks 5, then 7, then 6:
     *   ack 5 -> cursor = 5   (contiguous from the resume point)
     *   ack 7 -> cursor = 5   (6 is an open gap, so 7 waits above the cursor)
     *   ack 6 -> cursor = 7   (the gap fills, so the cursor runs up through 7)
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
        $sequence = $delivery['sequence'];

        $this->acked[$topic][$sequence] = true;

        // Advance only across a contiguous run of acked sequences, so a gap is never skipped.
        $cursor = $this->cursors[$topic] ?? ($sequence - 1);
        while (isset($this->acked[$topic][$cursor + 1])) {
            unset($this->acked[$topic][$cursor + 1]);
            $cursor++;
        }
        $this->cursors[$topic] = $cursor;

        return ['topic' => $topic, 'sequence' => $sequence, 'cursor' => $cursor];
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
