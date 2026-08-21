<?php

namespace Utopia\Mqtt;

use Swoole\Server;

class Broker
{
    // Control packet types (MQTT fixed header, high nibble).
    private const CONNECT = 1;
    private const PUBLISH = 3;
    private const SUBSCRIBE = 8;
    private const UNSUBSCRIBE = 10;
    private const PINGREQ = 12;
    private const DISCONNECT = 14;

    /** @var array<int, int> fd => protocol level (4 = 3.1.1, 5 = 5.0) */
    private array $protocol = [];

    /** @var array<int, array<string, true>> fd => set of subscribed topic filters */
    private array $subscriptions = [];

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 1883,
    ) {
    }

    public function start(): void
    {
        $server = new Server($this->host, $this->port, SWOOLE_BASE);
        $server->set([
            'open_mqtt_protocol' => true,
            'worker_num' => 1,
        ]);

        $server->on('receive', $this->onReceive(...));
        $server->on('close', $this->onClose(...));

        echo "MQTT broker listening on {$this->host}:{$this->port}\n";
        $server->start();
    }

    private function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        $type = ord($data[0]) >> 4;
        $flags = ord($data[0]) & 0x0F;

        [$remaining, $lenBytes] = $this->decodeLength($data, 1);
        $body = substr($data, 1 + $lenBytes, $remaining);

        match ($type) {
            self::CONNECT => $this->handleConnect($server, $fd, $body),
            self::SUBSCRIBE => $this->handleSubscribe($server, $fd, $body),
            self::UNSUBSCRIBE => $this->handleUnsubscribe($server, $fd, $body),
            self::PUBLISH => $this->handlePublish($server, $fd, $flags, $body),
            self::PINGREQ => $server->send($fd, chr(0xD0) . chr(0x00)),
            self::DISCONNECT => $server->close($fd),
            default => null,
        };
    }

    private function handleConnect(Server $server, int $fd, string $body): void
    {
        $offset = 0;
        [, $offset] = $this->readString($body, $offset); // protocol name
        $level = ord($body[$offset]);
        $this->protocol[$fd] = $level;

        // CONNACK: [ack flags = 0][reason code = 0](+ [property length = 0] for v5)
        $variable = chr(0x00) . chr(0x00) . ($level >= 5 ? chr(0x00) : '');
        $server->send($fd, chr(0x20) . $this->encodeLength(strlen($variable)) . $variable);
    }

    private function handleSubscribe(Server $server, int $fd, string $body): void
    {
        $offset = 0;
        $packetId = substr($body, 0, 2);
        $offset += 2;
        $offset = $this->skipProperties($fd, $body, $offset);

        $granted = '';
        while ($offset < strlen($body)) {
            [$filter, $offset] = $this->readString($body, $offset);
            $offset += 1; // subscription options byte
            $this->subscriptions[$fd][$filter] = true;
            $granted .= chr(0x00); // granted QoS 0
        }

        // SUBACK: packet id (+ property length 0 for v5) + granted codes
        $variable = $packetId . ($this->protocol[$fd] >= 5 ? chr(0x00) : '') . $granted;
        $server->send($fd, chr(0x90) . $this->encodeLength(strlen($variable)) . $variable);
    }

    private function handleUnsubscribe(Server $server, int $fd, string $body): void
    {
        $offset = 0;
        $packetId = substr($body, 0, 2);
        $offset += 2;
        $offset = $this->skipProperties($fd, $body, $offset);

        $count = 0;
        while ($offset < strlen($body)) {
            [$filter, $offset] = $this->readString($body, $offset);
            unset($this->subscriptions[$fd][$filter]);
            $count++;
        }

        // UNSUBACK: packet id (+ property length 0 + one reason code per filter for v5)
        $variable = $packetId;
        if ($this->protocol[$fd] >= 5) {
            $variable .= chr(0x00) . str_repeat(chr(0x00), $count);
        }
        $server->send($fd, chr(0xB0) . $this->encodeLength(strlen($variable)) . $variable);
    }

    private function handlePublish(Server $server, int $fd, int $flags, string $body): void
    {
        $qos = ($flags >> 1) & 0x03;

        [$topic, $offset] = $this->readString($body, 0);

        $packetId = null;
        if ($qos > 0) {
            $packetId = substr($body, $offset, 2);
            $offset += 2;
        }
        $offset = $this->skipProperties($fd, $body, $offset);
        $payload = substr($body, $offset);

        $this->deliver($server, $topic, $payload);

        if ($qos === 1 && $packetId !== null) {
            // PUBACK: packet id (reason/properties omitted -> valid for both versions)
            $server->send($fd, chr(0x40) . chr(0x02) . $packetId);
        }
    }

    private function deliver(Server $server, string $topic, string $payload): void
    {
        foreach ($this->subscriptions as $fd => $filters) {
            foreach ($filters as $filter => $_) {
                if ($this->matches($filter, $topic)) {
                    $variable = $this->encodeString($topic)
                        . (($this->protocol[$fd] ?? 4) >= 5 ? chr(0x00) : '')
                        . $payload;
                    // PUBLISH, QoS 0
                    $server->send($fd, chr(0x30) . $this->encodeLength(strlen($variable)) . $variable);
                    break; // one copy per subscriber even if several filters match
                }
            }
        }
    }

    private function onClose(Server $server, int $fd): void
    {
        unset($this->subscriptions[$fd], $this->protocol[$fd]);
    }

    /**
     * MQTT topic filter matching with '+' (single level) and '#' (multi level).
     */
    private function matches(string $filter, string $topic): bool
    {
        if ($filter === $topic) {
            return true;
        }

        $filterParts = explode('/', $filter);
        $topicParts = explode('/', $topic);

        foreach ($filterParts as $i => $part) {
            if ($part === '#') {
                return true;
            }
            if (!isset($topicParts[$i])) {
                return false;
            }
            if ($part !== '+' && $part !== $topicParts[$i]) {
                return false;
            }
        }

        return count($filterParts) === count($topicParts);
    }

    /**
     * Skip the MQTT 5.0 property block (variable-length length prefix + bytes).
     * No-op for MQTT 3.1.1, which has no properties.
     */
    private function skipProperties(int $fd, string $body, int $offset): int
    {
        if (($this->protocol[$fd] ?? 4) < 5) {
            return $offset;
        }
        [$length, $lenBytes] = $this->decodeLength($body, $offset);
        return $offset + $lenBytes + $length;
    }

    /** @return array{0: string, 1: int} decoded string and the new offset */
    private function readString(string $data, int $offset): array
    {
        $length = (ord($data[$offset]) << 8) + ord($data[$offset + 1]);
        $value = substr($data, $offset + 2, $length);
        return [$value, $offset + 2 + $length];
    }

    private function encodeString(string $value): string
    {
        $length = strlen($value);
        return chr($length >> 8) . chr($length & 0xFF) . $value;
    }

    /** Decode a variable-length integer. @return array{0: int, 1: int} value and byte count */
    private function decodeLength(string $data, int $offset): array
    {
        $value = 0;
        $multiplier = 1;
        $bytes = 0;
        do {
            $byte = ord($data[$offset + $bytes]);
            $value += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
            $bytes++;
        } while (($byte & 0x80) !== 0);

        return [$value, $bytes];
    }

    private function encodeLength(int $length): string
    {
        $out = '';
        do {
            $byte = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($length > 0);

        return $out;
    }
}
