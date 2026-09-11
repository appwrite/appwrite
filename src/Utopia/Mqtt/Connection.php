<?php

namespace Utopia\Mqtt;

use Appwrite\Extend\Exception;

/**
 * Per-connection state, keyed by the transport's file descriptor. Handlers mutate
 * it across the packet lifecycle: CONNECT records the protocol level, client id,
 * clean-start flag, and resolved identity; delivery draws outbound packet ids and
 * tracks each QoS 1 message in flight until its PUBACK.
 */
class Connection
{
    /** Protocol level: 4 = MQTT 3.1.1, 5 = MQTT 5.0. */
    public int $protocol = 4;

    /** Project id from the CONNECT User Property. */
    public string $projectId = '';

    /** The per-device session anchor: the client-supplied CONNECT id, or an account-level fallback (see setClientId). */
    private string $clientId = '';

    /** Clean Start (5.0) / Clean Session (3.1.1): true discards any stored session, so delivery is live-only. */
    public bool $cleanStart = true;

    /** @var array<string, string> resolved identity (project/user ids) from the authenticator */
    public array $identity = [];

    /** Counted as active (accepted CONNECT), for a balanced gauge. */
    public bool $active = false;

    private int $packetId = 0;

    /**
     * Outbound QoS 1 deliveries awaiting a PUBACK, keyed by packet id. Each entry
     * keeps the topic and the durable sequence delivered, so the matching PUBACK
     * can advance that topic's cursor. Emptied as acks arrive; the remaining entries
     * are exactly this session's unacked gaps, used to advance the cursor only to the
     * highest *contiguous* ack (Redis holds only the committed cursor, not the gaps).
     *
     * @var array<int, array{topic: string, sequence: int}>
     */
    private array $inflight = [];

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

    /** Record an outbound QoS 1 delivery so its PUBACK can be matched back to a topic and sequence. */
    public function track(int $packetId, string $topic, int $sequence): void
    {
        $this->inflight[$packetId] = ['topic' => $topic, 'sequence' => $sequence];
    }

    /**
     * Resolve a PUBACK to the delivery it acknowledges, removing it from the in-flight
     * set. Returns the topic, the acked sequence, and `cursor`: the highest sequence
     * safe to persist — one below the lowest sequence still in flight for the topic, or
     * this ack's sequence once nothing is pending. Advancing to `cursor` (not `sequence`)
     * keeps a non-contiguous ack from skipping an earlier unacked message; the worst case
     * is a harmless tail re-delivery on reconnect, never a lost message. Returns null for
     * an unknown or duplicate ack.
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

    public function setClientId(string $clientId): void
    {
        if ($this->identity === []) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Client id cannot be set before the identity is resolved');
        }

        $this->clientId = $clientId !== ''
            ? $clientId
            : 'custom_' . $this->projectId . '_' . ($this->identity['userId'] ?? '');
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }
}
