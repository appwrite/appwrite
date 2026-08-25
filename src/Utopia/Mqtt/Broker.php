<?php

namespace Utopia\Mqtt;

use Swoole\Server;
use Utopia\Span\Span;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\UpDownCounter;

class Broker
{
    // Control packet types (MQTT fixed header, high nibble).
    private const CONNECT = 1;
    private const CONNACK = 2;
    private const PUBLISH = 3;
    private const PUBACK = 4;
    private const SUBSCRIBE = 8;
    private const SUBACK = 9;
    private const UNSUBSCRIBE = 10;
    private const UNSUBACK = 11;
    private const PINGREQ = 12;
    private const PINGRESP = 13;
    private const DISCONNECT = 14;
    private const AUTH = 15;

    // Reason codes (MQTT 5.0). 0x00 is Success across every acknowledgement.
    private const REASON_SUCCESS = 0x00;
    private const REASON_NOT_AUTHORIZED = 0x87;
    private const AUTH_SUCCESS = 0x00;
    private const AUTH_CONTINUE = 0x18; // @phpstan-ignore classConstant.unused (reserved for multi-round continue-auth)
    private const AUTH_REAUTH = 0x19; // @phpstan-ignore classConstant.unused (reserved for the reauth flow)

    private const QOS_1 = 1;

    // Property identifiers, shared between CONNECT and AUTH property blocks.
    private const PROP_AUTH_METHOD = 0x15;
    private const PROP_AUTH_DATA = 0x16;
    private const PROP_USER = 0x26;

    /** @var array<int, int> fd => protocol level (4 = 3.1.1, 5 = 5.0) */
    private array $protocol = [];

    /** @var array<int, string> fd => project id (from a CONNECT User Property) */
    private array $project = [];

    /** Bidirectional subscription index: fd <-> project-scoped topic trie. */
    private SubscriptionStore $subscriptions;

    /** @var array<int, array<string, string>> fd => resolved identity (project/user ids) */
    private array $identity = [];

    /** @var array<int, int> fd => last outbound packet id (QoS 1 delivery) */
    private array $packetId = [];

    /** @var array<int, true> fds counted as active (accepted CONNECT), for a balanced gauge */
    private array $active = [];

    /**
     *
     * @var (callable(string, string, string): array<string, string>)|null
     */
    private $authenticator = null;

    /**
     * ACL for SUBSCRIBE: given the connection identity and a topic filter, may it subscribe?
     * Without one, every authenticated connection may subscribe to anything.
     *
     * @var (callable(array<string, string>, string): bool)|null
     */
    private $authorizer = null;

