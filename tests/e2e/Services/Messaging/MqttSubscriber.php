<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Messaging;

use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

/**
 * A minimal MQTT 5.0 subscriber for the broker e2e tests, driven straight over a
 * socket with the utopia-php/mqtt codec: enhanced-auth CONNECT, SUBSCRIBE, and QoS 1
 * consume (PUBACK per message). Purpose-built so tests control the client id and
 * cleanStart flag — i.e. persistent sessions, which the messaging push adapter does
 * not expose — so offline session replay can be exercised end to end.
 */
final class MqttSubscriber
{
    /** @var resource|null */
    private $socket = null;

    private int $packetId = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $connectTimeout = 10.0,
    ) {
    }

    /**
     * Enhanced-auth CONNECT. Returns the CONNACK reason code (0 = success); the broker
     * rejects with 0x87 (not authorized). Throws only on a transport failure.
     */
    public function connect(string $projectId, string $credential, string $clientId, bool $cleanStart, string $authMethod = 'appwrite-jwt'): int
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $this->connectTimeout);
        if ($socket === false) {
            throw new \RuntimeException("mqtt connect failed: {$errstr} ({$errno})");
        }
        $this->socket = $socket;

        $properties = new Properties();
        $properties->add(new Property(Property::AUTHENTICATION_METHOD, $authMethod));
        $properties->add(new Property(Property::AUTHENTICATION_DATA, $credential));
        $properties->add(new Property(Property::USER, ['projectId' => $projectId]));

        $this->write(V5::connect($clientId, 60, $cleanStart, $properties));

        $packet = $this->readPacket(microtime(true) + $this->connectTimeout);
        if ($packet === null || $packet->type !== Packet::CONNACK) {
            throw new \RuntimeException('mqtt: no CONNACK received');
        }

        // CONNACK body: [acknowledge flags][reason code][properties].
        return \ord($packet->body[1] ?? "\x80");
    }

    /**
     * SUBSCRIBE to the given topic filters at QoS 1; returns once the SUBACK arrives.
     *
     * @param string[] $topics
     */
    public function subscribe(array $topics, float $timeout = 5.0): void
    {
        $this->write(V5::subscribe($this->nextPacketId(), $topics, Packet::QOS_1));

        $deadline = microtime(true) + $timeout;
        while (true) {
            $packet = $this->readPacket($deadline);
            if ($packet === null) {
                throw new \RuntimeException('mqtt: no SUBACK received');
            }
            if ($packet->type === Packet::SUBACK) {
                return;
            }
            // Ignore any early PUBLISH replay queued before the SUBACK; consume() reads those.
        }
    }

    /**
     * Consume up to $limit QoS 1 PUBLISH messages within $timeout seconds, PUBACKing each.
     * $shouldAck, given the 0-based index of a received message, may return false to skip
     * its PUBACK — used to simulate non-contiguous acknowledgement.
     *
     * @param  (callable(int): bool)|null  $shouldAck
     * @return array<int, array{topic: string, payload: string, dup: bool}>
     */
    public function consume(int $limit, float $timeout, ?callable $shouldAck = null): array
    {
        $deadline = microtime(true) + $timeout;
        $received = [];

        while (\count($received) < $limit) {
            $packet = $this->readPacket($deadline);
            if ($packet === null) {
                break; // timeout or disconnect
            }
            if ($packet->type !== Packet::PUBLISH) {
                continue;
            }

            $body = $packet->body;
            [$topic, $offset] = Packet::readString($body, 0);

            $packetIdBytes = '';
            if ($packet->qos() > 0) {
                $packetIdBytes = substr($body, $offset, 2);
                $offset += 2;
            }
            $offset = Properties::skip($body, $offset);
            $payload = substr($body, $offset);

            $received[] = ['topic' => $topic, 'payload' => $payload, 'dup' => $packet->dup()];

            $ack = $shouldAck === null || $shouldAck(\count($received) - 1);
            if ($ack && $packet->qos() === 1 && $packetIdBytes !== '') {
                $this->write(V5::puback($packetIdBytes));
            }
        }

        return $received;
    }

    public function disconnect(): void
    {
        if (!\is_resource($this->socket)) {
            return;
        }
        @fwrite($this->socket, V5::disconnect(0)); // 0x00 = normal disconnection
        @fclose($this->socket);
        $this->socket = null;
    }

    private function write(string $packet): void
    {
        if (!\is_resource($this->socket)) {
            throw new \RuntimeException('mqtt: socket is not connected');
        }
        @fwrite($this->socket, $packet);
    }

    /** Read one whole MQTT packet (fixed header + remaining length + body), or null on timeout/EOF. */
    private function readPacket(float $deadline): ?Packet
    {
        $header = $this->readBytes(1, $deadline);
        if ($header === null) {
            return null;
        }

        // Remaining Length is a 1-4 byte variable-length integer.
        $lengthBytes = '';
        $multiplier = 1;
        $length = 0;
        do {
            $next = $this->readBytes(1, $deadline);
            if ($next === null) {
                return null;
            }
            $lengthBytes .= $next;
            $byte = \ord($next);
            $length += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0 && \strlen($lengthBytes) < 4);

        $body = $length > 0 ? $this->readBytes($length, $deadline) : '';
        if ($body === null) {
            return null;
        }

        return Packet::parse($header . $lengthBytes . $body);
    }

    /** Read exactly $n bytes before $deadline, or null on timeout/EOF. */
    private function readBytes(int $n, float $deadline): ?string
    {
        $buffer = '';
        while (\strlen($buffer) < $n) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            $read = [$this->socket];
            $write = null;
            $except = null;
            $seconds = (int) $remaining;
            $ready = @stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000));
            if ($ready === false) {
                return null;
            }
            if ($ready === 0) {
                continue; // nothing readable yet; re-check the deadline
            }

            $chunk = @fread($this->socket, $n - \strlen($buffer));
            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                return null;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function nextPacketId(): int
    {
        $this->packetId = ($this->packetId % 65535) + 1;

        return $this->packetId;
    }
}
