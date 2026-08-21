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
    private const AUTH = 15;

    // Authenticate reason codes (MQTT 5.0).
    private const AUTH_SUCCESS = 0x00;
    private const AUTH_CONTINUE = 0x18;
    private const AUTH_REAUTH = 0x19;

    // Property identifiers, shared between CONNECT and AUTH property blocks.
    private const PROP_AUTH_METHOD = 0x15;
    private const PROP_AUTH_DATA = 0x16;
    private const PROP_USER = 0x26;

    /** @var array<int, int> fd => protocol level (4 = 3.1.1, 5 = 5.0) */
    private array $protocol = [];

    /** @var array<int, string> fd => project id (from the CONNECT username field) */
    private array $project = [];

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
            self::AUTH => $this->handleAuth($server, $fd, $body),
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
        $offset += 1; // protocol level
        $offset += 1; // connect flags
        $offset += 2; // keep alive

        // CONNECT properties carry enhanced auth and our custom metadata.
        [$userProperties] = $this->readUserProperties($fd, $body, $offset);

        // projectId is sent as a User Property. deviceType and any other client
        // metadata can be added the same way, e.g. $userProperties['deviceType'].
        $project = $userProperties['projectId'] ?? '';

        $this->project[$fd] = $project;
        echo "CONNECT project={$project}\n";

        // TODO: select the project DB from $project and verify the session credential.

        // CONNACK: [ack flags = 0][reason code = 0](+ [property length = 0] for v5)
        $variable = chr(0x00) . chr(0x00) . ($level >= 5 ? chr(0x00) : '');
        $server->send($fd, chr(0x20) . $this->encodeLength(strlen($variable)) . $variable);
    }

    /**
     * Read the User Properties from a v5 CONNECT property block as a key/value map,
     * skipping the auth method/data. No-op for MQTT 3.1.1 (no properties).
     *
     * @return array{0: array<string, string>, 1: int} [userProperties, newOffset]
     */
    private function readUserProperties(int $fd, string $body, int $offset): array
    {
        $userProperties = [];

        if (($this->protocol[$fd] ?? 4) < 5) {
            return [$userProperties, $offset];
        }

        [$length, $lenBytes] = $this->decodeLength($body, $offset);
        $offset += $lenBytes;
        $end = $offset + $length;

        while ($offset < $end) {
            $id = ord($body[$offset]);
            $offset++;

            if ($id === self::PROP_AUTH_METHOD || $id === self::PROP_AUTH_DATA) {
                [, $offset] = $this->readString($body, $offset);
            } elseif ($id === self::PROP_USER) {
                [$key, $offset] = $this->readString($body, $offset);
                [$value, $offset] = $this->readString($body, $offset);
                $userProperties[$key] = $value;
            } else {
                // POC assumption: clients send only auth and user properties.
                throw new \RuntimeException('Unhandled connect property 0x' . dechex($id));
            }
        }

        return [$userProperties, $offset];
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

    private function handleAuth(Server $server, int $fd, string $body): void
    {
        if ($body === '') {
            // Shorthand: remaining length 0 means reason Success with no properties.
            $reasonCode = self::AUTH_SUCCESS;
            $method = $data = '';
        } else {
            $reasonCode = ord($body[0]);
            [$method, $data] = $this->readAuthProperties($body, 1);
        }

        // $reasonCode: 0x19 re-authenticate, 0x18 continue.
        // $method e.g. "appwrite-session"; $data is the credential to verify.
        // TODO: verify $data and derive the identity, then persist it for this $fd.
        // Success on a live connection is acknowledged with an AUTH packet;
        // failure should DISCONNECT (or CONNACK 0x87 during the initial handshake).
        $server->send($fd, $this->encodeAuth(self::AUTH_SUCCESS, $method));
    }

    /**
     * Read the Authentication Method and Data from a property block.
     * Works for both the AUTH packet and the CONNECT properties.
     *
     * @return array{0: string, 1: string} [authMethod, authData]
     */
    private function readAuthProperties(string $body, int $offset): array
    {
        $method = $data = '';

        [$length, $lenBytes] = $this->decodeLength($body, $offset);
        $offset += $lenBytes;
        $end = $offset + $length;

        while ($offset < $end) {
            $id = ord($body[$offset]);
            $offset++;

            match ($id) {
                self::PROP_AUTH_METHOD => [$method, $offset] = $this->readString($body, $offset),
                self::PROP_AUTH_DATA => [$data, $offset] = $this->readString($body, $offset),
                // POC assumption: clients send only method and data. A general
                // skip needs each property's wire type to advance correctly.
                default => throw new \RuntimeException('Unhandled auth property 0x' . dechex($id)),
            };
        }

        return [$method, $data];
    }

    private function encodeAuth(int $reasonCode, string $method, string $data = ''): string
    {
        $properties = '';
        if ($method !== '') {
            $properties .= chr(self::PROP_AUTH_METHOD) . $this->encodeString($method);
        }
        if ($data !== '') {
            $properties .= chr(self::PROP_AUTH_DATA) . $this->encodeString($data);
        }

        $variable = chr($reasonCode) . $this->encodeLength(strlen($properties)) . $properties;

        return chr(self::AUTH << 4) . $this->encodeLength(strlen($variable)) . $variable;
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
        unset($this->subscriptions[$fd], $this->protocol[$fd], $this->project[$fd]);
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