    private Counter $connectionsOpened;
    private UpDownCounter $connectionsActive;
    private Counter $subscriptionsCounter;
    private Counter $messagesPublished;
    private Counter $messagesDelivered;
    private Counter $messagesDropped;

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 1883,
        private readonly int $maxPacketSize = 64000,
    ) {
        $this->subscriptions = new SubscriptionStore();
        $this->withTelemetry(new NoTelemetry());
    }

    /**
     * Register telemetry instruments for connection and delivery metrics. Defaults to a
     * no-op adapter, so the broker works untelemetered (e.g. raw protocol testing).
     */
    public function withTelemetry(Telemetry $telemetry): void
    {
        $this->connectionsOpened = $telemetry->createCounter('mqtt.connections.opened');
        $this->connectionsActive = $telemetry->createUpDownCounter('mqtt.connections.active');
        $this->subscriptionsCounter = $telemetry->createCounter('mqtt.subscriptions');
        $this->messagesPublished = $telemetry->createCounter('mqtt.messages.published');
        $this->messagesDelivered = $telemetry->createCounter('mqtt.messages.delivered');
        $this->messagesDropped = $telemetry->createCounter('mqtt.messages.dropped');
    }

    /**
     * Register the CONNECT authenticator. Without one the broker accepts every
     * connection (raw protocol testing).
     *
     * @param callable(string, string, string): array<string, string> $authenticator
     */
    public function onConnect(callable $authenticator): void
    {
        $this->authenticator = $authenticator;
    }

    /**
     * Register the SUBSCRIBE authorizer (ACL). Denied filters are answered with a
     * Not Authorized reason code and never enter the subscription store.
     *
     * @param callable(array<string, string>, string): bool $authorizer
     */
    public function onSubscribe(callable $authorizer): void
    {
        $this->authorizer = $authorizer;
    }

    public function start(): void
    {
        $server = new Server($this->host, $this->port, SWOOLE_BASE);
        $server->set([
            'open_mqtt_protocol' => true,
            'worker_num' => 1,
            'package_max_length' => $this->maxPacketSize,
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

        // One span per control packet, tagged with the connection's identity for tracing.
        $span = Span::init('mqtt.' . $this->packetName($type));
        $span->set('mqtt.fd', $fd);
        $span->set('project.id', $this->project[$fd] ?? '');
        $span->set('user.id', $this->identity[$fd]['userId'] ?? '');

        try {
            match ($type) {
                self::CONNECT => $this->handleConnect($server, $fd, $body),
                self::SUBSCRIBE => $this->handleSubscribe($server, $fd, $body),
                self::UNSUBSCRIBE => $this->handleUnsubscribe($server, $fd, $body),
                self::PUBLISH => $this->handlePublish($server, $fd, $flags, $body),
                self::PUBACK => null,
                self::AUTH => $this->handleAuth($server, $fd, $body),
                self::PINGREQ => $server->send($fd, chr(self::PINGRESP << 4) . $this->encodeLength(0)),
                self::DISCONNECT => $server->close($fd),
                default => null,
            };
            $span->finish();
        } catch (\Throwable $error) {
            $span->finish(error: $error);
            throw $error;
        }
    }

    private function packetName(int $type): string
    {
        return match ($type) {
            self::CONNECT => 'connect',
            self::SUBSCRIBE => 'subscribe',
            self::UNSUBSCRIBE => 'unsubscribe',
            self::PUBLISH => 'publish',
            self::PUBACK => 'puback',
            self::AUTH => 'auth',
            self::PINGREQ => 'pingreq',
            self::DISCONNECT => 'disconnect',
            default => 'unknown',
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

        $properties = $this->getProperties($fd, $body, $offset);

        $projectId = $properties['user']['projectId'] ?? '';
        $this->project[$fd] = $projectId;
        $authMethod = $properties['authMethod'];
        Span::add('project.id', $projectId);
        Span::add('mqtt.auth_method', $authMethod);

        // TODO: add abuse limiting keyed on the client ip.
        if ($this->authenticator !== null) {
            $identity = ($this->authenticator)($projectId, $authMethod, $properties['authData']);

            if ($identity === []) {
                $this->connectionsOpened->add(1, ['auth_method' => $authMethod, 'result' => 'rejected']);
                Span::add('mqtt.result', 'rejected');
                $this->sendConnack($server, $fd, $level, self::REASON_NOT_AUTHORIZED);
                $server->close($fd);
                return;
            }

            $this->identity[$fd] = $identity;
            Span::add('user.id', $identity['userId'] ?? '');
        }

        $this->connectionsOpened->add(1, ['auth_method' => $authMethod, 'result' => 'accepted']);
        $this->connectionsActive->add(1);
        $this->active[$fd] = true;
        $this->sendConnack($server, $fd, $level, self::REASON_SUCCESS);
    }

    private function sendConnack(Server $server, int $fd, int $level, int $reasonCode): void
    {
        // CONNACK: [ack flags = 0][reason code](+ [property length = 0] for v5)
        $variable = chr(0x00) . chr($reasonCode) . ($level >= 5 ? $this->encodeLength(0) : '');
        $server->send($fd, chr(self::CONNACK << 4) . $this->encodeLength(strlen($variable)) . $variable);
    }

    /**
     * Read a v5 property block: User Properties as a key/value map plus the
     * Authentication Method and Data. Shared by CONNECT and AUTH, which carry an
     * identical block. No-op for MQTT 3.1.1 (no properties).
     *
     * @return array{user: array<string, string>, authMethod: string, authData: string}
     */
    private function getProperties(int $fd, string $body, int $offset): array
    {
        $properties = ['user' => [], 'authMethod' => '', 'authData' => ''];

        if (($this->protocol[$fd] ?? 4) < 5) {
            return $properties;
        }

        [$length, $lenBytes] = $this->decodeLength($body, $offset);
        $offset += $lenBytes;
        $end = $offset + $length;

        while ($offset < $end) {
            $id = ord($body[$offset]);
            $offset++;

            switch ($id) {
                case self::PROP_AUTH_METHOD:
                    [$properties['authMethod'], $offset] = $this->readString($body, $offset);
                    break;
                case self::PROP_AUTH_DATA:
                    [$properties['authData'], $offset] = $this->readString($body, $offset);
                    break;
                case self::PROP_USER:
                    [$key, $offset] = $this->readString($body, $offset);
                    [$value, $offset] = $this->readString($body, $offset);
                    $properties['user'][$key] = $value;
                    break;
                default:
                    // POC assumption: clients send only auth and user properties.
                    throw new \RuntimeException('Unhandled connect property 0x' . dechex($id));
            }
        }

        return $properties;
    }

    private function handleSubscribe(Server $server, int $fd, string $body): void
    {
        $offset = 0;
        $packetId = substr($body, 0, 2);
        $offset += 2;

        $subId = $this->getProperties($fd, $body, $offset)['user']['subId'] ?? '';
        $offset = $this->skipProperties($fd, $body, $offset);

        $projectId = $this->project[$fd] ?? '';
        $userId = $this->identity[$fd]['userId'] ?? '';
        $identity = $this->identity[$fd] ?? [];

        $granted = '';
        while ($offset < strlen($body)) {
            [$filter, $offset] = $this->readString($body, $offset);
            $offset += 1; // subscription options byte
            Span::add('mqtt.topic', $filter);

            // ACL: an unauthorized filter is answered Not Authorized and never stored.
            if ($this->authorizer !== null && !($this->authorizer)($identity, $filter)) {
                $granted .= chr(self::REASON_NOT_AUTHORIZED);
                $this->subscriptionsCounter->add(1, ['result' => 'denied']);
                Span::add('mqtt.result', 'denied');
                continue;
            }

            $this->subscriptions->subscribe($projectId, $userId, $subId ?: $filter, $filter, $fd, self::QOS_1);
            $granted .= chr(self::QOS_1); // granted max QoS 1
            $this->subscriptionsCounter->add(1, ['result' => 'granted']);
        }
        // SUBACK: packet id (+ property length 0 for v5) + granted codes
        $variable = $packetId . ($this->protocol[$fd] >= 5 ? $this->encodeLength(0) : '') . $granted;
        $server->send($fd, chr(self::SUBACK << 4) . $this->encodeLength(strlen($variable)) . $variable);
    }

    private function handleUnsubscribe(Server $server, int $fd, string $body): void
    {
        $offset = 0;
        $packetId = substr($body, 0, 2);
        $offset += 2;

        $subId = $this->getProperties($fd, $body, $offset)['user']['subId'] ?? '';
        $offset = $this->skipProperties($fd, $body, $offset);

        $count = 0;
        while ($offset < strlen($body)) {
            [$filter, $offset] = $this->readString($body, $offset);
            $this->subscriptions->unsubscribe($subId ?: $filter, $fd);
            $count++;
        }

        // UNSUBACK: packet id (+ property length 0 + one reason code per filter for v5)
        $variable = $packetId;
        if ($this->protocol[$fd] >= 5) {
            $variable .= $this->encodeLength(0) . str_repeat(chr(self::REASON_SUCCESS), $count);
        }
        $server->send($fd, chr(self::UNSUBACK << 4) . $this->encodeLength(strlen($variable)) . $variable);
    }

    private function handleAuth(Server $server, int $fd, string $body): void
    {
        $method = '';
        $data = '';
        $userProperties = [];

        if ($body !== '') {
            // $body[0] is the reason code (0x19 re-authenticate)
            // from the offset 1 everything is the mqtt v5 properties
            $properties = $this->getProperties($fd, $body, 1);
            $method = $properties['authMethod'];
            $data = $properties['authData'];
            $userProperties = $properties['user'];
        }

        // The AUTH packet re-sends projectId as a User Property.
        // TODO: check the events for the reauth only refreshes
        // the credential, so enforce it stays on the project resolved at CONNECT — the
        // connection must not switch tenants — then verify the fresh credential.
        if ($this->authenticator !== null) {
            $projectId = $userProperties['projectId'] ?? '';

            $identity = ($projectId !== '' && $projectId === ($this->project[$fd] ?? ''))
                ? ($this->authenticator)($projectId, $method, $data)
                : [];

            if ($identity === []) {
                $this->sendDisconnect($server, $fd, self::REASON_NOT_AUTHORIZED);
                return;
            }

            $this->identity[$fd] = $identity;
        }

        // Acknowledge success on the live connection with an AUTH packet.
        $server->send($fd, $this->encodeAuth(self::AUTH_SUCCESS, $method));
    }

    private function sendDisconnect(Server $server, int $fd, int $reasonCode): void
    {
        $server->send($fd, chr(self::DISCONNECT << 4) . $this->encodeLength(1) . chr($reasonCode));
        $server->close($fd);
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

        Span::add('mqtt.topic', $topic);
        $this->messagesPublished->add(1, ['qos' => $qos]);

        $subscribers = $this->subscriptions->getSubscribers($this->project[$fd] ?? '', $topic);
        Span::add('mqtt.subscribers', count($subscribers));

        if ($subscribers === []) {
            $this->messagesDropped->add(1, ['reason' => 'no_subscriber']);
        }

        foreach ($subscribers as $subscriberFd => $grantedQos) {
            $effectiveQos = min($qos, $grantedQos);
            $this->send($server, $subscriberFd, $topic, $payload, $effectiveQos);
            $this->messagesDelivered->add(1, ['qos' => $effectiveQos]);
        }

        if ($qos === 1) {
            // PUBACK: packet id (reason/properties omitted -> valid for both versions)
            $server->send($fd, chr(self::PUBACK << 4) . $this->encodeLength(strlen($packetId)) . $packetId);
        }
    }

    private function send(Server $server, int $fd, string $topic, string $payload, int $qos): void
    {
        // Variable header order: topic, packet id (QoS > 0), properties (v5), payload.
        $variable = $this->encodeString($topic);
        if ($qos > 0) {
            $packetId = $this->getNextPacketId($fd);
            $variable .= chr($packetId >> 8) . chr($packetId & 0xFF);
        }
        $variable .= ((($this->protocol[$fd] ?? 4) >= 5 ? $this->encodeLength(0) : '')) . $payload;

        // PUBLISH, flags = QoS << 1
        $header = chr((self::PUBLISH << 4) | ($qos << 1));
        $server->send($fd, $header . $this->encodeLength(strlen($variable)) . $variable);
    }

    /** Next per-connection outbound packet id, wrapping 1..65535 (0 is not allowed). */
    private function getNextPacketId(int $fd): int
    {
        $next = (($this->packetId[$fd] ?? 0) % 0xFFFF) + 1;
        $this->packetId[$fd] = $next;

        return $next;
    }

    private function onClose(Server $server, int $fd): void
    {
        if (isset($this->active[$fd])) {
            $this->connectionsActive->add(-1);
        }
        $this->subscriptions->close($fd);
        unset(
            $this->protocol[$fd],
            $this->project[$fd],
            $this->identity[$fd],
            $this->packetId[$fd],
            $this->active[$fd],
        );
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
